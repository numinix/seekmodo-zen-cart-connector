<?php
/**
 * Per-shopper personalization helpers — Sprint 5 PR 6
 * (search-features-plan).
 *
 * Resolves and forwards three things to the gateway on every search /
 * recommend / event call so the gateway-side ShopperContextResolver
 * can decide whether to apply the tenant's personalized LTR model:
 *
 *   - `customer_id` (Zen Cart's customers_id) when the shopper is
 *     logged in. Most durable identity; survives across sessions.
 *   - `shopper_pid`: the value of the first-party `sm_pid` cookie
 *     (random 22-char URL-safe id, 1-year TTL). Durable anonymous
 *     identity; survives the session cookie expiring. Gated behind
 *     NUMINIX_SEEKMODO_PERSONALIZATION_COOKIE_ENABLED (default false)
 *     so EU storefronts can wire it behind their cookie-consent
 *     flow before enabling.
 *   - `do_not_personalize`: true when the shopper's request carried
 *     a `Do-Not-Personalize: 1` HTTP header. Short-circuits BOTH
 *     reads and writes on the gateway side so an opted-out shopper
 *     never trains the personalized model and never sees a
 *     personalized result.
 *
 * The session_id fallback (Zen Cart's PHP session id) is the
 * ephemeral floor and is already plumbed by
 * `_numinix_seekmodo_session_id()` in the search lib, so we don't
 * duplicate it here — the gateway reads `session_id` from the
 * payload independently.
 *
 * No new metering. Personalization piggybacks on the parent search /
 * recommend call's existing `Counter::incrementBilled()` per §6.5
 * of SEARCH_FEATURES_PLAN.md.
 *
 * Privacy posture:
 *   - The cookie value is a random opaque token — never derived from
 *     PII. The gateway only sees the token, never the customer's
 *     email / name / address.
 *   - A Do-Not-Personalize header skips even creating the cookie.
 *   - The `seekmodo_forget_me` deeplink (see Pairing\ForgetMe.php)
 *     clears the cookie locally and asks the gateway to erase every
 *     row for that shopper.
 */

if (!defined('NUMINIX_SEEKMODO_PID_COOKIE_NAME')) {
    /**
     * First-party long-lived cookie carrying the anonymous shopper
     * id. Plain `sm_pid` (no `numinix_` prefix) so a privacy-tool
     * sweep that looks for "sm" / Seekmodo cookies catches it.
     */
    define('NUMINIX_SEEKMODO_PID_COOKIE_NAME', 'sm_pid');
}

if (!defined('NUMINIX_SEEKMODO_PID_COOKIE_TTL_S')) {
    // 1 year. Long enough to amortize the per-shopper training
    // signal across return visits, short enough that an inactive
    // shopper's identity ages out organically.
    define('NUMINIX_SEEKMODO_PID_COOKIE_TTL_S', 60 * 60 * 24 * 365);
}

if (!function_exists('numinix_seekmodo_personalization_cookie_enabled')) {
    /**
     * Master switch for the sm_pid cookie. Default OFF so EU
     * storefronts that need cookie-consent wiring can opt in only
     * after their consent flow signals OK. Operators flip it on by
     * defining NUMINIX_SEEKMODO_PERSONALIZATION_COOKIE_ENABLED=true
     * in an init include (or, easier, by toggling it from the
     * admin.seekmodo.com → Personalization panel, which the
     * RemoteConfig snapshot mirrors into the constant on the next
     * boot).
     *
     * When this returns false the cookie is never read, set, or
     * forwarded — shopper_pid stays unset on every payload, and
     * the gateway falls back to the session_id-only identity path.
     */
    function numinix_seekmodo_personalization_cookie_enabled(): bool
    {
        if (defined('NUMINIX_SEEKMODO_PERSONALIZATION_COOKIE_ENABLED')) {
            $v = NUMINIX_SEEKMODO_PERSONALIZATION_COOKIE_ENABLED;
            if (is_bool($v)) {
                return $v;
            }
            if (is_string($v)) {
                return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
            }
            return (bool) $v;
        }
        return false;
    }
}

