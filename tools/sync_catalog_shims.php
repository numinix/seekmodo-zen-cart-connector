<?php
/**
 * Copy Seekmodo catalog-root HTTP shims from the newest vendored plugin
 * version into the live catalog root.
 *
 * Zen Cart's zc_plugins/.htaccess blocks direct HTTP access to PHP under
 * zc_plugins/, so endpoints like numinix_seekmodo_suggest.php must live at
 * the catalog root. Deploy pipelines that rsync the git tree do not always
 * refresh these copies — run this after connector upgrades.
 *
 *   cd /path/to/catalog
 *   php tools/sync_catalog_shims.php
 *
 * Tenant repos may wrap this (e.g. KIP's kip_sync_seekmodo_catalog_shims.php).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("CLI only\n");
}

$catalogRoot = realpath(__DIR__ . '/..');
if ($catalogRoot === false) {
    fwrite(STDERR, "FATAL: catalog root missing\n");
    exit(2);
}

$versions = [];
foreach (glob($catalogRoot . '/zc_plugins/Seekmodo/v*', GLOB_ONLYDIR) ?: [] as $dir) {
    $versions[] = basename($dir);
}
if ($versions === []) {
    fwrite(STDERR, "FATAL: no Seekmodo plugin versions found\n");
    exit(2);
}
usort($versions, 'version_compare');
$version = (string) end($versions);
$srcDir = $catalogRoot . '/zc_plugins/Seekmodo/' . $version . '/catalog';

$shims = [
    'numinix_seekmodo_suggest.php',
    'numinix_seekmodo_click.php',
    'numinix_seekmodo_recommend.php',
    'numinix_seekmodo_pair_callback.php',
    'numinix_seekmodo_forget_me.php',
];

$ok = true;
foreach ($shims as $file) {
    $src = $srcDir . '/' . $file;
    $dst = $catalogRoot . '/' . $file;
    if (!is_file($src)) {
        fwrite(STDERR, "WARN: missing source {$src}\n");
        continue;
    }
    if (!copy($src, $dst)) {
        fwrite(STDERR, "ERROR: failed to copy {$file}\n");
        $ok = false;
        continue;
    }
    fwrite(STDOUT, "synced {$file} from {$version}\n");
}

exit($ok ? 0 : 2);
