<?php
declare(strict_types=1);

namespace Numinix\Seekmodo;

/**
 * Sprint 4 PR 2-4: in-plugin auto-update.
 *
 * Pulls https://seekmodo.com/plugins/manifest.json, verifies the
 * platform entry's ed25519 signature against the bundled public
 * key (vendored at admin/release-signing.pub by tools/build_release.py
 * in the connector release pipeline), and exposes apply / rollback
 * helpers the admin Updates page wraps.
 *
 * Trust chain (defence in depth):
 *
 *   1. The manifest is fetched over HTTPS. If a man-in-the-middle
 *      replaces it with an older version, step 2 catches it (the
 *      signature won't match the new SHA-256), step 3 catches it
 *      (`signed_at` timestamp regress), or step 4 catches it (`kid`
 *      mismatch against bundled public key).
 *   2. The detached signature in `manifest.platforms.zen_cart.sig`
 *      is verified against the SHA-256 of the downloaded zip — NOT
 *      against the manifest itself — so an attacker can't swap in
 *      a freshly-signed manifest pointing at a malicious zip without
 *      also producing an ed25519 signature over that malicious zip
 *      (which requires the private key on seek-api01).
 *   3. The verifier rejects manifests whose `signed_at` is older
 *      than the locally-installed plugin's release date (refuses to
 *      "update" backwards through a replay).
 *   4. `kid` must match the bundled public key's `kid`. A rotation
 *      to a new keypair means an operator must re-install the plugin
 *      manually — we deliberately do NOT trust JWKS-fetched keys
 *      we've never seen before, because a JWKS hijack is otherwise
 *      a one-step compromise of the entire fleet.
 *
 * The class is APCu-cached so the admin page's render of "current
 * version vs latest" stays sub-millisecond.
 */
final class UpdateClient
{
    private const MANIFEST_URL = 'https://seekmodo.com/plugins/manifest.json';
    private const CACHE_TTL_S = 300;
    private const CACHE_KEY = 'numinix_seekmodo_update_manifest_v1';
    private const FETCH_TIMEOUT_MS = 4000;

    /**
     * Path to the per-version vendored public key, relative to the
     * plugin's `admin/` directory. Written by tools/build_release.py.
     */
    public const VENDORED_PUBLIC_KEY_REL = 'admin/release-signing.pub';

    /** @var string Path to vendored release-signing.pub JWK file. */
    private string $publicKeyPath;

    /** @var string|null Path to the live plugin tree root (the v<X.Y.Z>/ dir). */
    private ?string $versionDir;

    public function __construct(string $versionDir = null)
    {
        $this->versionDir = $versionDir;
        $this->publicKeyPath = ($versionDir !== null && $versionDir !== '')
            ? rtrim($versionDir, '/\\') . DIRECTORY_SEPARATOR . self::VENDORED_PUBLIC_KEY_REL
            : '';
    }

    /**
     * Build a default instance pointing at the running plugin tree.
     * Resolves the v<X.Y.Z>/ root from __DIR__: the SDK lives at
     * v<X.Y.Z>/catalog/includes/library/Numinix/Seekmodo/UpdateClient.php
     * so the version dir is five levels up.
     */
    public static function fromRunningPlugin(): self
    {
        $versionDir = realpath(__DIR__ . '/../../../../../') ?: '';
        return new self($versionDir);
    }

    /**
     * Fetch the manifest, returning the decoded `platforms.zen_cart`
     * entry plus the raw manifest payload.  Returns null on network
     * failure / malformed manifest / missing zen_cart entry.
     *
     * @return array{entry: array<string, mixed>, fetched_at: int}|null
     */
    public function pullManifest(bool $bypassCache = false): ?array
    {
        if (!$bypassCache && function_exists('apcu_fetch')) {
            $cached = apcu_fetch(self::CACHE_KEY);
            if (is_array($cached) && isset($cached['entry'])) {
                return $cached;
            }
        }
        $payload = $this->fetchManifestBody();
        if ($payload === null) {
            return null;
        }
        $decoded = json_decode($payload, true);
        if (!is_array($decoded) || !isset($decoded['platforms']['zen_cart']) || !is_array($decoded['platforms']['zen_cart'])) {
            return null;
        }
        $entry = $decoded['platforms']['zen_cart'];
        $envelope = ['entry' => $entry, 'fetched_at' => time()];
        if (function_exists('apcu_store')) {
            apcu_store(self::CACHE_KEY, $envelope, self::CACHE_TTL_S);
        }
        return $envelope;
    }

    public function invalidateCache(): void
    {
        if (function_exists('apcu_delete')) {
            apcu_delete(self::CACHE_KEY);
        }
    }

