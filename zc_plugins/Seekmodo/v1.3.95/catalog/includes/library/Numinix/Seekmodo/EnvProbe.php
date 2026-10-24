<?php

namespace Numinix\Seekmodo;

/**
 * Read-only environment probe.
 *
 * Returns a structured map describing the PHP extension state of the
 * host running the connector. Pure PHP — no I/O, DB, or network.
 * Cheap enough to call on every admin page render (the sodium check
 * in `numinix_seekmodo_connect.php` has the same posture).
 *
 * Two consumers:
 *   - `admin/numinix_seekmodo_connect.php` — drives the "APCu missing"
 *     warning banner and the Diagnostics panel so a self-hosted
 *     merchant who's never seen our docs can self-serve a fix.
 *   - `RemoteConfig::push()` (v1.0.19+) — ships the env map up to
 *     the gateway on every FSM snapshot push, so support can see
 *     degraded tenants from `admin.seekmodo.com` without waiting
 *     for the merchant to look at their Connect page.
 *
 * PHP 7.4-compatible: only baseline `function_exists` /
 * `extension_loaded` / `phpversion`. No enums, readonly, constructor
 * promotion, or `mixed` returns — v1.0.19 still ships to the
 * ea-php74 host floor (KIP).
 */
final class EnvProbe
{
    /**
     * Severity tiers for the diagnostics rows. Map a check to one of
     * these and the admin UI renders green / yellow / red / gray.
     *
     *   - SEV_OK   passing, no action needed
     *   - SEV_WARN recommended fix (degraded behaviour without it,
     *              but the storefront still works)
     *   - SEV_FAIL required fix (a real feature is broken; sodium is
     *              the canonical example — pairing fails without it)
     *   - SEV_INFO informational, not a check (PHP version, SAPI)
     */
    public const SEV_OK   = 'ok';
    public const SEV_WARN = 'warn';
    public const SEV_FAIL = 'fail';
    public const SEV_INFO = 'info';

    /**
     * Snapshot the current PHP environment.
     *
     * Keys returned:
     *   - php_version       string  (e.g. "8.1.34")
     *   - php_sapi          string  (e.g. "fpm-fcgi")
     *   - sodium_loaded     bool
     *   - apcu_loaded       bool   (extension present AND enabled)
     *   - apcu_extension    bool   (extension present regardless of apc.enabled)
     *   - opcache_enabled   bool
     *   - curl_loaded       bool
     *   - openssl_loaded    bool
     *   - mysqli_loaded     bool
     *   - intl_loaded       bool   (used for IDN -> ASCII canonicalisation)
     *   - json_loaded       bool   (PHP 8 always; defensive for stripped 7.4 builds)
     *   - server_time_unix  int    (lets the gateway compute clock skew when this map is pushed)
     *
     * @return array<string, mixed>
     */
    public static function current(): array
    {
        $apcuExt = extension_loaded('apcu') && function_exists('apcu_fetch');
        // apcu_enabled() is the canonical "is the cache live for this
        // request?" check; on hosts that compile apcu without it, fall
        // back to the apc.enabled INI flag. CLI hosts often have the
        // extension loaded but apc.enable_cli=0, which is fine — the
        // banner and gateway alike treat "loaded but disabled" as
        // degraded, same as "missing".
        $apcuEnabled = $apcuExt
            && (function_exists('apcu_enabled')
                ? (bool) @apcu_enabled()
                : (bool) ini_get('apc.enabled'));
        // Some ea-php74 FPM pools report apcu_enabled()=false even when
        // the user cache is live (apc.enabled=0 in php.ini but APCu
        // still serves web requests). A one-shot store/fetch probe is the
        // only reliable signal — same posture as the admin Diagnostics
        // panel, which merchants trust over the INI flag.
        if (!$apcuEnabled && $apcuExt && function_exists('apcu_store')) {
            $probeKey = '__seekmodo_apcu_probe_' . (function_exists('getmypid') ? getmypid() : 0);
            if (@apcu_store($probeKey, 1, 5) && apcu_fetch($probeKey) === 1) {
                $apcuEnabled = true;
                if (function_exists('apcu_delete')) {
                    @apcu_delete($probeKey);
                }
            }
        }
        $opcacheEnabled = false;
        if (function_exists('opcache_get_status')) {
            $status = @opcache_get_status(false);
            $opcacheEnabled = is_array($status)
                && !empty($status['opcache_enabled']);
        }
        if (!$opcacheEnabled) {
            // CLI / disabled-status fallback — the INI flags still
            // mean opcache is compiled in even if get_status() is
            // restricted by opcache.restrict_api.
            $opcacheEnabled = (bool) ini_get('opcache.enable')
                || (bool) ini_get('opcache.enable_cli');
        }
        return [
            'php_version'      => PHP_VERSION,
            'php_sapi'         => PHP_SAPI,
            'sodium_loaded'    => function_exists('sodium_crypto_sign_verify_detached'),
            'apcu_loaded'      => $apcuEnabled,
            'apcu_extension'   => $apcuExt,
            'opcache_enabled'  => $opcacheEnabled,
            'curl_loaded'      => function_exists('curl_init'),
            'openssl_loaded'   => function_exists('openssl_verify'),
            'mysqli_loaded'    => extension_loaded('mysqli'),
            'intl_loaded'      => function_exists('idn_to_ascii'),
            'json_loaded'      => function_exists('json_encode'),
            'server_time_unix' => time(),
        ];
    }

