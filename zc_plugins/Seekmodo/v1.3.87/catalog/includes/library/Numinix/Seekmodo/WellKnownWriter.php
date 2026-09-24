<?php
declare(strict_types=1);

namespace Numinix\Seekmodo;

/**
 * Drops a static `.well-known/mcp.json` discovery file on disk so
 * AI agents (ChatGPT, Claude Desktop, Cursor, Perplexity, …) can
 * find this storefront's anonymous-MCP endpoint at the IETF-standard
 * URL `https://<storefront>/.well-known/mcp.json` without depending
 * on any web-server rewrite cooperation we can't reliably get.
 *
 * --- Why a real file on disk? --------------------------------------
 *
 * v1.0.11 shipped a PHP-driven interceptor at autoLoadConfig[60]
 * that answered the same path. It only fires when Apache / nginx
 * routes the request to PHP, which doesn't happen on a stock
 * install because:
 *
 *   - Apache's default config treats `.well-known/` like a normal
 *     hidden directory and never forwards it to `index.php` unless
 *     the docroot's `.htaccess` carries an explicit rewrite.
 *   - Many Zen Cart installs live in a subdirectory
 *     (`<docroot>/catalog/`). The well-known URL is supposed to be
 *     at the site root, but the Zen Cart `.htaccess` only sees
 *     `/catalog/` requests. A root-level redirect (the standard
 *     redlinestands.com config) then catches `/.well-known/mcp.json`
 *     before it can reach Zen Cart.
 *
 * Writing a real file fixes both cases:
 *
 *   - For root-installed sites (numinix.com), `<docroot>/.well-known/
 *     mcp.json` IS `https://numinix.com/.well-known/mcp.json`.
 *     Apache serves it as a static file; no rewrite required.
 *   - For subdir-installed sites (redlinestands.com → /catalog/),
 *     we walk one level up to the parent of `DIR_FS_CATALOG`,
 *     verify it matches `$_SERVER['DOCUMENT_ROOT']` (or is
 *     close enough), and write the file there so the root-level
 *     URL works.
 *
 * --- Idempotency ---------------------------------------------------
 *
 * Every write is conditional on the on-disk content NOT matching
 * the canonical payload. This makes the helper safe to call on
 * every storefront request (which we don't, but the contract
 * holds) and zero-cost when nothing has changed.
 *
 * --- Failure posture -----------------------------------------------
 *
 * Every code path is wrapped in try/catch. The writer NEVER throws
 * to its caller — callers (Pairing, RemoteConfig, admin Connect
 * page) get back a result array and decide whether to surface
 * partial-success / outright failure in their own UI. A typical
 * failure mode is a docroot the FPM user doesn't own (shared
 * hosting); we report it but don't bubble.
 *
 * --- Why not also write an `.htaccess` allow rule? -----------------
 *
 * The default Apache 2.4 config only denies `.ht*` files
 * (`<Files ".ht*">`), not `.well-known/` directories at large.
 * Cloudways / cPanel / DirectAdmin all serve `.well-known/`
 * directories as static content out of the box. We sidestep the
 * problem entirely by writing real files; the dotfile-deny
 * pattern (which targets `.htaccess` / `.htpasswd` specifically)
 * doesn't catch us.
 */
final class WellKnownWriter
{
    /**
     * Subpath inside each target directory we write to.
     */
    public const RELATIVE_PATH = '.well-known/mcp.json';

    /**
     * Octal mode for the `.well-known/` directory (rwx for owner,
     * rx for group + other — same as the catalog root on stock ZC).
     */
    private const DIR_MODE = 0755;

    /**
     * Octal mode for the JSON file (r for everyone, w for owner
     * only).
     */
    private const FILE_MODE = 0644;

    /**
     * Defence-in-depth `.htaccess` snippet dropped alongside
     * `mcp.json` to force-allow the file on shared hosts whose
     * default config denies dotfile-directory access wholesale
     * (some cPanel / DirectAdmin / WHM setups carry a global
     * `<Directory ~ "/\.">` deny). Apache 2.4 honours
     * `Require all granted`; 2.2 honours `Allow from all` — both
     * lines are inert in the other version so this is portable.
     *
     * Trailing newline keeps `file_get_contents` round-trips clean.
     */
    private const HTACCESS_CONTENT = <<<HTACCESS
# Auto-written by Numinix Seekmodo connector.
# Allows AI-agent discovery clients to fetch mcp.json on hosts
# whose default config denies dotfile-directory access.
<Files "mcp.json">
    Require all granted
    Order allow,deny
    Allow from all
</Files>

HTACCESS;

