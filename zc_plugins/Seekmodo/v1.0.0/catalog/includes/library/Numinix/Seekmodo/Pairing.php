<?php
declare(strict_types=1);

namespace Numinix\Seekmodo;

/**
 * M5 plugin pairing helper.
 *
 * Implements both ends of the claim-token round-trip:
 *
 *   - mint_install_token() — admin side. Generates a 32-hex install
 *     token, persists it to NUMINIX_SEEKMODO_INSTALL_TOKEN with a
 *     short TTL written into NUMINIX_SEEKMODO_INSTALL_TOKEN_EXP, and
 *     returns the URL the merchant's browser opens.
 *
 *   - verify_pair_callback() — storefront side. Validates the JWT
 *     POSTed by seekmodo.com against the published JWKS, checks that
 *     the X-Seekmodo-Install-Token header matches the stored value,
 *     and on success writes NUMINIX_SEEKMODO_TENANT_ID +
 *     NUMINIX_SEEKMODO_SHARED_SECRET into the configuration table.
 *
 * EdDSA verification uses libsodium's
 * sodium_crypto_sign_verify_detached, which ships with PHP 7.2+ via
 * the bundled libsodium extension. No third-party JWT library
 * dependency.
 */
final class Pairing
{
    public const INSTALL_TOKEN_KEY = 'NUMINIX_SEEKMODO_INSTALL_TOKEN';
    public const INSTALL_TOKEN_EXP_KEY = 'NUMINIX_SEEKMODO_INSTALL_TOKEN_EXP';
    public const TOKEN_TTL_SECONDS = 600; // 10 minutes; matches seekmodo.com claim TTL.

    /**
     * Generate and persist a fresh install_token. Returns the
     * seekmodo.com URL the merchant's browser should open, including
     * install_token + callback query params.
     */
    public static function mint_install_token(string $seekmodoBase, string $callbackUrl): string
    {
        global $db;
        $token = bin2hex(random_bytes(16));
        $expiresAt = time() + self::TOKEN_TTL_SECONDS;
        self::set_or_insert_config(self::INSTALL_TOKEN_KEY, $token);
        self::set_or_insert_config(self::INSTALL_TOKEN_EXP_KEY, (string)$expiresAt);

        $base = rtrim($seekmodoBase, '/');
        $sep = strpos($base, '?') === false ? '/' : '/'; // always path-style
        $qs = http_build_query([
            'install_token' => $token,
            'callback' => $callbackUrl,
        ]);
        return $base . '/connect?' . $qs;
    }

    /**
     * Storefront-side verification. Reads the JWT from the POSTed JSON
     * body, validates everything, and on success returns the decoded
     * claims so the caller can write configuration. Throws on any
     * validation failure.
     */
    public static function verify_pair_callback(string $jwksUrl): array
    {
        $body = file_get_contents('php://input') ?: '';
        if ($body === '') {
            throw new \RuntimeException('empty body');
        }
        $headers = self::request_headers();
        $installHeader = $headers['x-seekmodo-install-token'] ?? '';
        if ($installHeader === '' || !preg_match('~^[a-f0-9]{16,64}$~', $installHeader)) {
            throw new \RuntimeException('missing or malformed install token header');
        }

        // 1. Match the stored install_token + check freshness. Read
        // from the DB instead of constants — the admin-side mint
        // happened in a different request, so a stale boot define
        // would lie to us.
        $stored = self::read_config_value(self::INSTALL_TOKEN_KEY);
        $expRaw = self::read_config_value(self::INSTALL_TOKEN_EXP_KEY);
        if ($stored === '' || !hash_equals($stored, $installHeader)) {
            throw new \RuntimeException('install token does not match this store');
        }
        $exp = (int)$expRaw;
        if ($exp > 0 && $exp < time()) {
            throw new \RuntimeException('install token expired');
        }

        // 2. Parse + verify the JWT.
        $payload = json_decode($body, true);
        if (!is_array($payload) || empty($payload['token'])) {
            throw new \RuntimeException('body missing `token`');
        }
        $jwt = (string)$payload['token'];
        $claims = self::verify_jwt($jwt, $jwksUrl);

        // 3. Cross-checks.
        if (($claims['install_token'] ?? '') !== $installHeader) {
            throw new \RuntimeException('install_token claim mismatch');
        }
        $now = time();
        if (($claims['exp'] ?? 0) < $now) {
            throw new \RuntimeException('jwt expired');
        }
        if (($claims['iss'] ?? '') !== 'https://seekmodo.com') {
            throw new \RuntimeException('jwt has wrong iss');
        }
        if (($claims['aud'] ?? '') !== 'seekmodo-plugin') {
            throw new \RuntimeException('jwt has wrong aud');
        }
        if (empty($claims['sub']) || empty($claims['shared_secret']) || empty($claims['mcp_url'])) {
            throw new \RuntimeException('jwt missing required claims');
        }
        return $claims;
    }

    /**
     * Persist tenant_id + shared_secret + mcp_url into the
     * configuration table after a successful verify.
     */
    public static function persist_credentials(array $claims): void
    {
        self::set_or_insert_config('NUMINIX_SEEKMODO_TENANT_ID', (string)$claims['sub']);
        self::set_or_insert_config('NUMINIX_SEEKMODO_SHARED_SECRET', (string)$claims['shared_secret']);
        if (!empty($claims['mcp_url'])) {
            self::set_or_insert_config('NUMINIX_SEEKMODO_URL', (string)$claims['mcp_url']);
        }
        // Burn the install token so a second POST cannot re-use it.
        self::set_or_insert_config(self::INSTALL_TOKEN_KEY, '');
        self::set_or_insert_config(self::INSTALL_TOKEN_EXP_KEY, '0');
    }