    /**
     * Build a list of diagnostic rows for the admin Connect page.
     * Each row is `{label, value, severity, hint}`. Hints are
     * one-liners — the goal is "merchant copy-pastes this and the
     * problem goes away" without leaving the admin page.
     *
     * @param array<string, mixed>|null $env Optionally pre-collected env map; defaults to a fresh `self::current()`.
     * @return array<int, array{label: string, value: string, severity: string, hint: string}>
     */
    public static function diagnostics(?array $env = null): array
    {
        $env = $env ?? self::current();
        // Derive cPanel/Debian package names from the host's PHP
        // version so the hints are copy-pasteable on the host the
        // merchant is actually running. ea-php81-pecl-apcu is the
        // canonical EasyApache 4 package name (yum/dnf); apt uses
        // php8.1-apcu instead.
        $phpV = explode('.', (string) ($env['php_version'] ?? PHP_VERSION));
        $maj  = $phpV[0] ?? '8';
        $min  = $phpV[1] ?? '1';
        $eaSodium  = "ea-php{$maj}{$min}-php-sodium";
        $eaApcu    = "ea-php{$maj}{$min}-pecl-apcu";
        $eaCurl    = "ea-php{$maj}{$min}-php-curl";
        $eaMysql   = "ea-php{$maj}{$min}-php-mysqlnd";
        $debSodium = "php{$maj}.{$min}-sodium";
        $debApcu   = "php{$maj}.{$min}-apcu";
        $debCurl   = "php{$maj}.{$min}-curl";
        $debMysql  = "php{$maj}.{$min}-mysql";
        $rows = [];
        $rows[] = [
            'label'    => 'PHP version',
            'value'    => (string) ($env['php_version'] ?? PHP_VERSION),
            'severity' => self::SEV_INFO,
            'hint'     => '',
        ];
        $rows[] = [
            'label'    => 'SAPI',
            'value'    => (string) ($env['php_sapi'] ?? PHP_SAPI),
            'severity' => self::SEV_INFO,
            'hint'     => '',
        ];
        $rows[] = [
            'label'    => 'sodium (required)',
            'value'    => !empty($env['sodium_loaded']) ? 'loaded' : 'missing',
            'severity' => !empty($env['sodium_loaded']) ? self::SEV_OK : self::SEV_FAIL,
            'hint'     => !empty($env['sodium_loaded']) ? '' :
                "Pairing will fail without sodium. Install: yum install -y {$eaSodium} (cPanel) or apt-get install -y {$debSodium} (Debian/Ubuntu), then refresh.",
        ];
        $apcuValue = !empty($env['apcu_loaded'])
            ? 'loaded'
            : (!empty($env['apcu_extension'])
                ? 'loaded but disabled (apc.enabled=0)'
                : 'missing');
        $rows[] = [
            'label'    => 'APCu (recommended)',
            'value'    => $apcuValue,
            'severity' => !empty($env['apcu_loaded']) ? self::SEV_OK : self::SEV_WARN,
            'hint'     => !empty($env['apcu_loaded']) ? '' :
                "Without APCu the connector reaches the gateway on every admin page render and every storefront search burst, instead of riding a 5-minute cache. Install: yum install -y {$eaApcu} (cPanel) or apt-get install -y {$debApcu} (Debian/Ubuntu). On managed hosting, ask your host to enable the apcu PHP extension and set apc.enabled=1.",
        ];
        $rows[] = [
            'label'    => 'OPcache',
            'value'    => !empty($env['opcache_enabled']) ? 'enabled' : 'disabled',
            'severity' => !empty($env['opcache_enabled']) ? self::SEV_OK : self::SEV_WARN,
            'hint'     => !empty($env['opcache_enabled']) ? '' :
                'OPcache typically speeds up Zen Cart by 3-5x. Enable opcache.enable=1 in your PHP ini and restart PHP-FPM.',
        ];
        $rows[] = [
            'label'    => 'cURL (required)',
            'value'    => !empty($env['curl_loaded']) ? 'loaded' : 'missing',
            'severity' => !empty($env['curl_loaded']) ? self::SEV_OK : self::SEV_FAIL,
            'hint'     => !empty($env['curl_loaded']) ? '' :
                "The connector cannot talk to the gateway without cURL. Install: yum install -y {$eaCurl} (cPanel) or apt-get install -y {$debCurl} (Debian/Ubuntu).",
        ];
        $rows[] = [
            'label'    => 'OpenSSL (required)',
            'value'    => !empty($env['openssl_loaded']) ? 'loaded' : 'missing',
            'severity' => !empty($env['openssl_loaded']) ? self::SEV_OK : self::SEV_FAIL,
            'hint'     => !empty($env['openssl_loaded']) ? '' :
                'HMAC signing + HTTPS to the gateway both require OpenSSL. Rebuild PHP with --with-openssl, or install your distribution\'s ext-openssl package.',
        ];
        $rows[] = [
            'label'    => 'mysqli (required)',
            'value'    => !empty($env['mysqli_loaded']) ? 'loaded' : 'missing',
            'severity' => !empty($env['mysqli_loaded']) ? self::SEV_OK : self::SEV_FAIL,
            'hint'     => !empty($env['mysqli_loaded']) ? '' :
                "Zen Cart's database driver. Install: yum install -y {$eaMysql} (cPanel) or apt-get install -y {$debMysql} (Debian/Ubuntu).",
        ];
        $rows[] = [
            'label'    => 'intl / IDN (recommended)',
            'value'    => !empty($env['intl_loaded']) ? 'loaded' : 'missing',
            'severity' => !empty($env['intl_loaded']) ? self::SEV_OK : self::SEV_WARN,
            'hint'     => !empty($env['intl_loaded']) ? '' :
                'Used to canonicalize IDN domains. Without it, non-ASCII storefront hostnames may fail to match the locked-domain check.',
        ];
        return $rows;
    }

