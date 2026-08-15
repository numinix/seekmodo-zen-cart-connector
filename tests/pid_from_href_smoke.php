<?php

declare(strict_types=1);

/**
 * Source-lock + regex smoke for v1.3.66 pidFromHref.
 *
 * The live matcher is a JS function concatenated into
 * NuminixSeekmodoObserver::emitSerpClickBeacon(). These assertions
 * lock the source so the v1.3.59 blanket cPath reject cannot sneak
 * back, then exercise the same patterns in PHP against the URLs
 * that were producing tracking_clicks_missing on www.numinix.com.
 */

$observer = __DIR__ . '/../zc_plugins/Seekmodo/v1.3.66/catalog/includes/classes/observers/NuminixSeekmodoObserver.php';
$src = file_get_contents($observer);
if ($src === false) {
    fwrite(STDERR, "missing observer\n");
    exit(1);
}

function pid_assert(bool $ok, string $msg): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

pid_assert(
    strpos($src, 'if(/[?&](?:cPath|categories_id)=/i.test(href))return null;') === false,
    'v1.3.66 must not blanket-reject cPath on product listing links'
);
pid_assert(
    strpos($src, 'm=href.match(/-(\\\\d+)(?:\\\\?|#|$)/);return m?m[1]:null;') !== false
        || strpos($src, "m=href.match(/-(\\\\d+)(?:\\\\?|#|\$)/);return m?m[1]:null;") !== false
        || strpos($src, 'm=href.match(/-(\\d+)(?:\\?|#|$)/);return m?m[1]:null;') !== false,
    'v1.3.66 must match SEO slug-{id}?query product URLs'
);
pid_assert(
    strpos($src, 'if(/-c-\\\\d+/i.test(href))return null;') !== false
        || strpos($src, "if(/-c-\\\\d+/i.test(href))return null;") !== false
        || strpos($src, 'if(/-c-\\d+/i.test(href))return null;') !== false,
    'v1.3.66 must still reject -c-N category slugs'
);

/**
 * PHP port of the JS matcher (same pattern order). Used to lock
 * expected URL shapes; the source-lock above keeps the JS in sync.
 */
function pidFromHref(string $href): ?string
{
    if (preg_match('/-c-\d+/i', $href)) {
        return null;
    }
    if (preg_match('/[?&]products_id=(\d+)/', $href, $m)) {
        return $m[1];
    }
    if (preg_match('/-p-(\d+)(?:\.html|[\/?#]|$)/i', $href, $m)) {
        return $m[1];
    }
    if (preg_match('/-(\d+)\.html(?:\?|#|$)/i', $href, $m)) {
        return $m[1];
    }
    if (preg_match('/-(\d+)(?:\?|#|$)/', $href, $m)) {
        return $m[1];
    }

    return null;
}

$cases = [
    ['https://www.numinix.com/tableau-for-woocommerce-1172?cPath=403_407_462', '1172'],
    ['https://www.numinix.com/tableau-for-woocommerce-1172', '1172'],
    ['https://www.numinix.com/foo-p-99.html', '99'],
    ['https://www.numinix.com/index.php?products_id=5', '5'],
    ['https://www.numinix.com/shop-by-ecommerce-platforms-179/', null],
    ['https://www.numinix.com/index.php?cPath=1_2', null],
    ['https://www.numinix.com/category-c-12', null],
    ['https://www.numinix.com/widget-p-77.html?cPath=1', '77'],
];

foreach ($cases as [$href, $want]) {
    $got = pidFromHref($href);
    pid_assert($got === $want, "pidFromHref({$href}) === " . var_export($want, true) . ' got ' . var_export($got, true));
}

echo "pidFromHref smoke OK\n";
