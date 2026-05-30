<?php
/**
 * Storefront client (procedural boot file) for the Seekmodo MCP gateway.
 *
 * This file is the bridge between Zen Cart's procedural code paths
 * (class.search.php, ajax_search_log.php, transfer_products.php) and
 * the OOP SDK in includes/library/Numinix/Seekmodo/Client.php.
 *
 * Design contract:
 *   - Always degrade gracefully. Every helper here returns null on
 *     ANY failure; the caller's existing direct-Typesense / LIKE
 *     code stays as the last line of defence (§5.2 of the project
 *     plan).
 *   - Hot path is hard-capped at NUMINIX_SEEKMODO_TIMEOUT_MS
 *     (default 250 ms). Storefront latency NEVER tracks gateway
 *     latency.
 *   - Circuit-breaker state is shared across php-fpm workers via
 *     APCu when the extension is loaded; otherwise per-process —
 *     the breaker's own contract bounds damage to one worker per
 *     window.
 *
 * Configuration (set by the plugin's ScriptedInstaller):
 *   NUMINIX_SEEKMODO_URL              base URL, e.g. https://mcp.seekmodo.com
 *   NUMINIX_SEEKMODO_TENANT_ID        per-store identifier
 *   NUMINIX_SEEKMODO_SHARED_SECRET    per-store HMAC key (64-hex)
 *   NUMINIX_SEEKMODO_MODE             off | shadow | enforce
 *   NUMINIX_SEEKMODO_TIMEOUT_MS       optional, defaults to 250
 *   NUMINIX_SEEKMODO_INDEX_BATCH      optional, defaults to 500
 *   NUMINIX_SEEKMODO_DEBUG            optional, true|false
 *
 * Until the plugin's installer runs (or while MODE=off) every
 * helper returns null and the storefront keeps using its existing
 * direct-Typesense path.
 */

if (!function_exists('_numinix_seekmodo_cfg')) {
    /**
     * Resolve a NUMINIX_SEEKMODO_* constant, falling back to a default.
     * Trims so admin UI typos don't leak into HTTP headers.
     */
    function _numinix_seekmodo_cfg(string $key, string $default = ''): string
    {
        if (!defined($key)) {
            return $default;
        }
        $v = trim((string)constant($key));
        return $v !== '' ? $v : $default;
    }
}

if (!function_exists('numinix_seekmodo_mode')) {
    /**
     * Returns the raw configured mode value. One of:
     *   off | shadow | enforce | active
     *
     * `active` is the auto-managed mode (default for new installs);
     * the storefront should NOT branch on this directly — call
     * numinix_seekmodo_effective_mode() instead, which resolves
     * `active` through the AutoPromoter state machine and returns
     * one of `off|shadow|enforce`.
     */
    function numinix_seekmodo_mode(): string
    {
        $m = strtolower(_numinix_seekmodo_cfg('NUMINIX_SEEKMODO_MODE', 'off'));
        if ($m === 'shadow' || $m === 'enforce' || $m === 'active') {
            return $m;
        }
        return 'off';
    }
}

if (!function_exists('_numinix_seekmodo_promoter')) {
    /**
     * Lazy-cached AutoPromoter so a single request answers
     * effective_mode() and observe() against the same FSM instance.
     */
    function _numinix_seekmodo_promoter(): \Numinix\Seekmodo\AutoPromoter
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        if (!class_exists('Numinix\\Seekmodo\\AutoPromoter')) {
            // SDK autoloader hasn't run yet; load directly. The path is
            // stable because the plugin manifest pins it.
            $base = __DIR__ . '/../library/Numinix/Seekmodo';
            require_once $base . '/PromotionStore.php';
            require_once $base . '/AutoPromoter.php';
        }
        $cached = new \Numinix\Seekmodo\AutoPromoter();
        return $cached;
    }
}

if (!function_exists('numinix_seekmodo_effective_mode')) {
    /**
     * Resolve the runtime mode the storefront should obey. Identical
     * to numinix_seekmodo_mode() except `active` is collapsed via the
     * AutoPromoter's state machine into one of `off|shadow|enforce`.
     */
    function numinix_seekmodo_effective_mode(): string
    {
        $configured = numinix_seekmodo_mode();
        try {
            return _numinix_seekmodo_promoter()->resolveMode($configured);
        } catch (\Throwable $e) {
            // FSM bug must never break the storefront. Fall through to
            // the literal mode, which collapses `active` to off.
            if ($configured === 'shadow' || $configured === 'enforce') {
                return $configured;
            }
            return 'off';
        }
    }
}

