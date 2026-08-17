<?php
/**
 * v1.3.72: SERP live-stock partition must not hydrate full catalog docs.
 *
 *     php tests/test_serp_live_stock_partition_v172.php
 */
declare(strict_types=1);

$repoRoot = dirname(__DIR__);
$base = $repoRoot . DIRECTORY_SEPARATOR . 'zc_plugins' . DIRECTORY_SEPARATOR . 'Seekmodo';
$best = null;
$bestParts = [-1, -1, -1];
foreach (glob($base . DIRECTORY_SEPARATOR . 'v1.3.*', GLOB_ONLYDIR) ?: [] as $dir) {
    $name = basename($dir);
    if (!preg_match('/^v(\d+)\.(\d+)\.(\d+)$/', $name, $m)) {
        continue;
    }
    $parts = [(int) $m[1], (int) $m[2], (int) $m[3]];
    if ($parts > $bestParts) {
        $bestParts = $parts;
        $best = $dir;
    }
}
if (!is_string($best)) {
    fwrite(STDERR, "FAIL: no zc_plugins/Seekmodo/v1.3.* tree\n");
    exit(1);
}

$errors = [];
$passed = 0;
$assert = static function (bool $ok, string $label) use (&$errors, &$passed): void {
    if ($ok) {
        $passed++;
        echo "  PASS {$label}\n";
        return;
    }
    $errors[] = $label;
    echo "  FAIL {$label}\n";
};

$ver = basename($best);
echo "Using {$ver}\n";

$lib = $best . '/catalog/includes/functions/numinix_seekmodo_catalog_doc_lib.php';
$assert(is_file($lib), 'catalog_doc_lib present');
require_once $lib;

$assert(
    function_exists('numinix_seekmodo_catalog_partition_ids_by_stock_flags'),
    'partition_ids_by_stock_flags defined'
);
$assert(
    function_exists('numinix_seekmodo_catalog_live_stock_flags_for_ids'),
    'live_stock_flags_for_ids defined'
);

$ranked = [10, 20, 30, 40];
$flags = [
    10 => true,
    20 => false,
    30 => true,
    // 40 missing → demote
];
$assert(
    numinix_seekmodo_catalog_partition_ids_by_stock_flags($ranked, $flags) === [10, 30, 20, 40],
    'in-stock stay in relative order; OOS/missing demoted'
);
$assert(
    numinix_seekmodo_catalog_partition_ids_by_stock_flags([7, 8], []) === [7, 8],
    'empty flags demotes every id (keeps gateway order among demoted)'
);

$src = (string) file_get_contents($lib);
$fnPos = strpos($src, 'function numinix_seekmodo_catalog_partition_product_ids_live_stock');
$assert($fnPos !== false, 'partition_product_ids_live_stock still defined');
$after = $fnPos === false ? '' : substr($src, $fnPos, 1200);
$assert(
    strpos($after, 'numinix_seekmodo_catalog_docs_for_ids') === false,
    'partition no longer calls docs_for_ids'
);
$assert(
    strpos($after, 'numinix_seekmodo_catalog_live_stock_flags_for_ids') !== false,
    'partition uses slim live_stock_flags helper'
);

$flagPos = strpos($src, 'function numinix_seekmodo_catalog_live_stock_flags_for_ids');
$flagBody = $flagPos === false ? '' : substr($src, $flagPos, 2000);
$assert(
    strpos($flagBody, 'products_description') === false,
    'slim flags query does not select products_description'
);
$assert(
    strpos($flagBody, 'products_quantity') !== false,
    'slim flags query selects products_quantity'
);

$obs = $best . '/catalog/includes/classes/observers/NuminixSeekmodoObserver.php';
$obsSrc = is_file($obs) ? (string) file_get_contents($obs) : '';
$assert(
    strpos($obsSrc, 'numinix_seekmodo_catalog_partition_product_ids_live_stock') !== false,
    'observer still live-stock partitions SERP ids'
);

if ($errors !== []) {
    fwrite(STDERR, 'FAIL: ' . count($errors) . " assertion(s) against {$ver}\n");
    exit(1);
}
fwrite(STDOUT, "OK: {$passed} assertion(s) against {$ver}\n");
exit(0);
