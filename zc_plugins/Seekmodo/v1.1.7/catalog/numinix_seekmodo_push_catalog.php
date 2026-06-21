<?php
/**
 * numinix_seekmodo_push_catalog.php
 *
 * Standalone catalog pusher — walks the active Zen Cart products
 * table and uploads matching documents to the tenant's Seekmodo
 * Typesense collection in batches via the connector's existing
 * `numinix_seekmodo_run_bulk_upsert($batch, $collection)` swap-point.
 *
 * Why this script exists
 * ----------------------
 * Up to v1.0.9 the connector's indexer side was a *swap-point*
 * (`numinix_seekmodo_run_bulk_upsert`) layered into a pre-existing
 * storefront-side Typesense indexer cron — the way Redline's
 * `transfer_products.php` / `typesense_indexer_lib.php` calls it.
 * That assumed the storefront already had a working Typesense
 * indexer to splice into.
 *
 * Plain Zen Cart 1.5/2.0 storefronts (numinix.com, numinix.ca, and
 * any out-of-the-box install) have no such cron. Result: the
 * connector paired and started talking to the gateway, but the
 * gateway's per-tenant Typesense collection stayed empty, every
 * `/v1/search` returned zero hits, the observer fell through to
 * native Zen Cart LIKE on every request, and no impressions or
 * clicks were ever attributed.
 *
 * v1.0.10 ships this file as the missing piece. It needs nothing
 * from the storefront beyond a configured + paired connector
 * (which the v1.0.9 install script already produces).
 *
 * Usage
 * -----
 * One-shot (full catalog reindex):
 *
 *   cd /home/<user>/<docroot>
 *   /usr/bin/php zc_plugins/Seekmodo/v1.0.10/catalog/numinix_seekmodo_push_catalog.php
 *
 * Cron (drop-in replacement for the missing transfer_products.php
 * cron the connector's install script prints):
 *
 *   11 3 * * * <user> cd /home/<user>/<docroot> && \
 *     /usr/bin/php zc_plugins/Seekmodo/v1.0.10/catalog/numinix_seekmodo_push_catalog.php \
 *     >>/home/<user>/logs/numinix_seekmodo_indexer.log 2>&1
 *
 * Dry run (counts but no upsert):
 *
 *   /usr/bin/php zc_plugins/Seekmodo/v1.0.10/catalog/numinix_seekmodo_push_catalog.php --dry-run
 *
 * Limit batches (smoke test):
 *
 *   /usr/bin/php zc_plugins/Seekmodo/v1.0.10/catalog/numinix_seekmodo_push_catalog.php --max-batches=2
 *
 * Override batch size (default = NUMINIX_SEEKMODO_INDEX_BATCH or 500):
 *
 *   /usr/bin/php zc_plugins/Seekmodo/v1.0.10/catalog/numinix_seekmodo_push_catalog.php --batch=200
 *
 * Schema mapping
 * --------------
 * The gateway's commerce Typesense schema (see
 * services/mcp-gateway/ops/tenant-add.php → schema_for_vertical) is:
 *
 *   id                    string                — products_id stringified
 *   name                  string                — products_description.products_name
 *   model                 string  optional      — products.products_model
 *   description           string  optional      — products_description.products_description
 *                                                  (HTML-stripped, NFKC-collapsed)
 *   brand                 string  optional facet — manufacturers.manufacturers_name
 *   category_id           int32[] optional facet — products_to_categories.categories_id
 *   p_type                int32   optional facet — products.products_type
 *   category_breadcrumbs  string[] optional facet — chain of categories_description.categories_name
 *                                                   walking up from each linked category
 *   price                 float   optional       — products.products_price (specials override
 *                                                   handled gateway-side)
 *   in_stock              bool    optional facet — products.products_quantity > 0
 *   purchasable           bool    optional facet — shopper can add to cart today
 *                                                   (includes backorder-eligible OOS;
 *                                                    excludes discontinued / call-for-price /
 *                                                    NPF forced-OOS when configured)
 *   sku                   string  optional       — products.products_model (ZC has no
 *                                                   separate SKU column; model is the
 *                                                   conventional stand-in)
 *   url                   string  optional       — zen_href_link product_info URL
 *   image_url             string  optional       — absolute URL of products.products_image
 *                                                   (`HTTP_SERVER + DIR_WS_CATALOG + DIR_WS_IMAGES`
 *                                                   prefix). Drives the typeahead's
 *                                                   product-row thumbnail; the bundle
 *                                                   reads `o.image_url ?? o.image`.
 *
 * The auto-computed Phase B rerank features (is_primary, is_accessory,
 * is_accessory_heuristic, title_token_count, head_noun,
 * price_pct_in_category) are populated gateway-side by IndexTool's
 * FeatureExtractor on every upsert — we don't need to compute them
 * here.
 *
 * Failure posture
 * ---------------
 * The pusher refuses to run when:
 *   - the connector isn't paired (missing TENANT_ID / SHARED_SECRET);
 *   - mode=off, or the tenant is locked-out (storefront host doesn't
 *     match `NUMINIX_SEEKMODO_LOCKED_DOMAIN`);
 *   - `numinix_seekmodo_run_bulk_upsert` isn't loaded (the connector
 *     plugin isn't installed in this docroot).
 *
 * Per-batch failures from the gateway are logged but DO NOT abort
 * the run — same posture as the search hot path: never let a
 * gateway problem brick the storefront's tooling.
 */

