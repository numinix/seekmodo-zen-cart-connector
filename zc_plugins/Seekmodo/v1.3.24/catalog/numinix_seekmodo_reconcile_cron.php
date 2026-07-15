<?php
/**
 * CLI entry to reconcile Seekmodo managed cron blocks.
 */
declare(strict_types=1);

$catalogRoot = realpath(__DIR__ . '/../../../../');
if ($catalogRoot === false || !is_dir($catalogRoot . '/includes')) {
    fwrite(STDERR, "ERROR: cannot resolve catalog docroot\n");
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

if (!class_exists(\Numinix\Seekmodo\CronReconciler::class)) {
    fwrite(STDERR, "CronReconciler not loaded\n");
    exit(3);
}

$result = (new \Numinix\Seekmodo\CronReconciler())->reconcile();
if (!empty($result['path'])) {
    fwrite(STDOUT, 'reconciled: ' . $result['path'] . "\n");
}
if (!empty($result['notice'])) {
    fwrite(STDERR, $result['notice'] . "\n");
}
exit(!empty($result['ok']) ? 0 : 1);
