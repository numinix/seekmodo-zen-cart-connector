<?php

namespace Numinix\Seekmodo;

// Rotated append helper (procedural) — soft-require from functions/.
if (!\function_exists('numinix_seekmodo_log_append')) {
    $___smLogLib = \dirname(__DIR__, 3) . '/functions/numinix_seekmodo_log_lib.php';
    if (\is_file($___smLogLib)) {
        require_once $___smLogLib;
    }
}


/**
 * Storefront client for the Seekmodo MCP gateway.
 *
 * Speaks HMAC-SHA256 (body-only) to https://mcp.seekmodo.com per the
 * §6.1 envelope spec in the Seekmodo project plan. Three entry points
 * cover everything M1 ships:
 *
 *   - search(...)      → POST /v1/search
 *   - index(...)       → POST /v1/index   (pre-chunked at 500 docs/req
 *                        by Numinix\Seekmodo\IndexerHelper, see boot
 *                        file numinix_seekmodo_client.php)
 *   - events(...)      → POST /v1/events
 *
 * Two non-functional contracts the rest of the connector relies on:
 *
 *   1. Hot-path budget. Every call honors a per-request timeout
 *      (default 250 ms; tunable via NUMINIX_SEEKMODO_TIMEOUT_MS).
 *      Anything slower than that counts as a failure for circuit
 *      accounting, so storefront latency NEVER tracks gateway
 *      latency — slow gateway → tripped breaker → native LIKE.
 *
 *   2. Circuit breaker. 5 failures in a rolling 60 s window opens
 *      the breaker for 30 s, mirroring the bot-check SDK threshold.
 *      State is shared across php-fpm workers via APCu, so one
 *      worker can disarm callers on every other worker.
 *
 * Mirrors services/bot-check/sdks/php/src/Client.php in the
 * redlinestands repo. Identical envelope shape, just a different
 * service URL and a different APCu key prefix.
 */
class Client
{
    public const MODE_OFF = 'off';
    public const MODE_SHADOW = 'shadow';
    public const MODE_ENFORCE = 'enforce';
    // `active` is the FSM-managed mode (default for new installs). The
    // SDK never branches behavior on it directly — fromConfiguration()
    // resolves it through numinix_seekmodo_effective_mode() at construct
    // time so $this->mode reflects the effective shadow|enforce|off
    // for accurate logging. The constant is exported so callers (and
    // the cutover tool) can refer to it symbolically.
    public const MODE_ACTIVE = 'active';

    private const HEADER_TENANT = 'X-Seekmodo-Tenant';
    private const HEADER_SIGNATURE = 'X-Seekmodo-Signature';
    private const HEADER_TIMESTAMP = 'X-Seekmodo-Timestamp';
    // Sprint 12 (v1.0.8+) — discovery header used by the gateway to
    // accumulate `observed_domains_json` for the admin UI's
    // storefront-domain dropdown. Source priority handled by
    // {@see self::storefrontHost}. Always sent even when the connector
    // is locked-out at the host level — the gateway's
    // `recordObservedDomain` is the only reason we know to surface
    // the wrong-host case in the admin UI at all.
    public const HEADER_STOREFRONT_HOST = 'X-Seekmodo-Storefront-Host';
    // Phase B3 — manual full-push consent. When set to `manual` on
    // POST /v1/index, the gateway treats the batch as operator-
    // acknowledged (index_manual_override_until window) rather than
    // an automated cron push subject to A3 quota preflight skip.
    public const HEADER_INDEX_INTENT = 'X-Seekmodo-Index-Intent';

    private const CIRCUIT_THRESHOLD = 5;
    private const CIRCUIT_COOLDOWN_S = 30;
    private const CIRCUIT_KEY = 'numinix.seekmodo.circuit';

    // Subscription state flags written to APCu (read-only by admin).
    // 60-min TTL means a one-off blip is forgotten on its own; sustained
    // 403 tenant_paused responses keep the flag warm.
    private const SUBSCRIPTION_KEY = 'numinix.seekmodo.subscription_state';
    private const SUBSCRIPTION_TTL_S = 3600;
    public const SUB_STATE_ACTIVE = 'active';
    public const SUB_STATE_CANCELLED = 'cancelled';
    public const SUB_STATE_OVER_QUOTA = 'over_quota';

    // Over-quota (HTTP 402) state — same TTL family as cancelled. The
    // admin status page renders an "Over your monthly quota — falling
    // back to native search. Upgrade to restore." banner when this
    // flag is set, and `numinix_seekmodo_subscription_state()` returns
    // 'over_quota' so the admin connect page can deep-link to billing.
    private const OVER_QUOTA_KEY = 'numinix.seekmodo.over_quota';
    private const OVER_QUOTA_TTL_S = 3600;

    // v1.3.65 — daily unpaid-recovery plan. shouldPreferLocalSuggest()
    // short-circuits BEFORE fromConfiguration()/RemoteConfig::pull()
    // ever runs (see numinix_seekmodo_typeahead_lib.php), so once the
    // sticky above is stamped, the periodic tenant.snapshot pull that
    // would otherwise notice a resubscribe/trial-extend never fires
    // again — the sticky is self-perpetuating until an operator (or
    // the merchant, via the admin "Refresh snapshot" button) forces a
    // pull. This key rate-limits a background force-pull to at most
    // once per day so a stuck tenant self-heals without either.
    private const UNPAID_RECHECK_KEY = 'numinix.seekmodo.unpaid_recheck_at';
    private const UNPAID_RECHECK_INTERVAL_S = 86400;

    // v1.0.17 — gateway 4xx error codes that mean "this tenant is
    // unavailable, but the request itself is well-formed". When the
    // gateway returns one of these codes (in either a 403 or 404
    // body), we mark the subscription as cancelled (so the admin
    // status page surfaces the lifecycle state) AND record the call
    // as a benign fallback rather than an error. Distinct from
    // `rate_limited` / malformed requests (logged as `caller_error`
    // but still return null → native) and from HMAC auth drift
    // (`auth_fail` / `signature_mismatch` — logged as
    // `auth_misconfig` with `fallback_reason = auth_misconfig`;
    // v1.3.33, AKS connector parity).
    //
    // Mirrors the AKS connector's `ClientException::TENANT_UNAVAILABLE_ERROR_CODES`
    // contract from v1.3 so the cross-platform observability picture
    // (which lifecycle state caused the fallback?) stays uniform on
    // both connectors.
    public const TENANT_UNAVAILABLE_ERROR_CODES = [
        'tenant_paused',
        'tenant_not_found',
        'tenant_unknown',
        'tenant_suspended',
        'tenant_disabled',
        // Legacy / pre-v1.3 codes the gateway still emits in some
        // paths. Kept here so existing tenants don't lose their
        // graceful-degradation behaviour on upgrade.
        'subscription_cancelled',
        'unknown_tenant',
    ];