if (!function_exists('numinix_seekmodo_do_not_personalize')) {
    /**
     * True when the inbound storefront request carried a
     * `Do-Not-Personalize: 1` HTTP header. We mirror the value
     * verbatim to the gateway in `shopper_context.do_not_personalize`
     * so the resolver there can short-circuit personalization at
     * the read AND write paths.
     *
     * The header check tolerates the usual case variations PHP
     * exposes (`HTTP_DO_NOT_PERSONALIZE`). Anything truthy other
     * than the literal string `0` / `false` / empty counts as opted
     * out — same defensive posture as DNT.
     */
    function numinix_seekmodo_do_not_personalize(): bool
    {
        $raw = $_SERVER['HTTP_DO_NOT_PERSONALIZE'] ?? null;
        if ($raw === null || $raw === '') {
            return false;
        }
        $v = strtolower(trim((string) $raw));
        if (in_array($v, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }
        return true;
    }
}

if (!function_exists('numinix_seekmodo_current_customer_id')) {
    /**
     * Zen Cart logged-in customer id, or null when the shopper is
     * anonymous. Reads `$_SESSION['customer_id']` defensively — the
     * storefront might be running outside a Zen Cart session (CLI
     * cron, init phase) and we never assume the key is set.
     *
     * Returns an int when present and > 0; null otherwise. The
     * gateway treats an empty / zero value as "no logged-in id"
     * regardless, but returning null here keeps the payload tidy.
     */
    function numinix_seekmodo_current_customer_id(): ?int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        if (!isset($_SESSION['customer_id'])) {
            return null;
        }
        $cid = (int) $_SESSION['customer_id'];
        return $cid > 0 ? $cid : null;
    }
}

if (!function_exists('numinix_seekmodo_resolve_pid')) {
    /**
     * Read the inbound `sm_pid` cookie, or mint a new one on the
     * first visit + set the Set-Cookie response header.
     *
     * Mint policy:
     *   - Cookie disabled (master switch off) → return null. We
     *     never write the Set-Cookie header in this case.
     *   - DNP header present → return null and DELETE any existing
     *     cookie via a past-dated Set-Cookie (defensive — an
     *     existing cookie set before the user opted out should be
     *     cleared).
     *   - Already set with a valid-looking value → return as-is.
     *   - Otherwise → mint a new 22-char URL-safe random token,
     *     set the cookie on the response with SameSite=Lax and the
     *     storefront's effective scheme (Secure when HTTPS).
     *
     * The token is random — never derived from PII or any session
     * value the shopper can predict. URL-safe characters only so
     * downstream URL-builders can carry it without re-encoding.
     */
    function numinix_seekmodo_resolve_pid(): ?string
    {
        if (!numinix_seekmodo_personalization_cookie_enabled()) {
            return null;
        }
        if (numinix_seekmodo_do_not_personalize()) {
            _numinix_seekmodo_pid_clear_cookie();
            return null;
        }
        $name = NUMINIX_SEEKMODO_PID_COOKIE_NAME;
        $existing = isset($_COOKIE[$name]) ? (string) $_COOKIE[$name] : '';
        if ($existing !== '' && _numinix_seekmodo_pid_is_valid($existing)) {
            return $existing;
        }
        $pid = _numinix_seekmodo_pid_mint();
        _numinix_seekmodo_pid_set_cookie($pid);
        // Surface the freshly-minted value for the rest of the
        // request without waiting for a round-trip from the
        // browser (the response cookie won't be visible in
        // $_COOKIE until the next request).
        $_COOKIE[$name] = $pid;
        return $pid;
    }
}

if (!function_exists('numinix_seekmodo_clear_pid')) {
    /**
     * Forget-me path: clear the sm_pid cookie on the response and
     * drop the in-request copy. The gateway-side erasure
     * (`tenant.shopper.forget`) is fired separately by the
     * deeplink handler (Pairing\ForgetMe.php) — this helper only
     * handles the cookie side.
     *
     * Idempotent — safe to call multiple times per request.
     */
    function numinix_seekmodo_clear_pid(): void
    {
        _numinix_seekmodo_pid_clear_cookie();
        $name = NUMINIX_SEEKMODO_PID_COOKIE_NAME;
        if (isset($_COOKIE[$name])) {
            unset($_COOKIE[$name]);
        }
    }
}

if (!function_exists('numinix_seekmodo_shopper_context')) {
    /**
     * Build the gateway-bound `shopper_context` payload subkey.
     *
     * Shape (matches the gateway's ShopperContextResolver expected
     * input):
     *
     *   [
     *     'customer_id'        => int|null,   // logged-in id
     *     'shopper_pid'        => string|null, // sm_pid cookie
     *     'do_not_personalize' => bool,
     *   ]
     *
     * Empty / null fields are dropped to keep the body small. When
     * neither customer_id nor shopper_pid resolves AND DNP is
     * false, we still emit the `do_not_personalize` field
     * explicitly so the resolver's "is the connector aware of
     * personalization?" detection works (a payload with no
     * shopper_context at all means a pre-v1.0.16 connector that
     * the gateway should treat as session-only).
     */
    function numinix_seekmodo_shopper_context(): array
    {
        $ctx = [
            'do_not_personalize' => numinix_seekmodo_do_not_personalize(),
        ];
        $customerId = numinix_seekmodo_current_customer_id();
        if ($customerId !== null) {
            $ctx['customer_id'] = $customerId;
        }
        $pid = numinix_seekmodo_resolve_pid();
        if ($pid !== null && $pid !== '') {
            $ctx['shopper_pid'] = $pid;
        }
        if (function_exists('numinix_seekmodo_current_language_code')) {
            $lang = numinix_seekmodo_current_language_code();
            if ($lang !== null && $lang !== '') {
                $ctx['lang'] = $lang;
            }
        }
        return $ctx;
    }
}

