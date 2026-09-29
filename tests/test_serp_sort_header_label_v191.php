<?php
/**
 * SERP Relevance UI must only inject into real sort menus.
 *
 * Ticket #615985 (Cannapot): after choosing Artikelname (sort=2a),
 * results sorted correctly but "Relevanz" appeared in pagination and
 * header lang/currency menus because the injector matched any <ul>
 * that merely preserved sort=2a in its links.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$observer = $root . '/zc_plugins/Seekmodo/v1.3.91/catalog/includes/classes/observers/NuminixSeekmodoObserver.php';
if (!is_file($observer)) {
    fwrite(STDERR, "FAIL: missing observer $observer\n");
    exit(1);
}

$src = (string) file_get_contents($observer);
$failures = 0;

function assert_true(bool $cond, string $label): void
{
    global $failures;
    if (!$cond) {
        fwrite(STDERR, "FAIL: $label\n");
        $failures++;
        return;
    }
    echo "OK: $label\n";
}

assert_true(
    strpos($src, 'function distinctSortCount(root)') !== false,
    'emits distinctSortCount helper'
);
assert_true(
    strpos($src, 'function isSortMenu(ul)') !== false,
    'emits isSortMenu helper'
);
assert_true(
    strpos($src, 'distinctSortCount(ul)>=2') !== false,
    'sort menus require 2+ distinct sort= values'
);
assert_true(
    strpos($src, '.pagination,nav.pagination,.navSplitPagesLinks') !== false,
    'excludes pagination containers'
);
assert_true(
    strpos($src, 'if(isSortMenu(ul))ensureAnchor(ul)') !== false,
    'ensureAnchor gated by isSortMenu'
);
assert_true(
    strpos($src, 'Artikelname|Product Name|Name|Modell|Model|Preis|Price') === false,
    'removed broad /Name/i toggle rewrite'
);
assert_true(
    strpos($src, 'sort=2a\\"],a[href*=\\"sort=2d\\"],a[href*=\\"sort=3a\\"]') === false
        && strpos($src, 'sort=2a"],a[href*="sort=2d"],a[href*="sort=3a"]') === false,
    'no longer matches any ul that merely contains sort=2a'
);
assert_true(
    strpos($src, 'else if(prev){sel.value=prev;}') !== false,
    'ensureSelect restores prior value when not ACTIVE'
);

// Simulate the distinct-sort rule in PHP (mirrors JS intent).
function distinct_sort_count(array $hrefs): int
{
    $seen = [];
    foreach ($hrefs as $href) {
        $q = parse_url($href, PHP_URL_QUERY);
        if (!is_string($q) || $q === '') {
            continue;
        }
        parse_str($q, $params);
        $v = isset($params['sort']) ? strtolower((string) $params['sort']) : '';
        if ($v !== '') {
            $seen[$v] = true;
        }
    }
    return count($seen);
}

function is_sort_menu_sim(array $hrefs, bool $isPagination): bool
{
    if ($isPagination) {
        return false;
    }
    return distinct_sort_count($hrefs) >= 2;
}

$sortMenu = [
    '/shop/?sort=2a',
    '/shop/?sort=2d',
    '/shop/?sort=3a',
    '/shop/?sort=3d',
];
$pagination = [
    '/shop/?keyword=x&sort=2a&page=1',
    '/shop/?keyword=x&sort=2a&page=2',
    '/shop/?keyword=x&sort=2a&page=3',
];
$headerLang = [
    '/shop/?sort=2a&language=de',
    '/shop/?sort=2a&language=en',
];

assert_true(is_sort_menu_sim($sortMenu, false), 'real sort menu accepted');
assert_true(!is_sort_menu_sim($pagination, true), 'pagination rejected by container');
assert_true(!is_sort_menu_sim($pagination, false), 'pagination rejected by single sort value');
assert_true(!is_sort_menu_sim($headerLang, false), 'header lang/currency rejected (one sort)');

if ($failures > 0) {
    exit(1);
}
echo "All assertions passed.\n";
