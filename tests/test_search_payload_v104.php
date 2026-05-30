<?php
/**
 * PR 7b regression tests for the v1.0.4 connector's
 * `_numinix_seekmodo_build_search_payload()` and the
 * `_numinix_seekmodo_apply_sort_deprecations()` helper.
 *
 * Lives in a separate file from `test_filter_registry.php` because
 * v1.0.3 and v1.0.4 both define the same global function names —
 * loading both in one process would trip "Cannot redeclare". This
 * file only requires v1.0.4.
 *
 * Loads from the v1.0.4 plugin tree directly — no PHPUnit dependency.
 *
 *     php services/zen-cart-connector/tests/test_search_payload_v104.php
 *
 * Exits 0 on pass, non-zero on fail.
 */

declare(strict_types=1);

$errors = [];
$passed = 0;

// Define DIR_FS_LOGS to a temp dir so deprecation logging is silent
// (and observable, for the "logs only when applied" assertion).
$tmpLogDir = sys_get_temp_dir() . '/seekmodo_v104_logs_' . uniqid('', true);
mkdir($tmpLogDir, 0700, true);
define('DIR_FS_LOGS', $tmpLogDir);

require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.0.4/catalog/includes/functions/numinix_seekmodo_search_lib.php';

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

function logFile(): string
{
    return rtrim(DIR_FS_LOGS, '/\\') . '/numinix_seekmodo.log';
}

function clearLog(): void
{
    @unlink(logFile());
}

function readLog(): string
{
    $f = logFile();
    return is_file($f) ? (string)file_get_contents($f) : '';
}

// =================================================================
// Case 1. products_instock:desc rewrites to in_stock:desc, logs once.
// =================================================================
echo "Case 1. single-clause stale sort token\n";

clearLog();
$out = _numinix_seekmodo_apply_sort_deprecations('products_instock:desc');
assertEq('rewrites_single_token', 'in_stock:desc', $out, $errors, $passed);
$log = readLog();
assertTruthy('logged_once', strpos($log, 'sort_deprecation_applied') !== false, $errors, $passed);
assertTruthy('log_includes_rewrite_pair', strpos($log, 'products_instock -> in_stock') !== false, $errors, $passed);

// =================================================================
// Case 2. Mixed list — only the deprecated clause is rewritten,
// already-canonical clauses pass through untouched.
// =================================================================
echo "Case 2. mixed canonical + stale clauses\n";

clearLog();
$out = _numinix_seekmodo_apply_sort_deprecations('_text_match:desc,products_instock:desc,price:asc');
assertEq(
    'mixed_rewrite',
    '_text_match:desc,in_stock:desc,price:asc',
    $out,
    $errors,
    $passed
);
$log = readLog();
assertTruthy('mixed_logged', strpos($log, 'sort_deprecation_applied') !== false, $errors, $passed);

// =================================================================
// Case 3. Already-canonical sort string passes through with no log.
// =================================================================
echo "Case 3. canonical sort untouched\n";

clearLog();
$out = _numinix_seekmodo_apply_sort_deprecations('in_stock:desc,price:asc');
assertEq('canonical_passthrough', 'in_stock:desc,price:asc', $out, $errors, $passed);
assertEq('canonical_no_log', '', readLog(), $errors, $passed);

// =================================================================
// Case 4. Empty input is a no-op.
// =================================================================
echo "Case 4. empty input\n";

clearLog();
assertEq('empty_returns_empty', '', _numinix_seekmodo_apply_sort_deprecations(''), $errors, $passed);
assertEq('empty_no_log', '', readLog(), $errors, $passed);

// =================================================================
// Case 5. Whitespace around clauses is normalized after rewrite.
// =================================================================
echo "Case 5. whitespace tolerance\n";

clearLog();
$out = _numinix_seekmodo_apply_sort_deprecations(' products_instock:desc , price:asc ');
assertEq('whitespace_normalized', 'in_stock:desc,price:asc', $out, $errors, $passed);

// =================================================================
// Case 6. Storefront integration — keyword sort constant flows through
// _numinix_seekmodo_build_search_payload() and gets rewritten.
// =================================================================
echo "Case 6. build_search_payload integration (keyword)\n";

if (!defined('NUMINIX_TYPESENSE_KEYWORD_SORT_BY')) {
    define('NUMINIX_TYPESENSE_KEYWORD_SORT_BY', 'products_instock:desc,_text_match:desc');
}
$_GET = [];
$_SERVER['REMOTE_ADDR'] = '10.0.0.7';

clearLog();
$payload = _numinix_seekmodo_build_search_payload(['keyword' => 'lift table']);
assertEq(
    'keyword_sort_rewritten',
    'in_stock:desc,_text_match:desc',
    $payload['sort_by'] ?? null,
    $errors,
    $passed
);
$log = readLog();
assertTruthy('keyword_sort_logged', strpos($log, 'sort_deprecation_applied') !== false, $errors, $passed);

// =================================================================
// Case 7. Browse sort constant (no keyword) also rewrites.
// =================================================================
echo "Case 7. build_search_payload integration (browse)\n";

if (!defined('NUMINIX_TYPESENSE_BROWSE_SORT_BY')) {
    define('NUMINIX_TYPESENSE_BROWSE_SORT_BY', 'products_instock:desc');
}
$_GET = [];
$_SERVER['REMOTE_ADDR'] = '10.0.0.7';

clearLog();
$payload = _numinix_seekmodo_build_search_payload(['keyword' => '']);
assertEq(
    'browse_sort_rewritten',
    'in_stock:desc',
    $payload['sort_by'] ?? null,
    $errors,
    $passed
);

// =================================================================
// Case 8. Caller-supplied sort_by pre-empts the constant; the
// deprecation map still rewrites stale tokens in it.
// =================================================================
echo "Case 8. caller-supplied sort_by also rewritten\n";

$_GET = [];
$_SERVER['REMOTE_ADDR'] = '10.0.0.7';

clearLog();
$payload = _numinix_seekmodo_build_search_payload([
    'keyword' => 'lift',
    'sort_by' => 'products_instock:desc',
]);
assertEq(
    'caller_sort_rewritten',
    'in_stock:desc',
    $payload['sort_by'] ?? null,
    $errors,
    $passed
);

// =================================================================
// Cleanup.
// =================================================================
@unlink(logFile());
@rmdir($tmpLogDir);

// =================================================================
// Report.
// =================================================================
echo "\n";
if ($errors === []) {
    echo "test_search_payload_v104: {$passed} assertion(s) passed.\n";
    exit(0);
}
echo "test_search_payload_v104: " . count($errors) . " failure(s), {$passed} passed.\n";
exit(1);
