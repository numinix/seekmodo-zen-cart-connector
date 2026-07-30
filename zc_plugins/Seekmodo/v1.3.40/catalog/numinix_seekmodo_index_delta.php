<?php
/**
 * numinix_seekmodo_index_delta.php
 *
 * Delta indexer tick — flushes pending delete tombstones and upserts
 * dirty product ids queued by admin saves / plugin releases.
 *
 * Cron (every 15 minutes, offset per tenant when reconciler is active).
 * Schedule minutes as 0,15,30,45 (equivalent to every-15-min step syntax)
 * so the example cannot terminate this docblock early.
 *
 *   0,15,30,45 * * * * <user> cd <docroot> && \
 *     /usr/bin/php zc_plugins/Seekmodo/v1.3.28/catalog/numinix_seekmodo_index_delta.php \
 *     >>/var/log/numinix_seekmodo_delta.log 2>&1
 */
declare(strict_types=1);

$___smLogLib = __DIR__ . '/includes/functions/numinix_seekmodo_log_lib.php';
if (is_file($___smLogLib)) {
    require_once $___smLogLib;
}
if (function_exists('numinix_seekmodo_require_cli')) {
    numinix_seekmodo_require_cli('numinix_seekmodo_index_delta.php');
}

$catalogRoot = realpath(__DIR__ . '/../../../../');
if ($catalogRoot === false || !is_dir($catalogRoot . '/includes')) {
    if (function_exists("numinix_seekmodo_stderr")) { numinix_seekmodo_stderr("ERROR: cannot resolve catalog docroot from " . __FILE__ . "\n"); }
    exit(2);
}
chdir($catalogRoot);

require './includes/configure.php';
ini_set('include_path', DIR_FS_CATALOG . PATH_SEPARATOR . ini_get('include_path'));
chdir(DIR_FS_CATALOG);
require_once 'includes/application_top.php';

// v1.3.24 — ensure zc_plugins catalog init when auto_loaders did not merge.
$ensureHelpers = [
    __DIR__ . '/includes/functions/numinix_seekmodo_ensure_plugin_init.php',
];
if (defined('DIR_FS_CATALOG') && is_string(DIR_FS_CATALOG) && DIR_FS_CATALOG !== '') {
    $ensureHelpers = array_merge(
        $ensureHelpers,
        glob(rtrim(str_replace('\\', '/', DIR_FS_CATALOG), '/') . '/zc_plugins/Seekmodo/v*/catalog/includes/functions/numinix_seekmodo_ensure_plugin_init.php') ?: []
    );
}
$ensureHelpers = array_values(array_unique(array_filter($ensureHelpers, 'is_file')));
usort($ensureHelpers, 'strnatcmp');
$ensureHelpers = array_reverse($ensureHelpers);
if ($ensureHelpers !== []) {
    require_once $ensureHelpers[0];
}
if (function_exists('numinix_seekmodo_ensure_plugin_init')) {
    numinix_seekmodo_ensure_plugin_init();
}

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
    if (function_exists('numinix_seekmodo_stderr')) {
        numinix_seekmodo_stderr(sprintf('[%s] %s index_delta: %s', date('c'), strtoupper($level), $msg) . "\n");
    }
}

if (!function_exists('numinix_seekmodo_enabled') || !numinix_seekmodo_enabled()) {
    _delta_log('error', 'connector not enabled or not paired');
    exit(4);
}
if (function_exists('numinix_seekmodo_mode') && numinix_seekmodo_mode() === 'off') {
    _delta_log('info', 'mode=off — skipping');
    exit(0);
}
if (function_exists('numinix_seekmodo_can_index') && !numinix_seekmodo_can_index()) {
    _delta_log('error', 'index writes blocked on this host (canonical / nonprod gate)');
    exit(5);
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
