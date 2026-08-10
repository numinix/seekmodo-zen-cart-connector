<?php
/**
 * Sprint 4 PR 6 — storefront-side recommendations AJAX endpoint.
 *
 * The client-side `jscript_seekmodo_recommendations.js` walks the
 * page for `<div data-seekmodo-placement="...">` placeholders and
 * GETs this endpoint with `?placement=...&doc_id=...&limit=...`.
 * Each placement render is exactly one fetch, and each fetch
 * eventually bills exactly one `searches` token on the gateway
 * (Sprint 4 PR 3 metering: recommend.* surfaces -> searches master
 * bucket + searches_recommend display bucket; bots excluded).
 *
 * Why this is a dedicated catalog-level endpoint (rather than
 * folding into the typeahead endpoint):
 *
 *   1. The HMAC signing has to happen server-side — the storefront
 *      JS can't see the tenant secret. Same reason the typeahead
 *      endpoint exists; we keep the two distinct so the storefront's
 *      single-page navigation can keep separate response caches.
 *   2. The response shape is recommend-tool-specific (anchor /
 *      recommendations[] / source attribution) and worth its own
 *      envelope so the JS doesn't have to sniff for product vs
 *      recommendation rows.
 *
 * Placement -> algorithm mapping is read from the connector config
 * defaults (NUMINIX_SEEKMODO_RECOMMENDATIONS_ENABLED gates the
 * whole feature). Any unknown placement returns `ok:false`.
 *
 * Response shape (always 200 unless the request is invalid):
 *   {
 *     ok: true,
 *     placement: "pdp-related",
 *     anchor_doc_id: "12345",
 *     algorithm: "related",
 *     recommendations: [
 *       { doc_id, score, source: 'co_view'|'lexical'|...,
 *         products_id, name, model, price, url, image }, ...
 *     ],
 *     total: int
 *   }
 * or
 *   {
 *     ok: false,
 *     reason: "off|locked_out|missing_doc_id|bad_placement|gateway_failure|disabled"
 *   }
 *
 * No HMAC signing here — `Client::call` does that server-to-gateway.
 */

declare(strict_types=1);

// v1.0.22: see numinix_seekmodo_suggest.php for the why — resolve
// `includes/application_top.php` via __DIR__ so the shim works whether
// it's served from the live catalog root or the plugin's versioned
// dir under `/catalog/zc_plugins/Seekmodo/v<version>/catalog/`.
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

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    return;
}

// Feature gate — when the operator hasn't enabled recommendations in
// the connector config, every placeholder div on the page just
// renders nothing. Saves one round-trip per page during a controlled
// rollout.
$enabled = false;
if (function_exists('_numinix_seekmodo_cfg')) {
    $enabled = ((string)_numinix_seekmodo_cfg(
        'NUMINIX_SEEKMODO_RECOMMENDATIONS_ENABLED',
        'false'
    ) === 'true');
}
if (!$enabled) {
    echo json_encode(['ok' => false, 'reason' => 'disabled']);
    return;
}

if (function_exists('numinix_seekmodo_is_locked_out')
    && numinix_seekmodo_is_locked_out()) {
    echo json_encode(['ok' => false, 'reason' => 'locked_out']);
    return;
}

$placement = isset($_GET['placement']) ? trim((string)$_GET['placement']) : '';
$docId = isset($_GET['doc_id']) ? trim((string)$_GET['doc_id']) : '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 8;
$limit = max(1, min(50, $limit));

// Placement -> algorithm map. Mirrors the ship-default placement
// keys from migration 025 (services/mcp-gateway/sql/migrations/025_reco_placements.sql),
// minus the 'pdp.bundle' / 'cart.bundle' ones which route to
// bundle.suggest (Growth+ only).
//
// Operators on the admin side may have configured custom
// `placement_key`s pointing to alternate tools — the gateway-side
// `numinix_mcp_reco_placements` table is the authority on the
// connector's eventual storefront DOM, but this map is the JS
// equivalent on the page render side. Future PR 7+ will fold the
// admin table into a connector-side cache; v1.0.15 ships with this
// static mapping so the JS doesn't need a per-render config fetch.
$placementMap = [
    'pdp-related'        => ['algo' => 'related',        'needs_doc' => true],
    'pdp-also-bought'    => ['algo' => 'also_bought',    'needs_doc' => true],
    'pdp-also-viewed'    => ['algo' => 'also_viewed',    'needs_doc' => true],
    'pdp-bundle'         => ['algo' => 'bundle.suggest', 'needs_doc' => true],
    'pdp-cascade'        => ['algo' => 'pdp',            'needs_doc' => true, 'cascade' => true],
    'cart'               => ['algo' => 'cart',           'needs_doc' => false, 'cascade' => true],
    'cart_below'         => ['algo' => 'cart',           'needs_doc' => false, 'cascade' => true],
    'cart-also-bought'   => ['algo' => 'also_bought',    'needs_doc' => true],
    'cart-bundle'        => ['algo' => 'bundle.suggest', 'needs_doc' => true],
    'home-trending'      => ['algo' => 'trending',       'needs_doc' => false],
    'category-trending'  => ['algo' => 'trending',       'needs_doc' => false],
];

