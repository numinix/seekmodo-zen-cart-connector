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
