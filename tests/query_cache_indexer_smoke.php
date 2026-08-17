<?php

declare(strict_types=1);

/**
 * Smoke for v1.3.67 catalog-push QueryCache drain.
 *
 * Zen Cart QueryCache retains every unique SELECT mysqli_result for the
 * request. The indexer must replace it with a no-op and empty it after
 * each page so 12k-SKU catalogs do not OOM.
 */

$lib = __DIR__ . '/../zc_plugins/Seekmodo/v1.3.67/catalog/includes/functions/numinix_seekmodo_log_lib.php';
require $lib;

function qc_assert(bool $ok, string $msg): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

class FakeSeekmodoQueryCache
{
    /** @var array<string, mixed> */
    public array $queries = [];

    public function cache($query, $result)
    {
        $this->queries[(string) $query] = $result;
        return true;
    }

    public function inCache($query)
    {
        return isset($this->queries[(string) $query]);
    }

    public function reset($query)
    {
        if ($query === 'ALL') {
            $this->queries = [];
            return false;
        }
        unset($this->queries[(string) $query]);
        return false;
    }
}

qc_assert(class_exists('NuminixSeekmodoNullQueryCache', false), 'null QueryCache class is defined');
qc_assert(function_exists('numinix_seekmodo_disable_query_cache'), 'disable helper exists');
qc_assert(function_exists('numinix_seekmodo_release_query_cache'), 'release helper exists');
qc_assert(function_exists('numinix_seekmodo_release_query_result'), 'result helper exists');

$GLOBALS['queryCache'] = new FakeSeekmodoQueryCache();
$GLOBALS['queryCache']->cache('SELECT a', 'keep-a');
$GLOBALS['queryCache']->cache('SELECT b', 'keep-b');
qc_assert($GLOBALS['queryCache']->inCache('SELECT a'), 'fake cache stored first query');

numinix_seekmodo_release_query_cache();
qc_assert($GLOBALS['queryCache'] instanceof FakeSeekmodoQueryCache, 'release leaves a real QueryCache in place');
qc_assert($GLOBALS['queryCache']->inCache('SELECT a'), 'release does not flush the storefront QueryCache');

$GLOBALS['queryCache']->cache('SELECT c', 'keep-c');
numinix_seekmodo_disable_query_cache();
qc_assert($GLOBALS['queryCache'] instanceof NuminixSeekmodoNullQueryCache, 'disable replaces QueryCache');
qc_assert($GLOBALS['queryCache']->inCache('SELECT c') === false, 'no-op cache never hits');
qc_assert($GLOBALS['queryCache']->cache('SELECT d', 'x') === false, 'no-op cache() refuses to store');
numinix_seekmodo_release_query_cache();
qc_assert($GLOBALS['queryCache'] instanceof NuminixSeekmodoNullQueryCache, 'release after disable stays on the no-op cache');

$fakeResult = new stdClass();
$fakeResult->result = [['products_id' => 1]];
$fakeResult->fields = ['products_id' => 1];
$fakeResult->resource = null;
numinix_seekmodo_release_query_result($fakeResult);
qc_assert($fakeResult->result === [], 'buffered rows dropped');
qc_assert($fakeResult->fields === [], 'current fields dropped');

$push = file_get_contents(__DIR__ . '/../zc_plugins/Seekmodo/v1.3.67/catalog/numinix_seekmodo_push_catalog.php');
qc_assert(is_string($push) && $push !== '', 'push_catalog readable');
qc_assert(strpos($push, 'numinix_seekmodo_disable_query_cache') !== false, 'push_catalog disables QueryCache');
qc_assert(strpos($push, 'numinix_seekmodo_catalog_doc_prime_category_ids') !== false, 'push_catalog prefetches category ids');

$delta = file_get_contents(__DIR__ . '/../zc_plugins/Seekmodo/v1.3.67/catalog/numinix_seekmodo_index_delta.php');
qc_assert(is_string($delta) && strpos($delta, 'numinix_seekmodo_disable_query_cache') !== false, 'index_delta disables QueryCache');

$logSrc = file_get_contents($lib);
qc_assert(
    is_string($logSrc) && strpos($logSrc, '$canFreeMysqli') !== false,
    'mysqli_free_result is gated on the indexer no-op QueryCache'
);

fwrite(STDOUT, "OK query_cache_indexer_smoke\n");
