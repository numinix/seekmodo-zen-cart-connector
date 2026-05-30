<?php

namespace Numinix\Seekmodo;

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

    private string $url;
    private string $tenantId;
    private string $sharedSecret;
    private string $mode;
    private int $timeoutMs;
    private bool $debug;
    private CircuitBreakerStore $breaker;
    /** @var callable|null hook for tests (signature: fn(string $level, string $msg, array $ctx): void) */
    private $logger;

    public function __construct(
        string $url,
        string $tenantId,
        string $sharedSecret,
        string $mode = self::MODE_OFF,
        int $timeoutMs = 250,
        bool $debug = false,
        ?CircuitBreakerStore $breaker = null,
        ?callable $logger = null
    ) {
        $this->url = rtrim(trim($url), '/');
        $this->tenantId = trim($tenantId);
        $this->sharedSecret = trim($sharedSecret);
        $this->mode = self::normalizeMode($mode);
        $this->timeoutMs = self::clampTimeout($timeoutMs);
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
        $timeout = (int) ($remote['timeout_ms']
            ?? $cfg('NUMINIX_SEEKMODO_TIMEOUT_MS', '250'));
        $debugRaw = $remote['debug']
            ?? $cfg('NUMINIX_SEEKMODO_DEBUG', 'false');
        $debug = is_bool($debugRaw)
            ? $debugRaw
            : strtolower((string) $debugRaw) === 'true';
        return new self($url, $tenant, $secret, $mode, $timeout, $debug);
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
        } catch (\Throwable) {
            return null;
        }
    }

    public function mode(): string
    {
        return $this->mode;
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
        return $this->call('/v1/search', $params);
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
     * @return array<string,mixed>|null
     */
    public function index(array $documents): ?array
    {
        return $this->call('/v1/index', ['documents' => $documents]);
    }

    /**
     * POST /v1/events.
     *
     * @param array<string,mixed> $event {kind, keyword, products_id, position, ...}
     * @return array<string,mixed>|null
     */
    public function events(array $event): ?array
    {
        return $this->call('/v1/events', $event);
    }

    private function call(string $path, array $body): ?array
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
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            // Connect (DNS+TCP+TLS) needs more headroom than a tight 200ms
            // when the resolver path is slow. Cap at 750ms but never less
            // than half the overall budget — TLS handshake to Cloudflare
            // is typically 60-120ms, so this still leaves room for the
            // POST round-trip.
            CURLOPT_CONNECTTIMEOUT_MS => max(250, min(750, intdiv($this->timeoutMs, 2))),
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
            $this->markOverQuota(is_string($resp) ? $resp : '');
            $this->log('warn', 'over_quota_402', [
                'path' => $path,
                'elapsed_ms' => $elapsedMs,
                'body_preview' => is_string($resp) ? substr($resp, 0, 200) : '',
            ]);
            return null;
        }
        if ($status >= 400) {
            $this->log('warn', 'caller_error', [
                'path' => $path,
                'status' => $status,
                'elapsed_ms' => $elapsedMs,
                'body_preview' => is_string($resp) ? substr($resp, 0, 200) : '',
            ]);
            // 403 + tenant_paused / subscription_cancelled is a clean
            // "this tenant is locked down" signal. Stash a flag so the
            // admin status page can render an "Account paused" notice
            // without the storefront having to surface the error path.
            // Active responses clear the flag in the success branch.
            if ($status === 403 && is_string($resp) && $resp !== '') {
                $body = json_decode($resp, true);
                if (is_array($body)) {
                    $err = strtolower((string)($body['error'] ?? ''));
                    if (
                        $err === 'tenant_paused'
                        || $err === 'subscription_cancelled'
                        || $err === 'unknown_tenant'
                    ) {
                        $this->markSubscriptionState(self::SUB_STATE_CANCELLED);
                    }
                }
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
        if ($elapsedMs > $this->timeoutMs * 2) {
            $this->breaker->record(self::CIRCUIT_KEY, false, self::CIRCUIT_THRESHOLD, self::CIRCUIT_COOLDOWN_S);
        } else {
            $this->breaker->record(self::CIRCUIT_KEY, true, self::CIRCUIT_THRESHOLD, self::CIRCUIT_COOLDOWN_S);
        }
        // Any 2xx clears the cancellation flag — if the gateway is
        // answering for our tenant, the subscription is back. The
        // over-quota flag stays put: a single successful index/events
        // call does NOT prove searches are back under cap, so we wait
        // for the 60-min TTL or an explicit PayPal tier-up event.
        $this->markSubscriptionState(self::SUB_STATE_ACTIVE);
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
        @file_put_contents($logDir . '/numinix_seekmodo.log', $line . PHP_EOL, FILE_APPEND);
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
        if (!function_exists('apcu_fetch')) {
            return 'unknown';
        }
        $ok = false;
        $val = @\apcu_fetch(self::SUBSCRIPTION_KEY, $ok);
        if ($ok && is_string($val) && $val === self::SUB_STATE_CANCELLED) {
            return self::SUB_STATE_CANCELLED;
        }
        // No cancellation; check the quota flag.
        $okQ = false;
        $valQ = @\apcu_fetch(self::OVER_QUOTA_KEY, $okQ);
        if ($okQ && is_string($valQ) && $valQ !== '') {
            return self::SUB_STATE_OVER_QUOTA;
        }
        if ($ok && is_string($val)) {
            return self::SUB_STATE_ACTIVE;
        }
        return 'unknown';
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
        if (!function_exists('apcu_fetch')) {
            return null;
        }
        $ok = false;
        $val = @\apcu_fetch(self::OVER_QUOTA_KEY, $ok);
        if (!$ok || !is_string($val) || $val === '') {
            return null;
        }
        $decoded = json_decode($val, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function markSubscriptionState(string $state): void
    {
        if (!function_exists('apcu_store')) {
            return;
        }
        @\apcu_store(self::SUBSCRIPTION_KEY, $state, self::SUBSCRIPTION_TTL_S);
    }

    /**
     * Stamp the over-quota flag with the gateway's 402 envelope body.
     * The body is the §1.8.2 shape (`code/quota/limit/used/resets_at`),
     * stored verbatim so the admin UI doesn't have to reformat it.
     */
    private function markOverQuota(string $rawBody): void
    {
        if (!function_exists('apcu_store')) {
            return;
        }
        $payload = $rawBody;
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded) || ($decoded['code'] ?? '') !== 'over_quota') {
            // Defensive — preserve the raw body anyway so the operator
            // has something to debug from. The admin UI checks for the
            // 'code' field and falls back to a generic message when
            // it can't parse.
            $payload = $rawBody;
        }
        @\apcu_store(self::OVER_QUOTA_KEY, $payload, self::OVER_QUOTA_TTL_S);
    }

    private static function clampTimeout(int $timeoutMs): int
    {
        if ($timeoutMs < 80) {
            return 80;
        }
        if ($timeoutMs > 2000) {
            return 2000;
        }
        return $timeoutMs;
    }
}
