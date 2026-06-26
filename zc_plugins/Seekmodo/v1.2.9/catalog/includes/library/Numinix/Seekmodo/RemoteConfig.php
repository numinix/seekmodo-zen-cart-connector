<?php

namespace Numinix\Seekmodo;

/**
 * Remote runtime config helper.
 *
 * Pulls the operator-controlled tenant config (mode, auto_promote,
 * timeout_ms, index_batch, debug) from the Seekmodo gateway every
 * 5 min and writes it through to zen_configuration so the rest of
 * the connector keeps reading the same NUMINIX_SEEKMODO_* constants
 * it always has. APCu cache fronts the network call so even a
 * thundering 100-rps storefront only hits the gateway once per
 * 5 min per worker.
 *
 * Push direction: AutoPromoter calls push() on every FSM transition,
 * and the indexer cron fires it once per run, so the admin UI's
 * ConnectorStatusCard always shows fresh state.
 *
 * Owns its own HMAC + curl machinery (rather than reusing
 * Numinix\Seekmodo\Client) because Client::isEnabled() would short-
 * circuit pull() when MODE=off — but pull() is the very thing that
 * determines whether MODE flipped to active. The two helpers are
 * sibling tools; they share the gateway URL + tenant + secret but
 * not the call path.
 */
final class RemoteConfig
{
    private const ENDPOINT = '/v1/tenant.snapshot';
    private const HEADER_TENANT = 'X-Seekmodo-Tenant';
    private const HEADER_SIGNATURE = 'X-Seekmodo-Signature';
    private const HEADER_TIMESTAMP = 'X-Seekmodo-Timestamp';

    // 5-min cache TTL per the §5.2 contract. Long enough that the
    // gateway only sees ~12 pulls/hour/store; short enough that an
    // operator flipping MODE in admin.seekmodo.com sees the change
    // within a render window.
    public const CACHE_TTL_S = 300;
    private const CACHE_KEY = 'numinix.seekmodo.remote_config';

    // Pull is on the hot path (storefront search) but still needs
    // enough headroom for DNS + TCP + TLS to Cloudflare. 250ms is too
    // tight on hosts with a slow resolver — first hit easily hits 200ms
    // for cold DNS alone, leaving no budget for the request body.
    // 750ms is the same overall budget Client.php uses today and keeps
    // a single missed pull under the 1s-perceived-latency line.
    private const PULL_TIMEOUT_MS = 750;
    // Push is a background fire-and-forget — can afford slightly more
    // headroom but still keep the indexer cron snappy.
    private const PUSH_TIMEOUT_MS = 1000;

    /** @var callable|null hook for tests (signature: fn(string $level, string $msg, array $ctx): void) */
    private $logger;

    // PHP 7.4-compatible property declarations. v1.0.19 ships to
    // hosts ranging from ea-php74 (KIP) to ea-php83, so we cannot
    // rely on PHP 8.0's constructor property promotion or 8.1's
    // readonly modifier here. The semantics stay identical: the
    // three values are assigned once in __construct and never
    // mutated thereafter.
    /** @var string */
    private $url;
    /** @var string */
    private $tenantId;
    /** @var string */
    private $sharedSecret;

    public function __construct(
        string $url,
        string $tenantId,
        string $sharedSecret,
        ?callable $logger = null
    ) {
        $this->url = $url;
        $this->tenantId = $tenantId;
        $this->sharedSecret = $sharedSecret;
        $this->logger = $logger;
    }

    /**
     * Build a RemoteConfig from the NUMINIX_SEEKMODO_* configuration
     * constants. Returns null when any required value is missing —
     * callers MUST treat null as "no remote config available, use
     * local constants".
     */
    public static function fromConfiguration(): ?self
    {
        $cfg = static fn (string $key, string $default = ''): string =>
            defined($key) ? (string) constant($key) : $default;
        $url = trim($cfg('NUMINIX_SEEKMODO_URL'));
        $tenant = trim($cfg('NUMINIX_SEEKMODO_TENANT_ID'));
        $secret = trim($cfg('NUMINIX_SEEKMODO_SHARED_SECRET'));
        if ($url === '' || $tenant === '' || $secret === '') {
            return null;
        }
        return new self(rtrim($url, '/'), $tenant, $secret);
    }

