<?php
/**
 * Shared logging + CLI I/O helpers for Seekmodo connector scripts.
 *
 * Two production failure modes this file closes:
 *
 * 1. STDERR under cgi-fcgi / php-fpm: `fwrite(STDERR, …)` when STDERR is
 *    not a defined stream emits "undefined constant STDERR" + fwrite
 *    type warnings into Zen Cart myDEBUG / PHP error logs on every HTTP
 *    hit. Bots probing admin cron entry points (or site wrappers like
 *    tools/seekmodo_index.php) turned that into multi-hundred-GB floods
 *    on KIP production.
 *
 * 2. Unbounded append to logs/numinix_seekmodo.log: every shadow search
 *    and transport warning appends forever. Cap + rotate so a busy
 *    shadow tenant cannot fill the disk again.
 */

if (!function_exists('numinix_seekmodo_stderr')) {
    /**
     * Write to STDERR without assuming the constant exists (CGI-safe).
     */
    function numinix_seekmodo_stderr(string $msg): void
    {
        if (defined('STDERR') && is_resource(STDERR)) {
            fwrite(STDERR, $msg);
            return;
        }
        $stream = @fopen('php://stderr', 'wb');
        if (is_resource($stream)) {
            fwrite($stream, $msg);
            fclose($stream);
            return;
        }
        // Last resort: never call error_log in a loop from hot paths;
        // echo only when headers allow (CLI / early bootstrap).
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            echo $msg;
        }
    }
}

if (!function_exists('numinix_seekmodo_require_cli')) {
    /**
     * Reject non-CLI invocations without touching STDERR.
     *
     * @return never
     */
    function numinix_seekmodo_require_cli(string $scriptName = 'this script'): void
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return;
        }
        if (!headers_sent()) {
            header('HTTP/1.1 403 Forbidden');
            header('Content-Type: text/plain; charset=UTF-8');
        }
        echo 'FATAL: ' . $scriptName . ' must run from CLI (PHP_SAPI=' . PHP_SAPI . ")\n";
        exit(2);
    }
}

if (!function_exists('numinix_seekmodo_log_append')) {
    /**
     * Append one line to logs/numinix_seekmodo.log with size rotation.
     *
     * Default cap: 32 MiB. When exceeded, rotate to
     * numinix_seekmodo.log.1 (single generation) then start fresh.
     */
    function numinix_seekmodo_log_append(string $line, int $maxBytes = 33554432): void
    {
        $logDir = '';
        if (defined('DIR_FS_LOGS')) {
            $logDir = rtrim((string) DIR_FS_LOGS, '/\\');
        } elseif (defined('DIR_FS_CATALOG')) {
            $logDir = rtrim((string) DIR_FS_CATALOG, '/\\') . '/logs';
        }
        if ($logDir === '' || !is_dir($logDir)) {
            return;
        }
        $path = $logDir . '/numinix_seekmodo.log';
        if ($maxBytes > 0 && is_file($path)) {
            $size = @filesize($path);
            if (is_int($size) && $size >= $maxBytes) {
                $rotated = $path . '.1';
                @unlink($rotated);
                @rename($path, $rotated);
            }
        }
        if ($line !== '' && substr($line, -1) !== "\n") {
            $line .= "\n";
        }
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }
}

if (!class_exists('NuminixSeekmodoNullQueryCache', false)) {
    /**
     * Drop-in for Zen Cart's QueryCache during long CLI indexer runs.
     *
     * Core QueryCache (and storefront overrides like zc_optimizer_query_cache)
     * keep every unique SELECT mysqli_result in `$queries[$sql]` for the
     * whole request. Keyset pages never reuse SQL, so a full catalog push
     * retains every products+description page plus per-product category
     * lookups until PHP OOMs (STRIN DEV: 512MB died around 4.3k of 12.5k
     * SKUs even after v1.3.56 pagination).
     */
    final class NuminixSeekmodoNullQueryCache
    {
        public function cache($query, $result)
        {
            return false;
        }

        public function getFromCache($query)
        {
            return null;
        }

        public function inCache($query)
        {
            return false;
        }

        public function isSelectStatement($q)
        {
            return false;
        }

        public function reset($query)
        {
            return false;
        }
    }
}

if (!function_exists('numinix_seekmodo_disable_query_cache')) {
    /**
     * Drain then replace the global QueryCache so indexer SQL cannot
     * accumulate. Safe no-op when queryCache is not loaded.
     */
    function numinix_seekmodo_disable_query_cache(): void
    {
        global $queryCache;
        if (isset($queryCache) && is_object($queryCache) && method_exists($queryCache, 'reset')) {
            $queryCache->reset('ALL');
        }
        $queryCache = new NuminixSeekmodoNullQueryCache();
    }
}

if (!function_exists('numinix_seekmodo_release_query_cache')) {
    /**
     * Drop indexer QueryCache entries accumulated since the last drain.
     *
     * No-op unless the global was swapped to NuminixSeekmodoNullQueryCache.
     * Shared doc builders also run on the storefront SERP; they must not
     * flush Zen Cart's live QueryCache mid-request.
     */
    function numinix_seekmodo_release_query_cache(): void
    {
        global $queryCache;
        if (!isset($queryCache) || !is_object($queryCache)) {
            return;
        }
        if (!$queryCache instanceof NuminixSeekmodoNullQueryCache) {
            return;
        }
        if (method_exists($queryCache, 'reset')) {
            $queryCache->reset('ALL');
        }
    }
}

if (!function_exists('numinix_seekmodo_release_query_result')) {
    /**
     * Drop PHP-buffered rows on a queryFactoryResult.
     *
     * mysqli_free_result is only safe after the indexer replaced
     * $queryCache with NuminixSeekmodoNullQueryCache. Zen Cart's live
     * QueryCache stores the same mysqli_result, and storefront helpers
     * such as docs_for_ids() must not free it (getFromCache then
     * mysqli_data_seek's a destroyed resource).
     *
     * @param mixed $result
     */
    function numinix_seekmodo_release_query_result($result): void
    {
        if (!is_object($result)) {
            return;
        }
        global $queryCache;
        $canFreeMysqli = isset($queryCache)
            && is_object($queryCache)
            && $queryCache instanceof NuminixSeekmodoNullQueryCache;
        if ($canFreeMysqli && isset($result->resource) && $result->resource instanceof \mysqli_result) {
            @mysqli_free_result($result->resource);
            $result->resource = null;
        }
        if (isset($result->result) && is_array($result->result)) {
            $result->result = [];
        }
        if (isset($result->fields) && is_array($result->fields)) {
            $result->fields = [];
        }
    }
}
