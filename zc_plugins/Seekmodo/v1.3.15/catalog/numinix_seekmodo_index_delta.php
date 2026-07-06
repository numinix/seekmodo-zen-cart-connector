<?php
/**
 * numinix_seekmodo_index_delta.php
 *
 * Delta indexer tick — flushes pending delete tombstones and upserts
 * dirty product ids queued by admin saves / plugin releases.
 *
 * Cron (every 15 minutes, offset per tenant when reconciler is active):
 *
 *   */15 * * * * <user> cd <docroot> && \
 *     /usr/bin/php zc_plugins/Seekmodo/v1.3.0/catalog/numinix_seekmodo_index_delta.php \
 *     >>/var/log/numinix_seekmodo_delta.log 2>&1
 */
declare(strict_types=1);

$catalogRoot = realpath(__DIR__ . '/../../../../');
if ($catalogRoot === false || !is_dir($catalogRoot . '/includes')) {
    fwrite(STDERR, "ERROR: cannot resolve catalog docroot from " . __FILE__ . "\n");
    exit(2);
}
chdir($catalogRoot);

require './includes/configure.php';
ini_set('include_path', DIR_FS_CATALOG . PATH_SEPARATOR . ini_get('include_path'));
chdir(DIR_FS_CATALOG);
require_once 'includes/application_top.php';

$opts = getopt('', ['verbose', 'batch::']);
$verbose = isset($opts['verbose']);
$batchLimit = isset($opts['batch']) ? (int) $opts['batch'] : 50;
if ($batchLimit <= 0 || $batchLimit > 200) {
    $batchLimit = 50;
}

function _delta_log(string $level, string $msg): void
{
    if (!defined('STDERR')) {
        define('STDERR', fopen('php://stderr', 'wb'));
    }
    fwrite(STDERR, sprintf('[%s] %s index_delta: %s', date('c'), strtoupper($level), $msg) . "\n");
}

if (!function_exists('numinix_seekmodo_enabled') || !numinix_seekmodo_enabled()) {
    _delta_log('error', 'connector not enabled or not paired');
    exit(4);
}
if (function_exists('numinix_seekmodo_mode') && numinix_seekmodo_mode() === 'off') {
    _delta_log('info', 'mode=off — skipping');
    exit(0);
}
if (function_exists('numinix_seekmodo_is_locked_out') && numinix_seekmodo_is_locked_out()) {
    _delta_log('error', 'locked out on this host');
    exit(5);
}

$deleteFlush = ['flushed' => 0, 'failed' => 0];
if (function_exists('numinix_seekmodo_flush_pending_catalog_deletes')) {
    $deleteFlush = numinix_seekmodo_flush_pending_catalog_deletes();
}

$dirtyFlush = ['flushed' => 0, 'failed' => 0, 'remaining' => 0];
if (function_exists('numinix_seekmodo_flush_dirty_products')) {
    $dirtyFlush = numinix_seekmodo_flush_dirty_products($batchLimit);
}

if ($verbose) {
    _delta_log(
        'info',
        sprintf(
            'deletes flushed=%d failed=%d dirty flushed=%d failed=%d remaining=%d',
            (int) $deleteFlush['flushed'],
            (int) $deleteFlush['failed'],
            (int) $dirtyFlush['flushed'],
            (int) $dirtyFlush['failed'],
            (int) $dirtyFlush['remaining']
        )
    );
}

if (class_exists(\Numinix\Seekmodo\AutoPromoter::class)) {
    try {
        (new \Numinix\Seekmodo\AutoPromoter())->pushSnapshot('delta_tick');
    } catch (\Throwable $e) {
        // best-effort
    }
}

exit(0);
