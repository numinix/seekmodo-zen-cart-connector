<?php
/**
 * Guard: proxy-first suggest ships DirectorySlash-Off htaccess so
 * Apache does not 301 POST /v1/suggest and drop the SDK body.
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

$required = [
    'v1/suggest/index.php',
    'v1/suggest/.htaccess',
    'v1/.htaccess',
];

$failures = 0;
foreach ($required as $rel) {
    $path = $base . '/' . $rel;
    if (!is_file($path)) {
        fwrite(STDERR, "FAIL: missing {$version}/catalog/{$rel}\n");
        $failures++;
        continue;
    }
    if (substr($rel, -10) === '.htaccess') {
        $body = (string) file_get_contents($path);
        if (stripos($body, 'DirectorySlash Off') === false) {
            fwrite(STDERR, "FAIL: {$rel} missing DirectorySlash Off\n");
            $failures++;
            continue;
        }
    }
    fwrite(STDOUT, "OK: {$version}/catalog/{$rel}\n");
}

$sync = (string) file_get_contents($root . '/tools/sync_catalog_shims.php');
foreach (['v1/suggest/.htaccess', 'v1/.htaccess'] as $needle) {
    if (strpos($sync, $needle) === false) {
        fwrite(STDERR, "FAIL: sync_catalog_shims.php does not sync {$needle}\n");
        $failures++;
    } else {
        fwrite(STDOUT, "OK: sync_catalog_shims.php syncs {$needle}\n");
    }
}

exit($failures === 0 ? 0 : 1);
