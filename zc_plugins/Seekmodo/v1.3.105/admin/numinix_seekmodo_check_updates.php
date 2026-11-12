<?php
/**
 * Sprint 4 PR 3: daily update-check CLI runner.
 *
 * Cron line is printed by `tools/install_redline_connector.py` in
 * the seekmodo monorepo.  Runs once a day; when a new connector
 * version is published it sets the `NUMINIX_SEEKMODO_UPDATE_NOTICE`
 * configuration row to the latest version string, which the admin
 * shell renders as a top-bar one-liner linking to the Updates page.
 *
 * Designed to run as a CLI from cron (no HTTP context).  Bootstraps
 * Zen Cart minimally so the Numinix\Seekmodo\UpdateClient can read
 * its APCu cache and the global $db handle is available for the
 * notice write.
 *
 * Exit codes:
 *   0  ran cleanly (whether or not an update was found)
 *   1  bootstrap failure (couldn't load Zen Cart admin core)
 *   2  manifest unreachable for the entire fetch window
 */

// CGI-safe: never fwrite(STDERR) on the HTTP reject path — that
// produced "undefined constant STDERR" floods when bots probed this URL.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg' && !(defined('IS_ADMIN_FLAG') && IS_ADMIN_FLAG)) {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo "FATAL: numinix_seekmodo_check_updates.php must run from CLI cron\n";
    exit(1);
}

$___smLogLib = dirname(__DIR__) . '/catalog/includes/functions/numinix_seekmodo_log_lib.php';
if (is_file($___smLogLib)) {
    require_once $___smLogLib;
}

// Bootstrap Zen Cart admin context the same way an admin page does
// so $db and the Numinix autoloader are wired up.  The `application_top.php`
// guard `IS_ADMIN_FLAG` is normally set by Zen Cart's admin index;
// declare it here so application_top sees us as an authorised
// admin-context request.
if (!defined('IS_ADMIN_FLAG')) {
    define('IS_ADMIN_FLAG', true);
}
chdir(__DIR__);
$bootstrap = __DIR__ . '/includes/application_top.php';
if (!is_file($bootstrap)) {
    if (function_exists("numinix_seekmodo_stderr")) { numinix_seekmodo_stderr("ERROR: cannot locate Zen Cart admin bootstrap at {$bootstrap}\n"); } elseif (defined("STDERR") && is_resource(STDERR)) { fwrite(STDERR, "ERROR: cannot locate Zen Cart admin bootstrap at {$bootstrap}\n"); }
    exit(1);
}
require $bootstrap;

use Numinix\Seekmodo\UpdateClient;

global $db;

$client = UpdateClient::fromRunningPlugin();
$envelope = $client->pullManifest(true);
if ($envelope === null || !isset($envelope['entry']) || !is_array($envelope['entry'])) {
    if (function_exists("numinix_seekmodo_stderr")) { numinix_seekmodo_stderr("ERROR: manifest unreachable\n"); } elseif (defined("STDERR") && is_resource(STDERR)) { fwrite(STDERR, "ERROR: manifest unreachable\n"); }
    exit(2);
}
$entry = $envelope['entry'];
$latest = isset($entry['latest']) ? 'v' . ltrim((string)$entry['latest'], 'v') : '';

$pluginVersionDir = realpath(__DIR__ . '/../');
$currentVersion = 'unknown';
if (is_string($pluginVersionDir) && $pluginVersionDir !== '' && is_file($pluginVersionDir . '/manifest.php')) {
    $localManifest = include $pluginVersionDir . '/manifest.php';
    if (is_array($localManifest) && isset($localManifest['pluginVersion'])) {
        $currentVersion = (string)$localManifest['pluginVersion'];
    }
}

$cmp = $client->compareVersions($currentVersion, $latest);

$noticeKey = 'NUMINIX_SEEKMODO_UPDATE_NOTICE';
$noticeValue = ($cmp < 0 && $latest !== '') ? $latest : '';

if (isset($db) && method_exists($db, 'Execute')) {
    $row = $db->Execute(
        "SELECT configuration_id FROM " . TABLE_CONFIGURATION
        . " WHERE configuration_key = '" . zen_db_input($noticeKey) . "' LIMIT 1"
    );
    if ($row !== null && !$row->EOF) {
        $db->Execute(
            "UPDATE " . TABLE_CONFIGURATION
            . " SET configuration_value = '" . zen_db_input($noticeValue) . "',"
            . " last_modified = NOW()"
            . " WHERE configuration_key = '" . zen_db_input($noticeKey) . "'"
        );
    } else {
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION
            . " (configuration_title, configuration_key, configuration_value,"
            . " configuration_description, configuration_group_id, sort_order, date_added)"
            . " VALUES ("
            . " 'Update notice (latest version)',"
            . " '" . zen_db_input($noticeKey) . "',"
            . " '" . zen_db_input($noticeValue) . "',"
            . " 'Set by numinix_seekmodo_check_updates.php cron when a new connector release is published. Empty when the local install is up to date.',"
            . " 6, 90, NOW())"
        );
    }
}

echo "current=" . $currentVersion . " latest=" . $latest . " update_available="
    . ($cmp < 0 ? 'yes' : 'no') . "\n";

if (class_exists(\Numinix\Seekmodo\CronReconciler::class)) {
    try {
        (new \Numinix\Seekmodo\CronReconciler())->reconcile();
    } catch (\Throwable $e) {
        if (function_exists('numinix_seekmodo_stderr')) { numinix_seekmodo_stderr('cron reconcile: ' . $e->getMessage() . "\n"); } elseif (defined('STDERR') && is_resource(STDERR)) { fwrite(STDERR, 'cron reconcile: ' . $e->getMessage() . "\n"); }
    }
}

exit(0);