declare(strict_types=1);

// --------------------------------------------------------------
// Bootstrap
// --------------------------------------------------------------
//
// The script lives at <docroot>/zc_plugins/Seekmodo/v1.0.10/catalog/
// numinix_seekmodo_push_catalog.php. Resolving the docroot back up
// from __FILE__ keeps it independent of the working directory the
// operator runs it from. We do still chdir() into the docroot so
// that Zen Cart's own `require` lookups (which all start at .
// for the catalog tree) resolve cleanly — same posture the
// connector's `numinix_seekmodo_pair_callback.php` uses.

$catalogRoot = realpath(__DIR__ . '/../../../../');
if ($catalogRoot === false || !is_dir($catalogRoot . '/includes')) {
    fwrite(STDERR, "ERROR: cannot resolve catalog docroot from " . __FILE__ . "\n");
    exit(2);
}
chdir($catalogRoot);

// Match the bootstrap shape the existing pair-callback uses so we
// inherit the same Zen Cart class graph (db / messageStack /
// $zco_notifier / configure.php constants / DIR_FS_LOGS). If we
// tried to short-circuit by only loading configure.php we'd miss
// the application_top wiring that makes `numinix_seekmodo_*`
// helpers callable.
require './includes/configure.php';
ini_set('include_path', DIR_FS_CATALOG . PATH_SEPARATOR . ini_get('include_path'));
chdir(DIR_FS_CATALOG);

// NB: do NOT set $current_page_base / $loaderPrefix here.
//
// Zen Cart 2.x's InitSystem treats $loaderPrefix as a required hint
// and tries to require `includes/auto_loaders/$loaderPrefix.core.php`
// — if we set $loaderPrefix='numinix_seekmodo_push_catalog' the
// loader pukes with "Failed opening required" because no such core
// file exists for this CLI entry. Leaving the variables unset lets
// Zen Cart fall through to the default 'index'-style boot which is
// what every other CLI entry point (e.g. Redline's transfer_products.php)
// relies on. The connector's own auto_loader/config.numinix_seekmodo.php
// is keyed by autoLoadConfig priority, not by $loaderPrefix, so the
// observer + procedural helpers still load.
require_once 'includes/application_top.php';

// --------------------------------------------------------------
// CLI arg parsing (intentionally minimal — getopt covers it)
// --------------------------------------------------------------

