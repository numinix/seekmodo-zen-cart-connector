<?php
/**
 * v1.3.34 — Push catalog PHP CLI discovery (NS-26042).
 *
 * Static + reflection checks; no live shell required.
 * Self-contained: exit 0 on pass.
 */
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

$pairing = __DIR__ . '/../zc_plugins/Seekmodo/v1.3.34/catalog/includes/library/Numinix/Seekmodo/Pairing.php';
assert_true(is_file($pairing), 'Pairing.php present in v1.3.34');

$src = (string) file_get_contents($pairing);
assert_true(strpos($src, 'ea-php83') !== false, 'resolver mentions ea-php83');
assert_true(strpos($src, 'NUMINIX_SEEKMODO_PHP_BINARY') !== false, 'supports PHP_BINARY override constant');
assert_true(strpos($src, 'cli_from_php_binary') !== false, 'derives CLI from PHP_BINARY');
assert_true(strpos($src, '--ack-quota') !== false, 'admin fork passes --ack-quota');
assert_true(
    strpos($src, "/opt/cpanel/ea-php' . \$mm") !== false
        || strpos($src, 'ea-php\' . $mm') !== false
        || preg_match("/ea-php'\s*\.\s*\$mm/", $src) === 1,
    'version-matched ea-php{MM} candidate'
);

$manifest = include __DIR__ . '/../zc_plugins/Seekmodo/v1.3.34/manifest.php';
assert_true(is_array($manifest) && ($manifest['pluginVersion'] ?? '') === 'v1.3.34', 'manifest pluginVersion v1.3.34');

$versionTxt = trim((string) file_get_contents(__DIR__ . '/../zc_plugins/Seekmodo/v1.3.34/VERSION.txt'));
assert_true($versionTxt === '1.3.34', 'VERSION.txt is 1.3.34');

echo "\n$passed passed, $errors failed\n";
exit($errors === 0 ? 0 : 1);
