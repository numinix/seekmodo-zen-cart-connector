<?php
/**
 * v1.3.49 — Seekmodo SERP rewrite also updates $GLOBALS['listing_sql'].
 *
 * product_listing.php rebuilds splitPageResults from $listing_sql, not
 * from the $result object passed to NOTIFY_SEARCH_RESULTS. Without the
 * global rewrite, headers show the Seekmodo count while the grid stays
 * on native FULLTEXT.
 *
 *     php tests/test_serp_listing_sql_global_v149.php
 */
declare(strict_types=1);

$errors = [];
$passed = 0;

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

// Minimal Zen Cart notifier base so the observer can construct.
if (!class_exists('base', false)) {
    class base
    {
        public function attach($observer, array $eventIds): void
        {
        }
    }
}

if (!defined('MAX_DISPLAY_PRODUCTS_LISTING')) {
    define('MAX_DISPLAY_PRODUCTS_LISTING', 24);
}

require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.3.49/catalog/includes/classes/observers/NuminixSeekmodoObserver.php';

$src = file_get_contents(
    __DIR__ . '/../zc_plugins/Seekmodo/v1.3.49/catalog/includes/classes/observers/NuminixSeekmodoObserver.php'
);
assertTruthy(
    'source_sets_listing_sql_global',
    is_string($src) && strpos($src, "\$GLOBALS['listing_sql'] = \$sql;") !== false,
    $errors,
    $passed
);

$GLOBALS['listing_sql'] = 'SELECT p.products_id FROM products p WHERE 1 /* native */';

$result = new stdClass();
$result->sql_query = $GLOBALS['listing_sql'];
$result->number_of_rows = 75;
$result->number_of_pages = 4;
$result->current_page_number = 1;

$observer = new NuminixSeekmodoObserver();
$method = new ReflectionMethod(NuminixSeekmodoObserver::class, 'rewriteSplitPageResults');
$method->setAccessible(true);

$seekSql = 'SELECT p.products_id FROM products p WHERE p.products_id IN (1,2,3) /* numinix_seekmodo_observer */ ORDER BY FIELD(p.products_id, 1,2,3)';
$method->invoke($observer, $result, $seekSql, 37);

assertEq('result_sql_query', $seekSql, $result->sql_query, $errors, $passed);
assertEq('result_number_of_rows', 37, $result->number_of_rows, $errors, $passed);
assertEq('result_number_of_pages', 2, $result->number_of_pages, $errors, $passed);
assertEq('globals_listing_sql', $seekSql, $GLOBALS['listing_sql'] ?? null, $errors, $passed);

echo "\n";
if ($errors === []) {
    echo "test_serp_listing_sql_global_v149: {$passed} assertion(s) passed.\n";
    exit(0);
}
echo "test_serp_listing_sql_global_v149: " . count($errors) . " failure(s), {$passed} passed.\n";
foreach ($errors as $e) {
    echo "{$e}\n";
}
exit(1);
