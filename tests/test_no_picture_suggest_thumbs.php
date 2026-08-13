<?php
/**
 * Regression: Zen no_picture must not win over catalog products_image
 * for suggest thumbs.
 *
 * Discovers the highest zc_plugins/Seekmodo/v1.3.x tree and exercises the
 * pure helpers (no Zen Cart bootstrap required).
 *
 *     php tests/test_no_picture_suggest_thumbs.php
 */
declare(strict_types=1);

$errors = [];
$passed = 0;

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

/**
 * @return array{0:string,1:string} [versionDirName, absoluteLibPath]
 */
function seekmodo_latest_typeahead_lib(string $repoRoot): array
{
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
    if ($best === null) {
        throw new RuntimeException('No zc_plugins/Seekmodo/v1.3.* directory found');
    }
    $lib = $best . '/catalog/includes/functions/numinix_seekmodo_typeahead_lib.php';
    if (!is_file($lib)) {
        throw new RuntimeException('Missing typeahead lib in ' . basename($best));
    }

    return [basename($best), $lib];
}

$repoRoot = dirname(__DIR__);
[$ver, $lib] = seekmodo_latest_typeahead_lib($repoRoot);
echo "Using {$ver}\n  {$lib}\n\n";

require_once $lib;

assertTruthy(
    'helper_is_no_picture_defined',
    function_exists('numinix_seekmodo_is_no_picture_url'),
    $errors,
    $passed
);
assertTruthy(
    'helper_prefer_defined',
    function_exists('numinix_seekmodo_prefer_catalog_over_placeholder_suggest_image'),
    $errors,
    $passed
);

$catalog = 'https://cdn.example.com/images/widgets/sku-1.jpg';
$placeholderAbs = 'https://shop.example.com/images/no_picture.gif';
$placeholderPng = '/images/no_picture.png';
$cacheAbs = 'https://shop.example.com/images/cache/sku-1.image.240x240.jpg';
$goodLocal = 'https://shop.example.com/images/widgets/sku-1.jpg';

assertEq('detect_gif', true, numinix_seekmodo_is_no_picture_url($placeholderAbs), $errors, $passed);
assertEq('detect_png', true, numinix_seekmodo_is_no_picture_url($placeholderPng), $errors, $passed);
assertEq('detect_webp', true, numinix_seekmodo_is_no_picture_url('/images/no_picture.webp'), $errors, $passed);
assertEq('detect_jpeg', true, numinix_seekmodo_is_no_picture_url('no_picture.jpeg'), $errors, $passed);
assertEq('reject_product_named_like', false, numinix_seekmodo_is_no_picture_url('/images/no_picture_frame.jpg'), $errors, $passed);
assertEq('reject_empty', false, numinix_seekmodo_is_no_picture_url(''), $errors, $passed);
assertEq('reject_catalog', false, numinix_seekmodo_is_no_picture_url($catalog), $errors, $passed);

assertEq(
    'no_picture_yields_catalog',
    $catalog,
    numinix_seekmodo_prefer_catalog_over_placeholder_suggest_image($placeholderAbs, $catalog),
    $errors,
    $passed
);
assertEq(
    'no_picture_alone_yields_empty',
    '',
    numinix_seekmodo_prefer_catalog_over_placeholder_suggest_image($placeholderAbs, ''),
    $errors,
    $passed
);
assertEq(
    'no_picture_and_catalog_placeholder_yields_empty',
    '',
    numinix_seekmodo_prefer_catalog_over_placeholder_suggest_image(
        $placeholderAbs,
        'https://shop.example.com/images/no_picture.gif'
    ),
    $errors,
    $passed
);
assertEq(
    'cache_miss_yields_catalog',
    $catalog,
    numinix_seekmodo_prefer_catalog_over_placeholder_suggest_image($cacheAbs, $catalog),
    $errors,
    $passed
);
assertEq(
    'bmz_miss_yields_catalog',
    $catalog,
    numinix_seekmodo_prefer_catalog_over_placeholder_suggest_image(
        'https://shop.example.com/images/bmz_cache/foo.jpg',
        $catalog
    ),
    $errors,
    $passed
);
assertEq(
    'good_local_wins',
    $goodLocal,
    numinix_seekmodo_prefer_catalog_over_placeholder_suggest_image($goodLocal, $catalog),
    $errors,
    $passed
);
assertEq(
    'empty_local_uses_catalog',
    $catalog,
    numinix_seekmodo_prefer_catalog_over_placeholder_suggest_image('', $catalog),
    $errors,
    $passed
);
assertEq(
    'empty_both',
    '',
    numinix_seekmodo_prefer_catalog_over_placeholder_suggest_image('', ''),
    $errors,
    $passed
);

// Observer hydrate still treats no_picture as force-upgrade (source lock).
$observer = $repoRoot . '/zc_plugins/Seekmodo/' . $ver
    . '/catalog/includes/classes/observers/NuminixSeekmodoSuggestObserver.php';
assertTruthy('observer_file', is_file($observer), $errors, $passed);
$obsSrc = is_file($observer) ? (string) file_get_contents($observer) : '';
assertTruthy(
    'observer_js_no_picture_force',
    preg_match('/no_picture\\\\.\\(\\?:gif\\|png\\|jpe\\?g\\|webp\\)/', $obsSrc) === 1,
    $errors,
    $passed
);

echo "\n";
if ($errors === []) {
    echo "test_no_picture_suggest_thumbs: {$passed} assertion(s) passed against {$ver}.\n";
    exit(0);
}
echo "test_no_picture_suggest_thumbs: " . count($errors) . " failure(s), {$passed} passed.\n";
foreach ($errors as $e) {
    echo "{$e}\n";
}
exit(1);