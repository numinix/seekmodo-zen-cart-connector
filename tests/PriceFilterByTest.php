<?php
/**
 * Vanilla Zen Cart pfrom/pto → Typesense price filter_by.
 *
 * Self-contained — no PHPUnit. Run:
 *   php tests/PriceFilterByTest.php
 */

declare(strict_types=1);

$lib = __DIR__ . '/../zc_plugins/Seekmodo/v1.3.64/catalog/includes/functions/numinix_seekmodo_search_lib.php';
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

if ($errors !== []) {
    fwrite(STDERR, 'FAIL: ' . count($errors) . " assertion(s)\n");
    foreach ($errors as $e) {
        fwrite(STDERR, "  - $e\n");
    }
    exit(1);
}
fwrite(STDOUT, "OK: {$passed} assertion(s)\n");
exit(0);