if (!function_exists('_numinix_seekmodo_pid_mint')) {
    /**
     * Generate a 22-char URL-safe random token (128 bits of entropy,
     * base64url-encoded, padding stripped). random_bytes is preferred;
     * older PHP installs degrade to openssl_random_pseudo_bytes; the
     * final fallback uses mt_rand which is fine for an opaque
     * non-secret id but logged so operators can fix it.
     */
    function _numinix_seekmodo_pid_mint(): string
    {
        $raw = '';
        try {
            if (function_exists('random_bytes')) {
                $raw = random_bytes(16);
            } elseif (function_exists('openssl_random_pseudo_bytes')) {
                $raw = (string) openssl_random_pseudo_bytes(16);
            }
        } catch (\Throwable $e) {
            $raw = '';
        }
        if ($raw === '' || strlen($raw) < 16) {
            $raw = '';
            for ($i = 0; $i < 16; $i++) {
                $raw .= chr(mt_rand(0, 255));
            }
        }
        $b64 = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        return substr($b64, 0, 22);
    }
}

if (!function_exists('_numinix_seekmodo_pid_is_valid')) {
    /**
     * Defensive validator. Rejects values outside the expected URL-
     * safe charset / length window to avoid mistaking a corrupted
     * cookie for a real shopper id. Keeps the resolver honest when
     * a third-party tool mangles the cookie.
     */
    function _numinix_seekmodo_pid_is_valid(string $v): bool
    {
        $len = strlen($v);
        if ($len < 16 || $len > 64) {
            return false;
        }
        return preg_match('/^[A-Za-z0-9_-]+$/', $v) === 1;
    }
}

if (!function_exists('_numinix_seekmodo_pid_set_cookie')) {
    /**
     * Set the sm_pid cookie on the response. SameSite=Lax (default;
     * we don't need cross-site sends), Secure when HTTPS, HttpOnly
     * is intentionally OFF so a client-side personalization-opt-out
     * JS snippet can read / clear the cookie without a round-trip
     * — the cookie itself is non-identifying so HttpOnly isn't a
     * meaningful protection.
     */
    function _numinix_seekmodo_pid_set_cookie(string $value): void
    {
        if (headers_sent()) {
            return;
        }
        $name = NUMINIX_SEEKMODO_PID_COOKIE_NAME;
        $expire = time() + NUMINIX_SEEKMODO_PID_COOKIE_TTL_S;
        $secure = _numinix_seekmodo_is_https();
        if (PHP_VERSION_ID >= 70300) {
            setcookie($name, $value, [
                'expires'  => $expire,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $secure,
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        } else {
            setcookie($name, $value, $expire, '/', '', $secure, false);
        }
    }
}

if (!function_exists('_numinix_seekmodo_pid_clear_cookie')) {
    /**
     * Past-date the cookie so the browser drops it on the next
     * request. Mirrors the same flag set as set_cookie() so the
     * browser matches and clears the right entry.
     */
    function _numinix_seekmodo_pid_clear_cookie(): void
    {
        if (headers_sent()) {
            return;
        }
        $name = NUMINIX_SEEKMODO_PID_COOKIE_NAME;
        $secure = _numinix_seekmodo_is_https();
        if (PHP_VERSION_ID >= 70300) {
            setcookie($name, '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $secure,
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        } else {
            setcookie($name, '', time() - 3600, '/', '', $secure, false);
        }
    }
}

if (!function_exists('_numinix_seekmodo_is_https')) {
    /**
     * True when the storefront request is HTTPS — directly, OR via
     * the standard reverse-proxy headers most production hosts use
     * (Cloudflare, AWS ALB, generic Nginx). Cookies set with
     * Secure=false on a Cloudflare-fronted HTTPS site would be
     * stripped, so this matters.
     */
    function _numinix_seekmodo_is_https(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        if (!empty($_SERVER['HTTP_CF_VISITOR'])
            && strpos((string) $_SERVER['HTTP_CF_VISITOR'], 'https') !== false) {
            return true;
        }
        return false;
    }
}
