<?php

/**
 * Sprint 12 — tenant domain lock helper.
 *
 * Gives the storefront a single source of truth for "should this
 * request short-circuit Seekmodo and fall back to native search
 * because the current host doesn't match the tenant's locked
 * storefront domain?".
 *
 * Used by every hot-path entry in this plugin
 * (search / typeahead / events / indexer) to early-return before
 * any gateway call is even built. Returning true triggers the
 * same native-fallback path that's already battle-tested for
 * MODE=off / MODE=shadow / circuit-breaker-open, so there's no
 * new failure mode to reason about: a misconfigured lock just
 * makes the storefront behave as if the connector were disabled.
 *
 * The lock value is mirrored from the gateway's
 * numinix_mcp_tenant_config.locked_domain column into
 * NUMINIX_SEEKMODO_LOCKED_DOMAIN by
 * Numinix\Seekmodo\RemoteConfig::writeThrough() every 5 minutes.
 * An operator-driven "Refresh now" from the Zen Cart admin
 * Connect page (Numinix\Seekmodo\RemoteConfig::invalidate())
 * forces an immediate re-pull when faster propagation is needed.
 *
 * The current host is the same canonical shape the gateway uses
 * in Store::canonicalizeHost: lowercased, port-stripped, trailing-
 * dot-stripped. We *don't* run the gateway's full canonicalizer
 * here (which IDN-to-ASCIIs and rejects IP literals) because
 * the gateway re-canonicalizes the locked_domain value before
 * sending it down the snapshot — so by the time the value lands
 * in NUMINIX_SEEKMODO_LOCKED_DOMAIN it's already ASCII / lowercased
 * / port-stripped. A simple strcasecmp is enough.
 */
if (!function_exists('numinix_seekmodo_current_host')) {
    /**
     * Best-effort current-host accessor. Sourced from:
     *
     *   1. $_SERVER['HTTP_HOST']           (storefront request context)
     *   2. parse_url(HTTPS_CATALOG_SERVER) (cron / CLI context)
     *   3. '' (gives up; caller treats empty as "no lock evaluation")
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
        } elseif (defined('HTTPS_CATALOG_SERVER')) {
            $parsed = parse_url((string) HTTPS_CATALOG_SERVER, PHP_URL_HOST);
            if (is_string($parsed)) {
                $raw = $parsed;
            }
        } elseif (defined('HTTP_CATALOG_SERVER')) {
            $parsed = parse_url((string) HTTP_CATALOG_SERVER, PHP_URL_HOST);
            if (is_string($parsed)) {
                $raw = $parsed;
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

if (!function_exists('numinix_seekmodo_is_locked_out')) {
    /**
     * Returns true when the gateway-managed lock is set AND the
     * connector's current host does NOT match. Otherwise false.
     *
     * Special-cases:
     *   - Lock not set (NULL / empty)   -> false (never short-circuit).
     *   - Current host unresolvable     -> false (CLI / cron without a
     *                                     HTTP_HOST and without an
     *                                     HTTPS_CATALOG_SERVER define
     *                                     can't classify the request; we
     *                                     err on the side of "do the
     *                                     work" rather than silently
     *                                     dropping an indexer run.)
     *   - Otherwise                     -> strcasecmp() against the
     *                                     mirrored lock value.
     *
     * Emits a `seekmodo_skip_locked_domain` debug log line (gated
     * on NUMINIX_SEEKMODO_DEBUG=true) on every lockout so operators
     * can grep `logs/numinix_seekmodo.log` on dev hosts when
     * troubleshooting an unexpected fallback.
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
            // The indexer is the main caller that hits this path and
            // skipping it silently would be a worse outcome than
            // re-indexing a tenant whose host moved.
            return false;
        }
        if (strcasecmp($current, $locked) === 0) {
            return false;
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
                ], JSON_UNESCAPED_SLASHES);
                if ($line !== false) {
                    @file_put_contents(
                        $logDir . '/numinix_seekmodo.log',
                        $line . PHP_EOL,
                        FILE_APPEND
                    );
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

if (!function_exists('numinix_seekmodo_can_index')) {
    /**
     * Write-side gate (v1.1.7 / WP v0.5.3 pattern).
     *
     * Blocks indexing from non-prod hosts when the locked domain is prod,
     * and blocks self-referential non-prod locks unless the operator
     * explicitly opts in via NUMINIX_SEEKMODO_ALLOW_NONPROD_INDEXING=true.
     */
    function numinix_seekmodo_can_index(): bool
    {
        if (
            function_exists('numinix_seekmodo_is_locked_out')
            && numinix_seekmodo_is_locked_out()
        ) {
            return false;
        }
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
        if ($locked !== '' && numinix_seekmodo_looks_like_nonprod($locked) === false
            && numinix_seekmodo_looks_like_nonprod($current)
        ) {
            return false;
        }
        if ($locked === '' && numinix_seekmodo_looks_like_nonprod($current)) {
            return false;
        }
        return true;
    }
}
