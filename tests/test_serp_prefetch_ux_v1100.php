<?php
/**
 * Phase 3 SSR SERP prefetch UX — autoboot attribute + Sec-Purpose guard.
 *
 *     php tests/test_serp_prefetch_ux_v1100.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$plugin = $root . '/zc_plugins/Seekmodo/v1.3.99';
$errors = [];
$passed = 0;

require_once $plugin . '/catalog/includes/functions/numinix_seekmodo_search_lib.php';

function assert_true(string $label, bool $ok, array &$errors, int &$passed): void
{
    if ($ok) {
        $passed++;
        echo "  PASS {$label}\n";
        return;
    }
    $errors[] = "  FAIL {$label}";
    echo "  FAIL {$label}\n";
}

echo "== prefetch helper ==\n";
unset($_SERVER['HTTP_SEC_PURPOSE'], $_SERVER['HTTP_PURPOSE']);
assert_true('no header', !numinix_seekmodo_is_http_prefetch_request(), $errors, $passed);

$_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
assert_true('Sec-Purpose prefetch', numinix_seekmodo_is_http_prefetch_request(), $errors, $passed);
unset($_SERVER['HTTP_SEC_PURPOSE']);

$_SERVER['HTTP_PURPOSE'] = 'prefetch';
assert_true('Purpose prefetch', numinix_seekmodo_is_http_prefetch_request(), $errors, $passed);
unset($_SERVER['HTTP_PURPOSE']);

$_SERVER['HTTP_SEC_PURPOSE'] = 'navigation';
assert_true('non-prefetch purpose', !numinix_seekmodo_is_http_prefetch_request(), $errors, $passed);
unset($_SERVER['HTTP_SEC_PURPOSE']);

echo "== remember_search_event on prefetch ==\n";
$_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
_numinix_seekmodo_remember_search_event([
    'search_event_id' => 42,
    'keyword' => 'widget',
    'filter_by' => '',
    'filters' => [],
    'filter_hash' => null,
    'recorded_at' => time(),
]);
$current = function_exists('numinix_seekmodo_current_search_event')
    ? numinix_seekmodo_current_search_event()
    : null;
assert_true('prefetch skips stash', $current === null, $errors, $passed);
unset($_SERVER['HTTP_SEC_PURPOSE']);

echo "== autoboot markup ==\n";
$suggest = file_get_contents($plugin . '/catalog/includes/classes/observers/NuminixSeekmodoSuggestObserver.php');
assert_true(
    'prefetch-serp in suggest autoboot',
    is_string($suggest) && strpos($suggest, "setAttribute('prefetch-serp'") !== false,
    $errors,
    $passed
);
assert_true(
    'observer skips gateway on prefetch',
    is_string(file_get_contents($plugin . '/catalog/includes/classes/observers/NuminixSeekmodoObserver.php'))
        && strpos(
            (string) file_get_contents($plugin . '/catalog/includes/classes/observers/NuminixSeekmodoObserver.php'),
            'numinix_seekmodo_is_http_prefetch_request'
        ) !== false,
    $errors,
    $passed
);

echo "\n{$passed} passed, " . count($errors) . " failed\n";
exit($errors === [] ? 0 : 1);