    // v1.3.33 — HMAC / pairing drift between the storefront secret
    // and the gateway tenant row. Same degradation story as
    // tenant_paused: return null so the caller falls through to
    // native search; log with `fallback_reason = auth_misconfig`
    // so admin observability can attribute volume correctly.
    //
    // Mirrors AKS connector `ClientException::AUTH_MISCONFIG_ERROR_CODES`.
    public const AUTH_MISCONFIG_ERROR_CODES = [
        'auth_fail',
        'signature_mismatch',
    ];

    private string $url;
    private string $tenantId;
    private string $sharedSecret;
    private string $mode;
    /**
     * v1.0.13 — search/events hot-path timeout (ms). Default 1500ms.
     * Bigger than the historical 250ms because real Cloudflare DNS+TLS
     * to mcp.seekmodo.com routinely runs 200-400ms cold; the previous
     * default was tripping the breaker on perfectly-healthy gateways.
     */
    private int $searchTimeoutMs;
    /**
     * v1.0.13 — index timeout (ms). Default 30000ms. Bulk catalog
     * upserts of 500 docs/req routinely take 5-15s on a cold
     * Typesense collection. The previous shared `timeoutMs` from
     * the search path made the indexer unable to complete on
     * tenants with thousands of products — every bulk push
     * returned NULL and the operator had to set HTTP_TIMEOUT_MS=30000
     * out of band. Splitting search and index timeouts removes
     * that footgun.
     */
    private int $indexTimeoutMs;
    private bool $debug;
    private CircuitBreakerStore $breaker;
    /** @var callable|null hook for tests (signature: fn(string $level, string $msg, array $ctx): void) */
    private $logger;
    /** @var string|null outbound index intent (`manual` for ack'd full push) */
    private ?string $indexIntent = null;

    /**
     * Default search timeout (ms) when fromConfiguration() can't read
     * a value from the gateway snapshot or local constants. Mirrors
     * the gateway-side {@see \Numinix\McpGateway\TenantConfig\Store::DEFAULTS}.
     */
    public const DEFAULT_SEARCH_TIMEOUT_MS = 1500;

    /**
     * Default index timeout (ms). Same intent as SEARCH but tuned
     * for bulk upserts of 500 docs/req against Typesense.
     */
    public const DEFAULT_INDEX_TIMEOUT_MS = 30000;

    public function __construct(
        string $url,
        string $tenantId,
        string $sharedSecret,
        string $mode = self::MODE_OFF,
        int $searchTimeoutMs = self::DEFAULT_SEARCH_TIMEOUT_MS,
        bool $debug = false,
        ?CircuitBreakerStore $breaker = null,
        ?callable $logger = null,
        int $indexTimeoutMs = self::DEFAULT_INDEX_TIMEOUT_MS
    ) {
        $this->url = rtrim(trim($url), '/');
        $this->tenantId = trim($tenantId);
        $this->sharedSecret = trim($sharedSecret);
        $this->mode = self::normalizeMode($mode);
        $this->searchTimeoutMs = self::clampSearchTimeout($searchTimeoutMs);
        $this->indexTimeoutMs = self::clampIndexTimeout($indexTimeoutMs);
        $this->debug = $debug;
        $this->breaker = $breaker ?? new ApcuCircuitBreakerStore();
        $this->logger = $logger;
    }

    /**
     * Build a Client from the NUMINIX_SEEKMODO_* configuration constants
     * the plugin's installer drops into TABLE_CONFIGURATION. Returns
     * null when any required value is missing — callers MUST treat null
     * as "stay on the legacy path" rather than erroring out.
     *
     * Remote-first: pulls the latest snapshot from the Seekmodo gateway
     * (5-min APCu-cached) before reading the constants, so an operator
     * flip on admin.seekmodo.com is reflected on the storefront within
     * one cache window. Falls back silently to whatever the constants
     * already hold when the gateway is unreachable.
     */
    public static function fromConfiguration(): ?self
    {
        $cfg = static fn (string $key, string $default = ''): string =>
            defined($key) ? (string)constant($key) : $default;

        $url = trim($cfg('NUMINIX_SEEKMODO_URL'));
        $tenant = trim($cfg('NUMINIX_SEEKMODO_TENANT_ID'));
        $secret = trim($cfg('NUMINIX_SEEKMODO_SHARED_SECRET'));
        if ($url === '' || $tenant === '' || $secret === '') {
            return null;
        }
        // Try a remote pull first. The pulled values are written through
        // to TABLE_CONFIGURATION but the *constants* in the current
        // request were defined at autoloader boot, so we use the pulled
        // row directly when present and fall back to the constants only
        // when the gateway is unavailable.
        $remote = self::tryRemotePull($url, $tenant, $secret);
        $rawMode = $remote['mode']
            ?? strtolower(trim($cfg('NUMINIX_SEEKMODO_MODE', 'off')));
        $rawMode = strtolower(trim((string) $rawMode));
        // Resolve `active` through the procedural FSM helper if it's
        // loaded so $this->mode reflects the effective shadow|enforce.
        // Falls back to literal-mode normalization (which keeps
        // `active` alive as enabled) when the helper isn't available
        // — installer-time, unit tests, or any boot ordering where
        // the function file hasn't been required yet.
        if ($rawMode === self::MODE_ACTIVE && function_exists('numinix_seekmodo_effective_mode')) {
            $mode = self::normalizeMode((string)numinix_seekmodo_effective_mode());
        } else {
            $mode = self::normalizeMode($rawMode);
        }
        // v1.0.13 — split the historical single `timeout_ms` into
        // search and index. Order of preference for each:
        //
        //   1. Gateway snapshot value for the specific bucket
        //      (`search_timeout_ms` / `index_timeout_ms`).
        //   2. Gateway snapshot's legacy `timeout_ms` (so a
        //      stale-schema gateway that hasn't run the v7
        //      migration still applies operator changes).
        //   3. Local constant for the specific bucket
        //      (NUMINIX_SEEKMODO_SEARCH_TIMEOUT_MS /
        //      NUMINIX_SEEKMODO_INDEX_TIMEOUT_MS).
        //   4. Local legacy constant NUMINIX_SEEKMODO_TIMEOUT_MS.
        //   5. Hard-coded default.
        $legacyTimeout = $remote['timeout_ms']
            ?? $cfg('NUMINIX_SEEKMODO_TIMEOUT_MS', '');
        $searchTimeout = (int) (
            $remote['search_timeout_ms']
            ?? $cfg('NUMINIX_SEEKMODO_SEARCH_TIMEOUT_MS', '')
            ?: ($legacyTimeout !== '' ? $legacyTimeout : self::DEFAULT_SEARCH_TIMEOUT_MS)
        );
        $indexTimeout = (int) (
            $remote['index_timeout_ms']
            ?? $cfg('NUMINIX_SEEKMODO_INDEX_TIMEOUT_MS', '')
            ?: self::DEFAULT_INDEX_TIMEOUT_MS
        );
        $debugRaw = $remote['debug']
            ?? $cfg('NUMINIX_SEEKMODO_DEBUG', 'false');
        $debug = is_bool($debugRaw)
            ? $debugRaw
            : strtolower((string) $debugRaw) === 'true';
        return new self(
            $url,
            $tenant,
            $secret,
            $mode,
            $searchTimeout,
            $debug,
            null,
            null,
            $indexTimeout
        );
    }

