<?php
/**
 * RED-SUGGEST fix-pack #4 -- regression test for the indexer batch
 * enrichment helpers added in v1.1.1's
 * `includes/functions/numinix_seekmodo_indexer_lib.php`.
 *
 * Self-contained, no PHPUnit. Mirrors Sprint12 / W6c test pattern:
 *
 *     php tests/FixPack4EnrichBreadcrumbsTest.php
 *
 * Coverage:
 *   - Docs missing both `category_id` + `category_breadcrumbs` get
 *     enriched from the storefront DB (the failure mode we observed
 *     on Redline's legacy `transfer_products.php` cron).
 *   - Docs that already carry both arrays are passed through
 *     untouched (idempotency).
 *   - Breadcrumbs are " > "-joined root-to-leaf and match the
 *     standalone push script's format.
 *   - Empty / zero-id products are skipped without breaking the
 *     surrounding batch.
 *   - Cycle guard: a parent_id loop in the categories tree doesn't
 *     hang or blow the stack.
 */

declare(strict_types=1);

$errors = [];
$passed = 0;

if (!defined('TABLE_PRODUCTS_TO_CATEGORIES')) {
    define('TABLE_PRODUCTS_TO_CATEGORIES', 'products_to_categories');
}
if (!defined('TABLE_CATEGORIES')) {
    define('TABLE_CATEGORIES', 'categories');
}
if (!defined('TABLE_CATEGORIES_DESCRIPTION')) {
    define('TABLE_CATEGORIES_DESCRIPTION', 'categories_description');
}

require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.1.1/catalog/includes/functions/numinix_seekmodo_indexer_lib.php';

/**
 * Tiny mock that recognises the two queries the enricher fires
 * (products_to_categories IN-list and the full categories tree)
 * and serves back canned rows. Everything else returns an
 * empty record set.
 */
final class EnrichFakeDb
{
    /** @var array<int, list<int>> */
    public array $productCats;

    /** @var array<int, array{name: string, parent: int}> */
    public array $categoryTree;

    public function __construct(array $productCats, array $categoryTree)
    {
        $this->productCats = $productCats;
        $this->categoryTree = $categoryTree;
    }

    public function Execute(string $sql): EnrichFakeResult
    {
        if (preg_match('/FROM products_to_categories\s+WHERE products_id IN \(([^)]+)\)/i', $sql, $m)) {
            $ids = array_map('intval', array_filter(array_map('trim', explode(',', $m[1])), 'strlen'));
            $rows = [];
            foreach ($ids as $pid) {
                foreach (($this->productCats[$pid] ?? []) as $cid) {
                    $rows[] = ['products_id' => $pid, 'categories_id' => $cid];
                }
            }
            return new EnrichFakeResult($rows);
        }
        if (preg_match('/FROM categories c\s+INNER JOIN categories_description cd/i', $sql)) {
            $rows = [];
            foreach ($this->categoryTree as $cid => $info) {
                $rows[] = [
                    'categories_id' => $cid,
                    'categories_name' => $info['name'],
                    'parent_id' => $info['parent'],
                ];
            }
            return new EnrichFakeResult($rows);
        }
        return new EnrichFakeResult([]);
    }
}

/**
 * Iterable result object roughly mirroring ZC's `queryFactoryResult`.
 */
final class EnrichFakeResult implements \IteratorAggregate
{
    public array $fields = [];
    public bool $EOF;
    /** @var list<array<string, mixed>> */
    private array $rows;

    public function __construct(array $rows)
    {
        $this->rows = array_values($rows);
        $this->EOF = $this->rows === [];
        $this->fields = $this->rows[0] ?? [];
    }

    public function getIterator(): \Iterator
    {
        return new ArrayIterator($this->rows);
    }
}

function expect(string $label, bool $cond, array &$errors, int &$passed, string $detail = ''): void
{
    if ($cond) {
        $passed++;
        return;
    }
    $errors[] = "FAIL: $label" . ($detail !== '' ? " — $detail" : '');
}

