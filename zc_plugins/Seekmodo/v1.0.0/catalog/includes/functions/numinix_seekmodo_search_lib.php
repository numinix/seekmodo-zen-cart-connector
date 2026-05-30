<?php
/**
 * Search replacement helpers for the Seekmodo connector.
 *
 * Used by Redline's includes/classes/class.search.php at the top of
 * numinix_elastic_search_results($params). The swap-point is a small
 * three-line conditional:
 *
 *     if (function_exists('numinix_seekmodo_enabled') && numinix_seekmodo_enabled()) {
 *         require_once DIR_FS_CATALOG . 'includes/functions/numinix_seekmodo_search_lib.php';
 *         $seekmodoResult = numinix_seekmodo_run_search($params);
 *         if ($seekmodoResult !== null) {
 *             return $seekmodoResult;
 *         }
 *         // null => off / shadow / breaker-open / failure — fall through
 *         // to the existing direct-Typesense path.
 *     }
 *
 * Mode semantics inside this helper:
 *   - enforce: call /v1/search; on success return the gateway result;
 *     on failure return null so the caller falls back to native.
 *   - shadow:  call /v1/search; log {native_count, gateway_count,
 *     overlap_top10} for diff analysis; ALWAYS return null so the
 *     shopper sees the native result. The "native" comparison set is
 *     captured later by tools/verify_redline_seekmodo.py from the
 *     gateway-side telemetry, so we don't need to compute the native
 *     result inside this hot path.
 *   - off:     not reached — numinix_seekmodo_enabled() is false.
 */

if (!function_exists('numinix_seekmodo_run_search')) {
    /**
     * @param array<string,mixed> $params Same shape class.search.php
     *     passes to numinix_elastic_search_results():
     *     {keyword, search_in_description, categories_id,
     *      manufacturers_id, pfrom, pto}
     * @return array<string,mixed>|null Native-shape result on enforce
     *     success, null otherwise.
     */
    function numinix_seekmodo_run_search(array $params): ?array
    {
        if (!function_exists('numinix_seekmodo_enabled') || !numinix_seekmodo_enabled()) {
            return null;
        }
        // Effective mode collapses `active` through the AutoPromoter
        // state machine so the rest of this function only needs to
        // reason about literal off/shadow/enforce values.
        $mode = function_exists('numinix_seekmodo_effective_mode')
            ? numinix_seekmodo_effective_mode()
            : numinix_seekmodo_mode();
        if ($mode === 'off') {
            return null;
        }

        // Build the gateway-side payload. The gateway's SearchTool
        // accepts the same param shape Typesense does plus a few
        // tenant-aware extras; we pass through the storefront's
        // already-built filter/sort hints so the gateway can apply
        // them server-side.
        $payload = _numinix_seekmodo_build_search_payload($params);

        // Page size + page cap mirror the legacy class.search.php
        // tuning (NUMINIX_TYPESENSE_PAGE_SIZE=250,
        // NUMINIX_TYPESENSE_MAX_PAGES=50). Zen Cart paginates the
        // returned ID list locally (via SQL `WHERE products_id IN (...)`
        // + LIMIT/OFFSET against the search result page), so we have
        // to return every match the storefront pagination might want
        // to walk through, not just the first 10.
        //
        // 250 is the gateway's hard per_page cap (SearchTool input
        // schema). Up to 50 pages × 250 = 12,500 IDs — enough for
        // even broad category-style queries on a 16k SKU catalog.
        $pageSize = 250;
        $maxPages = 50;
        $payload['per_page'] = $pageSize;

        $startMs = (int)(microtime(true) * 1000);

        $merged = [
            'results' => [
                'found' => 0,
                'hits' => [],
            ],
            'meta' => null,
        ];
        $page = 1;
        $sawFailure = false;
        $firstResp = null;
        while ($page <= $maxPages) {
            $payload['page'] = $page;
            $resp = numinix_seekmodo_search($payload);
            if ($resp === null) {
                $sawFailure = true;
                break;
            }
            if ($firstResp === null) {
                $firstResp = $resp;
            }
            $hits = $resp['results']['hits'] ?? null;
            $found = isset($resp['results']['found']) ? (int)$resp['results']['found'] : null;
            if ($found !== null) {
                $merged['results']['found'] = $found;
            }
            if (is_array($hits) && $hits !== []) {
                foreach ($hits as $h) {
                    $merged['results']['hits'][] = $h;
                }
                $hitCount = count($hits);
            } else {
                $hitCount = 0;
            }
            // Last page when we either hit the global found-count or
            // got fewer hits than the page size.
            if ($found !== null && count($merged['results']['hits']) >= $found) {
                break;
            }
            if ($hitCount < $pageSize) {
                break;
            }
            $page++;
        }
        $elapsedMs = (int)(microtime(true) * 1000) - $startMs;

        // Tell the AutoPromoter how this call went. Any partial-success
        // page sequence still counts as `ok=true` for FSM purposes —
        // the connector's job is "did we get usable results" and a
        // single transport failure mid-paging is still recoverable.
        if (function_exists('numinix_seekmodo_observe')) {
            numinix_seekmodo_observe(!$sawFailure);
        }

        if ($mode === 'shadow') {
            // Shadow mode is observation-only. Log what we got back
            // and return null so the storefront falls through to its
            // native direct-Typesense path. The verifier compares
            // gateway hits to native hits using the gateway-side
            // telemetry rows (numinix_telemetry_search_events).
            _numinix_seekmodo_shadow_log($params, $firstResp, $elapsedMs);
            return null;
        }

        // enforce
        if ($sawFailure || $firstResp === null) {
            return null;
        }
        // Hand back the merged envelope (carrying every page's hits)
        // so the normalizer can extract a complete products[] list.
        // Carry forward any non-results metadata from the first
        // response (bot_check, ab/ltr meta) — those don't change page
        // to page.
        if (isset($firstResp['bot_check'])) {
            $merged['bot_check'] = $firstResp['bot_check'];
        }
        if (isset($firstResp['meta'])) {
            $merged['meta'] = $firstResp['meta'];
        }
        return _numinix_seekmodo_normalize_response($merged, $params);
    }
}

