<?php


if (!function_exists('numinix_seekmodo_log_append')) {
    $___smLogLib = __DIR__ . '/numinix_seekmodo_log_lib.php';
    if (is_file($___smLogLib)) {
        require_once $___smLogLib;
    }
}
/**
 * Sprint 12 — tenant domain lock helper (+ same-apex preview hosts).
 *
 * Read gate (`numinix_seekmodo_is_locked_out`): false when unlocked,
 * current host equals locked_domain, or current host is in
 * NUMINIX_SEEKMODO_ALLOWED_STOREFRONT_HOSTS (comma/JSON list of
 * same-apex satellites mirrored from tenant.snapshot).
 *
 * Write gates:
 *   - `numinix_seekmodo_can_index()`  — locked_domain only (+ nonprod fail-closed)
 *   - `numinix_seekmodo_can_events()` — locked_domain only (LTR / click stream)
 */
if (!function_exists('numinix_seekmodo_current_host')) {
    /**
     * Best-effort current-host accessor. Sourced from:
     *
     *   1. $_SERVER['HTTP_HOST']           (storefront request context)
     *   2. parse_url(HTTPS_CATALOG_SERVER) (cron / CLI — when defined)
     *   3. parse_url(HTTP_CATALOG_SERVER)
     *   4. parse_url(HTTPS_SERVER)         (Zen Cart configure.php default)
     *   5. parse_url(HTTP_SERVER)
     *   6. '' (gives up; caller treats empty as "no lock evaluation")
     *
     * Mirrors Numinix\Seekmodo\Client::storefrontHost so the value
     * sent on the X-Seekmodo-Storefront-Host outbound header and
     * the value compared against NUMINIX_SEEKMODO_LOCKED_DOMAIN
     * never drift apart.
     */
    function numinix_seekmodo_current_host(): string
    {
        if (class_exists('Numinix\\Seekmodo\\Client')) {
            return Numinix\Seekmodo\Client::storefrontHost();
        }
        // Manual fallback for the (rare) ordering where this file
        // loads before the Client class autoloader has run. Same
        // logic as Client::storefrontHost so any drift would be a
        // bug in one place to fix.
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
        if (strpos($raw, ':') !== false) {
            $raw = (string) strstr($raw, ':', true);
        }
        return rtrim($raw, '.');
    }
}

if (!function_exists('numinix_seekmodo_allowed_storefront_hosts')) {
    /**
     * @return list<string>
     */
    function numinix_seekmodo_allowed_storefront_hosts(): array
    {
        if (!defined('NUMINIX_SEEKMODO_ALLOWED_STOREFRONT_HOSTS')) {
            return [];
        }
        $raw = trim((string) NUMINIX_SEEKMODO_ALLOWED_STOREFRONT_HOSTS);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $out = [];
            foreach ($decoded as $entry) {
                if (is_string($entry) && $entry !== '') {
                    $out[] = strtolower(trim($entry));
                }
            }
            return array_values(array_unique($out));
        }
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = strtolower(trim((string) $p));
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('numinix_seekmodo_is_locked_out')) {
    /**
     * Returns true when Seekmodo reads should short-circuit (native
     * fallback). False when unlocked, on locked_domain, or on an
     * allowlisted same-apex preview host.
     */
    function numinix_seekmodo_is_locked_out(): bool
    {
        if (!defined('NUMINIX_SEEKMODO_LOCKED_DOMAIN')) {
            return false;
        }
        $locked = trim((string) NUMINIX_SEEKMODO_LOCKED_DOMAIN);
        if ($locked === '') {
            return false;
        }
        $current = numinix_seekmodo_current_host();
        if ($current === '') {
            // CLI / cron with no resolvable host -> don't short-circuit.
            return false;
        }
        if (strcasecmp($current, $locked) === 0) {
            return false;
        }
        foreach (numinix_seekmodo_allowed_storefront_hosts() as $allowed) {
            if (strcasecmp($current, $allowed) === 0) {
                return false;
            }
        }
        if (
            defined('NUMINIX_SEEKMODO_DEBUG')
            && strtolower((string) NUMINIX_SEEKMODO_DEBUG) === 'true'
        ) {
            $logDir = null;
            if (defined('DIR_FS_LOGS')) {
                $logDir = rtrim(DIR_FS_LOGS, '/\\');
            } elseif (defined('DIR_FS_CATALOG')) {
                $logDir = rtrim(DIR_FS_CATALOG, '/\\') . '/logs';
            }
            if ($logDir !== null && is_dir($logDir)) {
                $line = json_encode([
                    'ts' => date('c'),
                    'level' => 'debug',
                    'msg' => 'seekmodo_skip_locked_domain',
                    'current_host' => $current,
                    'locked_domain' => $locked,
                    'allowed_storefront_hosts' => numinix_seekmodo_allowed_storefront_hosts(),
                ], JSON_UNESCAPED_SLASHES);
                if ($line !== false) {
                    if (function_exists('numinix_seekmodo_log_append')) {
                        numinix_seekmodo_log_append($line);
                    } else {
                        @file_put_contents(
                            $logDir . '/numinix_seekmodo.log',
                            $line . PHP_EOL,
                            FILE_APPEND
                        );
                    }
                }
            }
        }
        return true;
    }
}

