<?php
/**
 * Storefront client for the central Numinix bot-check service.
 *
 * Talks HMAC-SHA256 to bot-check.numinix.com (Phases 1-2). Used by:
 *   - the search-result template (issues a nonce on render)
 *   - the click-beacon endpoint (verifies the nonce on each click)
 *   - the search log writer in Phase 4 (asks for a real verdict)
 *
 * Design contract:
 *   - Always degrade gracefully. The Phase 0 in-process velocity caps in
 *     ajax/ajax_search_log.php remain the last line of defence, so every
 *     entry point here returns null on any failure and the caller falls
 *     back to whatever local logic it already had.
 *   - Hot path is hard-capped at NUMINIX_BOT_CHECK_TIMEOUT_MS (default 80
 *     ms). Anything slower than that disarms the circuit for 60 s, so
 *     storefront latency NEVER tracks bot-check latency.
 *   - Circuit-breaker state is shared across php-fpm workers via APCu
 *     when the extension is loaded; otherwise it's per-process which
 *     still bounds the damage to one worker per 60 s window.
 *
 * Configuration (set by installer 1_6_3 in Phase 4):
 *   NUMINIX_BOT_CHECK_URL            base URL, e.g. https://bot-check.numinix.com
 *   NUMINIX_BOT_CHECK_TENANT_ID      per-store identifier
 *   NUMINIX_BOT_CHECK_SHARED_SECRET  per-store HMAC key
 *   NUMINIX_BOT_CHECK_MODE           off | shadow | enforce
 *   NUMINIX_BOT_CHECK_TIMEOUT_MS     optional, defaults to 80
 *   NUMINIX_BOT_CHECK_BACKEND        optional, 'legacy' (default) | 'gateway'
 *
 *     When 'gateway', endpoints are rewritten to the Seekmodo MCP
 *     gateway's bot.classify / nonce.issue / nonce.verify tools and
 *     the request is signed with the connector's gateway secret
 *     (`NUMINIX_SEEKMODO_*` constants) instead of the bot-check
 *     secret. This is the migration path defined in
 *     seekmodo/PROJECT_PLAN.md §P1-14 — Phase B.
 *
 *     Operators flip this to 'gateway' for shadow-mode validation
 *     (verdicts logged but not yet authoritative) and then to
 *     enforce. Default stays 'legacy' so an in-place file deploy
 *     does NOT change behaviour until the operator opts in.
 *
 * This file ships ahead of installer 1_6_3 so the search template / click
 * beacon can already call the helpers; until the installer runs, the
 * helpers return null and the storefront keeps using its local logic.
 */

if (!defined('NUMINIX_BOT_CHECK_CIRCUIT_THRESHOLD')) {
    define('NUMINIX_BOT_CHECK_CIRCUIT_THRESHOLD', 5);
}
if (!defined('NUMINIX_BOT_CHECK_CIRCUIT_COOLDOWN_S')) {
    define('NUMINIX_BOT_CHECK_CIRCUIT_COOLDOWN_S', 60);
}

if (!function_exists('_numinix_bot_check_cfg')) {
    /**
     * Resolve a NUMINIX_BOT_CHECK_* constant, falling back to a default.
     * Trims whitespace so admin UI typos don't leak into HTTP headers.
     */
    function _numinix_bot_check_cfg(string $key, string $default = ''): string
    {
        if (!defined($key)) {
            return $default;
        }
        $v = trim((string)constant($key));
        return $v !== '' ? $v : $default;
    }
}

if (!function_exists('numinix_bot_check_mode')) {
    /**
     * Returns 'off', 'shadow', or 'enforce'. Anything else is treated as
     * 'off' so a half-configured store keeps shipping clean traffic.
     */
    function numinix_bot_check_mode(): string
    {
        $m = strtolower(_numinix_bot_check_cfg('NUMINIX_BOT_CHECK_MODE', 'off'));
        if ($m === 'shadow' || $m === 'enforce') {
            return $m;
        }
        return 'off';
    }
}

if (!function_exists('numinix_bot_check_backend')) {
    /**
     * Returns 'legacy' (default) or 'gateway'. Anything else is treated
     * as 'legacy' so a typo doesn't accidentally route shopper traffic
     * through the new path before shadow validation completes.
     *
     * PROJECT_PLAN.md §P1-14 — bot-check consolidation, Phase B.
     */
    function numinix_bot_check_backend(): string
    {
        $b = strtolower(_numinix_bot_check_cfg('NUMINIX_BOT_CHECK_BACKEND', 'legacy'));
        return $b === 'gateway' ? 'gateway' : 'legacy';
    }
}