    // ---- internals ------------------------------------------------------

    private static function verify_jwt(string $jwt, string $jwksUrl): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \RuntimeException('malformed jwt');
        }
        [$h, $c, $s] = $parts;
        $header = json_decode(self::b64u_decode($h), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'EdDSA') {
            throw new \RuntimeException('unsupported alg');
        }
        $kid = $header['kid'] ?? '';
        if ($kid === '' || !preg_match('~^[A-Za-z0-9._\-]+$~', (string)$kid)) {
            throw new \RuntimeException('missing or invalid kid');
        }

        $claims = json_decode(self::b64u_decode($c), true);
        if (!is_array($claims)) {
            throw new \RuntimeException('malformed claims');
        }

        $publicKey = self::resolve_public_key($jwksUrl, (string)$kid);
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new \RuntimeException('libsodium not available; install php-sodium');
        }
        $signed = $h . '.' . $c;
        $sig = self::b64u_decode($s);
        if (!sodium_crypto_sign_verify_detached($sig, $signed, $publicKey)) {
            throw new \RuntimeException('jwt signature invalid');
        }
        return $claims;
    }

    /**
     * Resolve the matching JWK. Cached for 5 minutes via APCu when
     * available, otherwise re-fetched per call (acceptable — pairing
     * is a one-shot user action).
     */
    private static function resolve_public_key(string $jwksUrl, string $kid): string
    {
        $cacheKey = 'seekmodo:jwk:' . md5($jwksUrl . '|' . $kid);
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($cacheKey, $ok);
            if ($ok && is_string($cached) && $cached !== '') {
                return $cached;
            }
        }
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 5,
                'header' => "User-Agent: Numinix-Seekmodo/1.0\r\n",
                'ignore_errors' => true,
            ],
            'https' => [
                'timeout' => 5,
                'header' => "User-Agent: Numinix-Seekmodo/1.0\r\n",
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($jwksUrl, false, $ctx);
        if (!$body) {
            throw new \RuntimeException('jwks fetch failed');
        }
        $jwks = json_decode($body, true);
        if (!is_array($jwks) || empty($jwks['keys'])) {
            throw new \RuntimeException('jwks malformed');
        }
        foreach ($jwks['keys'] as $k) {
            if (($k['kid'] ?? '') !== $kid) continue;
            if (($k['kty'] ?? '') !== 'OKP' || ($k['crv'] ?? '') !== 'Ed25519') continue;
            $x = $k['x'] ?? '';
            if ($x === '') continue;
            $raw = self::b64u_decode((string)$x);
            if (function_exists('apcu_store')) {
                apcu_store($cacheKey, $raw, 300);
            }
            return $raw;
        }
        throw new \RuntimeException('matching kid not found in jwks');
    }

    private static function b64u_decode(string $s): string
    {
        $pad = strlen($s) % 4;
        if ($pad > 0) {
            $s .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode(strtr($s, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \RuntimeException('base64url decode failed');
        }
        return $decoded;
    }

    private static function request_headers(): array
    {
        $out = [];
        if (function_exists('getallheaders')) {
            foreach ((array)getallheaders() as $k => $v) {
                $out[strtolower($k)] = $v;
            }
            return $out;
        }
        foreach ($_SERVER as $k => $v) {
            if (strncmp($k, 'HTTP_', 5) === 0) {
                $name = strtolower(str_replace('_', '-', substr($k, 5)));
                $out[$name] = $v;
            }
        }
        return $out;
    }

    private static function read_config_value(string $key): string
    {
        global $db;
        if (!isset($db)) {
            return '';
        }
        $rs = $db->Execute(
            "SELECT configuration_value FROM " . TABLE_CONFIGURATION
            . " WHERE configuration_key = '" . zen_db_input($key) . "' LIMIT 1"
        );
        if ($rs->EOF) {
            return '';
        }
        return (string)$rs->fields['configuration_value'];
    }

    /**
     * Idempotent UPSERT into Zen Cart's configuration table — match
     * the InstallerScripted's pattern but without the metadata lookup
     * (the row already exists; we just need to update the value).
     */
    private static function set_or_insert_config(string $key, string $value): void
    {
        global $db;
        if (!isset($db)) {
            return;
        }
        $existsRs = $db->Execute(
            "SELECT configuration_id FROM " . TABLE_CONFIGURATION
            . " WHERE configuration_key = '" . zen_db_input($key) . "' LIMIT 1"
        );
        $valueEsc = "'" . zen_db_input($value) . "'";
        if (!$existsRs->EOF) {
            $db->Execute(
                "UPDATE " . TABLE_CONFIGURATION
                . " SET configuration_value = $valueEsc, last_modified = NOW()"
                . " WHERE configuration_key = '" . zen_db_input($key) . "'"
            );
        } else {
            // Insert a minimal row so verification flow can still
            // complete on a half-installed plugin.
            $db->Execute(
                "INSERT INTO " . TABLE_CONFIGURATION
                . " (configuration_title, configuration_key, configuration_value, configuration_description,"
                . " configuration_group_id, sort_order, date_added)"
                . " VALUES ('" . zen_db_input($key) . "', '" . zen_db_input($key) . "', $valueEsc, ''"
                . ", 1, 999, NOW())"
            );
        }
    }
}
