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
require $applicationTopPath;

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

if ($cfg['needs_doc'] && ($docId === '' || !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $docId))) {
    echo json_encode(['ok' => false, 'reason' => 'missing_doc_id']);
    return;
}

// Auto-load defensive — application_top already pulls in the boot
// init, but if init_numinix_seekmodo.php is disabled we still want
// the JS to get a clean fallback response.
if (!function_exists('numinix_seekmodo_recommend')) {
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

// v1.0.16 (search-features-plan Sprint 5 PR 6) — per-shopper
// personalization envelope. PersonalizedRanker on the gateway picks
// up a returning shopper from this map and reorders the recommend.*
// candidates by affinity. Empty / DNP-opted-out shoppers fall back
// to the unpersonalized base ranking.
if (function_exists('numinix_seekmodo_shopper_context')) {
    $payload['shopper_context'] = numinix_seekmodo_shopper_context();
}

$resp = numinix_seekmodo_recommend($algorithm, $payload);
if (!is_array($resp)) {
    echo json_encode(['ok' => false, 'reason' => 'gateway_failure']);
    return;
}

// recommend.* envelopes carry `recommendations[]`; bundle.suggest
// carries `bundle` + `alternatives[]` — flatten the bundle path into
// the same shape so the JS only has to branch once for the algo.
$recs = [];
if ($algorithm === 'bundle.suggest') {
    $bundle = $resp['bundle'] ?? null;
    if (is_array($bundle) && isset($bundle['picks']) && is_array($bundle['picks'])) {
        foreach ($bundle['picks'] as $pick) {
            if (!is_array($pick)) {
                continue;
            }
            $recs[] = [
                'doc_id' => (string)($pick['doc_id'] ?? ''),
                'score'  => isset($bundle['score']) ? (float)$bundle['score'] : 0.0,
                'source' => 'bundle',
                'name'   => (string)($pick['title'] ?? ''),
                'brand'  => (string)($pick['brand'] ?? ''),
                'price'  => isset($pick['price']) ? (float)$pick['price'] : null,
                'image'  => isset($pick['image_url']) ? (string)$pick['image_url'] : '',
            ];
        }
    }
} else {
    $raw = $resp['recommendations'] ?? [];
    if (is_array($raw)) {
        foreach ($raw as $r) {
            if (!is_array($r)) {
                continue;
            }
            $docIdR = (string)($r['doc_id'] ?? '');
            if ($docIdR === '') {
                continue;
            }
            $row = [
                'doc_id' => $docIdR,
                'score'  => isset($r['score']) ? (float)$r['score'] : 0.0,
                'source' => (string)($r['source'] ?? ''),
            ];
            // Hydrate display fields the storefront card needs.
            // recommend.* tools include hydrated Typesense docs as a
            // sibling `hits[]` array indexed by doc_id; we look it up
            // there if present.
            if (isset($resp['hits']) && is_array($resp['hits']) && isset($resp['hits'][$docIdR])
                && is_array($resp['hits'][$docIdR])) {
                $doc = $resp['hits'][$docIdR];
                $row['name'] = (string)($doc['name'] ?? '');
                $row['model'] = (string)($doc['model'] ?? '');
                if (isset($doc['price'])) {
                    $row['price'] = (float)$doc['price'];
                }
                if (isset($doc['image'])) {
                    $row['image'] = (string)$doc['image'];
                }
            }
            // Zen Cart-side hydration (URL, formatted price, thumbnail).
            $pid = (int)$docIdR;
            if ($pid > 0) {
                if (function_exists('zen_get_products_display_price')) {
                    $row['price_formatted'] = (string)@zen_get_products_display_price($pid);
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
                        // decorative; never fail the row
                    }
                }
                $row['products_id'] = $pid;
            }
            $recs[] = $row;
        }
    }
}

echo json_encode([
    'ok' => true,
    'placement' => $placement,
    'anchor_doc_id' => $docId,
    'algorithm' => $algorithm,
    'recommendations' => $recs,
    'total' => count($recs),
], JSON_UNESCAPED_SLASHES);