// --------------------------------------------------------------
// Tree fixture: Lifts > Parts & Accessories > Motorcycle Lift
//                                            > Wheel Vise
//               Cycles > LoopRoot (self-referential, cycle guard)
// --------------------------------------------------------------
$categoryTree = [
    290 => ['name' => 'Lifts', 'parent' => 0],
    304 => ['name' => 'Parts & Accessories', 'parent' => 290],
    308 => ['name' => 'Motorcycle Lift Wheel Vise', 'parent' => 304],
    400 => ['name' => 'Loop A', 'parent' => 401],
    401 => ['name' => 'Loop B', 'parent' => 400], // cycle
];
$productCats = [
    1979 => [290, 304, 308],
    2563 => [290, 304],
    9999 => [400], // would loop without guard
];

$db = new EnrichFakeDb($productCats, $categoryTree);
$GLOBALS['db'] = $db;
$_SESSION = ['languages_id' => 1];

$batch = [
    ['id' => '1979', 'name' => 'K&L Motorcycle Lift Table Adjustable Wheel Vise Rail Kit'],
    [
        'id' => '2563',
        'name' => 'Handy Motorcycle Wheel Vise Cover Kit',
        'category_id' => [290, 304],
        'category_breadcrumbs' => ['Lifts > Parts & Accessories'],
    ],
    ['id' => '9999', 'name' => 'Cycle Loop Test'],
    ['id' => '0', 'name' => 'no-id product, should pass through untouched'],
];

$out = numinix_seekmodo_indexer_enrich_batch($batch);

expect('returns same row count', count($out) === count($batch), $errors, $passed);

// Doc 0: was missing both -- should now carry full breadcrumbs.
expect(
    'doc 0 gains category_id',
    isset($out[0]['category_id']) && $out[0]['category_id'] === [290, 304, 308],
    $errors, $passed,
    json_encode($out[0]['category_id'] ?? null)
);
expect(
    'doc 0 gains breadcrumbs',
    isset($out[0]['category_breadcrumbs']) && in_array(
        'Lifts > Parts & Accessories > Motorcycle Lift Wheel Vise',
        $out[0]['category_breadcrumbs'],
        true
    ),
    $errors, $passed,
    json_encode($out[0]['category_breadcrumbs'] ?? null)
);
expect(
    'doc 0 breadcrumbs are unique + root-walked',
    isset($out[0]['category_breadcrumbs']) && count($out[0]['category_breadcrumbs']) === 3,
    $errors, $passed,
    json_encode($out[0]['category_breadcrumbs'] ?? null)
);

// Doc 1: already had both arrays -- must be untouched.
expect(
    'doc 1 category_id idempotent',
    $out[1]['category_id'] === [290, 304],
    $errors, $passed
);
expect(
    'doc 1 breadcrumbs idempotent',
    $out[1]['category_breadcrumbs'] === ['Lifts > Parts & Accessories'],
    $errors, $passed
);

// Doc 2: cycle in category tree -- guard caps walk, no infinite loop.
expect(
    'doc 2 cycle-guard does not produce infinite breadcrumbs',
    isset($out[2]['category_id']) && $out[2]['category_id'] === [400],
    $errors, $passed,
    json_encode($out[2]['category_id'] ?? null)
);
// Breadcrumb may or may not be present (depends on whether the
// guard fired before name lookup); critical thing is the call
// returned at all and didn't OOM. Accept either outcome.

// Doc 3: id=0 -- should be passed through with no enrichment.
expect(
    'doc 3 with id=0 is left untouched',
    !isset($out[3]['category_id']) && !isset($out[3]['category_breadcrumbs']),
    $errors, $passed
);

// Idempotency check: running enrich twice should be a no-op.
$twice = numinix_seekmodo_indexer_enrich_batch($out);
expect(
    'enrichment is idempotent across runs',
    $twice === $out,
    $errors, $passed
);

// Empty batch passes through.
expect(
    'empty batch returns empty',
    numinix_seekmodo_indexer_enrich_batch([]) === [],
    $errors, $passed
);

// No-DB fail-open: drop the global db and a fresh batch should
// pass through untouched rather than throw.
unset($GLOBALS['db']);
$rawBatch = [['id' => 1979, 'name' => 'no-db check']];
$rawOut = numinix_seekmodo_indexer_enrich_batch($rawBatch);
expect(
    'fails open when DB is missing',
    $rawOut === $rawBatch,
    $errors, $passed
);

// Done.
echo "passed=$passed\n";
if ($errors !== []) {
    foreach ($errors as $e) {
        echo $e . "\n";
    }
    exit(1);
}
echo "OK\n";