if (!function_exists('_numinix_seekmodo_build_search_payload')) {
    /**
     * Translate Redline's storefront search params into the gateway's
     * /v1/search payload shape. Anything we don't recognize is dropped
     * — the gateway has a strict input schema.
     */
    function _numinix_seekmodo_build_search_payload(array $params): array
    {
        $keyword = isset($params['keyword']) ? trim((string)$params['keyword']) : '';
        $payload = [
            'q' => $keyword === '' ? '*' : $keyword,
            'search_in_description' => !empty($params['search_in_description']),
        ];
        if (!empty($params['categories_id'])) {
            $payload['categories_id'] = (string)$params['categories_id'];
        }
        if (!empty($params['manufacturers_id'])) {
            $payload['manufacturers_id'] = (int)$params['manufacturers_id'];
        }
        if (isset($params['pfrom']) && (float)$params['pfrom'] > 0) {
            $payload['price_from'] = (float)$params['pfrom'];
        }
        if (isset($params['pto']) && (float)$params['pto'] > 0) {
            $payload['price_to'] = (float)$params['pto'];
        }
        // Pass through facet selectors mirrored from $_GET — the
        // existing class.search.php plumbing also reads these.
        foreach (['brand', 'type', 'capacity_by_lbs'] as $facet) {
            if (isset($_GET[$facet]) && $_GET[$facet] !== '') {
                $payload['facets'][$facet] = (string)$_GET[$facet];
            }
        }
        return $payload;
    }
}

