<?php
/**
 * Regression test for products_id / numeric model lookup routing.
 *
 *     php tests/test_products_id_search.php
 */
declare(strict_types=1);

$errors = [];
$passed = 0;

require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.3.43/catalog/includes/functions/numinix_seekmodo_search_lib.php';

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

echo "Case 1. parse_products_id_query\n";
assertEq('single_id', [1898], _numinix_seekmodo_parse_products_id_query('1898'), $errors, $passed);
assertEq('short_id', [167], _numinix_seekmodo_parse_products_id_query('167'), $errors, $passed);
assertEq('multi_id', [167, 1898], _numinix_seekmodo_parse_products_id_query('167,1898'), $errors, $passed);
assertEq('text_query_null', null, _numinix_seekmodo_parse_products_id_query('wine glass'), $errors, $passed);
assertEq('alpha_suffix_null', null, _numinix_seekmodo_parse_products_id_query('1898a'), $errors, $passed);

echo "Case 2. build_search_payload products_id + model/sku OR (RED-1862)\n";
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
$_SERVER['REMOTE_ADDR'] = '10.0.0.7';

$expectedSingle = '(id:=`1898` || model:=`1898` || sku:=`1898`)';
$payload = _numinix_seekmodo_build_search_payload(['keyword' => '1898']);
assertEq('search_q_keeps_digits', '1898', $payload['q'], $errors, $passed);
assertEq('search_filter_by', $expectedSingle, $payload['filter_by'], $errors, $passed);

// RED-1862: digit-only model number must OR model/sku, not exclusive id.
$expectedRed = '(id:=`4826` || model:=`4826` || sku:=`4826`)';
$payload = _numinix_seekmodo_build_search_payload(['keyword' => '4826']);
assertEq('red_model_q_keeps_digits', '4826', $payload['q'], $errors, $passed);
assertEq('red_model_filter_or', $expectedRed, $payload['filter_by'], $errors, $passed);

$expectedMulti = '(id:=[`167`,`1898`] || model:=[`167`,`1898`] || sku:=[`167`,`1898`])';
$payload = _numinix_seekmodo_build_search_payload(['keyword' => '167,1898']);
assertEq('search_multi_filter', $expectedMulti, $payload['filter_by'], $errors, $passed);
assertEq('search_multi_q', '167,1898', $payload['q'], $errors, $passed);

$payload = _numinix_seekmodo_build_search_payload(['keyword' => 'mug']);
assertEq('text_search_unchanged', 'mug', $payload['q'], $errors, $passed);
assertTruthy('text_search_no_pid_filter', !isset($payload['filter_by']), $errors, $passed);

echo "Case 3. build_suggest_payload products_id + model/sku OR\n";
$expectedSuggest = '(id:=`167` || model:=`167` || sku:=`167`)';
$suggest = _numinix_seekmodo_build_suggest_payload('167', 8);
assertEq('suggest_q_keeps_digits', '167', $suggest['q'], $errors, $passed);
assertEq('suggest_filter_by', $expectedSuggest, $suggest['filter_by'], $errors, $passed);
assertEq('suggest_complete', true, $suggest['complete'], $errors, $passed);

echo "\n";
if ($errors === []) {
    echo "test_products_id_search: {$passed} assertion(s) passed.\n";
    exit(0);
}
echo "test_products_id_search: " . count($errors) . " failure(s), {$passed} passed.\n";
exit(1);