    /**
     * Pull the gateway snapshot once. Returns the array on success or
     * null when the helper class isn't loaded / gateway is unreachable.
     *
     * Wrapped in a try/catch because RemoteConfig is loaded by the
     * library autoloader; in the rare boot order where it isn't
     * present yet (uninstall path, unit tests) we want fromConfiguration
     * to keep working off the local constants.
     *
     * @return array<string, mixed>|null
     */
    private static function tryRemotePull(string $url, string $tenant, string $secret): ?array
    {
        if (!class_exists(RemoteConfig::class)) {
            return null;
        }
        try {
            $rc = new RemoteConfig($url, $tenant, $secret);
            return $rc->pull();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /**
     * v1.0.13 — search/events hot-path timeout in ms.
     */
    public function searchTimeoutMs(): int
    {
        return $this->searchTimeoutMs;
    }

    /**
     * v1.0.13 — bulk index upsert timeout in ms.
     */
    public function indexTimeoutMs(): int
    {
        return $this->indexTimeoutMs;
    }

    public function isEnabled(): bool
    {
        return $this->mode !== self::MODE_OFF
            && $this->url !== ''
            && $this->tenantId !== ''
            && $this->sharedSecret !== ''
            && function_exists('curl_init');
    }

    public function isCircuitOpen(): bool
    {
        $state = $this->breaker->state(self::CIRCUIT_KEY, self::CIRCUIT_COOLDOWN_S);
        return !empty($state['open']);
    }

    /**
     * POST /v1/search.
     *
     * @param array<string,mixed> $params Storefront search params:
     *   keyword, query_by, filter_by, sort_by, page, per_page, etc.
     *   See gateway src/Tools/SearchTool.php for the full schema.
     * @return array<string,mixed>|null Decoded response on success;
     *   null on auth failure, transport error, circuit-open, or any
     *   non-2xx status. Caller MUST treat null as "fall back to native
     *   Typesense / LIKE search" per §5.2 graceful-degradation.
     */
    public function search(array $params): ?array
    {
        return $this->call('/v1/search', $params, $this->searchTimeoutMs);
    }

    /**
     * Stamp the index intent sent on subsequent POST /v1/index calls.
     * Pass `manual` after operator `--ack-quota` consent; pass null to
     * clear. Only `manual` is honoured today — unknown values are
     * ignored so a typo can't accidentally bypass quota gates.
     */
    public function setIndexIntent(?string $intent): void
    {
        if ($intent === null || $intent === '') {
            $this->indexIntent = null;
            return;
        }
        $normalized = strtolower(trim($intent));
        $this->indexIntent = $normalized === 'manual' ? 'manual' : null;
    }

    public function indexIntent(): ?string
    {
        return $this->indexIntent;
    }

    /**
     * POST /v1/index.
     *
     * Caller is expected to pre-chunk to <= NUMINIX_SEEKMODO_INDEX_BATCH
     * (default 500). The gateway accepts up to 1000/req but we leave
     * headroom; numinix_seekmodo_index_chunked() in the boot file does
     * the chunking automatically.
     *
     * @param array<int,array<string,mixed>> $documents
     * @param string|null $intent Optional per-call intent override
     *   (`manual`). When omitted, uses {@see setIndexIntent()}.
     * @return array<string,mixed>|null
     */
    public function index(array $documents, ?string $intent = null): ?array
    {
        return $this->call(
            '/v1/index',
            ['documents' => $documents],
            $this->indexTimeoutMs,
            $intent
        );
    }

    /**
     * POST /v1/events.
     *
     * @param array<string,mixed> $event {kind, keyword, products_id, position, ...}
     * @return array<string,mixed>|null
     */
    public function events(array $event): ?array
    {
        return $this->call('/v1/events', $event, $this->searchTimeoutMs);
    }

    /**
     * POST /v1/suggest — Sprint 3 PR 6 typeahead opt-in.
     *
     * Routes to the gateway's `SuggestTool` (registered in
     * services/mcp-gateway/src/Tools/ToolCatalog.php as Sprint 3 PR 3).
     * Returns three result blocks in one round-trip:
     *
     *   - `keywords[]`   — top prior shopper searches matching the
     *                      prefix (mined from numinix_telemetry_search_events
     *                      over the last 90 days, bot-filtered).
     *   - `products[]`   — Typesense prefix-matched docs limited to a
     *                      lean field set (5-8 rows, no facets, no
     *                      price-range pivoting). Doc shape matches
     *                      the tenant's `name` / `model` / `price`
     *                      schema; the storefront helper renders
     *                      defensively.
     *   - `categories[]` — top matching breadcrumbs from a Typesense
     *                      facet query against the same prefix.
     *
     * Bot-gating mirrors `/v1/search`: scraper keystroke traffic
     * short-circuits to an empty envelope BEFORE any Typesense
     * round-trip, and the meta.surface_id='suggest' marker keeps
     * the dispatcher's `searches_suggest` metering bucket
     * separate from full-search counts.
     *
     * Uses the search timeout because typeahead is on the hot path
     * — anything slower than the search budget is already too slow
     * for a useful suggestion. The breaker is the same one
     * `/v1/search` charges against, so a sustained typeahead
     * brown-out also opens the breaker on full search (intentional
     * — typeahead and search share the gateway and a brown-out on
     * one tends to mean a brown-out on the other).
     *
     * Returns null on any failure (auth / transport / circuit-open
     * / non-2xx). Callers MUST treat null as "fall back to the
     * existing storefront typeahead path" per §5.2 graceful-
     * degradation; the typeahead UX never breaks just because the
     * gateway is unreachable.
     *
     * @param array<string,mixed> $params {q, limit, session_id, ua, ip, ...}
     * @return array<string,mixed>|null
     */
    public function suggest(array $params): ?array
    {
        return $this->call('/v1/suggest', $params, $this->searchTimeoutMs);
    }

    /**
     * POST /v1/recommend.{algorithm} — Sprint 4 PR 6 recommendations.
     *
     * Routes to one of the four anchor-anchored / non-anchored
     * recommendation tools registered in Sprint 4 PR 3:
     *
     *   - recommend.related      (anchor-anchored, co-view signal)
     *   - recommend.also_bought  (anchor-anchored, co-purchase)
     *   - recommend.also_viewed  (anchor-anchored, same-session)
     *   - recommend.trending     (no anchor, top-K in last 7 days)
     *
     * `bundle.suggest` lives next door under POST /v1/bundle.suggest.
     *
     * The shape mirrors `suggest()`: every recommend.* tool returns
     * `{ recommendations: [{ doc_id, score, source }], meta: {...} }`
     * with `meta.surface_id` set to the tool name so the gateway can
     * meter the call against the `searches_recommend` display
     * bucket (Sprint 4 PR 3 SurfaceWeights wiring).
     *
     * Same null-on-failure contract as `suggest()`: caller MUST
     * treat null as "render nothing in that placement". A 402
     * `feature_not_in_plan` (Hobby tenant calling recommend.* on a
     * non-entitled plan) also surfaces as null so the placeholder
     * div renders empty rather than showing a 500.
     *
     * `$algorithm` is validated against an allowlist to keep us from
     * accidentally hitting an arbitrary `/v1/recommend.*` path if
     * the caller passes an unexpected value.
     *
     * @param string $algorithm One of 'related' | 'also_bought' |
     *                          'also_viewed' | 'trending' | 'bundle.suggest'.
     * @param array<string,mixed> $params Tool-specific params (e.g.
     *                          {anchor_doc_id, limit, session_id,
     *                          ua, ip}).
     * @return array<string,mixed>|null
     */
    public function recommend(string $algorithm, array $params): ?array
    {
        static $allowed = [
            'related'        => '/v1/recommend.related',
            'also_bought'    => '/v1/recommend.also_bought',
            'also_viewed'    => '/v1/recommend.also_viewed',
            'trending'       => '/v1/recommend.trending',
            'bundle.suggest' => '/v1/bundle.suggest',
        ];
        if (!isset($allowed[$algorithm])) {
            $this->log('warn', 'recommend_bad_algorithm', ['algo' => $algorithm]);
            return null;
        }
        return $this->call($allowed[$algorithm], $params, $this->searchTimeoutMs);
    }

    /**
     * Generic tenant-scoped tool invocation. Use sparingly — search/
     * events/recommend/suggest are the hot-path entry points and
     * each carries its own metering + telemetry contract. This
     * method is the escape hatch for low-volume admin-style tools
     * the connector occasionally needs to fire (e.g.
     * `tenant.shopper.forget` from the "Forget me" deeplink in
     * v1.0.16).
     *
     * The call rides the standard HMAC envelope (NOT the operator-
     * scoped admin key) so it's authorized as the tenant the
     * connector is paired to — never as a platform operator. The
     * tool's `isAvailable($vertical, $plan)` check is what gates
     * which tools the connector can reach this way; mutating
     * admin-only tools the operator hasn't entitled the tenant for
     * just 501 here.
     *
     * Tool-name validation is intentionally narrow — only allow
     * dot-separated identifiers (`tenant.shopper.forget`,
     * `ltr.status`, etc.). Anything else short-circuits with a
     * warning log instead of round-tripping to the gateway.
     *
     * @param string $toolName  Catalog tool name (e.g. `tenant.shopper.forget`).
     * @param array<string,mixed> $args  Tool input arguments.
     * @return array<string,mixed>|null  Decoded gateway response,
     *   or null on auth/transport/circuit failure (caller decides
     *   how to surface that).
     */
    public function callTool(string $toolName, array $args): ?array
    {
        if (preg_match('/^[A-Za-z0-9_.\\-]{2,128}$/', $toolName) !== 1) {
            $this->log('warn', 'call_tool_bad_name', ['tool' => $toolName]);
            return null;
        }
        return $this->call('/v1/' . $toolName, $args, $this->searchTimeoutMs);
    }

    /**
     * v1.0.22 (SM-606 fixup): POST /v1/tenants/token — mint a
     * short-lived browser-scoped JWT for the storefront's SDK.
     *
     * Distinct from {@see callTool()} because the gateway exposes
     * this as a non-tool admin endpoint (slash-separated path, not
     * a dot-separated tool catalog name). `callTool('tenants/token')`
     * trips the dot-only regex on line 488 and short-circuits to
     * null with a `call_tool_bad_name` warning — which is what
     * v1.0.21 (and the first two v1.0.22 fixup commits) silently
     * did, manifesting as `{"error":"mint_failed"}` 503s from the
     * `numinix_seekmodo_suggest.php?action=browser-token` shim and
     * empty `<meta name="seekmodo:token">` from the head observer.
     *
     * The wire shape mirrors the gateway's own response (Sprint 7
     * PR 2): `{token, expires_at, issued_at, scope, session_id,
     * token_type}`. Callers typically only consume `token` +
     * `expires_at`; the rest is included so the WP connector's
     * `Frontend\TypeaheadUI` snapshot can pass-through the same
     * envelope to the SDK without a translation layer.
     *
     * Failure semantics are identical to the rest of the Client
     * surface: every error path returns null and the caller MUST
     * fall back gracefully (the storefront falls back to the
     * legacy search UX; the shim returns 503 with a structured
     * JSON error body the SDK can introspect).
     *
     * @param int $ttlSeconds Requested TTL (floored at 30, ceil 900
     *                        by the gateway). Defaults to 300 to
     *                        match the gateway's own default and
     *                        the WP connector's BrowserToken::MINT_TTL_S.
     * @param string $sid     Optional storefront-side session id so
     *                        downstream bot-check correlates the JWT
     *                        mint with the storefront's own
     *                        analytics. Empty string means "let the
     *                        gateway synthesise one".
     * @return array<string,mixed>|null
     */
    public function mintBrowserToken(int $ttlSeconds = 300, string $sid = ''): ?array
    {
        $body = ['ttl_seconds' => max(30, min(900, $ttlSeconds))];
        if ($sid !== '') {
            $body['sid'] = substr($sid, 0, 64);
        }
        return $this->call('/v1/tenants/token', $body, $this->searchTimeoutMs);
    }

    private function call(string $path, array $body, int $timeoutMs, ?string $indexIntentOverride = null): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }
        if ($this->isCircuitOpen()) {
            $this->log('debug', 'circuit_open_skip', ['path' => $path]);
            return null;
        }

        $raw = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($raw === false) {
            return null;
        }
        $url = $this->url . $path;
        $sig = hash_hmac('sha256', $raw, $this->sharedSecret);

        $headers = [
            'Content-Type: application/json',
            self::HEADER_TENANT . ': ' . $this->tenantId,
            self::HEADER_SIGNATURE . ': ' . $sig,
            self::HEADER_TIMESTAMP . ': ' . time(),
            'Accept: application/json',
        ];
        $hostHeader = self::storefrontHost();
        if ($hostHeader !== '') {
            $headers[] = self::HEADER_STOREFRONT_HOST . ': ' . $hostHeader;
        }
        if ($path === '/v1/index') {
            $intent = $indexIntentOverride ?? $this->indexIntent;
            if (is_string($intent) && $intent === 'manual') {
                $headers[] = self::HEADER_INDEX_INTENT . ': manual';
            }
        }

        $ch = curl_init($url);
        if ($ch === false) {
            $this->breaker->record(self::CIRCUIT_KEY, false, self::CIRCUIT_THRESHOLD, self::CIRCUIT_COOLDOWN_S);
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $raw,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            // Connect (DNS+TCP+TLS) needs more headroom than a tight 200ms
            // when the resolver path is slow. Cap at 750ms but never less
            // than half the overall budget — TLS handshake to Cloudflare
            // is typically 60-120ms, so this still leaves room for the
            // POST round-trip.
            CURLOPT_CONNECTTIMEOUT_MS => max(250, min(750, intdiv($timeoutMs, 2))),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            // Force IPv4. Some hosts (Redline web03) have a flaky IPv6 path
            // to Cloudflare which causes connect timeouts that trip the
            // breaker even though IPv4 is healthy. v4-only is safe; the
            // gateway is dual-stacked.
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);
        $startMs = (int)(microtime(true) * 1000);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errNum = curl_errno($ch);
        $errStr = curl_error($ch);
        curl_close($ch);
        $elapsedMs = (int)(microtime(true) * 1000) - $startMs;

        // Hard transport failures and 5xx count toward the breaker.
        // 4xx (except 429) is a caller error — bad payload, missing
        // tenant — we don't degrade the breaker for our own typo.
        if ($errNum !== 0 || $status === 0 || $status >= 500 || $status === 429) {
            $this->breaker->record(self::CIRCUIT_KEY, false, self::CIRCUIT_THRESHOLD, self::CIRCUIT_COOLDOWN_S);
            $this->log('warn', 'transport_failure', [
                'path' => $path,
                'status' => $status,
                'curl_errno' => $errNum,
                'curl_error' => $errStr,
                'elapsed_ms' => $elapsedMs,
            ]);
            return null;
        }
        // HTTP 402 over_quota is a *known* failure mode per §1.8.2 — the
        // gateway is healthy, the tenant has used their plan ceiling.
        // Treat it like 5xx for the caller (return null → fall back to
        // native search) but DON'T credit the breaker either way: the
        // gateway is fine, we just stop talking to it for this bucket
        // until the period rolls or PayPal sends SUBSCRIPTION.UPDATED.
        // Stash the flag so the admin status page can render a banner.
        if ($status === 402) {
            self::markCloudSuggestDenied(is_string($resp) ? $resp : '');
            $this->log('warn', 'over_quota_402', [
                'path' => $path,
                'elapsed_ms' => $elapsedMs,
                'body_preview' => is_string($resp) ? substr($resp, 0, 200) : '',
            ]);
            return null;
        }
        if ($status >= 400) {
            // v1.0.17 / v1.3.33 — peek at the body BEFORE logging so
            // the structured log event distinguishes tenant lifecycle
            // (`tenant_unavailable`), HMAC/pairing drift
            // (`auth_misconfig`), and genuine caller bugs
            // (`rate_limited`, malformed request → `caller_error`).
            // All three still return null and let the caller fall
            // through to native; only the log line + fallback_reason
            // differ so admin observability can attribute volume.
            $body = is_string($resp) ? $resp : null;
            $errorCode = self::classifyTenantUnavailable($status, $body);
            $authMisconfigCode = $errorCode === null
                ? self::classifyAuthMisconfig($status, $body)
                : null;
            $tenantUnavailable = $errorCode !== null;
            $authMisconfig = $authMisconfigCode !== null;
            if ($tenantUnavailable) {
                $logEvent = 'tenant_unavailable';
                $fallbackReason = 'tenant_unavailable';
            } elseif ($authMisconfig) {
                $logEvent = 'auth_misconfig';
                $fallbackReason = 'auth_misconfig';
            } else {
                $logEvent = 'caller_error';
                $fallbackReason = null;
            }
            $this->log('warn', $logEvent, [
                'path' => $path,
                'status' => $status,
                'elapsed_ms' => $elapsedMs,
                'body_preview' => is_string($resp) ? substr($resp, 0, 200) : '',
                'error_code' => $errorCode ?? $authMisconfigCode,
                'fallback_reason' => $fallbackReason,
            ]);
            // Tenant-lifecycle 4xx (paused / suspended / disabled /
            // not_found / unknown / subscription_cancelled) is a
            // clean "this tenant is locked down" signal. Stash a flag
            // so the admin status page can render an "Account paused"
            // notice without the storefront having to surface the
            // error path. Active responses clear the flag in the
            // success branch.
            //
            // v1.0.17: codes are now sourced from the shared
            // self::TENANT_UNAVAILABLE_ERROR_CODES list (parity with
            // AKS connector v1.3) and the body peek covers BOTH 403
            // and 404 (the gateway uses 404 for tenant_not_found /
            // tenant_unknown, 403 for the rest).
            if ($tenantUnavailable) {
                $this->markSubscriptionState(self::SUB_STATE_CANCELLED);
            }
            // Non-degrading failure — record success so a string of
            // 4xx auth errors during configuration doesn't accidentally
            // disarm the breaker for legitimate traffic. The caller
            // gets null and falls back as usual.
            return null;
        }
        if (!is_string($resp) || $resp === '') {
            $this->breaker->record(self::CIRCUIT_KEY, false, self::CIRCUIT_THRESHOLD, self::CIRCUIT_COOLDOWN_S);
            return null;
        }
        $decoded = json_decode($resp, true);
        if (!is_array($decoded)) {
            $this->breaker->record(self::CIRCUIT_KEY, false, self::CIRCUIT_THRESHOLD, self::CIRCUIT_COOLDOWN_S);
            return null;
        }

        // Treat absurdly slow successes (over 2x the configured budget)
        // as failures for breaker purposes — the hot path budget
        // contract beats the HTTP status code.
        if ($elapsedMs > $timeoutMs * 2) {
            $this->breaker->record(self::CIRCUIT_KEY, false, self::CIRCUIT_THRESHOLD, self::CIRCUIT_COOLDOWN_S);
        } else {
            $this->breaker->record(self::CIRCUIT_KEY, true, self::CIRCUIT_THRESHOLD, self::CIRCUIT_COOLDOWN_S);
        }
        // Any 2xx clears the cancellation flag — if the gateway is
        // answering for our tenant, the subscription is back.
        // Metered search/suggest successes also clear the over-quota /
        // trial_expired sticky so resubscribe recovers without waiting
        // for the TTL. Index/events successes do NOT clear it (a
        // catalog push can succeed while the search bucket is still
        // capped).
        $this->markSubscriptionState(self::SUB_STATE_ACTIVE);
        if (self::pathClearsCloudSuggestDenial($path)) {
            self::clearCloudSuggestDenial();
        }
        // Quota headers, when present, give the admin status page
        // accurate progress bars without an extra round-trip. Cheap
        // enough to always extract — but never let them fail the call.
        // (Header parse is a future hook; today the gateway echoes
        //  X-Seekmodo-Quota-* on every successful call.)

        $this->log('debug', 'ok', [
            'path' => $path,
            'status' => $status,
            'elapsed_ms' => $elapsedMs,
        ]);

        return $decoded;
    }