if (!function_exists('numinix_seekmodo_observe')) {
    /**
     * Record one gateway outcome so the AutoPromoter can decide whether
     * to promote, demote, or stay put. Always safe to call; never
     * throws.
     */
    function numinix_seekmodo_observe(bool $ok): void
    {
        try {
            _numinix_seekmodo_promoter()->observe($ok);
        } catch (\Throwable $e) {
            // best-effort
        }
    }
}

if (!function_exists('numinix_seekmodo_promotion_snapshot')) {
    /**
     * Read-only snapshot of the FSM state — used by the admin "Status"
     * page and by tools/verify_redline_seekmodo.py to expose a
     * canonical "what is the connector doing right now" view.
     *
     * @return array<string,mixed>
     */
    function numinix_seekmodo_promotion_snapshot(): array
    {
        try {
            return _numinix_seekmodo_promoter()->snapshot();
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}

if (!function_exists('numinix_seekmodo_enabled')) {
    /**
     * Active when the *effective* mode is not 'off' AND all three
     * credentials are populated. Resolves `active` through the
     * AutoPromoter, so a half-bootstrapped store still returns true
     * (it'll just behave as shadow internally until promotion lands).
     */
    function numinix_seekmodo_enabled(): bool
    {
        if (numinix_seekmodo_effective_mode() === 'off') {
            return false;
        }
        $url = _numinix_seekmodo_cfg('NUMINIX_SEEKMODO_URL');
        $tenant = _numinix_seekmodo_cfg('NUMINIX_SEEKMODO_TENANT_ID');
        $secret = _numinix_seekmodo_cfg('NUMINIX_SEEKMODO_SHARED_SECRET');
        if ($url === '' || $tenant === '' || $secret === '') {
            return false;
        }
        if (!function_exists('curl_init')) {
            return false;
        }
        return true;
    }
}

if (!function_exists('_numinix_seekmodo_client')) {
    /**
     * Lazy-cached SDK Client built from the NUMINIX_SEEKMODO_*
     * constants. Returns null when not enabled — callers MUST treat
     * null as "fall back to native path".
     */
    function _numinix_seekmodo_client(): ?\Numinix\Seekmodo\Client
    {
        static $cached = false;
        static $client = null;
        if ($cached) {
            return $client;
        }
        $cached = true;
        if (!numinix_seekmodo_enabled()) {
            return $client;
        }
        if (!class_exists('Numinix\\Seekmodo\\Client')) {
            return $client;
        }
        $client = \Numinix\Seekmodo\Client::fromConfiguration();
        return $client;
    }
}

if (!function_exists('numinix_seekmodo_search')) {
    /**
     * POST /v1/search — returns the decoded gateway response on
     * success, null on any failure (auth / transport / circuit-open
     * / non-2xx). The caller (typically class.search.php's
     * numinix_elastic_search_results()) MUST fall back to direct
     * Typesense or LIKE on null.
     *
     * Contract: only called when MODE != off. Mode-aware behavior
     * (shadow vs enforce) lives in the caller — here we just route
     * the request.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    function numinix_seekmodo_search(array $params): ?array
    {
        $client = _numinix_seekmodo_client();
        if ($client === null) {
            return null;
        }
        return $client->search($params);
    }
}

if (!function_exists('numinix_seekmodo_index_chunked')) {
    /**
     * Push a batch of documents through /v1/index, chunking at
     * NUMINIX_SEEKMODO_INDEX_BATCH (default 500) so we stay under
     * the gateway's 1000-doc cap.
     *
     * Returns an aggregate result:
     *   ['ok' => bool, 'chunks' => int, 'sent' => int, 'failed' => int,
     *    'errors' => string[]]
     *
     * 'ok' is true ONLY when every chunk succeeded. The caller (cron
     * indexer) treats false as "fall back to direct Typesense
     * documents/import" — we never half-index without telling them.
     *
     * @param array<int,array<string,mixed>> $documents
     * @return array{ok:bool,chunks:int,sent:int,failed:int,errors:array<int,string>}
     */
    function numinix_seekmodo_index_chunked(array $documents): array
    {
        $result = ['ok' => false, 'chunks' => 0, 'sent' => 0, 'failed' => 0, 'errors' => []];
        $client = _numinix_seekmodo_client();
        if ($client === null) {
            $result['errors'][] = 'seekmodo_disabled';
            return $result;
        }
        $batchSize = (int)_numinix_seekmodo_cfg('NUMINIX_SEEKMODO_INDEX_BATCH', '500');
        if ($batchSize < 50 || $batchSize > 1000) {
            $batchSize = 500;
        }
        $total = count($documents);
        if ($total === 0) {
            $result['ok'] = true;
            return $result;
        }
        $allOk = true;
        for ($offset = 0; $offset < $total; $offset += $batchSize) {
            $chunk = array_slice($documents, $offset, $batchSize);
            $resp = $client->index($chunk);
            $result['chunks']++;
            if ($resp === null) {
                $allOk = false;
                $result['failed'] += count($chunk);
                $result['errors'][] = 'chunk_' . $result['chunks'] . '_failed';
                // Don't bail on first failure — we want to know how
                // many chunks the gateway can handle so the cron's
                // exit-code summary is accurate. The breaker will
                // open after 5 in-window failures and the rest of
                // the loop will fast-skip.
                continue;
            }
            $upserted = isset($resp['upserted']) ? max(0, (int)$resp['upserted']) : count($chunk);
            $failed = isset($resp['failed']) ? max(0, (int)$resp['failed']) : 0;
            $skipped = isset($resp['skipped_disabled']) ? max(0, (int)$resp['skipped_disabled']) : 0;
            // Disabled docs skipped/evicted by the gateway are handled no-ops, not failures.
            $accounted = $upserted + $failed + $skipped;
            $result['sent'] += $upserted + $skipped;
            if ($failed > 0 || $accounted < count($chunk)) {
                $allOk = false;
                $missing = max(0, count($chunk) - $accounted);
                $result['failed'] += $failed + $missing;
                $result['errors'][] = 'chunk_' . $result['chunks'] . '_partial_failed';
                if (!empty($resp['errors']) && is_array($resp['errors'])) {
                    foreach (array_slice($resp['errors'], 0, 3) as $err) {
                        $result['errors'][] = is_scalar($err)
                            ? (string)$err
                            : substr(json_encode($err, JSON_UNESCAPED_SLASHES), 0, 200);
                    }
                }
            }
        }
        $result['ok'] = $allOk && $result['failed'] === 0;
        return $result;
    }
}

if (!function_exists('numinix_seekmodo_event')) {
    /**
     * POST /v1/events. Used by ajax_search_log.php to mirror click
     * beacons to seek-db01. The caller is expected to keep writing
     * its existing local row (numinix_search_log) too — we treat
     * the gateway as a secondary sink during shadow mode and the
     * primary aggregator after enforce.
     *
     * Returns the decoded response or null on failure. Callers MUST
     * NOT block on this — the beacon is fire-and-forget UX.
     *
     * @param array<string,mixed> $event
     * @return array<string,mixed>|null
     */
    function numinix_seekmodo_event(array $event): ?array
    {
        $client = _numinix_seekmodo_client();
        if ($client === null) {
            return null;
        }
        return $client->events($event);
    }
}

if (!function_exists('numinix_seekmodo_subscription_state')) {
    /**
     * Read the cached subscription state (active|cancelled|unknown).
     * Set by the SDK on every gateway round-trip — `cancelled` means
     * the gateway recently returned 403 tenant_paused, so the admin
     * UI should render an "Account paused" notice.
     */
    function numinix_seekmodo_subscription_state(): string
    {
        if (!class_exists('Numinix\\Seekmodo\\Client')) {
            return 'unknown';
        }
        return \Numinix\Seekmodo\Client::readSubscriptionState();
    }
}

if (!function_exists('numinix_seekmodo_circuit_open')) {
    /**
     * Lets the storefront skip even constructing a search request
     * payload when the breaker is already open. Cheap shortcut to
     * the SDK's own state check.
     */
    function numinix_seekmodo_circuit_open(): bool
    {
        $client = _numinix_seekmodo_client();
        if ($client === null) {
            return false;
        }
        return $client->isCircuitOpen();
    }
}
