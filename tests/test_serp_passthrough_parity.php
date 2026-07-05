<?php
/**
 * RED-1612 regression — suggest `serp_passthrough` must carry the same
 * Typesense tuning as `_numinix_seekmodo_build_search_payload()` so
 * dropdown SERP-preview product order matches the full SERP.
 *
 * The AKS connector pins this in EzNumberBoosterTest::
 * test_serp_passthrough_fields_match_apply_tuning; the Zen Cart
 * connector had no equivalent until this file.
 *
 * Does NOT cover the separate operational gap where shadow mode makes
 * `numinix_seekmodo_run_search()` return null while suggest still
 * hits the gateway — that is a deployment/mode concern, not tuning drift.
 *
 *     php tests/test_serp_passthrough_parity.php
 *
 * Exits 0 on pass, non-zero on fail.
 */

declare(strict_types=1);

$errors = [];
$passed = 0;

require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.3.13/catalog/includes/functions/numinix_seekmodo_search_lib.php';

/** @param mixed $expected @param mixed $actual */
function assertEq(string $label, $expected, $actual, array &$errors, int &$passed): void
{
    if ($expected === $actual) {
        $passed++;
        echo "  PASS {$label}\n";
        return;
    }
    $msg = "  FAIL {$label}: expected " . var_export($expected, true)
        . ', got ' . var_export($actual, true);
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

/** Keys forwarded by gateway SuggestTool::serpPassthroughFromSuggestRequest. */
function gatewayPassthroughKeys(): array
{
    return [
        'query_by',
        'query_by_weights',
        'prefix',
        'infix',
        'typo_tokens_threshold',
        'drop_tokens_threshold',
        'sort_by',
        'search_in_description',
        'filter_by',
        'filters',
    ];
}

/** Tuning keys the connector merges into /v1/search (RED-1612). */
function connectorTuningKeys(): array
{
    return [
        'typo_tokens_threshold',
        'drop_tokens_threshold',
        'query_by',
        'query_by_weights',
        'prefix',
        'infix',
        'sort_by',
    ];
}

// Redline-shaped constants — field-count alignment rules apply.
if (!defined('NUMINIX_TYPESENSE_QUERY_BY')) {
    define('NUMINIX_TYPESENSE_QUERY_BY', 'name,model,description,brand,category_breadcrumbs');
}
if (!defined('NUMINIX_TYPESENSE_QUERY_BY_WEIGHTS')) {
    define('NUMINIX_TYPESENSE_QUERY_BY_WEIGHTS', '4,3,1,2,1');
}
if (!defined('NUMINIX_TYPESENSE_PREFIX')) {
    define('NUMINIX_TYPESENSE_PREFIX', 'true,true,false,true,true');
}
if (!defined('NUMINIX_TYPESENSE_INFIX')) {
    define('NUMINIX_TYPESENSE_INFIX', 'off,off,off,off,off');
}
if (!defined('NUMINIX_TYPESENSE_DROP_TOKENS_THRESHOLD')) {
    define('NUMINIX_TYPESENSE_DROP_TOKENS_THRESHOLD', 10);
}
if (!defined('NUMINIX_TYPESENSE_KEYWORD_SORT_BY')) {
    define('NUMINIX_TYPESENSE_KEYWORD_SORT_BY', '_text_match:desc,in_stock:desc');
}

$_GET = [];
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Test';
$_SERVER['REMOTE_ADDR'] = '10.0.0.7';

// =================================================================
// Case 1. build_serp_passthrough === typesense_tuning_params(keyword)
// =================================================================
echo "Case 1. build_serp_passthrough helper\n";

$passthrough = _numinix_seekmodo_build_serp_passthrough();
$tuning = _numinix_seekmodo_typesense_tuning_params(true);
assertEq('passthrough_equals_tuning_helper', $tuning, $passthrough, $errors, $passed);

// =================================================================
// Case 2. Every tuning key on SERP payload matches passthrough
// =================================================================
echo "Case 2. search payload tuning slice matches passthrough\n";

$payload = _numinix_seekmodo_build_search_payload([
    'keyword' => 'wheel vise',
    'search_in_description' => '1',
]);

foreach (connectorTuningKeys() as $key) {
    if (!array_key_exists($key, $tuning)) {
        continue;
    }
    assertEq(
        "payload_tuning_{$key}",
        $tuning[$key],
        $payload[$key] ?? null,
        $errors,
        $passed
    );
    assertEq(
        "passthrough_tuning_{$key}",
        $tuning[$key],
        $passthrough[$key] ?? null,
        $errors,
        $passed
    );
}

// =================================================================
// Case 3. Passthrough keys are a subset gateway SuggestTool accepts
// =================================================================
echo "Case 3. passthrough keys are gateway-forwardable\n";

foreach (array_keys($passthrough) as $key) {
    assertTruthy(
        "passthrough_key_forwarded_{$key}",
        in_array($key, gatewayPassthroughKeys(), true),
        $errors,
        $passed
    );
}

// =================================================================
// Case 4. Browse (empty keyword) uses browse sort on payload only
// =================================================================
echo "Case 4. browse sort differs from keyword passthrough (expected)\n";

if (!defined('NUMINIX_TYPESENSE_BROWSE_SORT_BY')) {
    define('NUMINIX_TYPESENSE_BROWSE_SORT_BY', 'in_stock:desc,price:asc');
}

$browsePayload = _numinix_seekmodo_build_search_payload(['keyword' => '']);
assertEq(
    'browse_payload_has_browse_sort',
    'in_stock:desc,price:asc',
    $browsePayload['sort_by'] ?? null,
    $errors,
    $passed
);
assertEq(
    'passthrough_still_keyword_sort',
    '_text_match:desc,in_stock:desc',
    $passthrough['sort_by'] ?? null,
    $errors,
    $passed
);

// =================================================================
// Report.
// =================================================================
echo "\n";
if ($errors === []) {
    echo "test_serp_passthrough_parity: {$passed} assertion(s) passed.\n";
    exit(0);
}
echo 'test_serp_passthrough_parity: ' . count($errors) . " failure(s), {$passed} passed.\n";
exit(1);
