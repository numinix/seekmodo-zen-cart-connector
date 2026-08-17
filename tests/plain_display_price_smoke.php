<?php

declare(strict_types=1);

/**
 * Smoke: v1.3.68 typeahead prices must not carry Zen Cart HTML.
 */

$lib = __DIR__ . '/../zc_plugins/Seekmodo/v1.3.68/catalog/includes/functions/numinix_seekmodo_typeahead_lib.php';
require $lib;

function pp_assert(bool $ok, string $msg): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

pp_assert(function_exists('numinix_seekmodo_plain_price_text'), 'plain_price_text exists');

$got = numinix_seekmodo_plain_price_text('<span class="productBasePrice">$28.00</span>');
pp_assert($got === '$28.00', 'strips productBasePrice span, got ' . $got);

$got = numinix_seekmodo_plain_price_text(
    '<span class="productOldPrice">$30.00</span><br /><span class="productBasePrice">$28.00</span>'
);
pp_assert($got === '$30.00 $28.00', 'sale pair becomes two amounts, got ' . $got);

$got = numinix_seekmodo_plain_price_text('');
pp_assert($got === '', 'empty stays empty');

$libSrc = (string) file_get_contents($lib);
pp_assert(
    substr_count($libSrc, 'numinix_seekmodo_plain_display_price($pid)') >= 2,
    'typeahead item builders use the plain display helper'
);

$js = (string) file_get_contents(
    __DIR__ . '/../zc_plugins/Seekmodo/v1.3.68/catalog/includes/templates/template_default/jscript/seekmodo_typeahead.legacy.js'
);
pp_assert(strpos($js, 'function plainPrice') !== false, 'legacy JS strips price tags');
pp_assert(strpos($js, 'escapeHtml(priceText)') !== false, 'legacy JS escapes stripped price');

fwrite(STDOUT, "OK plain_display_price_smoke\n");
