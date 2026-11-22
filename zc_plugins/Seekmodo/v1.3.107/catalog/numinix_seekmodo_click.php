<?php
/**
 * Fire-and-forget click beacon for competitor-rendered SERPs (Klevu)
 * and other surfaces where HTTP Referer may be missing (target=_blank).
 *
 * POST/GET params:
 *   keyword      (required)
 *   products_id  (required)
 *   position     (optional, default 0)
 *   surface      (optional, default competitor_serp_js)
 *   search_event_id (optional)
 */

declare(strict_types=1);

// Capture beacon params before ZC bootstrap. init_sanitize.php redirects any
// request that carries products_id without a valid main_page to product_info,
// which would swallow sendBeacon POSTs and GET probes alike.
$clickSrc = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') ? $_POST : $_GET;
$clickKeyword = isset($clickSrc['keyword']) ? trim((string) $clickSrc['keyword']) : '';
$clickProductsId = isset($clickSrc['products_id']) ? (int) $clickSrc['products_id'] : 0;
$clickPosition = isset($clickSrc['position']) ? (int) $clickSrc['position'] : 0;
$clickSurface = isset($clickSrc['surface']) && (string) $clickSrc['surface'] !== ''
    ? (string) $clickSrc['surface']
    : 'competitor_serp_js';
$clickSearchEventId = (isset($clickSrc['search_event_id']) && is_numeric($clickSrc['search_event_id']))
    ? (int) $clickSrc['search_event_id']
    : 0;

unset($_GET['products_id'], $_POST['products_id'], $_REQUEST['products_id']);

$applicationTopCandidates = [
    __DIR__ . '/includes/application_top.php',
    __DIR__ . '/../../../../includes/application_top.php',
];
$applicationTopPath = null;
foreach ($applicationTopCandidates as $candidate) {
    if (is_file($candidate)) {
        $applicationTopPath = $candidate;
        break;
    }
}
if ($applicationTopPath === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'application_top_not_found']);
    return;
}
$includesDir = dirname((string) realpath($applicationTopPath));
$catalogRoot = dirname($includesDir);
if ($catalogRoot !== '' && is_dir($catalogRoot)) {
    chdir($catalogRoot);
}
require $applicationTopPath;

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

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if ($clickKeyword === '' || $clickProductsId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_keyword_or_products_id']);
    return;
}

if (!function_exists('numinix_seekmodo_mirror_click')) {
    if (function_exists('numinix_seekmodo_ensure_plugin_init')) {
        numinix_seekmodo_ensure_plugin_init();
    }
}

if (!function_exists('numinix_seekmodo_mirror_click')) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'connector_not_loaded']);
    return;
}

if (!function_exists('numinix_seekmodo_enabled') || !numinix_seekmodo_enabled()) {
    http_response_code(200);
    echo json_encode(['ok' => false, 'skipped' => 'disabled']);
    return;
}

$opts = ['surface' => $clickSurface];
if ($clickSearchEventId > 0) {
    $opts['search_event_id'] = $clickSearchEventId;
}

numinix_seekmodo_mirror_click($clickKeyword, $clickProductsId, max(0, $clickPosition), null, $opts);

echo json_encode(['ok' => true]);
