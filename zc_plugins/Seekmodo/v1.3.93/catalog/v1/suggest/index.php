<?php
/**
 * Same-origin rich suggest proxy for `<seekmodo-suggest>` (proxy-first).
 *
 * The stock SDK POSTs to `{seekmodo:gateway}/v1/suggest`. SuggestObserver
 * points gateway at the catalog origin, so this file must live at
 * catalog-root `/v1/suggest/index.php` (deployed by ScriptedInstaller +
 * tools/sync_catalog_shims.php).
 *
 * Body: JSON `{ q, limit?, session_id?, … }` — returns the gateway
 * rich envelope (or an empty envelope on failure). Authorization is
 * ignored; HMAC to mcp is server-side via Client::suggest().
 */

declare(strict_types=1);

// Zen Cart CSRF: init_sanitize rejects POSTs without securityToken.
// This shim only reads the raw JSON body (never $_POST form fields).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = [];
}

$applicationTopCandidates = [
    __DIR__ . '/../../includes/application_top.php',
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
    echo json_encode(['error' => 'application_top_not_found']);
    return;
}

$catalogRoot = dirname($applicationTopPath, 2);
if (is_dir($catalogRoot)) {
    chdir($catalogRoot);
}
require $applicationTopPath;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$empty = [
    'keywords'     => [],
    'products'     => [],
    'categories'   => [],
    'recent'       => [],
    'trending'     => [],
    'did_you_mean' => null,
    'meta'         => [
        'surface_id' => 'suggest',
        'total'      => 0,
        'counts'     => new stdClass(),
    ],
];

$raw = file_get_contents('php://input');
$params = [];
if (is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $params = $decoded;
    }
}
if ($params === []) {
    $qGet = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
    if ($qGet !== '') {
        $params['q'] = $qGet;
        if (isset($_GET['limit'])) {
            $params['limit'] = (int) $_GET['limit'];
        } elseif (isset($_GET['max'])) {
            $params['limit'] = (int) $_GET['max'];
        }
    }
}

$q = isset($params['q']) ? trim((string) $params['q']) : '';
if ($q === '' || mb_strlen($q) < 2) {
    echo json_encode($empty, JSON_UNESCAPED_SLASHES);
    return;
}

$limit = isset($params['limit']) ? (int) $params['limit'] : (isset($params['max']) ? (int) $params['max'] : 8);
$limit = max(1, min(25, $limit));

if (function_exists('numinix_seekmodo_ensure_plugin_init')) {
    numinix_seekmodo_ensure_plugin_init();
}

if (!function_exists('numinix_seekmodo_suggest')) {
    echo json_encode($empty, JSON_UNESCAPED_SLASHES);
    return;
}

$body = [
    'q'     => $q,
    'limit' => $limit,
];
foreach (['session_id', 'ua', 'ip', 'referer', 'filter_by', 'lang', 'language', 'vertical'] as $key) {
    if (isset($params[$key]) && $params[$key] !== '' && $params[$key] !== null) {
        $body[$key] = $params[$key];
    }
}

$envelope = numinix_seekmodo_suggest($body);
if (!is_array($envelope)) {
    echo json_encode($empty, JSON_UNESCAPED_SLASHES);
    return;
}

echo json_encode($envelope, JSON_UNESCAPED_SLASHES);
