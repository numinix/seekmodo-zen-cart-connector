<?php
/**
 * v1.3.73: Zen Cart categories_id=0 / cPath=0 must not become category_id:=0.
 *
 *     php tests/test_categories_id_zero_filter_v173.php
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

require_once $best . '/catalog/includes/functions/numinix_seekmodo_search_lib.php';

$errors = [];
$passed = 0;
$assertEq = static function (string $label, $expected, $actual) use (&$errors, &$passed): void {
    if ($expected !== $actual) {
        $errors[] = sprintf('%s: expected %s, got %s', $label, var_export($expected, true), var_export($actual, true));
        echo "  FAIL {$label}\n";
        return;
    }
    $passed++;
    echo "  PASS {$label}\n";
};

echo "Using " . basename($best) . "\n";

$_GET = [];
numinix_seekmodo_reset_filter_mappings();
$assertEq('no_params_null', null, numinix_seekmodo_build_filter_by());

$_GET = ['categories_id' => '0'];
$assertEq('categories_id_0_null', null, numinix_seekmodo_build_filter_by());
$assertEq('categories_id_0_map_empty', [], numinix_seekmodo_build_filter_map());

$_GET = ['categories_id' => 0];
$assertEq('categories_id_int0_null', null, numinix_seekmodo_build_filter_by());

$_GET = ['cPath' => '0'];
$assertEq('cPath_0_null', null, numinix_seekmodo_build_filter_by());

$_GET = ['categories_id' => '7'];
$assertEq('categories_id_7', 'category_id:=7', numinix_seekmodo_build_filter_by());
$assertEq('categories_id_7_map', ['category_id' => 7], numinix_seekmodo_build_filter_map());

$_GET = ['cPath' => '7_12'];
$assertEq('cPath_nested_keeps_first', 'category_id:=7', numinix_seekmodo_build_filter_by());

$_GET = ['categories_id' => '0', 'brand' => '12'];
$assertEq('cat0_with_brand', 'brand:=[12]', numinix_seekmodo_build_filter_by());

$src = file_get_contents($best . '/catalog/includes/functions/numinix_seekmodo_search_lib.php');
$assertEq('cache_key_v4', true, strpos($src, "sm_search_v4:") !== false);
$assertEq(
    'does_not_cache_empty_products',
    true,
    strpos($src, "!empty(\$normalized['products'])") !== false
);

echo "\n{$passed} passed, " . count($errors) . " failed\n";
exit($errors === [] ? 0 : 1);
