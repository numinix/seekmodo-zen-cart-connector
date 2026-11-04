<?php
/**
 * Guard: proxy-first suggest ships a file shim (not a suggest/ directory)
 * so Apache does not 301 POST /v1/suggest and drop the SDK body.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$versions = [];
foreach (glob($root . '/zc_plugins/Seekmodo/v1.3.*', GLOB_ONLYDIR) ?: [] as $dir) {
    $versions[] = basename($dir);
}
usort($versions, 'version_compare');
$version = (string) end($versions);
$base = $root . '/zc_plugins/Seekmodo/' . $version . '/catalog';

$failures = 0;

$suggestPhp = $base . '/v1/suggest.php';
if (!is_file($suggestPhp)) {
    fwrite(STDERR, "FAIL: missing {$version}/catalog/v1/suggest.php\n");
    $failures++;
} else {
    $php = (string) file_get_contents($suggestPhp);
    if (strpos($php, "__DIR__ . '/../includes/application_top.php'") === false) {
        fwrite(STDERR, "FAIL: suggest.php missing catalog/v1 application_top candidate\n");
        $failures++;
    } else {
        fwrite(STDOUT, "OK: {$version}/catalog/v1/suggest.php\n");
    }
}

$ht = $base . '/v1/.htaccess';
if (!is_file($ht)) {
    fwrite(STDERR, "FAIL: missing {$version}/catalog/v1/.htaccess\n");
    $failures++;
} else {
    $body = (string) file_get_contents($ht);
    if (strpos($body, 'suggest.php') === false) {
        fwrite(STDERR, "FAIL: v1/.htaccess missing suggest.php rewrite\n");
        $failures++;
    } else {
        fwrite(STDOUT, "OK: {$version}/catalog/v1/.htaccess\n");
    }
}

$legacyDir = $base . '/v1/suggest';
if (is_dir($legacyDir)) {
    fwrite(STDERR, "FAIL: legacy directory {$version}/catalog/v1/suggest/ must not ship\n");
    $failures++;
} else {
    fwrite(STDOUT, "OK: no legacy v1/suggest/ directory\n");
}

$installer = (string) file_get_contents($root . '/zc_plugins/Seekmodo/' . $version . '/Installer/ScriptedInstaller.php');
foreach (['suggest.dirbak', "v1/suggest.php", "'/suggest'"] as $needle) {
    if (strpos($installer, $needle) === false) {
        fwrite(STDERR, "FAIL: ScriptedInstaller missing {$needle}\n");
        $failures++;
    } else {
        fwrite(STDOUT, "OK: ScriptedInstaller references {$needle}\n");
    }
}

$sync = (string) file_get_contents($root . '/tools/sync_catalog_shims.php');
foreach (['v1/suggest.php', 'suggest.dirbak'] as $needle) {
    if (strpos($sync, $needle) === false) {
        fwrite(STDERR, "FAIL: sync_catalog_shims.php does not handle {$needle}\n");
        $failures++;
    } else {
        fwrite(STDOUT, "OK: sync_catalog_shims.php handles {$needle}\n");
    }
}

exit($failures === 0 ? 0 : 1);