    /**
     * Locked-domain status — compares NUMINIX_SEEKMODO_LOCKED_DOMAIN
     * to HTTPS_CATALOG_SERVER, then HTTP_CATALOG_SERVER, then the Zen
     * Cart configure.php HTTPS_SERVER / HTTP_SERVER pair (same chain
     * as Client::storefrontHost). Returns `[severity, code, detail]`:
     *
     *   - [SEV_OK,   'matches',  'locked: <host>']
     *   - [SEV_WARN, 'mismatch', 'locked: <a>; current: <b>']  (split-brain — operator should reconcile)
     *   - [SEV_INFO, 'unset',    'no lock configured ...']
     *   - [SEV_INFO, 'unknown',  'locked: <host>; storefront base URL unavailable from this context']
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function lockedDomainStatus(): array
    {
        $locked = defined('NUMINIX_SEEKMODO_LOCKED_DOMAIN')
            ? trim((string) constant('NUMINIX_SEEKMODO_LOCKED_DOMAIN'))
            : '';
        $catalog = '';
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
            if ($val !== '') {
                $catalog = $val;
                break;
            }
        }
        $catalogHost = '';
        if ($catalog !== '') {
            $h = parse_url($catalog, PHP_URL_HOST);
            if (is_string($h) && $h !== '') {
                $catalogHost = strtolower($h);
            }
        }
        if ($locked === '') {
            return [
                self::SEV_INFO,
                'unset',
                'no lock configured (the gateway will auto-default on the first storefront request)',
            ];
        }
        if ($catalogHost === '') {
            return [
                self::SEV_INFO,
                'unknown',
                'locked: ' . $locked . '; storefront base URL unavailable from this context',
            ];
        }
        if (strtolower($locked) === $catalogHost) {
            return [self::SEV_OK, 'matches', 'locked: ' . $locked];
        }
        return [
            self::SEV_WARN,
            'mismatch',
            'locked: ' . $locked . '; current: ' . $catalogHost,
        ];
    }
}
