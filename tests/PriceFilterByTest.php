<?php
/**
 * Vanilla Zen Cart pfrom/pto → Typesense price filter_by.
 *
 * Self-contained — no PHPUnit. Run:
 *   php tests/PriceFilterByTest.php
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
$lib = $best . '/catalog/includes/functions/numinix_seekmodo_search_lib.php';
require_once $lib;

$errors = [];
$passed = 0;
$assertEq = static function (string $label, mixed $expected, mixed $actual) use (&$errors, &$passed): void {
    if ($expected !== $actual) {
        $errors[] = sprintf('%s: expected %s, got %s', $label, var_export($expected, true), var_export($actual, true));
        return;
    }
    $passed++;
};

$assertEq('15-25', 'price:>=15 && price:<=25', numinix_seekmodo_price_filter_by(15, 25));
$assertEq('0-15 keeps zero', 'price:>=0 && price:<=15', numinix_seekmodo_price_filter_by(0, 15));
$assertEq('50+ open upper', 'price:>=50', numinix_seekmodo_price_filter_by(50, null));
$assertEq('pto only', 'price:<=15', numinix_seekmodo_price_filter_by(null, 15));
$assertEq('empty', null, numinix_seekmodo_price_filter_by(null, null));
$assertEq('blank strings', null, numinix_seekmodo_price_filter_by('', ''));
$assertEq('string numbers', 'price:>=15 && price:<=25', numinix_seekmodo_price_filter_by('15', '25'));

$assertEq('parse missing', null, numinix_seekmodo_parse_price_bound(null));
$assertEq('parse blank', null, numinix_seekmodo_parse_price_bound(''));
$assertEq('parse non-numeric', null, numinix_seekmodo_parse_price_bound('guitar'));
$assertEq('parse zero string', 0.0, numinix_seekmodo_parse_price_bound('0'));
$assertEq('parse int zero', 0.0, numinix_seekmodo_parse_price_bound(0));
$assertEq('parse 15.5', 15.5, numinix_seekmodo_parse_price_bound('15.5'));

$assertEq(
    'missing GET is unbound (not $0 clamp)',
    null,
    numinix_seekmodo_price_filter_by(
        numinix_seekmodo_parse_price_bound(null),
        numinix_seekmodo_parse_price_bound(null)
    )
);
$assertEq(
    'explicit 0-0 band still clamps to free',
    'price:>=0 && price:<=0',
    numinix_seekmodo_price_filter_by(
        numinix_seekmodo_parse_price_bound('0'),
        numinix_seekmodo_parse_price_bound('0')
    )
);
$assertEq(
    'first band 0-15',
    'price:>=0 && price:<=15',
    numinix_seekmodo_price_filter_by(
        numinix_seekmodo_parse_price_bound('0'),
        numinix_seekmodo_parse_price_bound('15')
    )
);

if ($errors !== []) {
    fwrite(STDERR, 'FAIL: ' . count($errors) . " assertion(s)\n");
    foreach ($errors as $e) {
        fwrite(STDERR, "  - $e\n");
    }
    exit(1);
}
fwrite(STDOUT, 'OK: ' . $passed . ' assertion(s) via ' . basename($best) . "\n");
exit(0);
