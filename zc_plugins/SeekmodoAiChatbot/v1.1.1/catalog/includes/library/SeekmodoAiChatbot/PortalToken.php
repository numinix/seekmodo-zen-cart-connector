<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot;

/**
 * Short-lived shopper portal token.
 *
 * Shape: v1.{base64url(json)}.{base64url(hmac-sha256)}
 * JSON: {"v":1,"e":"customer@example.com","x":unix_exp}
 * HMAC is over the middle segment, keyed with the tenant shared secret.
 * The gateway verifies the same shape and injects `e` as customer_email.
 */
final class PortalToken
{
    public const TTL_DEFAULT = 900;
    public const TTL_MAX = 3600;

    public static function sharedSecret(): string
    {
        $chatbot = defined('SEEKMODO_AI_CHATBOT_SEEKMODO_SHARED_SECRET')
            ? trim((string) SEEKMODO_AI_CHATBOT_SEEKMODO_SHARED_SECRET) : '';
        if ($chatbot !== '') {
            return $chatbot;
        }
        return defined('NUMINIX_SEEKMODO_SHARED_SECRET')
            ? trim((string) NUMINIX_SEEKMODO_SHARED_SECRET) : '';
    }

    public static function mint(string $email, string $secret, int $ttlSeconds = self::TTL_DEFAULT): string
    {
        self::loadSharedPackage();
        if (class_exists(\Numinix\SeekmodoChat\PortalToken::class)) {
            return \Numinix\SeekmodoChat\PortalToken::mint($email, $secret, $ttlSeconds);
        }
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('invalid_email');
        }
        if ($secret === '') {
            throw new \InvalidArgumentException('missing_secret');
        }
        $ttlSeconds = max(60, min(self::TTL_MAX, $ttlSeconds));
        $payload = self::b64(json_encode([
            'v' => 1,
            'e' => $email,
            'x' => time() + $ttlSeconds,
        ], JSON_UNESCAPED_SLASHES));
        $sig = self::b64(hash_hmac('sha256', $payload, $secret, true));
        return 'v1.' . $payload . '.' . $sig;
    }

    /**
     * @return array{email:string,exp:int}
     */
    public static function verify(string $token, string $secret): array
    {
        self::loadSharedPackage();
        if (class_exists(\Numinix\SeekmodoChat\PortalToken::class)) {
            return \Numinix\SeekmodoChat\PortalToken::verify($token, $secret);
        }
        if ($secret === '') {
            throw new \InvalidArgumentException('missing_secret');
        }
        $parts = explode('.', trim($token));
        if (count($parts) !== 3 || $parts[0] !== 'v1' || $parts[1] === '' || $parts[2] === '') {
            throw new \InvalidArgumentException('bad_portal_token');
        }
        $expected = self::b64(hash_hmac('sha256', $parts[1], $secret, true));
        if (!hash_equals($expected, $parts[2])) {
            throw new \InvalidArgumentException('bad_portal_token');
        }
        $json = self::b64decode($parts[1]);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('bad_portal_token');
        }
        $email = strtolower(trim((string) ($data['e'] ?? '')));
        $exp = (int) ($data['x'] ?? 0);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $exp < 1) {
            throw new \InvalidArgumentException('bad_portal_token');
        }
        if ($exp < time()) {
            throw new \InvalidArgumentException('portal_token_expired');
        }
        if ($exp > time() + self::TTL_MAX) {
            throw new \InvalidArgumentException('portal_token_ttl');
        }
        return ['email' => $email, 'exp' => $exp];
    }

    private static function loadSharedPackage(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;
        $file = dirname(__DIR__) . '/vendor/seekmodo-chat/autoload.php';
        if (is_file($file)) {
            require_once $file;
        }
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64decode(string $encoded): string
    {
        $pad = strlen($encoded) % 4;
        if ($pad > 0) {
            $encoded .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        return is_string($decoded) ? $decoded : '';
    }
}