$opts = getopt('', [
    'dry-run',
    'batch::',
    'max-batches::',
    'verbose',
    'site::',
]);

$dryRun = isset($opts['dry-run']);
$verbose = isset($opts['verbose']);
$batchOverride = isset($opts['batch']) ? (int)$opts['batch'] : 0;
$maxBatches = isset($opts['max-batches']) ? (int)$opts['max-batches'] : 0;

$batchSize = $batchOverride > 0
    ? $batchOverride
    : (defined('NUMINIX_SEEKMODO_INDEX_BATCH') ? (int)NUMINIX_SEEKMODO_INDEX_BATCH : 500);
if ($batchSize <= 0 || $batchSize > 1000) {
    // Gateway IndexTool::MAX_DOCUMENTS_PER_CALL = 1000.
    $batchSize = 500;
}

// --------------------------------------------------------------
// Preflight
// --------------------------------------------------------------

function _push_log(string $level, string $msg): void
{
    $ts = date('c');
    $line = sprintf('[%s] %s push_catalog: %s', $ts, strtoupper($level), $msg);
    fwrite(STDERR, $line . "\n");
}

if (!function_exists('numinix_seekmodo_enabled')) {
    _push_log('error', 'connector helper numinix_seekmodo_enabled() not loaded — is the Seekmodo plugin installed in this docroot?');
    exit(3);
}
if (!numinix_seekmodo_enabled()) {
    _push_log('error', 'connector mode=off or not paired (NUMINIX_SEEKMODO_MODE / TENANT_ID / SHARED_SECRET unset). Pair via Tools → Connect to Seekmodo before running the pusher.');
    exit(4);
}
if (function_exists('numinix_seekmodo_is_locked_out') && numinix_seekmodo_is_locked_out()) {
    $observed = function_exists('numinix_seekmodo_current_host')
        ? (string) numinix_seekmodo_current_host()
        : (string)($_SERVER['HTTP_HOST'] ?? '');
    $locked = defined('NUMINIX_SEEKMODO_LOCKED_DOMAIN')
        ? (string) NUMINIX_SEEKMODO_LOCKED_DOMAIN
        : '';
    _push_log('error', "tenant is locked out (observed host '{$observed}', locked_domain '{$locked}'). Run from the canonical storefront host (or set HTTP_HOST in CLI: HTTP_HOST=www.example.com php …) to pass the gate.");
    exit(5);
}
if (!function_exists('numinix_seekmodo_run_bulk_upsert')) {
    _push_log('error', 'numinix_seekmodo_run_bulk_upsert() not loaded — connector plugin tree appears incomplete.');
    exit(6);
}

// Resolve target collection. The IndexTool routes the upsert based
// on the tenant_id in the HMAC envelope, but we still pass the
// expected collection name through to the swap-point so its log
// line is informative + so any future routing assertions in
// run_bulk_upsert have something to inspect.
$tenantId = defined('NUMINIX_SEEKMODO_TENANT_ID') ? (string) NUMINIX_SEEKMODO_TENANT_ID : '';
$collection = 't_' . $tenantId . '_products';

_push_log('info', sprintf(
    'starting catalog push tenant=%s collection=%s batch=%d dry_run=%s',
    $tenantId,
    $collection,
    $batchSize,
    $dryRun ? 'yes' : 'no'
));

// --------------------------------------------------------------
// SQL — walk active products
// --------------------------------------------------------------
//
// One row per product. Column list mirrors what the doc shape
// needs; everything else (specials, breadcrumbs) is fetched per
// product in a small follow-up query so the main scan stays
// cache-friendly.

global $db;

$languageId = (int)($_SESSION['languages_id'] ?? 1);
if ($languageId <= 0) {
    $languageId = 1;
}

$totalSql = "SELECT COUNT(*) AS n FROM " . TABLE_PRODUCTS . " WHERE products_status = 1";
$totalRow = $db->Execute($totalSql);
$total = $totalRow ? (int)$totalRow->fields['n'] : 0;
if ($total === 0) {
    _push_log('warn', 'no active products in catalog — nothing to push.');
    exit(0);
}