if (!function_exists('_numinix_seekmodo_normalize_response')) {
    /**
     * Reshape the gateway's /v1/search response into the native
     * {products, total, corrected_query, variant, semantic_shadow}
     * envelope class.search.php's caller expects.
     *
     * The gateway envelope (SearchTool::execute) is:
     *   {
     *     "results": {                       // raw Typesense response
     *       "found": int, "hits": [
     *         { "document": {"products_id": int, ...}, "highlight": {...} }
     *       ], ...
     *     },
     *     "bot_check": {...},
     *     "meta": {...}
     *   }
     *
     * Some flat-envelope keys (`products`, `total`, `corrected_query`,
     * `variant`, `semantic_shadow`) are also accepted as a future-proof
     * fallback so a gateway change to a flat shape doesn't break this
     * normalizer.
     */
    function _numinix_seekmodo_normalize_response(array $resp, array $params): array
    {
        $products = [];
        if (isset($resp['products']) && is_array($resp['products'])) {
            foreach ($resp['products'] as $pid) {
                $intPid = (int)$pid;
                if ($intPid > 0) {
                    $products[] = $intPid;
                }
            }
        }
        // Pull product IDs out of the Typesense hit envelope when the
        // flat 'products' key isn't present.
        if ($products === [] && isset($resp['results']['hits']) && is_array($resp['results']['hits'])) {
            foreach ($resp['results']['hits'] as $hit) {
                if (!is_array($hit)) {
                    continue;
                }
                $doc = $hit['document'] ?? null;
                if (!is_array($doc)) {
                    continue;
                }
                $pid = $doc['products_id'] ?? $doc['id'] ?? null;
                if ($pid === null) {
                    continue;
                }
                $intPid = (int)$pid;
                if ($intPid > 0) {
                    $products[] = $intPid;
                }
            }
        }
        if (isset($resp['total'])) {
            $total = (int)$resp['total'];
        } elseif (isset($resp['results']['found'])) {
            $total = (int)$resp['results']['found'];
        } else {
            $total = count($products);
        }
        $corrected = isset($resp['corrected_query']) ? (string)$resp['corrected_query'] : null;
        if ($corrected === '') {
            $corrected = null;
        }
        $variant = isset($resp['variant']) ? (string)$resp['variant'] : 'lexical';
        $semanticShadow = isset($resp['semantic_shadow']) ? (string)$resp['semantic_shadow'] : '';

        return [
            'products' => $products,
            'total' => $total,
            'corrected_query' => $corrected,
            'variant' => $variant,
            'semantic_shadow' => $semanticShadow,
        ];
    }
}

if (!function_exists('_numinix_seekmodo_shadow_log')) {
    /**
     * Capture a shadow-mode observation. Writes a single line to
     * logs/numinix_seekmodo.log (alongside the SDK's own debug log)
     * so verify_redline_seekmodo.py can tail it after a shadow run
     * and confirm the gateway path executed.
     *
     * No-op when DIR_FS_LOGS isn't writable — we never throw from
     * shadow logging.
     */
    function _numinix_seekmodo_shadow_log(array $params, ?array $resp, int $elapsedMs): void
    {
        $logDir = '';
        if (defined('DIR_FS_LOGS')) {
            $logDir = rtrim(DIR_FS_LOGS, '/\\');
        } elseif (defined('DIR_FS_CATALOG')) {
            $logDir = rtrim(DIR_FS_CATALOG, '/\\') . '/logs';
        }
        if ($logDir === '' || !is_dir($logDir)) {
            return;
        }
        // Pull total + product IDs out of either a flat envelope
        // (`products`, `total`) or the gateway's Typesense-shaped one
        // (`results.found`, `results.hits[*].document.products_id`).
        $total = null;
        $products = [];
        if (is_array($resp)) {
            if (isset($resp['total'])) {
                $total = (int) $resp['total'];
            } elseif (isset($resp['results']['found'])) {
                $total = (int) $resp['results']['found'];
            }
            if (isset($resp['products']) && is_array($resp['products'])) {
                foreach ($resp['products'] as $pid) {
                    $products[] = (int) $pid;
                }
            } elseif (isset($resp['results']['hits']) && is_array($resp['results']['hits'])) {
                foreach ($resp['results']['hits'] as $hit) {
                    if (!is_array($hit)) {
                        continue;
                    }
                    $doc = $hit['document'] ?? null;
                    if (!is_array($doc)) {
                        continue;
                    }
                    $pid = $doc['products_id'] ?? $doc['id'] ?? null;
                    if ($pid !== null) {
                        $products[] = (int) $pid;
                    }
                }
            }
        }
        $row = [
            'ts' => date('c'),
            'msg' => 'shadow_search',
            'keyword' => isset($params['keyword']) ? (string)$params['keyword'] : '',
            'gateway_total' => $total,
            'gateway_count' => count($products),
            'gateway_top10' => array_slice($products, 0, 10),
            'elapsed_ms' => $elapsedMs,
            'gateway_ok' => $resp !== null,
        ];
        $line = json_encode($row, JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }
        @file_put_contents($logDir . '/numinix_seekmodo.log', $line . PHP_EOL, FILE_APPEND);
    }
}
