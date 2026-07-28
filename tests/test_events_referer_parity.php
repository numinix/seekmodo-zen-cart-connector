<?php
/**
 * Rec 3 — events builders stamp HTTP_REFERER when present.
 *
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

$eventsLib = __DIR__ . '/../zc_plugins/Seekmodo/v1.3.33/catalog/includes/functions/numinix_seekmodo_events_lib.php';
assert_true(is_file($eventsLib), 'events_lib present in v1.3.33');

$src = file_get_contents($eventsLib);
assert_true($src !== false && $src !== '', 'events_lib readable');

// Three builders must each include the HTTP_REFERER stamp (search parity).
$count = preg_match_all(
    '/if\s*\(\s*!empty\(\s*\$_SERVER\[[\'"]HTTP_REFERER[\'"]\]\s*\)\s*\)\s*\{\s*\$event\[[\'"]referer[\'"]\]/',
    (string) $src,
    $m
);
assert_true($count >= 3, "events_lib stamps referer in >=3 builders (got $count)");

$manifest = __DIR__ . '/../zc_plugins/Seekmodo/v1.3.33/manifest.php';
$man = include $manifest;
assert_true(is_array($man) && ($man['pluginVersion'] ?? '') === 'v1.3.33', 'manifest pluginVersion v1.3.33');

echo "\n$passed passed, $errors failed\n";
exit($errors === 0 ? 0 : 1);