/**
 * Resolve the NPF "force OOS" products column name, if any.
 *
 * Tenant override via snapshot:
 *   relevance_overrides.indexer_overrides.zen_cart.npf_force_oos_column
 * Empty string disables the probe; null uses default `out_of_stock`.
 */
function _push_resolve_npf_force_oos_column(): ?string
{
    $override = null;
    if (class_exists(\Numinix\Seekmodo\RemoteConfig::class)) {
        $override = \Numinix\Seekmodo\RemoteConfig::indexerOverride(
            'zen_cart',
            'npf_force_oos_column'
        );
    }
    if ($override === '') {
        return null;
    }
    if (is_string($override) && $override !== '') {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $override)) {
            return null;
        }
        return $override;
    }
    return 'out_of_stock';
}

/**
 * Return the NPF column when it exists on products, else null.
 */
function _push_npf_force_oos_column(): ?string
{
    global $db;
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved === false ? null : $resolved;
    }
    $candidate = _push_resolve_npf_force_oos_column();
    if ($candidate === null) {
        $resolved = false;
        return null;
    }
    $check = $db->Execute(
        'SHOW COLUMNS FROM ' . TABLE_PRODUCTS . " LIKE '" . $candidate . "'"
    );
    if ($check && $check->RecordCount() > 0) {
        $resolved = $candidate;
        return $candidate;
    }
    $resolved = false;
    return null;
}

$npfForceOosColumn = _push_npf_force_oos_column();

$baseSql = "SELECT p.products_id, p.products_model, p.products_type, p.products_price,"
    . " p.products_quantity, p.products_status, p.master_categories_id,"
    . " p.manufacturers_id, p.products_image, p.product_is_call,"
    . " pt.allow_add_to_cart,"
    . " pd.products_name, pd.products_description,"
    . " m.manufacturers_name"
    . " FROM " . TABLE_PRODUCTS . " p"
    . " INNER JOIN " . TABLE_PRODUCTS_DESCRIPTION . " pd"
    . "   ON pd.products_id = p.products_id AND pd.language_id = " . $languageId
    . " LEFT JOIN " . TABLE_MANUFACTURERS . " m"
    . "   ON m.manufacturers_id = p.manufacturers_id"
    . " LEFT JOIN " . TABLE_PRODUCT_TYPES . " pt"
    . "   ON pt.type_id = p.products_type"
    . " WHERE p.products_status = 1"
    . " ORDER BY p.products_id ASC";

if ($npfForceOosColumn !== null) {
    $baseSql = str_replace(
        ' p.product_is_call,',
        ' p.product_is_call, p.`' . $npfForceOosColumn . '`,',
        $baseSql
    );
}

$products = $db->Execute($baseSql);
if (!$products || $products->RecordCount() === 0) {
    _push_log('warn', 'product scan returned zero rows.');
    exit(0);
}

// --------------------------------------------------------------
// Per-product helpers
// --------------------------------------------------------------

/**
 * Strip HTML and collapse whitespace for the description field.
 * The gateway's tokenizer doesn't need raw HTML and the wire size
 * matters — Zen Cart product descriptions are commonly 5-20kB of
 * marketing HTML.
 */
function _push_clean_description(string $raw): string
{
    if ($raw === '') {
        return '';
    }
    $stripped = strip_tags($raw);
    $stripped = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $stripped = preg_replace('/\s+/u', ' ', $stripped);
    return trim((string)$stripped);
}

/**
 * Fetch all linked categories_id for a given products_id.
 *
 * @return int[]
 */
function _push_category_ids(int $productsId): array
{
    global $db;
    $rows = $db->Execute(
        'SELECT categories_id FROM ' . TABLE_PRODUCTS_TO_CATEGORIES
        . ' WHERE products_id = ' . $productsId
    );
    $ids = [];
    if ($rows) {
        foreach ($rows as $r) {
            $ids[] = (int)$r['categories_id'];
        }
    }
    return $ids;
}