    /**
     * Build the discovery JSON payload for the given tenant. Mirrors
     * the shape served by the PHP interceptor in
     * `init_numinix_seekmodo_well_known.php` so a site with BOTH
     * arms live serves byte-identical content.
     */
    public static function buildPayload(string $tenantId, string $gatewayHost): string
    {
        $tenantId = trim($tenantId);
        $gatewayHost = strtolower(trim($gatewayHost));
        if ($gatewayHost === '') {
            $gatewayHost = 'mcp.seekmodo.com';
        }
        $anonymousHost = $tenantId . '.' . $gatewayHost;
        $anonymousEndpoint = 'https://' . $anonymousHost . '/mcp';
        $payload = [
            'name'        => 'Seekmodo product search',
            'description' => 'Read-only product catalog search for this storefront,'
                . ' provided by Seekmodo. Anonymous tier — no authentication,'
                . ' per-IP rate-limited.',
            'tenant_id'   => $tenantId,
            'endpoints'   => [
                [
                    'type'      => 'mcp',
                    'transport' => 'http',
                    'url'       => $anonymousEndpoint,
                    'auth'      => 'none',
                ],
            ],
            'tools'       => ['search'],
            'rate_limits' => [
                'per_ip_per_minute'     => 60,
                'per_tenant_ip_per_day' => 500,
                'notes'                 => 'Server-side enforced by Seekmodo gateway;'
                    . ' a 429 with Retry-After is returned when the budget is exhausted.'
                    . ' Treat the limits above as approximate — the gateway is authoritative.',
            ],
            'docs'        => 'https://seekmodo.com/docs/mcp',
            'generator'   => 'numinix-seekmodo-zen-cart-connector',
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        return $json === false ? '{}' : $json;
    }

    /**
     * Write `.well-known/mcp.json` to every target directory we can
     * resolve. Returns a per-target result map so callers can surface
     * partial-success in the admin UI.
     *
     * Target directories (in priority order):
     *
     *   1. `DIR_FS_CATALOG`           — always tried; this is the
     *      install dir Zen Cart owns. Apache serves it whenever the
     *      storefront URL pattern matches (e.g. root install =
     *      `https://site/.well-known/mcp.json`; subdir install =
     *      `https://site/catalog/.well-known/mcp.json`).
     *   2. `$_SERVER['DOCUMENT_ROOT']` — when set, present, AND
     *      different from `DIR_FS_CATALOG`. Covers the most common
     *      subdir-install pattern (`<docroot>/catalog/` → write to
     *      `<docroot>/`) so the root-level URL works without an
     *      `.htaccess` rewrite.
     *   3. `dirname(DIR_FS_CATALOG)`  — fallback when
     *      `$_SERVER['DOCUMENT_ROOT']` is empty (CLI context, some
     *      reverse-proxy configs). Heuristic only — we ONLY try this
     *      when the parent is writeable AND distinct from
     *      `DIR_FS_CATALOG`.
     *
     * @param string $tenantId       NUMINIX_SEEKMODO_TENANT_ID value.
     * @param string $gatewayHost    Host portion of NUMINIX_SEEKMODO_URL
     *                               (e.g. 'mcp.seekmodo.com').
     * @return array<int, array{path: string, status: string, detail?: string}>
     */
    public static function writeFor(string $tenantId, string $gatewayHost): array
    {
        $results = [];
        $tenantId = trim($tenantId);
        if ($tenantId === '') {
            return [['path' => '', 'status' => 'skipped', 'detail' => 'no_tenant_id']];
        }
        $json = self::buildPayload($tenantId, $gatewayHost);
        $targets = self::resolveTargets();
        foreach ($targets as $target) {
            $results[] = self::writeOne($target, $json);
        }
        return $results;
    }

    /**
     * Resolve the candidate target directories (full paths to where
     * the `.well-known/` subdir should live, NOT including the
     * `.well-known/` segment). De-duplicated; only directories that
     * already exist on disk are returned.
     *
     * @return string[]
     */
    private static function resolveTargets(): array
    {
        $targets = [];
        if (defined('DIR_FS_CATALOG')) {
            $catalog = (string) constant('DIR_FS_CATALOG');
            $catalog = rtrim(self::normalisePath($catalog), '/');
            if ($catalog !== '' && is_dir($catalog)) {
                $targets[] = $catalog;
            }
        }
        $docRoot = isset($_SERVER['DOCUMENT_ROOT'])
            ? (string) $_SERVER['DOCUMENT_ROOT']
            : '';
        $docRoot = rtrim(self::normalisePath($docRoot), '/');
        if ($docRoot !== '' && is_dir($docRoot) && !in_array($docRoot, $targets, true)) {
            $targets[] = $docRoot;
        }
        // Heuristic fallback: parent of DIR_FS_CATALOG. Only useful
        // when DOCUMENT_ROOT was empty (CLI / odd FPM setups). We
        // explicitly guard against suggesting a root-of-disk parent
        // (e.g. when DIR_FS_CATALOG IS the docroot, dirname() yields
        // the disk root, which is a directory we should never try
        // to write to).
        if ($docRoot === '' && defined('DIR_FS_CATALOG')) {
            $catalog = rtrim(self::normalisePath((string) constant('DIR_FS_CATALOG')), '/');
            if ($catalog !== '' && $catalog !== '/') {
                $parent = dirname($catalog);
                if (
                    $parent !== ''
                    && $parent !== '/'
                    && $parent !== '.'
                    && !preg_match('~^[A-Za-z]:[/\\\\]?$~', $parent) // not C:\ / D:\
                    && is_dir($parent)
                    && $parent !== $catalog
                    && !in_array($parent, $targets, true)
                ) {
                    $targets[] = $parent;
                }
            }
        }
        return $targets;
    }

    /**
     * Write the JSON payload to `$baseDir/.well-known/mcp.json`.
     * Idempotent: if the file already exists with matching content,
     * the write is skipped and we return `status=unchanged`.
     *
     * @return array{path: string, status: string, detail?: string}
     */
    private static function writeOne(string $baseDir, string $json): array
    {
        $wellKnownDir = $baseDir . '/.well-known';
        $filePath = $wellKnownDir . '/mcp.json';
        try {
            // Ensure the .well-known directory exists. mkdir() with
            // $recursive=true is a no-op when the path already exists,
            // EXCEPT it emits a warning — suppress with @ so we don't
            // pollute error logs on every storefront request.
            if (!is_dir($wellKnownDir)) {
                if (!@mkdir($wellKnownDir, self::DIR_MODE, true) && !is_dir($wellKnownDir)) {
                    return [
                        'path'   => $filePath,
                        'status' => 'failed',
                        'detail' => 'mkdir_failed',
                    ];
                }
                // chmod is best-effort — on shared hosts the umask may
                // be more restrictive than what mkdir requested.
                @chmod($wellKnownDir, self::DIR_MODE);
            }
            // Idempotency check — avoid a tmpfile + rename when the
            // on-disk content already matches.
            if (is_file($filePath)) {
                $existing = @file_get_contents($filePath);
                if (is_string($existing) && $existing === $json) {
                    return [
                        'path'   => $filePath,
                        'status' => 'unchanged',
                    ];
                }
            }
            // Atomic write — stage to a sibling tmpfile, fsync via
            // close, then rename. Avoids a partially-written JSON
            // doc being served if Apache reads mid-write.
            $tmp = $filePath . '.tmp.' . bin2hex(random_bytes(4));
            $bytes = @file_put_contents($tmp, $json, LOCK_EX);
            if ($bytes === false) {
                return [
                    'path'   => $filePath,
                    'status' => 'failed',
                    'detail' => 'write_failed',
                ];
            }
            @chmod($tmp, self::FILE_MODE);
            if (!@rename($tmp, $filePath)) {
                // Some Windows + shared-host combos forbid atomic
                // rename across the same dir if the target exists.
                // Fall back to unlink + rename, then unlink the tmp
                // if rename still fails.
                @unlink($filePath);
                if (!@rename($tmp, $filePath)) {
                    @unlink($tmp);
                    return [
                        'path'   => $filePath,
                        'status' => 'failed',
                        'detail' => 'rename_failed',
                    ];
                }
            }
            // Drop the defence-in-depth .htaccess alongside the JSON.
            // Idempotent + best-effort — a failure here doesn't
            // demote the overall write status (the JSON file itself
            // is what matters; the .htaccess only helps on locked-
            // down hosts).
            $htaccessPath = $wellKnownDir . '/.htaccess';
            if (!is_file($htaccessPath) || @file_get_contents($htaccessPath) !== self::HTACCESS_CONTENT) {
                @file_put_contents($htaccessPath, self::HTACCESS_CONTENT, LOCK_EX);
                @chmod($htaccessPath, self::FILE_MODE);
            }
            return [
                'path'   => $filePath,
                'status' => 'written',
            ];
        } catch (\Throwable $e) {
            return [
                'path'   => $filePath,
                'status' => 'failed',
                'detail' => 'exception: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Normalise a filesystem path: collapse backslashes to forward
     * slashes (Windows local dev / IIS), strip duplicate slashes,
     * and trim trailing whitespace.
     */
    private static function normalisePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('~/{2,}~', '/', $path);
        return $path === null ? '' : $path;
    }
}