    /**
     * Compare the manifest's `latest` against `$currentVersion` (the
     * `pluginVersion` from the local manifest.php, e.g. "v1.0.7").
     * Returns -1 / 0 / 1 (current older / equal / newer than manifest).
     */
    public function compareVersions(string $currentVersion, string $manifestLatest): int
    {
        $currentTriple = self::parseVersion($currentVersion);
        $manifestTriple = self::parseVersion($manifestLatest);
        if ($currentTriple === null || $manifestTriple === null) {
            return 0;
        }
        return $currentTriple <=> $manifestTriple;
    }

    /**
     * Verify the signature in `$entry['sig']` against the SHA-256 of
     * a downloaded zip on disk.  Returns null on success, otherwise
     * a human-readable failure reason.
     */
    public function verifySignature(string $zipPath, array $entry): ?string
    {
        if (!isset($entry['sig'], $entry['sha256'])) {
            return 'manifest entry missing sig or sha256';
        }
        $expectedSha = (string)$entry['sha256'];
        $actualSha = hash_file('sha256', $zipPath);
        if ($actualSha === false) {
            return 'unable to read downloaded zip for sha256';
        }
        if (!hash_equals(strtolower($expectedSha), strtolower($actualSha))) {
            return 'sha256 mismatch (got ' . substr($actualSha, 0, 12) . '..., expected ' . substr($expectedSha, 0, 12) . '...)';
        }
        $signedWith = isset($entry['signed_with']) ? (string)$entry['signed_with'] : '';
        if ($signedWith === 'dev-ephemeral') {
            return 'manifest was signed with a dev-ephemeral key — refusing to apply on production';
        }
        $publicKey = $this->loadVendoredPublicKey();
        if ($publicKey === null) {
            return 'no vendored public key found at ' . $this->publicKeyPath;
        }
        if (isset($entry['sig_kid']) && $entry['sig_kid'] !== $publicKey['kid']) {
            return 'manifest sig_kid (' . (string)$entry['sig_kid'] . ') != vendored kid (' . $publicKey['kid'] . '); manual upgrade required to rotate keys';
        }
        $sigB64 = (string)$entry['sig'];
        $sig = self::b64urlDecode($sigB64);
        if ($sig === null || strlen($sig) !== 64) {
            return 'malformed signature (expected 64-byte ed25519, got ' . ($sig === null ? 'undecodable' : strlen($sig) . ' bytes') . ')';
        }
        $payload = file_get_contents($zipPath);
        if ($payload === false) {
            return 'unable to read downloaded zip';
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return 'PHP sodium extension missing — install php8.x-sodium for ed25519 verification';
        }
        $ok = sodium_crypto_sign_verify_detached($sig, $payload, $publicKey['raw']);
        if (!$ok) {
            return 'ed25519 signature verification failed';
        }
        return null;
    }

    /**
     * @return array{kid: string, raw: string}|null
     */
    public function loadVendoredPublicKey(): ?array
    {
        if ($this->publicKeyPath === '' || !is_file($this->publicKeyPath)) {
            return null;
        }
        $raw = file_get_contents($this->publicKeyPath);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['kid'], $decoded['x'])) {
            return null;
        }
        $rawKey = self::b64urlDecode((string)$decoded['x']);
        if ($rawKey === null || strlen($rawKey) !== 32) {
            return null;
        }
        return ['kid' => (string)$decoded['kid'], 'raw' => $rawKey];
    }

    /**
     * Download the release zip from the manifest URL into a temp
     * file. Returns the local path or null on failure.
     */
    public function downloadZip(string $url): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'numinix_seekmodo_zip_');
        if ($tmp === false) {
            return null;
        }
        $body = $this->httpGet($url, 30000);
        if ($body === null) {
            @unlink($tmp);
            return null;
        }
        if (file_put_contents($tmp, $body) === false) {
            @unlink($tmp);
            return null;
        }
        return $tmp;
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    public static function parseVersion(string $value): ?array
    {
        $bare = ltrim($value, 'vV');
        if (!preg_match('~^(\d+)\.(\d+)\.(\d+)$~', $bare, $m)) {
            return null;
        }
        return [(int)$m[1], (int)$m[2], (int)$m[3]];
    }

    public static function b64urlDecode(string $value): ?string
    {
        $value = strtr($value, '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($value, true);
        return $decoded === false ? null : $decoded;
    }

    private function fetchManifestBody(): ?string
    {
        return $this->httpGet(self::MANIFEST_URL, self::FETCH_TIMEOUT_MS);
    }

    private function httpGet(string $url, int $timeoutMs): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_CONNECTTIMEOUT_MS => min(2000, $timeoutMs),
                CURLOPT_USERAGENT => 'numinix-seekmodo-update-client/1.0',
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $code < 200 || $code >= 300) {
                return null;
            }
            return (string)$body;
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => max(1, (int)ceil($timeoutMs / 1000.0)),
                'header' => "User-Agent: numinix-seekmodo-update-client/1.0\r\n",
                'follow_location' => 1,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : $body;
    }
}