/**
 * Walk the category tree from each linked category up to the root,
 * stitching the product's category breadcrumbs as a list of strings
 * (root-most first). Each breadcrumb is the full path of one linked
 * category, joined with " > " for readability in admin UIs and as a
 * facet token (the gateway tokenizer treats `>` as whitespace).
 *
 * @return string[]
 */
function _push_breadcrumbs(int $productsId, int $languageId): array
{
    global $db;
    static $catNameCache = [];
    static $catParentCache = [];

    $linkedCats = _push_category_ids($productsId);
    if ($linkedCats === []) {
        return [];
    }

    if ($catNameCache === []) {
        $rows = $db->Execute(
            'SELECT cd.categories_id, cd.categories_name, c.parent_id'
            . ' FROM ' . TABLE_CATEGORIES . ' c'
            . ' INNER JOIN ' . TABLE_CATEGORIES_DESCRIPTION . ' cd'
            . '   ON cd.categories_id = c.categories_id AND cd.language_id = ' . $languageId
        );
        if ($rows) {
            foreach ($rows as $r) {
                $cid = (int)$r['categories_id'];
                $catNameCache[$cid] = (string)$r['categories_name'];
                $catParentCache[$cid] = (int)$r['parent_id'];
            }
        }
    }

    $crumbs = [];
    foreach ($linkedCats as $cid) {
        $path = [];
        $cursor = $cid;
        $guard = 0;
        while ($cursor > 0 && $guard < 16) {
            if (!isset($catNameCache[$cursor])) {
                break;
            }
            array_unshift($path, $catNameCache[$cursor]);
            $cursor = $catParentCache[$cursor] ?? 0;
            $guard++;
        }
        if ($path !== []) {
            $crumbs[] = implode(' > ', $path);
        }
    }
    return array_values(array_unique($crumbs));
}

/**
 * Build the absolute image URL for the storefront-visible product
 * thumbnail. Zen Cart stores the relative path in
 * `products.products_image` (e.g. `category/widget-thumb.jpg`); the
 * gateway/typeahead needs a fully-qualified URL so the suggest
 * widget's `<img src=...>` works cross-origin.
 *
 * Order of preference:
 *
 *   1. HTTPS_SERVER + DIR_WS_HTTPS_CATALOG (when SSL is enabled
 *      catalog-side; matches what Zen Cart serves to logged-in
 *      shoppers).
 *   2. HTTP_SERVER + DIR_WS_CATALOG (the catalog's canonical
 *      non-SSL base — every Zen Cart install defines this).
 *
 * Returns an empty string when the product has no image OR when
 * neither base URL constant is defined (the suggest bundle then
 * renders the empty `<div class="thumb">` placeholder).
 *
 * NB: we intentionally do NOT use `zen_get_products_image()` here
 * because that helper returns an HTML `<img>` snippet sized for
 * Zen Cart's product grid; the gateway document wants a raw URL.
 */
