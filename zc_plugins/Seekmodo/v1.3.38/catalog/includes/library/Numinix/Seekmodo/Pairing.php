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
     * v1.0.13 — APCu marker recording the wall-clock UNIX time of
     * the most recent successful initial-push fork. The pair
     * callback consults this in {@see self::should_fork_initial_push}
     * so a paranoid double-pair within 6 hours doesn't queue two
     * full reindexes. Six hours is short enough that an honest
     * "I broke something, let me re-pair" workflow still gets a
     * fresh push, but long enough that the operator notices the
     * skip in the report and reaches for the explicit
     * `php numinix_seekmodo_push_catalog.php` they were going to
     * run anyway. APCu-only — no DB row, because if APCu is off
     * we just always-fork (defensive default).
     */
    public const FIRST_PUSH_THROTTLE_KEY = 'numinix.seekmodo.first_push_at';
    public const FIRST_PUSH_THROTTLE_S = 21600; // 6h

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

    /**
     * v1.0.13 — make pairing self-sufficient.
     *
     * Up to v1.0.12 the pair-callback only persisted tenant_id +
     * shared_secret. Everything else needed to make the storefront
     * actually USE the gateway — flipping out of the default `off` /
     * `shadow` modes, kicking off the initial catalog index, and
     * discovering the storefront's canonical host so the
     * locked_domain heuristic can fire — was a manual operator step
     * (run tools/install_numinix_connector.py, manually flip
     * tenant_config.mode in the gateway DB, etc.). Result: a
     * freshly-paired storefront looked "connected" but search
     * fell through to native Zen Cart LIKE indefinitely.
     *
     * activate_after_pair() closes that gap. Called from
     * numinix_seekmodo_pair_callback.php immediately after
     * persist_credentials() it does three things:
     *
     *   1. Flip NUMINIX_SEEKMODO_MODE to 'enforce' locally so the
     *      observer routes search to the gateway on the very next
     *      storefront request. This is overridden by the gateway's
     *      tenant.snapshot pull within 5 min anyway, but having the
     *      right local default avoids a "pair → 5 min of native
     *      search → suddenly enforce" surprise.
     *
     *   2. Fork a non-blocking initial catalog push. The pair
     *      callback runs from a server-to-server POST that the
     *      seekmodo.com /connect page is already waiting on with
     *      an 8s timeout — we MUST NOT do the index inline. Instead
     *      we shell out to numinix_seekmodo_push_catalog.php and
     *      detach immediately. The pusher writes its log to
     *      <docroot>/logs/numinix_seekmodo_indexer.log so an
     *      operator can `tail -f` it for progress.
     *
     *   3. Make a single best-effort GET to /v1/tenant.snapshot
     *      (via RemoteConfig::pull) so the gateway records this
     *      storefront's host in observed_domains_json. The
     *      auto-default heuristic in TenantConfig\Store::ensure
     *      will then pick that host as the locked_domain on the
     *      NEXT pull, completing the round-trip. Failures here
     *      are intentionally silent — a quiet gateway must NOT
     *      4xx a successful pair.
     *
     * Idempotent: running activate_after_pair() twice is safe.
     * The push-catalog fork is throttled by an APCu marker so a
     * paranoid double-pair doesn't queue two full reindexes.
     */
    public static function activate_after_pair(array $claims): array
    {
        $report = [
            'mode_set' => false,
            'first_push_forked' => false,
            'snapshot_pulled' => false,
            'errors' => [],
        ];

        // 1. Flip local mode to enforce. The next remote-config pull
        // (within 5 min, or sooner if an admin opens the Connect
        // page and clicks "Refresh now") will overwrite this with
        // whatever the gateway actually has. We intentionally also
        // write NUMINIX_SEEKMODO_DEFAULT_MODE so the FSM's "what
        // mode do I fall through to with no auto_state" answer is
        // also `enforce` — otherwise the AutoPromoter would observe
        // a few storefront calls in shadow and keep us there even
        // though the gateway snapshot says enforce.
        try {
            self::set_or_insert_config('NUMINIX_SEEKMODO_MODE', 'enforce');
            self::set_or_insert_config('NUMINIX_SEEKMODO_DEFAULT_MODE', 'enforce');
            $report['mode_set'] = true;
        } catch (\Throwable $e) {
            $report['errors'][] = 'mode_set: ' . $e->getMessage();
        }

        // 2. Fork an initial catalog push. The pusher inherits the
        // current docroot (we resolve the absolute path to
        // numinix_seekmodo_push_catalog.php from __FILE__ so a
        // pwd-different caller still hits the right file). On
        // Windows we'd skip this — Zen Cart doesn't run on
        // Windows in production, but the function-existence
        // checks below keep this safe even on dev hosts.
        try {
            if (self::should_fork_initial_push()) {
                $result = self::fork_initial_push();
                $report['first_push_forked'] = $result['forked'];
                if (!empty($result['err'])) {
                    $report['errors'][] = 'first_push: ' . $result['err'];
                }
            } else {
                $report['first_push_forked'] = false;
                $report['errors'][] = 'first_push: throttled (recently pushed)';
            }
        } catch (\Throwable $e) {
            $report['errors'][] = 'first_push: ' . $e->getMessage();
        }

        // 3. Best-effort tenant.snapshot pull so the gateway
        // observes this storefront's host RIGHT NOW. This is
        // what feeds the auto-default locked_domain heuristic.
        // Wrapped in try/catch because RemoteConfig requires the
        // same constants we just wrote — an autoloader oddity in
        // a freshly-paired install where constants are still
        // boot-defined values, not the new persisted values.
        try {
            $url = (string) ($claims['mcp_url'] ?? (defined('NUMINIX_SEEKMODO_URL') ? NUMINIX_SEEKMODO_URL : ''));
            $tenant = (string) ($claims['sub'] ?? (defined('NUMINIX_SEEKMODO_TENANT_ID') ? NUMINIX_SEEKMODO_TENANT_ID : ''));
            $secret = (string) ($claims['shared_secret'] ?? (defined('NUMINIX_SEEKMODO_SHARED_SECRET') ? NUMINIX_SEEKMODO_SHARED_SECRET : ''));
            if ($url !== '' && $tenant !== '' && $secret !== '' && class_exists(RemoteConfig::class)) {
                $rc = new RemoteConfig(rtrim($url, '/'), $tenant, $secret);
                // Force a fresh pull (bypass APCu) so the call
                // actually hits the gateway and triggers
                // recordObservedDomain on the auth path.
                $rc->invalidate();
                $row = $rc->pull();
                $report['snapshot_pulled'] = is_array($row);
            }
        } catch (\Throwable $e) {
            $report['errors'][] = 'snapshot_pull: ' . $e->getMessage();
        }

        return $report;
    }

    // ---- internals ------------------------------------------------------

    /**
     * v1.0.13 — true when no initial push has fired in the last
     * FIRST_PUSH_THROTTLE_S seconds (or APCu is unavailable, in
     * which case we always-fork as the defensive default).
     */
    private static function should_fork_initial_push(): bool
    {
        if (!function_exists('apcu_fetch') || !function_exists('apcu_store')) {
            return true;
        }
        $ok = false;
        $stamp = @\apcu_fetch(self::FIRST_PUSH_THROTTLE_KEY, $ok);
        if ($ok && is_numeric($stamp) && (time() - (int) $stamp) < self::FIRST_PUSH_THROTTLE_S) {
            return false;
        }
        return true;
    }

    /**
     * Merchant/admin: fork a full catalog push (same path as post-pair).
     *
     * Used by Tools → Connect → "Push catalog now" so organic sign-ups
     * can recover from an empty Typesense collection without SSH/CLI.
     * Pass $force=true to clear the APCu throttle (e.g. after flipping
     * gateway mode from off → active).
     *
     * @return array{forked: bool, err: string|null, cmd: string}
     */
    public static function request_catalog_push(bool $force = false): array
    {
        if ($force && function_exists('apcu_delete')) {
            @\apcu_delete(self::FIRST_PUSH_THROTTLE_KEY);
        }
        if (!$force && !self::should_fork_initial_push()) {
            return [
                'forked' => false,
                'err' => 'throttled (a catalog push was started recently — wait a few minutes or check logs/numinix_seekmodo_indexer.log)',
                'cmd' => '',
            ];
        }
        return self::fork_initial_push();
    }

    /**
     * v1.0.13 — fork the standalone catalog pusher detached from
     * the request. Stamps the APCu throttle marker on success so a
     * back-to-back re-pair doesn't queue a second reindex.
     *
     * Strategy:
     *   - Resolve the absolute path to numinix_seekmodo_push_catalog.php
     *     from this file's location (we're in
     *     ".../catalog/includes/library/Numinix/Seekmodo/Pairing.php"
     *     and the pusher is ".../catalog/numinix_seekmodo_push_catalog.php").
     *   - Resolve a PHP CLI binary (PHP_BINARY under FPM often points
     *     at php-fpm — prefer PATH / EasyApache / CloudLinux siblings
     *     matching the running major.minor).
     *   - shell_exec a `nohup php <pusher> --ack-quota >> <log> 2>&1 &`
     *     so the child detaches and the parent returns immediately.
     *   - On Windows / hosts without nohup we degrade to a no-op
     *     and report it. The operator can still run the pusher
     *     manually from the Connect admin page or CLI.
     *
     * Returns ['forked' => bool, 'err' => string|null, 'cmd' => string].
     *
     * @return array{forked: bool, err: string|null, cmd: string}
     */
    private static function fork_initial_push(): array
    {
        $pusherPath = realpath(__DIR__ . '/../../../../numinix_seekmodo_push_catalog.php');
        if ($pusherPath === false || !is_file($pusherPath)) {
            return ['forked' => false, 'err' => 'pusher script not found at expected path', 'cmd' => ''];
        }

        // Resolve a PHP CLI binary. Under php-fpm, PHP_BINARY often
        // points at the FPM binary which cannot run a script — prefer
        // the matching EasyApache / distro CLI.
        $php = self::resolve_php_binary();
        if ($php === '') {
            $hintPath = '/usr/bin/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
            return [
                'forked' => false,
                'err' => 'no php binary found in $PATH (host PHP '
                    . PHP_VERSION
                    . '). Create includes/extra_configures/numinix_seekmodo_php_binary.php under your catalog root (/shop/) with: '
                    . "<?php define('NUMINIX_SEEKMODO_PHP_BINARY', '" . $hintPath . "'); "
                    . '(or /opt/cpanel/ea-php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '/root/usr/bin/php). '
                    . 'Then Refresh snapshot and Push catalog now again. Or upgrade to the latest Seekmodo zip.',
                'cmd' => '',
            ];
        }

        $logDir = defined('DIR_FS_LOGS')
            ? rtrim(DIR_FS_LOGS, '/\\')
            : (defined('DIR_FS_CATALOG') ? rtrim(DIR_FS_CATALOG, '/\\') . '/logs' : sys_get_temp_dir());
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/numinix_seekmodo_indexer.log';

        // Pass the storefront host through to the child so its
        // `numinix_seekmodo_is_locked_out` gate sees the same host
        // we just observed (avoids the auto-default heuristic
        // racing the child to set locked_domain).
        $hostHeader = '';
        if (!empty($_SERVER['HTTP_HOST'])) {
            $hostHeader = (string) $_SERVER['HTTP_HOST'];
        } elseif (defined('HTTPS_CATALOG_SERVER')) {
            $parsed = parse_url((string) HTTPS_CATALOG_SERVER, PHP_URL_HOST);
            if (is_string($parsed)) {
                $hostHeader = $parsed;
            }
        }
        $envPrefix = $hostHeader !== ''
            ? 'HTTP_HOST=' . escapeshellarg($hostHeader) . ' '
            : '';

        // Detached fork. `nohup` survives the request finishing,
        // `setsid` reparents to init so a php-fpm worker recycle
        // doesn't kill the indexer. Use disown-by-redirect (`>/dev/null
        // 2>&1 < /dev/null &`) so PHP doesn't keep its file
        // descriptors tethered. `--ack-quota` stamps the manual
        // reindex intent so Essential-plan quota preflight does not
        // skip an admin-initiated Push catalog now.
        $cmd = $envPrefix . 'nohup setsid '
            . escapeshellarg($php) . ' '
            . escapeshellarg($pusherPath)
            . ' --ack-quota'
            . ' >> ' . escapeshellarg($logFile)
            . ' 2>&1 < /dev/null &';

        if (!function_exists('shell_exec')) {
            return ['forked' => false, 'err' => 'shell_exec disabled in php.ini', 'cmd' => $cmd];
        }
        if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
            return ['forked' => false, 'err' => 'Windows host (skipping fork — not a prod target)', 'cmd' => $cmd];
        }

        try {
            @shell_exec($cmd);
        } catch (\Throwable $e) {
            return ['forked' => false, 'err' => 'shell_exec threw: ' . $e->getMessage(), 'cmd' => $cmd];
        }

        // Stamp the throttle marker. Best-effort.
        if (function_exists('apcu_store')) {
            @\apcu_store(self::FIRST_PUSH_THROTTLE_KEY, (string) time(), self::FIRST_PUSH_THROTTLE_S);
        }
        return ['forked' => true, 'err' => null, 'cmd' => $cmd];
    }

    /**
     * Best-effort PHP CLI binary resolution for forking the catalog
     * pusher from an FPM / admin request.
     *
     * Order:
     *   1. Explicit override NUMINIX_SEEKMODO_PHP_BINARY — trusted even
     *      when open_basedir blocks is_file() on /opt/cpanel/... paths
     *      (common on shared hosts; NS-26042 Cannapot).
     *   2. `command -v php` / `which php` when shell_exec works.
     *   3. Derive CLI from PHP_BINARY (php-fpm → php sibling).
     *   4. Version-matched EasyApache / CloudLinux paths, validated via
     *      is_file OR a shell `-r` probe when open_basedir hides them.
     *   5. Well-known distro + multi-version fallbacks + `ls` glob.
     *
     * Returns '' if nothing resolves — the caller turns that into a
     * clean "fork skipped" report rather than crashing the pair.
     */
    private static function resolve_php_binary(): string
    {
        self::maybe_load_php_binary_override();

        if (defined('NUMINIX_SEEKMODO_PHP_BINARY')) {
            $override = trim((string) NUMINIX_SEEKMODO_PHP_BINARY);
            // Trust the operator override even when is_file() is false —
            // open_basedir often blocks visibility of /opt/cpanel/... while
            // shell_exec can still invoke that same path.
            if ($override !== '' && self::looks_like_php_cli_path($override)) {
                return $override;
            }
        }

        if (function_exists('shell_exec')) {
            foreach (['command -v php 2>/dev/null', 'which php 2>/dev/null'] as $probe) {
                $which = trim((string) @shell_exec($probe));
                // Some hosts return multiple lines; take the first path.
                if (strpos($which, "\n") !== false) {
                    $which = trim(explode("\n", $which)[0]);
                }
                if ($which !== '' && self::is_usable_php_cli($which)) {
                    return $which;
                }
            }
        }

        $fromBinary = self::cli_from_php_binary();
        if ($fromBinary !== '') {
            return $fromBinary;
        }

        $mm = PHP_MAJOR_VERSION . PHP_MINOR_VERSION; // e.g. "83" for 8.3.x
        $candidates = [
            '/opt/cpanel/ea-php' . $mm . '/root/usr/bin/php',
            '/opt/alt/php' . $mm . '/usr/bin/php',
            '/opt/alt/php' . $mm . '/usr/bin/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            '/usr/bin/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            '/usr/local/bin/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/cpanel/ea-php84/root/usr/bin/php',
            '/opt/cpanel/ea-php83/root/usr/bin/php',
            '/opt/cpanel/ea-php82/root/usr/bin/php',
            '/opt/cpanel/ea-php81/root/usr/bin/php',
            '/opt/cpanel/ea-php80/root/usr/bin/php',
            '/opt/alt/php84/usr/bin/php',
            '/opt/alt/php83/usr/bin/php',
            '/opt/alt/php82/usr/bin/php',
            '/opt/alt/php81/usr/bin/php',
        ];
        foreach ($candidates as $c) {
            if (self::is_usable_php_cli($c)) {
                return $c;
            }
        }

        if (function_exists('shell_exec')) {
            $glob = trim((string) @shell_exec(
                'ls -1 /opt/cpanel/ea-php*/root/usr/bin/php /opt/alt/php*/usr/bin/php 2>/dev/null | sort -V | tail -n 1'
            ));
            if ($glob !== '' && self::is_usable_php_cli($glob)) {
                return $glob;
            }
            // Last resort: shell-probe version-matched EasyApache path even
            // when open_basedir made is_file() return false.
            $ea = '/opt/cpanel/ea-php' . $mm . '/root/usr/bin/php';
            if (self::shell_probe_php_cli($ea)) {
                return $ea;
            }
            $alt = '/opt/alt/php' . $mm . '/usr/bin/php';
            if (self::shell_probe_php_cli($alt)) {
                return $alt;
            }
        }

        return '';
    }

    /**
     * Admin Connect → Push runs in the admin SAPI, which does not load
     * catalog includes/extra_configures/*.php. Merchants typically drop
     * NUMINIX_SEEKMODO_PHP_BINARY under the catalog root (/shop/), so
     * pull that file in before resolving the CLI binary.
     */
    private static function maybe_load_php_binary_override(): void
    {
        if (defined('NUMINIX_SEEKMODO_PHP_BINARY')) {
            return;
        }
        $candidates = [];
        if (defined('DIR_FS_CATALOG') && is_string(DIR_FS_CATALOG) && DIR_FS_CATALOG !== '') {
            $candidates[] = rtrim(str_replace('\\', '/', DIR_FS_CATALOG), '/')
                . '/includes/extra_configures/numinix_seekmodo_php_binary.php';
        }
        // Admin often defines DIR_FS_CATALOG as the catalog root already;
        // also try sibling ../includes when admin is nested.
        if (defined('DIR_FS_ADMIN') && is_string(DIR_FS_ADMIN) && DIR_FS_ADMIN !== '') {
            $candidates[] = rtrim(str_replace('\\', '/', DIR_FS_ADMIN), '/')
                . '/../includes/extra_configures/numinix_seekmodo_php_binary.php';
        }
        foreach ($candidates as $path) {
            $real = @realpath($path);
            if ($real === false || !is_file($real)) {
                continue;
            }
            include_once $real;
            if (defined('NUMINIX_SEEKMODO_PHP_BINARY')) {
                return;
            }
        }
    }

    /**
     * Path shape check (no filesystem access) — used for operator overrides
     * under open_basedir.
     */
    private static function looks_like_php_cli_path(string $path): bool
    {
        if ($path === '' || strpos($path, "\0") !== false) {
            return false;
        }
        $base = basename(str_replace('\\', '/', $path));
        if (stripos($base, 'php-fpm') !== false || stripos($base, 'php-cgi') !== false) {
            return false;
        }
        return (bool) preg_match('/^php(\d+(\.\d+)*)?$/i', $base)
            || (bool) preg_match('#/(bin|sbin)/php(\d+(\.\d+)*)?$#i', str_replace('\\', '/', $path));
    }

    /**
     * True when $path looks like a real PHP CLI.
     * Tries is_file first; if open_basedir blocks it, falls back to a
     * short shell_exec probe (`php -r`).
     */
    private static function is_usable_php_cli(string $path): bool
    {
        if ($path === '' || !self::looks_like_php_cli_path($path)) {
            return false;
        }
        if (@is_file($path)) {
            if (@is_executable($path)) {
                return true;
            }
            $base = basename($path);
            return (bool) preg_match('/^php(\d+(\.\d+)*)?$/i', $base);
        }
        return self::shell_probe_php_cli($path);
    }

    /**
     * Confirm a CLI binary works even when is_file() is blocked.
     */
    private static function shell_probe_php_cli(string $path): bool
    {
        if (!function_exists('shell_exec') || !self::looks_like_php_cli_path($path)) {
            return false;
        }
        $out = trim((string) @shell_exec(
            escapeshellarg($path) . ' -r ' . escapeshellarg('echo SEEKMODO_OK;') . ' 2>/dev/null'
        ));
        return $out === 'SEEKMODO_OK';
    }

    /**
     * Derive a CLI sibling from PHP_BINARY when running under FPM.
     * Example: /opt/cpanel/ea-php83/root/usr/sbin/php-fpm
     *       → /opt/cpanel/ea-php83/root/usr/bin/php
     */
    private static function cli_from_php_binary(): string
    {
        $bin = defined('PHP_BINARY') ? (string) PHP_BINARY : '';
        if ($bin === '') {
            return '';
        }
        if (self::is_usable_php_cli($bin)) {
            return $bin;
        }

        $normalized = str_replace('\\', '/', $bin);
        $guesses = [];

        // sbin/php-fpm → bin/php
        if (strpos($normalized, '/sbin/') !== false) {
            $guesses[] = str_replace('/sbin/', '/bin/', $normalized);
        }
        $guesses[] = preg_replace('/php-fpm.*$/i', 'php', $normalized) ?? '';
        $guesses[] = preg_replace('/php-cgi.*$/i', 'php', $normalized) ?? '';

        // /opt/cpanel/ea-php83/... → force classic CLI path
        if (preg_match('~/ea-php(\d+)/~', $normalized, $m)) {
            $guesses[] = '/opt/cpanel/ea-php' . $m[1] . '/root/usr/bin/php';
        }
        if (preg_match('~/alt/php(\d+)/~', $normalized, $m)) {
            $guesses[] = '/opt/alt/php' . $m[1] . '/usr/bin/php';
        }

        foreach ($guesses as $g) {
            $g = trim((string) $g);
            if ($g !== '' && self::is_usable_php_cli($g)) {
                return $g;
            }
        }
        return '';
    }

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
            // Friendly, actionable error message — this is what the
            // merchant sees in the seekmodo.com pairing dialog when
            // their host doesn't ship the sodium extension. Avoid
            // the generic "install php-sodium" message; on cPanel
            // (the most common Zen Cart host) the package name is
            // ea-php<NN>-php-sodium and a yum install + automatic
            // FPM restart is all that's needed.
            $phpVersion = explode('.', PHP_VERSION);
            $phpMajorMinor = ($phpVersion[0] ?? '8') . ($phpVersion[1] ?? '1');
            throw new \RuntimeException(
                'PHP sodium extension is required but not loaded on this storefront (PHP '
                . PHP_VERSION . '). On cPanel/EasyApache hosts (most common): '
                . '`yum install -y ea-php' . $phpMajorMinor . '-php-sodium` as root, '
                . 'then PHP-FPM will be restarted automatically. On Debian/Ubuntu: '
                . '`apt-get install -y php' . $phpVersion[0] . '.' . ($phpVersion[1] ?? '1')
                . '-sodium && systemctl restart php' . $phpVersion[0] . '.' . ($phpVersion[1] ?? '1')
                . '-fpm`. Then click Connect again from the admin Tools menu.'
            );
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