    private function log(string $level, string $msg, array $ctx): void
    {
        if ($this->logger !== null) {
            ($this->logger)($level, $msg, $ctx);
            return;
        }
        if (!$this->debug && $level === 'debug') {
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
            'mode' => $this->mode,
            'ctx' => $ctx,
        ], JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }
        if (function_exists('numinix_seekmodo_log_append')) {
            numinix_seekmodo_log_append($line);
        } else {
            @file_put_contents($logDir . '/numinix_seekmodo.log', $line . PHP_EOL, FILE_APPEND);
        }
    }

    private static function normalizeMode(string $mode): string
    {
        $m = strtolower(trim($mode));
        if ($m === self::MODE_SHADOW || $m === self::MODE_ENFORCE) {
            return $m;
        }
        // `active` reaches here only when fromConfiguration() couldn't
        // resolve it through the FSM helper (installer / unit tests /
        // boot order). Treat it as enabled — the search-lib will call
        // numinix_seekmodo_effective_mode() at request time and pick
        // the real shadow|enforce behavior. Without this branch the
        // SDK would normalize `active` to MODE_OFF, isEnabled() would
        // return false, and the FSM would observe perpetual failure
        // and never promote.
        if ($m === self::MODE_ACTIVE) {
            return self::MODE_ACTIVE;
        }
        return self::MODE_OFF;
    }

    /**
     * Subscription state read for the admin status page. Returns
     * 'active' | 'cancelled' | 'over_quota' | 'unknown' (the last when
     * no recent gateway round-trip has happened).
     *
     * Cancelled wins over over_quota — once the subscription is gone
     * the merchant has bigger problems to solve than their search cap.
     */
    public static function readSubscriptionState(): string
    {
        $cancelled = self::cacheGet(self::SUBSCRIPTION_KEY);
        if (is_string($cancelled) && $cancelled === self::SUB_STATE_CANCELLED) {
            return self::SUB_STATE_CANCELLED;
        }
        // No cancellation; check the quota / trial_expired sticky.
        $quota = self::cacheGet(self::OVER_QUOTA_KEY);
        if (is_string($quota) && $quota !== '') {
            return self::SUB_STATE_OVER_QUOTA;
        }
        if (is_string($cancelled) && $cancelled === self::SUB_STATE_ACTIVE) {
            return self::SUB_STATE_ACTIVE;
        }
        return 'unknown';
    }

    /**
     * True when storefront suggest / typeahead should stay on the
     * same-origin Enhanced Native path and skip browser→gateway
     * `/v1/suggest` calls. Sticky after HTTP 402 (over_quota /
     * trial_expired) or cancelled tenant; cleared on a successful
     * metered search/suggest response or when the TTL expires.
     */
    public static function shouldPreferLocalSuggest(): bool
    {
        $state = self::readSubscriptionState();

        if ($state === self::SUB_STATE_OVER_QUOTA || $state === self::SUB_STATE_CANCELLED) {
            self::maybeRecheckUnpaidState();
            // A successful recheck clears the sticky above — re-read
            // so a tenant that just came back doesn't lose one extra
            // request to Enhanced Native before the flag catches up.
            $state = self::readSubscriptionState();
        }

        return $state === self::SUB_STATE_OVER_QUOTA
            || $state === self::SUB_STATE_CANCELLED;
    }

    /**
     * Daily unpaid-recovery plan (2026-08). `shouldPreferLocalSuggest()`
     * is the single choke point every suggest/typeahead surface calls
     * BEFORE `Client::fromConfiguration()` / `RemoteConfig::pull()` ever
     * run (see `numinix_seekmodo_typeahead_lib.php`), so once the
     * over-quota / cancelled sticky above is stamped, nothing on the
     * suggest path talks to the gateway again to notice a resubscribe
     * or an operator trial extension — the sticky is otherwise
     * self-perpetuating for its full TTL. Full search doesn't have
     * this gap: it always calls `Client::search()` regardless of
     * sticky, so the normal 2xx success path (above, `call()`) clears
     * it on the very next attempt.
     *
     * Rate-limited to once per `UNPAID_RECHECK_INTERVAL_S` so a stuck
     * tenant self-heals within about a day without spending a metered
     * search/suggest call and without the merchant needing to find the
     * admin "Refresh snapshot" button. Mirrors the same
     * mark-active-and-clear-denial pair the 2xx success path performs,
     * just triggered from a background snapshot pull instead of a
     * metered response.
     */
    private static function maybeRecheckUnpaidState(): void
    {
        $last = self::cacheGet(self::UNPAID_RECHECK_KEY);
        if (is_int($last) && (time() - $last) < self::UNPAID_RECHECK_INTERVAL_S) {
            return;
        }
        // Stamp the gate before doing any network work — even when
        // RemoteConfig can't be built or the pull fails outright, we
        // don't want to retry on every single storefront request
        // until the interval elapses again.
        self::cacheSet(self::UNPAID_RECHECK_KEY, time(), self::UNPAID_RECHECK_INTERVAL_S);

        if (!class_exists(RemoteConfig::class)) {
            return;
        }
        $rc = RemoteConfig::fromConfiguration();
        if ($rc === null) {
            return;
        }
        // Bypass the 5-min APCu snapshot cache — we need billing state
        // as of right now, not whatever was cached before the sticky
        // was stamped (possibly hours or days ago).
        $rc->invalidate();
        self::applyBillingSnapshot($rc->pull());
    }

    /**
     * Shared write-through for both recovery paths: the background
     * daily recheck above AND the admin "Refresh snapshot" button
     * (`numinix_seekmodo_connect.php`'s `refresh` action). Both pull a
     * fresh `tenant.snapshot`; this is the one place that turns
     * `billing.status === 'active'` into an actual sticky clear so a
     * merchant who clicks Refresh snapshot right after resubscribing
     * gets the immediate recovery the billing UI promises, not just an
     * updated `mode`/FSM row.
     *
     * @param array<string, mixed>|null $row
     * @return bool True when an active billing status cleared the sticky.
     */
    public static function applyBillingSnapshot(?array $row): bool
    {
        if (!is_array($row) || !isset($row['billing']) || !is_array($row['billing'])) {
            return false;
        }
        $billingStatus = (string) ($row['billing']['status'] ?? '');
        if ($billingStatus !== self::SUB_STATE_ACTIVE) {
            return false;
        }
        self::cacheSet(self::SUBSCRIPTION_KEY, self::SUB_STATE_ACTIVE, self::SUBSCRIPTION_TTL_S);
        self::clearCloudSuggestDenial();

        return true;
    }

    /**
     * Clear the unpaid / over-quota sticky so cloud suggest resumes
     * after a successful metered call (resubscribe / new period).
     */
    public static function clearCloudSuggestDenial(): void
    {
        self::cacheDelete(self::OVER_QUOTA_KEY);
        // Episode-scoped dismiss: future unpaid/quota lapses must notify
        // again after cloud recovers (successful metered call).
        if (class_exists(BillingAdminNotice::class)) {
            BillingAdminNotice::clearAllDismissals();
        } elseif (isset($_SESSION['admin']) && is_array($_SESSION['admin'])) {
            unset($_SESSION['admin']['seekmodo_billing_dismissed']);
        }
    }

    /**
     * Read the most recent 402 envelope body so the admin banner can
     * surface "Over <bucket> quota — resets <date>" without re-issuing
     * a request. Returns the decoded JSON or null when the flag isn't
     * set or has decayed.
     *
     * @return array<string,mixed>|null
     */
    public static function readOverQuotaEnvelope(): ?array
    {
        $val = self::cacheGet(self::OVER_QUOTA_KEY);
        if (!is_string($val) || $val === '') {
            return null;
        }
        $decoded = json_decode($val, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function markSubscriptionState(string $state): void
    {
        self::cacheSet(self::SUBSCRIPTION_KEY, $state, self::SUBSCRIPTION_TTL_S);
    }

    /**
     * Stamp the over-quota / trial_expired sticky with the gateway's
     * 402 envelope body. Shape is §1.8.2 (`code/quota/limit/used/
     * resets_at`) or trial_expired — stored verbatim so the admin UI
     * doesn't have to reformat it. Also mirrors into $_SESSION so
     * hosts without APCu still flip suggest to Enhanced Native for
     * the rest of the shopper session.
     *
     * Public so the browser path can stamp the same sticky after a
     * gateway 402 without waiting for the next PHP typeahead hop.
     */
    public static function markCloudSuggestDenied(string $rawBody = ''): void
    {
        $payload = $rawBody !== '' ? $rawBody : '{"code":"over_quota"}';
        $decoded = json_decode($rawBody, true);
        $ttl = self::OVER_QUOTA_TTL_S;
        if (is_array($decoded) && !empty($decoded['resets_at'])) {
            $resetTs = strtotime((string) $decoded['resets_at']);
            if ($resetTs !== false && $resetTs > time()) {
                $ttl = max(60, min(self::OVER_QUOTA_TTL_S, $resetTs - time()));
            }
        }
        self::cacheSet(self::OVER_QUOTA_KEY, $payload, $ttl);
    }

    /** @return bool */
    private static function pathClearsCloudSuggestDenial(string $path): bool
    {
        $p = strtolower($path);
        return strpos($p, '/v1/suggest') !== false
            || strpos($p, '/v1/search') !== false
            || strpos($p, '/v1/typeahead') !== false;
    }

    /** @return mixed|null */
    private static function cacheGet(string $key)
    {
        if (function_exists('apcu_fetch')) {
            $ok = false;
            $val = @\apcu_fetch($key, $ok);
            if ($ok) {
                return $val;
            }
        }
        if (session_status() === PHP_SESSION_ACTIVE
            && isset($_SESSION[$key])
            && is_array($_SESSION[$key])
        ) {
            $row = $_SESSION[$key];
            $exp = isset($row['exp']) ? (int) $row['exp'] : 0;
            if ($exp > time() && array_key_exists('val', $row)) {
                return $row['val'];
            }
            unset($_SESSION[$key]);
        }

        return null;
    }

    /** @param mixed $value */
    private static function cacheSet(string $key, $value, int $ttl): void
    {
        if (function_exists('apcu_store')) {
            @\apcu_store($key, $value, $ttl);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[$key] = [
                'val' => $value,
                'exp' => time() + max(1, $ttl),
            ];
        }
    }

    private static function cacheDelete(string $key): void
    {
        if (function_exists('apcu_delete')) {
            @\apcu_delete($key);
        }
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }

    /**
     * v1.0.13 — clamp the search/events hot-path timeout. Floor of
     * 80ms (defense against an accidentally-zeroed config) and a
     * ceiling of 5000ms (a search that takes >5s should already
     * have fallen back to native LIKE; we don't want a slow
     * gateway holding a php-fpm worker for longer than that).
     */
    private static function clampSearchTimeout(int $timeoutMs): int
    {
        if ($timeoutMs < 80) {
            return 80;
        }
        if ($timeoutMs > 5000) {
            return 5000;
        }
        return $timeoutMs;
    }

    /**
     * v1.0.13 — clamp the index timeout. Floor of 1000ms (anything
     * smaller is hostile to bulk catalog upserts) and a ceiling
     * of 120000ms (2 min — Cloudflare's edge timeout is 100s, so
     * anything beyond that is wasted budget that can't actually
     * land at the gateway).
     */
    private static function clampIndexTimeout(int $timeoutMs): int
    {
        if ($timeoutMs < 1000) {
            return 1000;
        }
        if ($timeoutMs > 120000) {
            return 120000;
        }
        return $timeoutMs;
    }

    /**
     * Back-compat alias kept for any out-of-tree callers (e.g.
     * test fixtures pinned to v1.0.x). Resolves to the search
     * timeout clamp; index callers should use the new
     * {@see self::clampIndexTimeout} directly.
     *
     * @deprecated since v1.0.13 — use clampSearchTimeout or
     *             clampIndexTimeout depending on bucket.
     */
    private static function clampTimeout(int $timeoutMs): int
    {
        return self::clampSearchTimeout($timeoutMs);
    }

    /**
     * Sprint 12 — best-effort source of the current storefront host
     * for the X-Seekmodo-Storefront-Host outbound header. Sourced from:
     *
     *   1. $_SERVER['HTTP_HOST']           (storefront request context)
     *   2. parse_url(HTTPS_CATALOG_SERVER) (cron / CLI — when defined)
     *   3. parse_url(HTTP_CATALOG_SERVER)
     *   4. parse_url(HTTPS_SERVER)         (Zen Cart configure.php default)
     *   5. parse_url(HTTP_SERVER)
     *   6. ''                              (skip the header)
     *
     * Many storefronts (e.g. Redline) only define HTTPS_SERVER /
     * HTTP_SERVER and leave the *_CATALOG_SERVER constants unset.
     * Without steps 4–5, CLI indexers see an empty host, fail
     * `numinix_seekmodo_can_index()`, and leave new products out of
     * Typesense forever.
     *
     * Port is stripped, host lowercased. Anything that doesn't look
     * like a valid hostname (IP literal, leading dot, empty after
     * trim) returns ''. The gateway re-canonicalizes via
     * Store::canonicalizeHost so an aggressive sanitizer here would
     * just create false negatives -- we keep it simple.
     */
    public static function storefrontHost(): string
    {
        $raw = '';
        if (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])) {
            $raw = $_SERVER['HTTP_HOST'];
        } else {
            foreach ([
                'HTTPS_CATALOG_SERVER',
                'HTTP_CATALOG_SERVER',
                'HTTPS_SERVER',
                'HTTP_SERVER',
            ] as $const) {
                if (!defined($const)) {
                    continue;
                }
                $val = (string) constant($const);
                if ($val === '') {
                    continue;
                }
                $parsed = parse_url($val, PHP_URL_HOST);
                if (is_string($parsed) && $parsed !== '') {
                    $raw = $parsed;
                    break;
                }
            }
        }
        $raw = strtolower(trim($raw));
        if ($raw === '') {
            return '';
        }
        // Strip port (the gateway tracks scheme separately + uses the
        // default port for the scheme).
        if (str_contains($raw, ':')) {
            $raw = (string) strstr($raw, ':', true);
        }
        return rtrim($raw, '.');
    }

    /**
     * v1.0.17 — peek at a 4xx response body and return the matched
     * tenant-lifecycle error code when the gateway's `error` field
     * indicates the tenant itself is unavailable (paused, suspended,
     * disabled, unknown, not_found). Returns `null` for everything
     * else, including:
     *
     *   - non-403/404 statuses (we only check the two the gateway
     *     uses for tenant-lifecycle responses);
     *   - empty bodies;
     *   - oversized bodies (>4 KB — defensive cap so a malformed
     *     gateway response can never DoS the storefront's request
     *     budget on `json_decode`);
     *   - non-JSON or non-array bodies;
     *   - JSON bodies whose `error` field is missing, blank, or
     *     not in `TENANT_UNAVAILABLE_ERROR_CODES`.
     *
     * Why parse the body at all: the gateway uses overlapping HTTP
     * statuses for very different outcomes. Without the body peek we
     * can't tell them apart at classification time:
     *
     *   - `tenant_paused` / lifecycle → `tenant_unavailable`
     *     (fallback to native)
     *   - `auth_fail` / `signature_mismatch` → `auth_misconfig`
     *     (fallback to native — pairing drift must not blank the
     *     SERP; see `classifyAuthMisconfig()`)
     *   - `rate_limited` / malformed request → `caller_error`
     *     (still returns null; logged for operator triage)
     *
     * Mirrors the AKS connector's `Client::classifyByErrorCode()`
     * from v1.3 (which lives on a Laravel/Guzzle stack rather than
     * curl). Naming is different to match the Zen Cart connector's
     * convention; behaviour is identical.
     */
    public static function classifyTenantUnavailable(int $status, ?string $body): ?string
    {
        if ($status !== 403 && $status !== 404) {
            return null;
        }
        if (!is_string($body) || $body === '' || strlen($body) > 4096) {
            return null;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return null;
        }
        $err = $decoded['error'] ?? null;
        if (!is_string($err) || $err === '') {
            return null;
        }
        $err = strtolower($err);
        if (in_array($err, self::TENANT_UNAVAILABLE_ERROR_CODES, true)) {
            return $err;
        }
        return null;
    }

    /**
     * Peek at a 4xx response body and return the gateway `error` or
     * `reason` code when it identifies HMAC / pairing misconfiguration
     * (`auth_fail`, `signature_mismatch`). Returns `null` for
     * everything else.
     *
     * Gateway responses currently mapped here:
     *   HTTP 401 {"error":"auth_fail","reason":"signature_mismatch"}
     *   HTTP 403 {"error":"signature_mismatch"}   (legacy shape)
     *   HTTP 401/403 {"error":"auth_fail", ...}
     *
     * Behaviour matches AKS connector `Client::classifyByErrorCode()`
     * for `KIND_AUTH_MISCONFIG`: return null from `call()` so the
     * storefront falls back to native search, but log with
     * `fallback_reason = auth_misconfig` so operators can distinguish
     * pairing drift from a bare `caller_error`.
     */
    public static function classifyAuthMisconfig(int $status, ?string $body): ?string
    {
        if ($status !== 401 && $status !== 403) {
            return null;
        }
        if (!is_string($body) || $body === '' || strlen($body) > 4096) {
            return null;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return null;
        }
        $error = $decoded['error'] ?? null;
        if (is_string($error) && $error !== '') {
            $error = strtolower($error);
            if (in_array($error, self::AUTH_MISCONFIG_ERROR_CODES, true)) {
                return $error;
            }
        }
        $reason = $decoded['reason'] ?? null;
        if (is_string($reason) && $reason !== '') {
            $reason = strtolower($reason);
            if (in_array($reason, self::AUTH_MISCONFIG_ERROR_CODES, true)) {
                return $reason;
            }
        }
        return null;
    }
}
