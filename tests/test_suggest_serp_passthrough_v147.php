<?php
/**
 * v1.3.47 — suggest payload carries serp_passthrough for SERP parity.
 *
 *     php tests/test_suggest_serp_passthrough_v147.php
 */
declare(strict_types=1);

$errors = [];
$passed = 0;

require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.3.47/catalog/includes/functions/numinix_seekmodo_search_lib.php';

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

$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
$_SERVER['REMOTE_ADDR'] = '10.0.0.7';

if (!defined('NUMINIX_TYPESENSE_QUERY_BY')) {
    define('NUMINIX_TYPESENSE_QUERY_BY', 'name,model,sku,description');
}
if (!defined('NUMINIX_TYPESENSE_TYPO_TOKENS_THRESHOLD')) {
    define('NUMINIX_TYPESENSE_TYPO_TOKENS_THRESHOLD', 4);
}

$suggest = _numinix_seekmodo_build_suggest_payload('Oil Evacuation Drain Hose', 8);
assertEq('q', 'Oil Evacuation Drain Hose', $suggest['q'], $errors, $passed);
assertEq('include_products', true, $suggest['include_products'] ?? null, $errors, $passed);
assertEq('complete_multiword', true, $suggest['complete'] ?? null, $errors, $passed);
assertTruthy('has_serp_passthrough', isset($suggest['serp_passthrough']) && is_array($suggest['serp_passthrough']), $errors, $passed);

$passthrough = _numinix_seekmodo_build_serp_passthrough();
assertEq(
    'serp_passthrough_matches_builder',
    $passthrough,
    $suggest['serp_passthrough'] ?? null,
    $errors,
    $passed
);

$digit = _numinix_seekmodo_build_suggest_payload('167', 8);
assertEq('digit_complete', true, $digit['complete'] ?? null, $errors, $passed);
assertTruthy('digit_has_serp', isset($digit['serp_passthrough']), $errors, $passed);

echo "\n";
if ($errors === []) {
    echo "test_suggest_serp_passthrough_v147: {$passed} assertion(s) passed.\n";
    exit(0);
}
echo "test_suggest_serp_passthrough_v147: " . count($errors) . " failure(s), {$passed} passed.\n";
foreach ($errors as $e) {
    echo "{$e}\n";
}
exit(1);
