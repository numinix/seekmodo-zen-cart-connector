<?php
/**
 * Gateway search gate must yield to Enhanced Native when unpaired or sticky unpaid.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/zc_plugins/Seekmodo/v1.3.54/catalog/includes/functions/numinix_seekmodo_enhanced_native_lib.php';

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

function assert_false(bool $cond, string $label): void
{
    assert_true(!$cond, $label);
}

// Stub: unpaired / disabled
if (!function_exists('numinix_seekmodo_enabled')) {
    function numinix_seekmodo_enabled(): bool
    {
        return $GLOBALS['__sm_enabled_stub'] ?? false;
    }
}

$GLOBALS['__sm_enabled_stub'] = false;
assert_false(
    numinix_seekmodo_should_attempt_gateway_search(),
    'unpaired/disabled does not attempt gateway search'
);

// Stub Client sticky unpaid without loading full Client.php
if (!class_exists('\\Numinix\\Seekmodo\\Client', false)) {
    eval('namespace Numinix\\Seekmodo; class Client {
        public static $preferLocal = false;
        public static function shouldPreferLocalSuggest(): bool { return self::$preferLocal; }
    }');
}

$GLOBALS['__sm_enabled_stub'] = true;
\Numinix\Seekmodo\Client::$preferLocal = true;
assert_false(
    numinix_seekmodo_should_attempt_gateway_search(),
    'sticky unpaid prefers local (no gateway search)'
);

\Numinix\Seekmodo\Client::$preferLocal = false;
assert_true(
    numinix_seekmodo_should_attempt_gateway_search(),
    'paired + paid attempts gateway search'
);

// Observer must not early-return before EN when disabled.
$observer = file_get_contents(
    $root . '/zc_plugins/Seekmodo/v1.3.54/catalog/includes/classes/observers/NuminixSeekmodoObserver.php'
);
$fnPos = strpos($observer, 'function onSearchResults');
$slice = $fnPos === false ? '' : substr($observer, $fnPos, 4500);
assert_true($slice !== '', 'onSearchResults present');
assert_true(
    strpos($slice, 'numinix_seekmodo_should_attempt_gateway_search') !== false
        || strpos($slice, 'shouldPreferLocalSuggest') !== false,
    'onSearchResults uses unpaid/unpaired gateway gate'
);
assert_true(
    strpos($slice, 'numinix_seekmodo_run_enhanced_native_search') !== false,
    'onSearchResults still calls Enhanced Native'
);
// Old bug: bare early-return on !enabled() before EN.
assert_false(
    (bool) preg_match(
        '/if\s*\(\s*!function_exists\(\'numinix_seekmodo_enabled\'\)\s*\|\|\s*!numinix_seekmodo_enabled\(\)\s*\)\s*\{\s*return;/',
        $slice
    ),
    'onSearchResults no longer early-returns on !enabled()'
);

exit($failures > 0 ? 1 : 0);