if (!function_exists('_numinix_bot_check_backend_cfg')) {
    /**
     * Resolve the (url, tenant, secret) triple for the active backend.
     * Returns ['url' => '', 'tenant' => '', 'secret' => '', 'scheme' => 'bot_check'|'seekmodo']
     * so the transport can pick the right header prefix.
     */
    function _numinix_bot_check_backend_cfg(): array
    {
        if (numinix_bot_check_backend() === 'gateway') {
            return [
                'url' => _numinix_bot_check_cfg('NUMINIX_SEEKMODO_URL'),
                'tenant' => _numinix_bot_check_cfg('NUMINIX_SEEKMODO_TENANT_ID'),
                'secret' => _numinix_bot_check_cfg('NUMINIX_SEEKMODO_SHARED_SECRET'),
                'scheme' => 'seekmodo',
            ];
        }
        return [
            'url' => _numinix_bot_check_cfg('NUMINIX_BOT_CHECK_URL'),
            'tenant' => _numinix_bot_check_cfg('NUMINIX_BOT_CHECK_TENANT_ID'),
            'secret' => _numinix_bot_check_cfg('NUMINIX_BOT_CHECK_SHARED_SECRET'),
            'scheme' => 'bot_check',
        ];
    }
}

if (!function_exists('_numinix_bot_check_remap_endpoint')) {
    /**
     * Translate the canonical legacy endpoint path
     * ('/v1/nonce/issue', '/v1/nonce/verify', '/v1/classify')
     * to whatever the active backend expects.
     *
     * Gateway side:
     *   /v1/nonce/issue   -> /v1/nonce.issue
     *   /v1/nonce/verify  -> /v1/nonce.verify
     *   /v1/classify      -> /v1/bot.classify
     */
    function _numinix_bot_check_remap_endpoint(string $legacyPath): string
    {
        if (numinix_bot_check_backend() !== 'gateway') {
            return $legacyPath;
        }
        switch (ltrim($legacyPath, '/')) {
            case 'v1/nonce/issue':   return '/v1/nonce.issue';
            case 'v1/nonce/verify':  return '/v1/nonce.verify';
            case 'v1/classify':      return '/v1/bot.classify';
            default:                 return $legacyPath;
        }
    }
}

if (!function_exists('numinix_bot_check_enabled')) {
    /**
     * The client is "enabled" when mode != off AND the active backend's
     * three credential constants are populated. We do NOT consider the
     * circuit state here — callers check enabled() to decide whether
     * to ATTEMPT the call; the circuit breaker happens inside the
     * actual transport.
     */
    function numinix_bot_check_enabled(): bool
    {
        if (numinix_bot_check_mode() === 'off') {
            return false;
        }
        $cfg = _numinix_bot_check_backend_cfg();
        if ($cfg['url'] === '' || $cfg['tenant'] === '' || $cfg['secret'] === '') {
            return false;
        }
        if (!function_exists('curl_init')) {
            return false;
        }
        return true;
    }
}

if (!function_exists('_numinix_bot_check_apcu_active')) {
    function _numinix_bot_check_apcu_active(): bool
    {
        return function_exists('apcu_fetch')
            && (bool)ini_get('apc.enabled')
            && (function_exists('apcu_enabled') ? apcu_enabled() : true);
    }
}

if (!function_exists('_numinix_bot_check_circuit_state')) {
    /**
     * Returns ['open' => bool, 'opened_at' => int, 'failures' => int].
     * 'open' means the breaker is currently disarmed and callers should
     * skip the network round-trip entirely.
     */
    function _numinix_bot_check_circuit_state(): array
    {
        $now = time();
        $state = ['open' => false, 'opened_at' => 0, 'failures' => 0];
        if (!_numinix_bot_check_apcu_active()) {
            static $local = ['failures' => [], 'opened_at' => 0];
            $state['failures'] = count($local['failures']);
            $state['opened_at'] = $local['opened_at'];
            if ($local['opened_at'] > 0 && $now - $local['opened_at'] < NUMINIX_BOT_CHECK_CIRCUIT_COOLDOWN_S) {
                $state['open'] = true;
            }
            return $state;
        }
        $ok = false;
        $cached = apcu_fetch('numinix.bot_check.circuit', $ok);
        if (!$ok || !is_array($cached)) {
            return $state;
        }
        $openedAt = (int)($cached['opened_at'] ?? 0);
        $state['failures'] = (int)($cached['failures'] ?? 0);
        $state['opened_at'] = $openedAt;
        if ($openedAt > 0 && $now - $openedAt < NUMINIX_BOT_CHECK_CIRCUIT_COOLDOWN_S) {
            $state['open'] = true;
        }
        return $state;
    }
}

