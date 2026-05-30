<?php
/**
 * Regression tests for the v1.0.3 filter-mapping registry and the
 * `filter_by` builder it powers.
 *
 * Loads from the v1.0.3 plugin tree directly — no PHPUnit dependency.
 * Mirrors the structure of tests/test_search_payload.php.
 *
 *     php services/zen-cart-connector/tests/test_filter_registry.php
 *
 * Exits 0 on pass, non-zero on fail.
 */

declare(strict_types=1);

$errors = [];
$passed = 0;

require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.0.3/catalog/includes/functions/numinix_seekmodo_search_lib.php';

function ftReset(): void
{
    $_GET = [];
    numinix_seekmodo_reset_filter_mappings();
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

// =================================================================
// Case 1. Default mappings exist out of the box.
// =================================================================
echo "Case 1. default mappings\n";

ftReset();
$reg = numinix_seekmodo_filter_mappings();
assertTruthy('brand_default_registered', isset($reg['brand']), $errors, $passed);
assertEq('brand_default_field', 'brand', $reg['brand']['field'] ?? '', $errors, $passed);
assertEq('type_default_field', 'p_type', $reg['type']['field'] ?? '', $errors, $passed);
assertEq('capacity_default_field', 'capacity', $reg['capacity_by_lbs']['field'] ?? '', $errors, $passed);

// =================================================================
// Case 2. Re-registering replaces.
// =================================================================
echo "Case 2. override default mapping\n";

ftReset();
numinix_seekmodo_register_filter_mapping('type', 'type', ['coerce' => 'int_list']);
$reg = numinix_seekmodo_filter_mappings();
assertEq('override_type_field', 'type', $reg['type']['field'], $errors, $passed);

// =================================================================
// Case 3. build_filter_by — no params -> null.
// =================================================================
echo "Case 3. build_filter_by with no params\n";

ftReset();
assertEq('empty_returns_null', null, numinix_seekmodo_build_filter_by(), $errors, $passed);

// =================================================================
// Case 4. Single-value int filter.
// =================================================================
echo "Case 4. single-value int_list\n";

ftReset();
$_GET['brand'] = '12';
assertEq('single_brand_value', 'brand:=[12]', numinix_seekmodo_build_filter_by(), $errors, $passed);

// =================================================================
// Case 5. Multi-value int filter, underscore separator.
// =================================================================
echo "Case 5. underscore-joined multi-value\n";

ftReset();
$_GET['brand'] = '12_34_56';
assertEq('multi_brand_underscore', 'brand:=[12,34,56]', numinix_seekmodo_build_filter_by(), $errors, $passed);

// =================================================================
// Case 6. Two filters AND'd together. Order matches registration order.
// =================================================================
echo "Case 6. multi-field AND\n";

ftReset();
$_GET['brand'] = '12';
$_GET['type'] = '7_9';
$out = (string)numinix_seekmodo_build_filter_by();
assertTruthy('contains_brand', strpos($out, 'brand:=[12]') !== false, $errors, $passed);
assertTruthy('contains_type', strpos($out, 'p_type:=[7,9]') !== false, $errors, $passed);
assertTruthy('joined_with_and', strpos($out, ' && ') !== false, $errors, $passed);

// =================================================================
// Case 7. Field renaming default — type ($_GET) -> p_type (Typesense).
// =================================================================
echo "Case 7. type → p_type mapping\n";

ftReset();
$_GET['type'] = '42';
assertEq('type_uses_p_type', 'p_type:=[42]', numinix_seekmodo_build_filter_by(), $errors, $passed);

// =================================================================
// Case 8. capacity_by_lbs → capacity mapping.
// =================================================================
echo "Case 8. capacity_by_lbs → capacity mapping\n";

ftReset();
$_GET['capacity_by_lbs'] = '6000_8000';
assertEq('capacity_remap', 'capacity:=[6000,8000]', numinix_seekmodo_build_filter_by(), $errors, $passed);

// =================================================================
// Case 9. Custom coerce=string for SKU-style filter.
// =================================================================
echo "Case 9. string coerce\n";

ftReset();
numinix_seekmodo_register_filter_mapping('vendor', 'vendor_slug', ['coerce' => 'string']);
$_GET['vendor'] = 'acme-corp';
assertEq('string_coerce_quoted', 'vendor_slug:=`acme-corp`', numinix_seekmodo_build_filter_by(), $errors, $passed);

// =================================================================
// Case 10. string_list coerce on a tag-style filter.
// =================================================================
echo "Case 10. string_list coerce\n";

ftReset();
numinix_seekmodo_register_filter_mapping('tag', 'tags', ['coerce' => 'string_list']);
$_GET['tag'] = 'red_blue';
assertEq('string_list_quoted', 'tags:=[`red`,`blue`]', numinix_seekmodo_build_filter_by(), $errors, $passed);

// =================================================================
// Case 11. bool coerce.
// =================================================================
echo "Case 11. bool coerce\n";

ftReset();
numinix_seekmodo_register_filter_mapping('in_stock', 'in_stock', ['coerce' => 'bool']);
$_GET['in_stock'] = 'true';
assertEq('bool_true', 'in_stock:=true', numinix_seekmodo_build_filter_by(), $errors, $passed);

ftReset();
numinix_seekmodo_register_filter_mapping('in_stock', 'in_stock', ['coerce' => 'bool']);
$_GET['in_stock'] = '0';
assertEq('bool_false', 'in_stock:=false', numinix_seekmodo_build_filter_by(), $errors, $passed);

// =================================================================
// Case 12. range coerce — shorthand AND from/to forms.
// =================================================================
echo "Case 12. range coerce\n";

ftReset();
numinix_seekmodo_register_filter_mapping('weight', 'weight', ['coerce' => 'range']);
$_GET['weight'] = '100..500';
assertEq('range_shorthand', 'weight:>=100 && weight:<=500', numinix_seekmodo_build_filter_by(), $errors, $passed);

ftReset();
numinix_seekmodo_register_filter_mapping('weight', 'weight', ['coerce' => 'range']);
$_GET['weight_from'] = '100';
$_GET['weight_to'] = '500';
assertEq('range_from_to', 'weight:>=100 && weight:<=500', numinix_seekmodo_build_filter_by(), $errors, $passed);

// =================================================================
// Case 13. apply_local_filter — preserves rank order, recomputes total.
// =================================================================
echo "Case 13. apply_local_filter\n";

$remote = [
    'products' => [10, 20, 30, 40, 50],
    'total' => 5,
    'corrected_query' => null,
    'variant' => 'lexical',
    'semantic_shadow' => '',
];
$filtered = numinix_seekmodo_apply_local_filter($remote, [50, 20, 30, 99]);
assertEq('intersection_count', 3, count($filtered['products']), $errors, $passed);
assertEq('intersection_rank_order', [20, 30, 50], $filtered['products'], $errors, $passed);
assertEq('intersection_total', 3, $filtered['total'], $errors, $passed);

$empty = numinix_seekmodo_apply_local_filter($remote, []);
assertEq('empty_allowlist_returns_zero', 0, $empty['total'], $errors, $passed);
assertEq('empty_allowlist_no_products', [], $empty['products'], $errors, $passed);

// =================================================================
// Case 14. Auto coerce — numeric tokens -> int_list, mixed -> string_list.
// =================================================================
echo "Case 14. auto coerce\n";

ftReset();
numinix_seekmodo_register_filter_mapping('cat', 'category_id');
$_GET['cat'] = '5_7_9';
assertEq('auto_numeric_int_list', 'category_id:=[5,7,9]', numinix_seekmodo_build_filter_by(), $errors, $passed);

ftReset();
numinix_seekmodo_register_filter_mapping('cat', 'category_slug');
$_GET['cat'] = 'red_blue';
assertEq('auto_mixed_string_list', 'category_slug:=[`red`,`blue`]', numinix_seekmodo_build_filter_by(), $errors, $passed);

// =================================================================
// Case 15. _build_search_payload merges registry-built filter_by with
// any caller-supplied filter_by (AND join).
// =================================================================
echo "Case 15. payload filter_by composition\n";

ftReset();
$_GET['brand'] = '12';
$_SERVER['REMOTE_ADDR'] = '10.0.0.7';

$payload = _numinix_seekmodo_build_search_payload([
    'keyword' => 'lift table',
    'filter_by' => 'in_stock:=true',
]);
assertEq(
    'payload_filter_by_anded',
    'in_stock:=true && brand:=[12]',
    $payload['filter_by'],
    $errors,
    $passed
);

ftReset();
$_GET['brand'] = '12';
$_SERVER['REMOTE_ADDR'] = '10.0.0.7';
$payload2 = _numinix_seekmodo_build_search_payload(['keyword' => 'lift']);
assertEq('payload_filter_by_solo', 'brand:=[12]', $payload2['filter_by'], $errors, $passed);

ftReset();
$_SERVER['REMOTE_ADDR'] = '10.0.0.7';
$payload3 = _numinix_seekmodo_build_search_payload(['keyword' => 'lift']);
assertTruthy('payload_filter_by_omitted_when_empty', !array_key_exists('filter_by', $payload3), $errors, $passed);

// =================================================================
// Report.
// =================================================================
echo "\n";
if ($errors === []) {
    echo "test_filter_registry: {$passed} assertion(s) passed.\n";
    exit(0);
}
echo "test_filter_registry: " . count($errors) . " failure(s), {$passed} passed.\n";
exit(1);