function _push_image_url(string $rawImage): string
{
    $rel = trim($rawImage);
    if ($rel === '') {
        return '';
    }
    // Defend against absolute-URL paths leaking in (some storefronts
    // pre-bake CDN URLs into products_image).
    if (preg_match('#^https?://#i', $rel) === 1) {
        return $rel;
    }
    if (defined('DIR_WS_IMAGES') && stripos($rel, DIR_WS_IMAGES) !== 0) {
        $rel = ltrim((string) DIR_WS_IMAGES, '/') . ltrim($rel, '/');
    }
    // Zen Cart stores filenames verbatim and many catalogs have
    // product images with spaces or other reserved URL characters
    // (e.g. "Generic Numinix.png"). The bundle uses image_url as an
    // `<img src=...>` attribute -- a bare value with spaces happens
    // to work in modern browsers but breaks the gateway's URL
    // validator and downstream link checkers. Encode each path
    // segment while preserving the slashes.
    $rel = _push_encode_image_path($rel);
    if (defined('HTTPS_SERVER') && defined('DIR_WS_HTTPS_CATALOG')
        && defined('ENABLE_SSL_CATALOG') && (string) ENABLE_SSL_CATALOG === 'true'
    ) {
        return rtrim((string) HTTPS_SERVER, '/')
            . (string) DIR_WS_HTTPS_CATALOG
            . ltrim($rel, '/');
    }
    if (defined('HTTP_SERVER') && defined('DIR_WS_CATALOG')) {
        return rtrim((string) HTTP_SERVER, '/')
            . (string) DIR_WS_CATALOG
            . ltrim($rel, '/');
    }
    return '';
}

/**
 * Rawurlencode each path segment of a relative image path while
 * preserving the segment separators. Pass-through for segments that
 * are already safely encoded (e.g. cached thumbnails with `%20` baked
 * in) so we don't double-encode.
 */
function _push_encode_image_path(string $path): string
{
    if ($path === '') {
        return '';
    }
    $segments = explode('/', $path);
    foreach ($segments as $i => $seg) {
        if ($seg === '') {
            continue;
        }
        // If a segment already contains a percent followed by two
        // hex digits, treat it as pre-encoded and leave it alone --
        // re-encoding would turn "%20" into "%2520".
        if (preg_match('/%[0-9a-fA-F]{2}/', $seg) === 1) {
            continue;
        }
        $segments[$i] = rawurlencode($seg);
    }
    return implode('/', $segments);
}

/**
 * Build the public product URL using zen_href_link. Falls back to a
 * hand-built URL when zen_href_link isn't available (e.g. in a
 * future test harness).
 */
function _push_product_url(int $productsId): string
{
    if (function_exists('zen_href_link') && function_exists('zen_get_info_page')) {
        try {
            return (string) zen_href_link(
                zen_get_info_page($productsId),
                'products_id=' . $productsId,
                'NONSSL',
                false
            );
        } catch (\Throwable $e) {
            // Fall through to manual.
        }
    }
    $base = (defined('HTTP_SERVER') ? HTTP_SERVER : '') . (defined('DIR_WS_CATALOG') ? DIR_WS_CATALOG : '/');
    return rtrim($base, '/') . '/index.php?main_page=product_info&products_id=' . $productsId;
}

// --------------------------------------------------------------
// Main loop
// --------------------------------------------------------------

$batch = [];
$batchesSent = 0;
$docsSent = 0;
$docsFailed = 0;
$errors = [];

$flushBatch = function () use (
    &$batch,
    &$batchesSent,
    &$docsSent,
    &$docsFailed,
    &$errors,
    $collection,
    $dryRun,
    $verbose
): void {
    if ($batch === []) {
        return;
    }
    $size = count($batch);
    $batchesSent++;
    if ($dryRun) {
        $docsSent += $size;
        if ($verbose) {
            _push_log('info', sprintf('[dry] would push batch %d size=%d', $batchesSent, $size));
        }
        $batch = [];
        return;
    }
    $start = (int)(microtime(true) * 1000);
    $result = numinix_seekmodo_run_bulk_upsert($batch, $collection);
    $elapsed = (int)(microtime(true) * 1000) - $start;
    if (!is_array($result)) {
        // null = mode=off / shadow / circuit-open / transport failure.
        $docsFailed += $size;
        $errors[] = "batch {$batchesSent}: null (gateway unreachable / shadow mode / circuit open)";
        _push_log('warn', sprintf('batch %d size=%d returned NULL after %dms', $batchesSent, $size, $elapsed));
        $batch = [];
        return;
    }
    $ok = (int)($result['count_ok'] ?? 0);
    $failed = (int)($result['count_failed'] ?? 0);
    $docsSent += $ok;
    $docsFailed += $failed;
    if (!empty($result['errors']) && is_array($result['errors'])) {
        foreach ($result['errors'] as $err) {
            $errors[] = "batch {$batchesSent}: " . (string)$err;
        }
    }
    if ($verbose || $failed > 0) {
        _push_log(
            $failed > 0 ? 'warn' : 'info',
            sprintf('batch %d size=%d ok=%d failed=%d %dms', $batchesSent, $size, $ok, $failed, $elapsed)
        );
    }
    $batch = [];
};

