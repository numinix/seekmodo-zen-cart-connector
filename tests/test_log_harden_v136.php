<?php
/**
 * Unit tests for numinix_seekmodo_log_lib.php (v1.3.36 log harden).
 */
declare(strict_types=1);

$lib = dirname(__DIR__) . '/zc_plugins/Seekmodo/v1.3.36/catalog/includes/functions/numinix_seekmodo_log_lib.php';
if (!is_file($lib)) {
    fwrite(STDERR, "missing log lib\n");
    exit(1);
}
require_once $lib;

$tmp = sys_get_temp_dir() . '/seekmodo_log_test_' . getmypid();
@mkdir($tmp, 0777, true);
define('DIR_FS_LOGS', $tmp);

$path = $tmp . '/numinix_seekmodo.log';
@unlink($path);
@unlink($path . '.1');

numinix_seekmodo_log_append('{"n":1}');
numinix_seekmodo_log_append('{"n":2}');
if (!is_file($path)) {
    fwrite(STDERR, "log not created\n");
    exit(1);
}
$first = file_get_contents($path);
if (substr_count($first, "\n") < 2) {
    fwrite(STDERR, "expected 2 lines\n");
    exit(1);
}

// Force rotation with tiny max
file_put_contents($path, str_repeat('x', 100));
numinix_seekmodo_log_append('{"n":3}', 50);
if (!is_file($path . '.1')) {
    fwrite(STDERR, "rotation missing .1\n");
    exit(1);
}
$after = file_get_contents($path);
if (strpos($after, '{"n":3}') === false) {
    fwrite(STDERR, "rotated log missing new line\n");
    exit(1);
}

// CGI-safe stderr should not fatal
numinix_seekmodo_stderr("ok\n");

echo "OK\n";
exit(0);
