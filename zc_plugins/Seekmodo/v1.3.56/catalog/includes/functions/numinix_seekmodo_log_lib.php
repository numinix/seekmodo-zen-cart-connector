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