if ($placement === '' || !isset($placementMap[$placement])) {
    echo json_encode(['ok' => false, 'reason' => 'bad_placement']);
    return;
}

$cfg = $placementMap[$placement];
$algorithm = $cfg['algo'];
$isCascade = !empty($cfg['cascade']);

if ($cfg['needs_doc'] && ($docId === '' || !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $docId))) {
    echo json_encode(['ok' => false, 'reason' => 'missing_doc_id']);
    return;
}

// Auto-load defensive — prefer zc_plugins init; legacy catalog includes/ fallback.
if (!function_exists('numinix_seekmodo_recommend')) {
    if (function_exists('numinix_seekmodo_ensure_plugin_init')) {
        numinix_seekmodo_ensure_plugin_init();
    }
    $libDir = (defined('DIR_FS_CATALOG') ? DIR_FS_CATALOG : __DIR__ . '/')
        . 'includes/functions/';
    if (is_file($libDir . 'numinix_seekmodo_client.php')) {
        require_once $libDir . 'numinix_seekmodo_client.php';
    }
}
if (!function_exists('numinix_seekmodo_recommend')) {
    echo json_encode(['ok' => false, 'reason' => 'connector_unavailable']);
    return;
}

$payload = ['limit' => $limit];
if ($cfg['needs_doc']) {
    $payload['anchor_doc_id'] = $docId;
}

// Bundle composer takes a bundle_size knob; map it from a separate
// query param so the JS can override per placement.
if ($algorithm === 'bundle.suggest') {
    $size = isset($_GET['bundle_size']) ? (int)$_GET['bundle_size'] : 3;
    $payload['bundle_size'] = max(2, min(5, $size));
}

// Shopper-context attribution — mirrors the suggest path so bot-check /
// telemetry / metering agree across surfaces.
$payload['session_id'] = function_exists('_numinix_seekmodo_session_id')
    ? _numinix_seekmodo_session_id()
    : '';
if (isset($_SERVER['HTTP_USER_AGENT']) && $_SERVER['HTTP_USER_AGENT'] !== '') {
    $payload['ua'] = substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 512);
} else {
    $payload['ua'] = '';
}
$payload['ip'] = function_exists('_numinix_seekmodo_client_ip')
    ? _numinix_seekmodo_client_ip()
    : (isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '');
if (!empty($_SERVER['HTTP_REFERER'])) {
    $payload['referer'] = substr((string)$_SERVER['HTTP_REFERER'], 0, 255);
}

if (function_exists('numinix_seekmodo_shopper_context')) {
    $payload['shopper_context'] = numinix_seekmodo_shopper_context();
}

/**
 * Hydrate gateway recommendation rows with Zen Cart URL / price / image.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
$hydrateRows = static function (array $rows): array {
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $docIdR = (string)($row['doc_id'] ?? '');
        if ($docIdR === '') {
            continue;
        }
        $pid = (int)$docIdR;
        if ($pid > 0) {
            $row['products_id'] = $pid;
            if (function_exists('zen_get_products_display_price')) {
                $row['price_formatted'] = (string)@zen_get_products_display_price($pid);
            }
            if (empty($row['name']) && function_exists('zen_get_products_name')) {
                $row['name'] = (string)@zen_get_products_name($pid);
            }
            if (function_exists('zen_href_link') && function_exists('zen_get_info_page')) {
                try {
                    $row['url'] = (string)zen_href_link(
                        zen_get_info_page($pid),
                        'products_id=' . $pid
                    );
                } catch (\Throwable $e) {
                    // skip URL on private/pending product
                }
            }
            if (function_exists('zen_get_products_image')) {
                try {
                    $row['image_html'] = (string)@zen_get_products_image($pid, 120, 120);
                } catch (\Throwable $e) {
                    // decorative
                }
            }
        }
        $out[] = $row;
    }
    return $out;
};

/**
 * Category peers from Zen Cart products_to_categories (popular fill).
 *
 * @param list<int> $productIds
 * @param int $limit
 * @return list<array<string,mixed>>
 */
