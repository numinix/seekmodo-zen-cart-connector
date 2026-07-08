<?php
/**
 * Pins view-all SERP → gateway skip_merchandising_redirect passthrough.
 *
 *     php tests/test_skip_merchandising_redirect_payload.php
 */
declare(strict_types=1);

$errors = [];
$passed = 0;

require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.3.20/catalog/includes/functions/numinix_seekmodo_search_lib.php';

function resetGet(): void
{
    $_GET = [];
}

/** @param mixed $expected @param mixed $actual */
function assertEq(string $label, $expected, $actual, array &$errors, int &$passed): void
{
    if ($expected === $actual) {
        $passed++;
        echo "  PASS {$label}\n";
        return;
    }
    $msg = "  FAIL {$label}: expected " . var_export($expected, true)
        . ", got " . var_export($actual, true);
    $errors[] = $msg;
    echo $msg . "\n";
}

function assertTruthy(string $label, bool $cond, array &$errors, int &$passed): void
{
    if ($cond) {
        $passed++;
        echo "  PASS {$label}\n";
        return;
    }
    $errors[] = "  FAIL {$label}";
    echo "  FAIL {$label}\n";
}

echo "Case 1. skip_merchandising_redirect from GET marker\n";
resetGet();
$_GET['seekmodo_skip_category_redirect'] = '1';
$payload = _numinix_seekmodo_build_search_payload(['keyword' => 'pint', 'search_in_description' => '1']);
assertEq('skip_flag_set_from_get', true, $payload['skip_merchandising_redirect'] ?? null, $errors, $passed);

echo "Case 2. skip_merchandising_redirect from explicit params\n";
resetGet();
$payload = _numinix_seekmodo_build_search_payload([
    'keyword' => 'pint',
    'skip_merchandising_redirect' => true,
]);
assertEq('skip_flag_set_from_params', true, $payload['skip_merchandising_redirect'] ?? null, $errors, $passed);

echo "Case 3. normal SERP omits skip flag\n";
resetGet();
$payload = _numinix_seekmodo_build_search_payload(['keyword' => 'pint']);
assertTruthy('skip_flag_absent_without_marker', !array_key_exists('skip_merchandising_redirect', $payload), $errors, $passed);

echo "\n";
if ($errors === []) {
    echo "test_skip_merchandising_redirect_payload: {$passed} assertion(s) passed.\n";
    exit(0);
}
echo "test_skip_merchandising_redirect_payload: " . count($errors) . " failure(s), {$passed} passed.\n";
exit(1);