$rownum = 0;
foreach ($products as $row) {
    $rownum++;
    $pid = (int)$row['products_id'];
    if ($pid <= 0) {
        continue;
    }
    $name = trim((string)$row['products_name']);
    if ($name === '') {
        continue;
    }
    $doc = [
        'id'   => (string)$pid,
        'name' => $name,
    ];
    if (!empty($row['products_model'])) {
        $doc['model'] = (string)$row['products_model'];
        $doc['sku']   = (string)$row['products_model'];
    }
    $desc = _push_clean_description((string)($row['products_description'] ?? ''));
    if ($desc !== '') {
        $doc['description'] = $desc;
    }
    if (!empty($row['manufacturers_name'])) {
        $doc['brand'] = (string)$row['manufacturers_name'];
    }
    $catIds = _push_category_ids($pid);
    if ($catIds !== []) {
        $doc['category_id'] = $catIds;
    }
    if (isset($row['products_type']) && (int)$row['products_type'] > 0) {
        $doc['p_type'] = (int)$row['products_type'];
    }
    $crumbs = _push_breadcrumbs($pid, $languageId);
    if ($crumbs !== []) {
        $doc['category_breadcrumbs'] = $crumbs;
    }
    if (isset($row['products_price'])) {
        $doc['price'] = (float)$row['products_price'];
    }
    $inStock = ((int)($row['products_quantity'] ?? 0) > 0);
    $doc['in_stock'] = $inStock;
    $allowCart = !isset($row['allow_add_to_cart'])
        || (string)$row['allow_add_to_cart'] !== 'N';
    $stockAllowCheckout = defined('STOCK_ALLOW_CHECKOUT') && STOCK_ALLOW_CHECKOUT === 'true';
    $isCall = (int)($row['product_is_call'] ?? 0) === 1;
    $npfForceOos = false;
    if ($npfForceOosColumn !== null && array_key_exists($npfForceOosColumn, $row)) {
        $npfForceOos = (int)$row[$npfForceOosColumn] === 1;
    }
    $doc['purchasable'] = $allowCart
        && !$isCall
        && !$npfForceOos
        && ($inStock || $stockAllowCheckout);
    $doc['url'] = _push_product_url($pid);
    $imageUrl = _push_image_url((string) ($row['products_image'] ?? ''));
    if ($imageUrl !== '') {
        $doc['image_url'] = $imageUrl;
    }

    $batch[] = $doc;
    if (count($batch) >= $batchSize) {
        $flushBatch();
        if ($maxBatches > 0 && $batchesSent >= $maxBatches) {
            _push_log('info', "max-batches={$maxBatches} reached; stopping early.");
            break;
        }
    }
}
$flushBatch();

// --------------------------------------------------------------
// Wrap-up
// --------------------------------------------------------------

_push_log('info', sprintf(
    'done. total_rows=%d batches=%d docs_sent=%d docs_failed=%d errors=%d',
    $rownum,
    $batchesSent,
    $docsSent,
    $docsFailed,
    count($errors)
));
if ($errors !== []) {
    foreach (array_slice($errors, 0, 10) as $err) {
        _push_log('warn', '  ' . $err);
    }
    if (count($errors) > 10) {
        _push_log('warn', '  … and ' . (count($errors) - 10) . ' more.');
    }
}

require_once 'includes/application_bottom.php';
exit($docsFailed > 0 ? 1 : 0);
