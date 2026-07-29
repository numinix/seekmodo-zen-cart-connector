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
 * Skip orphan reconcile (debugging stale-id questions):
 *
 *   /usr/bin/php .../numinix_seekmodo_push_catalog.php --no-prune
 *
 * Override batch size (default = NUMINIX_SEEKMODO_INDEX_BATCH or 500):
 *
 *   /usr/bin/php zc_plugins/Seekmodo/v1.0.10/catalog/numinix_seekmodo_push_catalog.php --batch=200
 *
 * Manual full push after operator quota ack (admin index.trigger or
 * informed SSH reindex — skips automated quota preflight and stamps
 * X-Seekmodo-Index-Intent: manual on each /v1/index batch):
 *
 *   /usr/bin/php .../numinix_seekmodo_push_catalog.php --ack-quota
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

$___smLogLib = __DIR__ . '/includes/functions/numinix_seekmodo_log_lib.php';
if (is_file($___smLogLib)) {
    require_once $___smLogLib;
}
if (function_exists('numinix_seekmodo_require_cli')) {
    numinix_seekmodo_require_cli('numinix_seekmodo_push_catalog.php');
} elseif (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo "FATAL: numinix_seekmodo_push_catalog.php must run from CLI\n";
    exit(2);
}

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
    if (function_exists("numinix_seekmodo_stderr")) { numinix_seekmodo_stderr("ERROR: cannot resolve catalog docroot from " . __FILE__ . "\n"); }
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

// v1.3.24 — ensure zc_plugins catalog init when auto_loaders did not merge.
$ensureHelpers = [
    __DIR__ . '/includes/functions/numinix_seekmodo_ensure_plugin_init.php',
];
if (defined('DIR_FS_CATALOG') && is_string(DIR_FS_CATALOG) && DIR_FS_CATALOG !== '') {
    $ensureHelpers = array_merge(
        $ensureHelpers,
        glob(rtrim(str_replace('\\', '/', DIR_FS_CATALOG), '/') . '/zc_plugins/Seekmodo/v*/catalog/includes/functions/numinix_seekmodo_ensure_plugin_init.php') ?: []
    );
}
$ensureHelpers = array_values(array_unique(array_filter($ensureHelpers, 'is_file')));
usort($ensureHelpers, 'strnatcmp');
$ensureHelpers = array_reverse($ensureHelpers);
if ($ensureHelpers !== []) {
    require_once $ensureHelpers[0];
}
if (function_exists('numinix_seekmodo_ensure_plugin_init')) {
    numinix_seekmodo_ensure_plugin_init();
}

// --------------------------------------------------------------
// CLI arg parsing (intentionally minimal — getopt covers it)
// --------------------------------------------------------------

$opts = getopt('', [
    'dry-run',
    'batch::',
    'max-batches::',
    'verbose',
    'site::',
    'no-prune',
    'ack-quota',
]);

$dryRun = isset($opts['dry-run']);
$verbose = isset($opts['verbose']);
$noPrune = isset($opts['no-prune']);
$ackQuota = isset($opts['ack-quota']);
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
    if (function_exists('numinix_seekmodo_stderr')) {
        numinix_seekmodo_stderr($line . "\n");
    } elseif (defined('STDERR') && is_resource(STDERR)) {
        fwrite(STDERR, $line . "\n");
    }
}

/**
 * Best-effort push of `last_full_push_skipped_reason` up to the
 * gateway so admin.seekmodo.com can surface "scheduled full push
 * skipped — indexing quota" on the connector status card.
 */
function _push_report_skipped_reason(string $reason): void
{
    try {
        if (!class_exists(\Numinix\Seekmodo\AutoPromoter::class)) {
            return;
        }
        (new \Numinix\Seekmodo\AutoPromoter())->pushSnapshot('full_push_skipped', [
            'last_full_push_skipped_reason' => $reason,
        ]);
    } catch (\Throwable $e) {
        // best-effort — gateway-down must never block the cron exit
    }
}

/**
 * Read index_quota.full_push_safe from a tenant.snapshot pull.
 * Fail-open (true) when the gateway is unreachable or the field is
 * absent — pre-v1.3.1 gateways omit index_quota entirely.
 */
function _push_full_push_safe(): bool
{
    if (!class_exists(\Numinix\Seekmodo\RemoteConfig::class)) {
        return true;
    }
    try {
        $rc = \Numinix\Seekmodo\RemoteConfig::fromConfiguration();
        if ($rc === null) {
            return true;
        }
        $snapshot = $rc->pull();
        if (!is_array($snapshot)) {
            return true;
        }
        $indexQuota = $snapshot['index_quota'] ?? null;
        if (!is_array($indexQuota) || !array_key_exists('full_push_safe', $indexQuota)) {
            return true;
        }
        return (bool) $indexQuota['full_push_safe'];
    } catch (\Throwable $e) {
        return true;
    }
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
    'starting catalog push tenant=%s collection=%s batch=%d dry_run=%s ack_quota=%s',
    $tenantId,
    $collection,
    $batchSize,
    $dryRun ? 'yes' : 'no',
    $ackQuota ? 'yes' : 'no'
));

