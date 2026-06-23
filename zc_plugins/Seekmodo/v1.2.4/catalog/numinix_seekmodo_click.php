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

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if ($clickKeyword === '' || $clickProductsId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_keyword_or_products_id']);
    return;
}

if (!function_exists('numinix_seekmodo_mirror_click')) {
    // Plugin libs live under zc_plugins/Seekmodo/v*/catalog/, not in the
    // live catalog includes/ tree. Prefer the active init bootstrap.
    $pluginRoot = (defined('DIR_FS_CATALOG') ? DIR_FS_CATALOG : __DIR__ . '/')
        . 'zc_plugins/Seekmodo/';
    $initFiles = glob($pluginRoot . 'v*/catalog/includes/init_includes/init_numinix_seekmodo.php') ?: [];
    usort($initFiles, 'strnatcmp');
    $initFiles = array_reverse($initFiles);
    foreach ($initFiles as $initFile) {
        if (is_file($initFile)) {
            require_once $initFile;
            break;
        }
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