if (!function_exists('_numinix_bot_check_record_outcome')) {
    /**
     * Record a success/failure for circuit accounting. Successes reset
     * the failure counter; failures push the count and open the breaker
     * when threshold is exceeded.
     */
    function _numinix_bot_check_record_outcome(bool $ok): void
    {
        if (_numinix_bot_check_apcu_active()) {
            $found = false;
            $state = apcu_fetch('numinix.bot_check.circuit', $found);
            if (!$found || !is_array($state)) {
                $state = ['failures' => 0, 'opened_at' => 0, 'window_started_at' => time()];
            }
            $now = time();
            // Window failures over the last 60 s only.
            if (($now - (int)($state['window_started_at'] ?? 0)) > NUMINIX_BOT_CHECK_CIRCUIT_COOLDOWN_S) {
                $state['failures'] = 0;
                $state['window_started_at'] = $now;
            }
            if ($ok) {
                $state['failures'] = 0;
                $state['opened_at'] = 0;
            } else {
                $state['failures'] = (int)$state['failures'] + 1;
                if ($state['failures'] >= NUMINIX_BOT_CHECK_CIRCUIT_THRESHOLD) {
                    $state['opened_at'] = $now;
                }
            }
            apcu_store('numinix.bot_check.circuit', $state, NUMINIX_BOT_CHECK_CIRCUIT_COOLDOWN_S * 4);
            return;
        }
        // No APCu — per-process bookkeeping in a static.
        static $local = ['failures' => 0, 'opened_at' => 0, 'window_started_at' => 0];
        $now = time();
        if (($now - $local['window_started_at']) > NUMINIX_BOT_CHECK_CIRCUIT_COOLDOWN_S) {
            $local['failures'] = 0;
            $local['window_started_at'] = $now;
        }
        if ($ok) {
            $local['failures'] = 0;
            $local['opened_at'] = 0;
        } else {
            $local['failures']++;
            if ($local['failures'] >= NUMINIX_BOT_CHECK_CIRCUIT_THRESHOLD) {
                $local['opened_at'] = $now;
            }
        }
    }
}

