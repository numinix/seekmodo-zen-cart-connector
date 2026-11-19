<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$versions = glob($root . '/zc_plugins/Seekmodo/v1.3.*', GLOB_ONLYDIR) ?: [];
usort($versions, static function ($a, $b) {
    return version_compare(basename($a), basename($b));
});
$latest = end($versions);
$shim = $latest . '/catalog/v1/suggest.php';
if (!is_file($shim)) {
    fwrite(STDERR, "FAIL: missing $shim\n");
    exit(1);
}
$src = (string) file_get_contents($shim);
$methodPos = strpos($src, "REQUEST_METHOD'] = 'GET'");
$inputPos = strpos($src, '$rawInput = file_get_contents');
if ($methodPos === false || $inputPos === false || $inputPos > $methodPos) {
    fwrite(STDERR, "FAIL: php://input must be captured before CSRF method rewrite in $shim\n");
    exit(1);
}
fwrite(STDOUT, "PASS: " . basename($latest) . " reads php://input before REQUEST_METHOD flip\n");