// Phase A3 — automated quota preflight. Cron / managed full pushes
// skip cleanly when the gateway advisor says there isn't enough
// indexed_docs headroom for a full catalog walk. Delta ticks and
// dry-runs are unaffected; --ack-quota (Phase B3 manual consent)
// bypasses this gate and stamps X-Seekmodo-Index-Intent: manual.
if (!$dryRun && !$ackQuota && !_push_full_push_safe()) {
    _push_log('info', 'full_push_skipped_quota');
    _push_report_skipped_reason('quota');
    require_once 'includes/application_bottom.php';
    exit(0);
}

if ($ackQuota) {
    _push_log('info', 'full_push_manual_ack');
    if (function_exists('_numinix_seekmodo_client')) {
        $client = _numinix_seekmodo_client();
        if ($client !== null && method_exists($client, 'setIndexIntent')) {
            $client->setIndexIntent('manual');
        }
    }
}

// Stamp before the upsert loop so every doc touched this run survives
// catalog.prune below (same contract as AKS seekmodo:vlp-project).
$runStartedAt = time();
$pushRunStartedMs = (int) (microtime(true) * 1000);

// Best-effort flush of any pending per-product delete tombstones queued
// since the last full run (admin hard-delete / status=0 delta path).
if (!$dryRun && function_exists('numinix_seekmodo_flush_pending_catalog_deletes')) {
    numinix_seekmodo_flush_pending_catalog_deletes();
}

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
    . " p.products_ordered,"
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
        // Standalone pusher: run_bulk_upsert returns null in shadow by
        // design (swap-point contract for native Typesense fallthrough).
        // Treat that as observation-ok, not a failed push — otherwise
        // nightly shadow crons flood indexer logs with false WARN lines.
        $mode = function_exists('numinix_seekmodo_effective_mode')
            ? (string) numinix_seekmodo_effective_mode()
            : (function_exists('numinix_seekmodo_mode') ? (string) numinix_seekmodo_mode() : '');
        if ($mode === 'shadow') {
            $docsSent += $size;
            if ($verbose) {
                _push_log('info', sprintf(
                    'batch %d size=%d shadow observation (%dms; null expected)',
                    $batchesSent,
                    $size,
                    $elapsed
                ));
            }
            $batch = [];
            return;
        }
        // null = mode=off / circuit-open / transport failure.
        $docsFailed += $size;
        $errors[] = "batch {$batchesSent}: null (gateway unreachable / circuit open)";
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
    if (!function_exists('numinix_seekmodo_catalog_doc_from_row')) {
        require_once __DIR__ . '/includes/functions/numinix_seekmodo_catalog_doc_lib.php';
    }
    $doc = numinix_seekmodo_catalog_doc_from_row($row, $languageId, $npfForceOosColumn);
    if ($doc === null) {
        continue;
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
// Orphan reconcile (catalog.prune)
// --------------------------------------------------------------
//
// After a fully successful upsert pass, evict Typesense docs that
// were not re-stamped during this run (hard-deleted SKUs, etc.).

$evictedOrphans = 0;
$pruneErrors = [];
if (
    !$dryRun
    && !$noPrune
    && $docsFailed === 0
    && function_exists('numinix_seekmodo_catalog_prune')
) {
    $pruneResult = numinix_seekmodo_catalog_prune($runStartedAt, $rownum);
    if (is_array($pruneResult)) {
        $evictedOrphans = (int) ($pruneResult['deleted'] ?? 0);
        if (!empty($pruneResult['errors']) && is_array($pruneResult['errors'])) {
            foreach ($pruneResult['errors'] as $err) {
                $pruneErrors[] = is_scalar($err) ? (string) $err : json_encode($err);
            }
        }
        if ($verbose || $evictedOrphans > 0) {
            _push_log('info', sprintf(
                'catalog prune cutoff_epoch=%d evicted_orphans=%d scanned=%d has_more=%s',
                $runStartedAt,
                $evictedOrphans,
                (int) ($pruneResult['scanned'] ?? 0),
                !empty($pruneResult['has_more']) ? 'yes' : 'no'
            ));
        }
    } elseif ($verbose) {
        _push_log('warn', 'catalog prune skipped (gateway unreachable / shadow mode / circuit open)');
    }
}

// --------------------------------------------------------------
// Wrap-up
// --------------------------------------------------------------

_push_log('info', sprintf(
    'done. total_rows=%d batches=%d docs_sent=%d docs_failed=%d evicted_orphans=%d prune_errors=%d errors=%d',
    $rownum,
    $batchesSent,
    $docsSent,
    $docsFailed,
    $evictedOrphans,
    count($pruneErrors),
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
if ($pruneErrors !== []) {
    foreach (array_slice($pruneErrors, 0, 5) as $err) {
        _push_log('warn', '  prune: ' . $err);
    }
}

if (
    !$dryRun
    && $docsFailed === 0
    && function_exists('numinix_seekmodo_record_indexer_run')
) {
    $durationS = max(0, (int) ((microtime(true) * 1000) - $pushRunStartedMs) / 1000);
    numinix_seekmodo_record_indexer_run('full_push', $durationS, $docsSent);
}

require_once 'includes/application_bottom.php';
exit($docsFailed > 0 ? 1 : 0);
