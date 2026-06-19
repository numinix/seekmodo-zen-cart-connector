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

$src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$keyword = isset($src['keyword']) ? trim((string) $src['keyword']) : '';
$productsId = isset($src['products_id']) ? (int) $src['products_id'] : 0;
$position = isset($src['position']) ? (int) $src['position'] : 0;
$surface = isset($src['surface']) && (string) $src['surface'] !== ''
    ? (string) $src['surface']
    : 'competitor_serp_js';

if ($keyword === '' || $productsId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_keyword_or_products_id']);
    return;
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

$opts = ['surface' => $surface];
if (isset($src['search_event_id']) && is_numeric($src['search_event_id'])) {
    $opts['search_event_id'] = (int) $src['search_event_id'];
}

numinix_seekmodo_mirror_click($keyword, $productsId, max(0, $position), null, $opts);

echo json_encode(['ok' => true]);