if (!function_exists('numinix_seekmodo_looks_like_nonprod')) {
    /**
     * Heuristic for non-prod host labels (mirrors WP DomainLock / Magento resolver).
     */
    function numinix_seekmodo_looks_like_nonprod(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return false;
        }
        if (strncmp($host, 'www.', 4) === 0) {
            $host = substr($host, 4);
        }
        $left = explode('.', $host)[0] ?? '';
        $blockers = [
            'dev', 'staging', 'stage', 'test', 'testing', 'qa', 'preview',
            'beta', 'preprod', 'pre-prod', 'sandbox', 'new-dev', 'newdev', 'demo',
        ];
        return in_array($left, $blockers, true) || strncmp($left, 'dev', 3) === 0;
    }
}

if (!function_exists('numinix_seekmodo_can_events')) {
    /**
     * Production-only click stream / LTR: only locked_domain may post events.
     */
    function numinix_seekmodo_can_events(): bool
    {
        $locked = defined('NUMINIX_SEEKMODO_LOCKED_DOMAIN')
            ? trim((string) NUMINIX_SEEKMODO_LOCKED_DOMAIN)
            : '';
        if ($locked === '') {
            // No lock → allow (pre-Sprint-12 / unset).
            return true;
        }
        $current = numinix_seekmodo_current_host();
        if ($current === '') {
            return false;
        }
        return strcasecmp($current, $locked) === 0;
    }
}

if (!function_exists('numinix_seekmodo_can_index')) {
    /**
     * Write-side gate. Only the canonical locked_domain may index
     * (plus nonprod fail-closed / opt-in).
     */
    function numinix_seekmodo_can_index(): bool
    {
        $current = numinix_seekmodo_current_host();
        if ($current === '') {
            return false;
        }
        $locked = defined('NUMINIX_SEEKMODO_LOCKED_DOMAIN')
            ? trim((string) NUMINIX_SEEKMODO_LOCKED_DOMAIN)
            : '';
        if (
            defined('NUMINIX_SEEKMODO_ALLOW_NONPROD_INDEXING')
            && strtolower((string) NUMINIX_SEEKMODO_ALLOW_NONPROD_INDEXING) === 'true'
        ) {
            return $locked === '' || strcasecmp($current, $locked) === 0;
        }
        if ($locked !== '') {
            if (strcasecmp($current, $locked) !== 0) {
                return false;
            }
            if (numinix_seekmodo_looks_like_nonprod($locked)) {
                return false;
            }
            return true;
        }
        if (numinix_seekmodo_looks_like_nonprod($current)) {
            return false;
        }
        return true;
    }
}