if (!function_exists('_numinix_bot_check_post')) {
    /**
     * Low-level HMAC-signed POST helper.
     * Returns the decoded JSON body on success, null on any failure.
     */
    function _numinix_bot_check_post(string $endpoint, array $body): ?array
    {
        if (!numinix_bot_check_enabled()) {
            return null;
        }
        $circuit = _numinix_bot_check_circuit_state();
        if ($circuit['open']) {
            return null;
        }

        $cfg = _numinix_bot_check_backend_cfg();
        $base = rtrim($cfg['url'], '/');
        $endpoint = _numinix_bot_check_remap_endpoint($endpoint);
        $url = $base . '/' . ltrim($endpoint, '/');
        $secret = $cfg['secret'];
        $tenant = $cfg['tenant'];
        $raw = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($raw === false) {
            return null;
        }
        $sig = hash_hmac('sha256', $raw, $secret);
        $timeoutMs = (int)_numinix_bot_check_cfg('NUMINIX_BOT_CHECK_TIMEOUT_MS', '80');
        if ($timeoutMs < 20 || $timeoutMs > 2000) {
            $timeoutMs = 80;
        }

        // Header prefix: bot-check uses X-Numinix-*, the gateway uses
        // X-Seekmodo-*. The body-only HMAC scheme is identical between
        // them so only the header names differ.
        if ($cfg['scheme'] === 'seekmodo') {
            $headers = [
                'Content-Type: application/json',
                'X-Seekmodo-Tenant: ' . $tenant,
                'X-Seekmodo-Signature: ' . $sig,
                'X-Seekmodo-Timestamp: ' . time(),
                'Accept: application/json',
            ];
        } else {
            $headers = [
                'Content-Type: application/json',
                'X-Numinix-Tenant: ' . $tenant,
                'X-Numinix-Signature: ' . $sig,
                'Accept: application/json',
            ];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            _numinix_bot_check_record_outcome(false);
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $raw,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min(40, $timeoutMs),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errNum = curl_errno($ch);
        curl_close($ch);

        if ($errNum !== 0 || $status >= 500 || $status === 0) {
            _numinix_bot_check_record_outcome(false);
            return null;
        }
        if ($status === 401 || $status === 400) {
            // Auth / bad-request failures are NOT counted toward the
            // circuit — they're caller errors, not service degradation.
            // Still return null because the body has no usable verdict.
            return null;
        }
        if ($status !== 200 || !is_string($resp)) {
            _numinix_bot_check_record_outcome(false);
            return null;
        }

        $decoded = json_decode($resp, true);
        if (!is_array($decoded)) {
            _numinix_bot_check_record_outcome(false);
            return null;
        }
        _numinix_bot_check_record_outcome(true);
        return $decoded;
    }
}

if (!function_exists('numinix_bot_check_issue_nonce')) {
    /**
     * Ask the service for a fresh HMAC nonce bound to (tenant, session,
     * keyword, current minute bucket). Returns the nonce string or null.
     */
    function numinix_bot_check_issue_nonce(string $sessionId, string $keyword): ?string
    {
        $sessionId = trim($sessionId);
        $keyword = trim($keyword);
        if ($sessionId === '' || $keyword === '') {
            return null;
        }
        $resp = _numinix_bot_check_post('/v1/nonce/issue', [
            'session_id' => $sessionId,
            'keyword' => $keyword,
        ]);
        if (!is_array($resp) || empty($resp['nonce'])) {
            return null;
        }
        return (string)$resp['nonce'];
    }
}

if (!function_exists('numinix_bot_check_verify_nonce')) {
    /**
     * Verify a nonce returned to the client at search-result render time.
     *
     * Return contract:
     *   ['valid' => bool, 'age_ms' => int, 'reason' => string|null]
     *   null  — service unreachable / circuit open / config missing
     *
     * Callers in `enforce` mode should treat null as "fall back to
     * Phase 0 caps" rather than auto-rejecting; that's the whole point
     * of the circuit breaker — degrade gracefully, never block legit
     * traffic when bot-check itself is down.
     */
    function numinix_bot_check_verify_nonce(string $sessionId, string $keyword, string $nonce): ?array
    {
        $sessionId = trim($sessionId);
        $keyword = trim($keyword);
        $nonce = trim($nonce);
        if ($sessionId === '' || $keyword === '' || $nonce === '') {
            return ['valid' => false, 'age_ms' => 0, 'reason' => 'missing'];
        }
        $resp = _numinix_bot_check_post('/v1/nonce/verify', [
            'session_id' => $sessionId,
            'keyword' => $keyword,
            'nonce' => $nonce,
        ]);
        if ($resp === null) {
            return null;
        }
        return [
            'valid' => !empty($resp['valid']),
            'age_ms' => (int)($resp['age_ms'] ?? 0),
            'reason' => isset($resp['reason']) ? (string)$resp['reason'] : null,
        ];
    }
}

if (!function_exists('numinix_bot_check_classify')) {
    /**
     * Full /v1/classify round-trip. Phase 4 will wire this into
     * numinix_search_log_is_bot_ua() under shadow/enforce mode; Phase 3
     * leaves the function here so the integration can be tested before
     * the storefront actually calls it on the hot path.
     *
     * Returns the full verdict array (as documented in services/bot-check
     * README.md) or null when the service is unavailable.
     */
    function numinix_bot_check_classify(string $ua, string $ip, string $sessionId = '', ?string $event = null, ?int $productId = null): ?array
    {
        $body = [
            'ua' => substr($ua, 0, 512),
            'ip' => $ip,
            'session_id' => $sessionId,
        ];
        if ($event !== null) {
            $body['event'] = $event;
        }
        if ($productId !== null) {
            $body['product_id'] = $productId;
        }
        return _numinix_bot_check_post('/v1/classify', $body);
    }
}