$categoryPeers = static function (array $productIds, int $limit) use ($hydrateRows): array {
    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn ($id) => $id > 0)));
    if ($productIds === [] || $limit <= 0 || !isset($GLOBALS['db'])) {
        return [];
    }
    $db = $GLOBALS['db'] ?? null;
    if ($db === null || !is_object($db) || !method_exists($db, 'Execute')) {
        return [];
    }
    $in = implode(',', $productIds);
    $catSql = "SELECT DISTINCT categories_id FROM " . TABLE_PRODUCTS_TO_CATEGORIES
        . " WHERE products_id IN (" . $in . ")";
    $catRes = $db->Execute($catSql);
    $catIds = [];
    while (!$catRes->EOF) {
        $cid = (int)($catRes->fields['categories_id'] ?? 0);
        if ($cid > 0) {
            $catIds[] = $cid;
        }
        $catRes->MoveNext();
    }
    $catIds = array_values(array_unique($catIds));
    if ($catIds === []) {
        return [];
    }
    $exclude = implode(',', $productIds);
    $catIn = implode(',', $catIds);
    $peerSql = "SELECT DISTINCT p.products_id
        FROM " . TABLE_PRODUCTS . " p
        INNER JOIN " . TABLE_PRODUCTS_TO_CATEGORIES . " ptc
          ON p.products_id = ptc.products_id
        WHERE ptc.categories_id IN (" . $catIn . ")
          AND p.products_status = 1
          AND p.products_id NOT IN (" . $exclude . ")
        ORDER BY p.products_ordered DESC, p.products_id DESC
        LIMIT " . (int)max(1, min(50, $limit * 3));
    $peerRes = $db->Execute($peerSql);
    $rows = [];
    while (!$peerRes->EOF) {
        $pid = (int)($peerRes->fields['products_id'] ?? 0);
        if ($pid > 0) {
            $rows[] = [
                'doc_id' => (string)$pid,
                'score'  => 0.0,
                'source' => 'category_peer',
            ];
        }
        $peerRes->MoveNext();
    }
    return $hydrateRows($rows);
};

$recommendFn = static function (string $algo, array $params) {
    return numinix_seekmodo_recommend($algo, $params);
};

if ($isCascade && $algorithm === 'pdp') {
    if (!class_exists('\\Numinix\\Seekmodo\\RecommendationsCascade')) {
        echo json_encode(['ok' => false, 'reason' => 'connector_unavailable']);
        return;
    }
    $peers = $categoryPeers([(int)$docId], $limit);
    $placements = \Numinix\Seekmodo\RecommendationsCascade::runPdp(
        $docId,
        $limit,
        $payload,
        $recommendFn,
        $hydrateRows,
        $peers
    );
    echo json_encode([
        'ok' => true,
        'placement' => 'pdp-cascade',
        'anchor_doc_id' => $docId,
        'algorithm' => 'pdp',
        'placements' => [
            'bought'  => $placements['bought'],
            'related' => $placements['related'],
            'popular' => $placements['popular'],
        ],
        'recommendations' => $placements['related'],
        'meta' => $placements['meta'],
        'total' => count($placements['bought']) + count($placements['related']) + count($placements['popular']),
    ], JSON_UNESCAPED_SLASHES);
    return;
}

if ($isCascade && $algorithm === 'cart') {
    if (!class_exists('\\Numinix\\Seekmodo\\RecommendationsCascade')) {
        echo json_encode(['ok' => false, 'reason' => 'connector_unavailable']);
        return;
    }
    $parseIds = static function (string $raw): array {
        $out = [];
        foreach (preg_split('/[,\s]+/', $raw) ?: [] as $part) {
            $part = trim((string)$part);
            if ($part !== '' && ctype_digit($part) && (int)$part > 0) {
                $out[] = $part;
            }
        }
        return array_values(array_unique($out));
    };
    $anchors = $parseIds(isset($_GET['doc_ids']) ? (string)$_GET['doc_ids'] : '');
    if ($anchors === [] && $docId !== '') {
        $anchors = [$docId];
    }
    $exclude = $parseIds(isset($_GET['exclude_doc_ids']) ? (string)$_GET['exclude_doc_ids'] : '');
    if ($exclude === []) {
        $exclude = $anchors;
    }
    if ($anchors === []) {
        echo json_encode(['ok' => false, 'reason' => 'missing_doc_id']);
        return;
    }
    $peerSeed = array_map('intval', array_merge($anchors, $exclude));
    $peers = $categoryPeers($peerSeed, $limit);
    $cartLimit = max(1, min(24, isset($_GET['limit']) ? (int)$_GET['limit'] : 12));
    $result = \Numinix\Seekmodo\RecommendationsCascade::runCart(
        $anchors,
        $exclude,
        $cartLimit,
        $payload,
        $recommendFn,
        $hydrateRows,
        $peers
    );
    echo json_encode([
        'ok' => true,
        'placement' => 'cart',
        'algorithm' => 'cart',
        'recommendations' => $result['recommendations'],
        'meta' => $result['meta'],
        'total' => count($result['recommendations']),
    ], JSON_UNESCAPED_SLASHES);
    return;
}

$resp = numinix_seekmodo_recommend($algorithm, $payload);
if (!is_array($resp)) {
    echo json_encode(['ok' => false, 'reason' => 'gateway_failure']);
    return;
}

$recs = $hydrateRows(\Numinix\Seekmodo\RecommendationsCascade::extractRows($resp));

echo json_encode([
    'ok' => true,
    'placement' => $placement,
    'anchor_doc_id' => $docId,
    'algorithm' => $algorithm,
    'recommendations' => $recs,
    'total' => count($recs),
], JSON_UNESCAPED_SLASHES);