    /**
     * Pull the current snapshot. Returns the decoded array on success,
     * null when the gateway is unreachable / unauthenticated / the
     * tenant is missing. APCu-cached for CACHE_TTL_S.
     *
     * @return array<string, mixed>|null
     */
    public function pull(): ?array
    {
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($this->cacheKey());
            if (is_array($cached)) {
                return $cached;
            }
        }
        $row = $this->call([], self::PULL_TIMEOUT_MS);
        if (!is_array($row)) {
            return null;
        }
        if (function_exists('apcu_store')) {
            apcu_store($this->cacheKey(), $row, self::CACHE_TTL_S);
        }
        $this->writeThrough($row);
        return $row;
    }

    /**
     * Best-effort POST of the connector's FSM snapshot up to the
     * gateway. Never blocks the caller — failures are logged and
     * forgotten, the next push retries fresh.
     *
     * v1.0.19+ — also ships an `env` map (PHP version, extension
     * load state) so admin.seekmodo.com can flag tenants running
     * with degraded environment (no APCu, OPcache disabled, …)
     * before the merchant notices. The env map is built by
     * {@see EnvProbe::current()} and merged into the push payload
     * here. A pre-v1.0.19-env gateway silently ignores the extra
     * key (Store::pushSnapshot is a per-key allowlist), so
     * shipping the connector before the gateway PR is safe.
     *
     * @param array<string, mixed> $fsm Keys: auto_state,
     *   auto_state_since (ISO 8601), auto_history (list of last 16
     *   transitions), observed_count, errors_count.
     */
    public function push(array $fsm): bool
    {
        if ($fsm === []) {
            return false;
        }
        // EnvProbe lives next to RemoteConfig in the same library
        // tree but the autoloader may not have run yet on the
        // pre-paired path; class_exists triggers a load attempt
        // and falls back to omitting env on failure.
        if (class_exists(EnvProbe::class) && !array_key_exists('env', $fsm)) {
            $fsm['env'] = EnvProbe::current();
        }
        $row = $this->call(['push' => $fsm], self::PUSH_TIMEOUT_MS);
        if (is_array($row)) {
            // Refresh the local cache so the next pull doesn't re-fetch
            // the same data we just wrote.
            if (function_exists('apcu_store')) {
                apcu_store($this->cacheKey(), $row, self::CACHE_TTL_S);
            }
            $this->writeThrough($row);
            return true;
        }
        return false;
    }

    /**
     * Force the next pull() to bypass the APCu cache. Used by the
     * Connect admin page's "Refresh now" button so an operator can
     * see settings flip in real time after editing them on
     * admin.seekmodo.com.
     */
    public function invalidate(): void
    {
        if (function_exists('apcu_delete')) {
            apcu_delete($this->cacheKey());
        }
    }

    /**
     * Read the APCu-cached gateway snapshot without a network round-trip.
     * Used by the catalog pusher for indexer_overrides (NPF column name).
     *
     * @return array<string, mixed>|null
     */
    public static function cachedSnapshotOrNull(): ?array
    {
        $rc = self::fromConfiguration();
        if ($rc === null || !function_exists('apcu_fetch')) {
            return null;
        }
        $cached = apcu_fetch($rc->cacheKey());
        return is_array($cached) ? $cached : null;
    }

    /**
     * Resolve an indexer-side override from the cached snapshot.
     *
     * Path: relevance_overrides.indexer_overrides.<platform>.<key>
     *
     * @return mixed null when unset
     */
    public static function indexerOverride(string $platform, string $key)
    {
        $snap = self::cachedSnapshotOrNull();
        if ($snap === null) {
            return null;
        }
        $overrides = $snap['relevance_overrides'] ?? null;
        if (!is_array($overrides)) {
            return null;
        }
        $platformOverrides = $overrides['indexer_overrides'][$platform] ?? null;
        if (!is_array($platformOverrides) || !array_key_exists($key, $platformOverrides)) {
            return null;
        }
        return $platformOverrides[$key];
    }

    /** @return array<string, mixed>|null */
    private function call(array $body, int $timeoutMs): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $raw = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($raw === false) {
            return null;
        }
        $url = $this->url . self::ENDPOINT;
        $sig = hash_hmac('sha256', $raw, $this->sharedSecret);
        $headers = [
            'Content-Type: application/json',
            self::HEADER_TENANT . ': ' . $this->tenantId,
            self::HEADER_SIGNATURE . ': ' . $sig,
            self::HEADER_TIMESTAMP . ': ' . time(),
            'Accept: application/json',
        ];
        // Sprint 12 — discovery header. Mirrors Client::call() so the
        // gateway's recordObservedDomain() sees this connector's host
        // even when the connector is otherwise quiet (a paired-but-
        // inactive dev storefront, an indexer-only cron host, …).
        $hostHeader = class_exists(Client::class) ? Client::storefrontHost() : '';
        if ($hostHeader !== '') {
            $headers[] = Client::HEADER_STOREFRONT_HOST . ': ' . $hostHeader;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $raw,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            // Match Client.php — give connect (DNS+TCP+TLS) enough headroom
            // so a slow resolver doesn't trip the breaker spuriously.
            CURLOPT_CONNECTTIMEOUT_MS => max(250, min(750, intdiv($timeoutMs, 2))),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            // Force IPv4 — match Client.php to avoid flaky-IPv6 timeouts.
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errNum = curl_errno($ch);
        $errStr = curl_error($ch);
        curl_close($ch);
        if ($errNum !== 0 || $status === 0) {
            $this->log('warn', 'remote_config_transport_failure', [
                'status' => $status,
                'curl_errno' => $errNum,
                'curl_error' => $errStr,
            ]);
            return null;
        }
        if ($status >= 400) {
            $this->log('warn', 'remote_config_http_error', [
                'status' => $status,
                'body_preview' => is_string($resp) ? substr($resp, 0, 200) : '',
            ]);
            return null;
        }
        if (!is_string($resp) || $resp === '') {
            return null;
        }
        $decoded = json_decode($resp, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Write the operator-controlled fields from the gateway snapshot
     * back into TABLE_CONFIGURATION so the rest of the connector keeps
     * reading them as plain `define()`'d constants. Idempotent — we
     * only UPDATE rows that already exist (the installer creates them).
     *
     * Mirrors **eleven** keys:
     *   - mode              → NUMINIX_SEEKMODO_MODE
     *   - default_mode      → NUMINIX_SEEKMODO_DEFAULT_MODE       (W6b, v1.0.5+)
     *   - auto_promote      → NUMINIX_SEEKMODO_AUTO_PROMOTE
     *   - timeout_ms        → NUMINIX_SEEKMODO_TIMEOUT_MS         (legacy, kept for back-compat)
     *   - search_timeout_ms → NUMINIX_SEEKMODO_SEARCH_TIMEOUT_MS  (Sprint 15, v1.0.13+)
     *   - index_timeout_ms  → NUMINIX_SEEKMODO_INDEX_TIMEOUT_MS   (Sprint 15, v1.0.13+)
     *   - index_batch       → NUMINIX_SEEKMODO_INDEX_BATCH
     *   - indexer_schedule  → NUMINIX_SEEKMODO_INDEXER_SCHEDULE   (W6b, v1.0.5+)
     *   - debug             → NUMINIX_SEEKMODO_DEBUG
     *   - bot_check_backend → NUMINIX_BOT_CHECK_BACKEND           (W6c, v1.0.6+)
     *   - locked_domain     → NUMINIX_SEEKMODO_LOCKED_DOMAIN      (Sprint 12, v1.0.8+)
     *   - ask_ai_enabled    → NUMINIX_SEEKMODO_ASK_AI_ENABLED     (Ask AI)
     *
     * `default_mode` is consumed by `numinix_seekmodo_mode()` as the
     * fall-through when `MODE` is unset; `indexer_schedule` is read by
     * the operator-side `tools/install_redline_connector.py` to render
     * `/etc/cron.d/numinix-seekmodo-<tenant>`.
     *
     * `bot_check_backend` is one of `'legacy'` | `'gateway'`. The
     * vendored `numinix_bot_check_client.php` reads the resulting
     * `NUMINIX_BOT_CHECK_BACKEND` constant on every classify /
     * nonce.issue / nonce.verify call and re-routes the request to
     * the gateway's `BotCheck\*` tools when set to `'gateway'`. An
     * unrecognised value (e.g. typo, empty) is treated as `'legacy'`
     * by the client, so an in-flight write that lands a corrupt
     * value cannot accidentally route shopper traffic at the new
     * path. PROJECT_PLAN.md §P1-14 — bot-check consolidation, Phase B.
     *
     * @param array<string, mixed> $row
     */
    private function writeThrough(array $row): void
    {
        if (!isset($GLOBALS['db']) || !defined('TABLE_CONFIGURATION')) {
            return;
        }
        $db = $GLOBALS['db'];
        $writes = [
            'NUMINIX_SEEKMODO_MODE'         => isset($row['mode'])
                ? (string) $row['mode'] : null,
            'NUMINIX_SEEKMODO_DEFAULT_MODE' => isset($row['default_mode'])
                ? (string) $row['default_mode'] : null,
            'NUMINIX_SEEKMODO_AUTO_PROMOTE' => array_key_exists('auto_promote', $row)
                ? ((bool) $row['auto_promote'] ? 'true' : 'false') : null,
            'NUMINIX_SEEKMODO_TIMEOUT_MS'   => isset($row['timeout_ms'])
                ? (string) (int) $row['timeout_ms'] : null,
            // Sprint 15 (v1.0.13+) — split-bucket timeouts. The
            // legacy `timeout_ms` is still mirrored above so an
            // older connector reading from a newer gateway, or a
            // newer connector reading from a stale-schema gateway,
            // both keep working. fromConfiguration() in Client.php
            // prefers the bucket-specific values when present.
            'NUMINIX_SEEKMODO_SEARCH_TIMEOUT_MS' => isset($row['search_timeout_ms'])
                ? (string) (int) $row['search_timeout_ms'] : null,
            'NUMINIX_SEEKMODO_INDEX_TIMEOUT_MS'  => isset($row['index_timeout_ms'])
                ? (string) (int) $row['index_timeout_ms'] : null,
            'NUMINIX_SEEKMODO_INDEX_BATCH'  => isset($row['index_batch'])
                ? (string) (int) $row['index_batch'] : null,
            'NUMINIX_SEEKMODO_INDEXER_SCHEDULE' => isset($row['indexer_schedule'])
                ? (string) $row['indexer_schedule'] : null,
            'NUMINIX_SEEKMODO_DEBUG'        => array_key_exists('debug', $row)
                ? ((bool) $row['debug'] ? 'true' : 'false') : null,
            // W6c (v1.0.6+) — accept only the two values the
            // bot-check client recognises. Anything else is dropped
            // here so a malformed snapshot can't poison the
            // configuration row.
            'NUMINIX_BOT_CHECK_BACKEND'     => isset($row['bot_check_backend'])
                && in_array(
                    strtolower((string) $row['bot_check_backend']),
                    ['legacy', 'gateway'],
                    true
                )
                ? strtolower((string) $row['bot_check_backend']) : null,
            // Sprint 12 (v1.0.8+) — tenant domain lock. NULL on the
            // snapshot (which is the default for tenants that haven't
            // picked a host on admin.seekmodo.com yet) writes the
            // empty string here so the configuration row reflects
            // "no lock". Anything else is treated as a literal host
            // string; canonicalization happens server-side, so the
            // value reaching us here is already lowercased / port-
            // stripped / IDN-ASCII'd. We deliberately do NOT re-
            // canonicalize on the client because then a stale-schema
            // gateway that doesn't yet send the field would clear an
            // operator-set lock on the next pull.
            'NUMINIX_SEEKMODO_LOCKED_DOMAIN' => array_key_exists('locked_domain', $row)
                ? (string) ($row['locked_domain'] ?? '')
                : null,
            'NUMINIX_SEEKMODO_ASK_AI_ENABLED' => array_key_exists('ask_ai_enabled', $row)
                ? ((bool) $row['ask_ai_enabled'] ? 'true' : 'false') : null,
            'NUMINIX_SEEKMODO_SUGGEST_LAYOUT' => isset($row['suggest_layout'])
                && is_string($row['suggest_layout'])
                ? strtolower(trim((string) $row['suggest_layout'])) : null,
            'NUMINIX_SEEKMODO_SUGGEST_SHOW_BRANDING' => array_key_exists('suggest_show_branding', $row)
                ? (!empty($row['suggest_show_branding']) ? 'true' : 'false') : null,
        ];
        foreach ($writes as $key => $value) {
            if ($value === null) {
                continue;
            }
            try {
                $db->Execute(
                    'UPDATE ' . TABLE_CONFIGURATION
                    . " SET configuration_value = '" . zen_db_input($value) . "',"
                    . ' last_modified = NOW()'
                    . " WHERE configuration_key = '" . zen_db_input($key) . "'"
                );
            } catch (\Throwable $e) {
                // Best-effort — a single failed write doesn't fail the pull.
            }
        }
        // Sprint 14 PR 4 (v1.0.12) — refresh the static
        // `.well-known/mcp.json` discovery file on disk. The writer
        // is idempotent (skips the write when on-disk content
        // matches) so running on every snapshot poll is effectively
        // free. Best-effort; a writer failure does NOT fail the
        // tenant.snapshot pull or push.
        try {
            $gatewayHost = '';
            if (!empty($this->url)) {
                $h = (string) parse_url((string) $this->url, PHP_URL_HOST);
                if ($h !== '') {
                    $gatewayHost = $h;
                }
            }
            WellKnownWriter::writeFor((string) $this->tenantId, $gatewayHost);
        } catch (\Throwable $e) {
            $this->log('warn', 'well_known_write_failed', ['err' => $e->getMessage()]);
        }
    }

    private function cacheKey(): string
    {
        return self::CACHE_KEY . '.' . md5($this->url . '|' . $this->tenantId);
    }

    private function log(string $level, string $msg, array $ctx): void
    {
        if ($this->logger !== null) {
            ($this->logger)($level, $msg, $ctx);
            return;
        }
        if (defined('DIR_FS_LOGS')) {
            $logDir = rtrim(DIR_FS_LOGS, '/\\');
        } elseif (defined('DIR_FS_CATALOG')) {
            $logDir = rtrim(DIR_FS_CATALOG, '/\\') . '/logs';
        } else {
            return;
        }
        if (!is_dir($logDir)) {
            return;
        }
        $line = json_encode([
            'ts' => date('c'),
            'level' => $level,
            'msg' => $msg,
            'tenant' => $this->tenantId,
            'ctx' => $ctx,
        ], JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }
        @file_put_contents($logDir . '/numinix_seekmodo.log', $line . PHP_EOL, FILE_APPEND);
    }
}
