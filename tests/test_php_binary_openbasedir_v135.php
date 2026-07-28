<?php
declare(strict_types=1);

$errors = 0;
$passed = 0;

function assert_true(bool $cond, string $msg): void
{
    global $errors, $passed;
    if ($cond) {
        $passed++;
        echo "PASS: $msg\n";
    } else {
        $errors++;
        echo "FAIL: $msg\n";
    }
}

$p = __DIR__ . '/../zc_plugins/Seekmodo/v1.3.35/catalog/includes/library/Numinix/Seekmodo/Pairing.php';
$src = (string) file_get_contents($p);
assert_true(is_file($p), 'Pairing v1.3.35 present');
assert_true(strpos($src, 'shell_probe_php_cli') !== false, 'shell probe helper');
assert_true(strpos($src, 'looks_like_php_cli_path') !== false, 'path shape helper');
assert_true(strpos($src, 'open_basedir') !== false, 'mentions open_basedir');
$man = include __DIR__ . '/../zc_plugins/Seekmodo/v1.3.35/manifest.php';
assert_true(($man['pluginVersion'] ?? '') === 'v1.3.35', 'manifest v1.3.35');

echo "\n$passed passed, $errors failed\n";
exit($errors === 0 ? 0 : 1);
