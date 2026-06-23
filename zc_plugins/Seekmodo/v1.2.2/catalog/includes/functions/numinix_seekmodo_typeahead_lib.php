<?php
/**
 * Typeahead / autocomplete helpers for the Seekmodo connector.
 *
 * Used by a tenant's AJAX autocomplete endpoint (typically
 * `ajax/ajax_typeahead.php` on Zen Cart). The integration pattern is
 * the same shape as the main `/v1/search` swap-point:
 *
 *     if (function_exists('numinix_seekmodo_enabled') && numinix_seekmodo_enabled()) {
 *         require_once DIR_FS_CATALOG . 'includes/functions/numinix_seekmodo_typeahead_lib.php';
 *         $result = numinix_seekmodo_run_typeahead($q, 8);
 *         if ($result !== null) {
 *             echo json_encode($result, JSON_UNESCAPED_SLASHES);
 *             exit;
 *         }
 *         // null => off / shadow / circuit-open / failure — fall through
 *         // to the existing direct-Typesense path.
 *     }
 *
 * Why we ship a dedicated helper instead of reusing `run_search`:
 *
 *   1. Typeahead has a different relevance contract — prefix and infix
 *      matching matter much more than full-token semantic relevance, and
 *      the storefront wants 8-15 lean rows rather than the full paginated
 *      ID list `numinix_seekmodo_run_search` produces.
 *   2. Telemetry needs surface attribution. Typeahead impressions and
 *      typeahead-click events both get tagged with
 *      `extra.surface='typeahead'` so the analytics pipeline (CTR,
 *      LTR training) can keep autocomplete signal separate from full
 *      SERP signal. Mixing them dilutes ranking quality.
 *   3. The latency budget is tighter — we cap the per-page at 15 and
 *      never page through results.
 *
 * Sprint 3 PR 6 — typeahead now routes through the gateway's dedicated
 * SuggestTool (POST /v1/suggest) when the gateway exposes it, instead
 * of riding the full /v1/search path. SuggestTool returns three
 * result blocks (keywords / products / categories) in one call and
 * meters the request against the `searches_suggest` display bucket
 * so typeahead volume can be priced separately from full search.
 * When the gateway is older than Sprint 3 (returns 404 for /v1/suggest),
 * the helper transparently falls back to the legacy /v1/search path
 * so a connector upgrade doesn't break against a stale gateway.
 *
 * Mode semantics match the rest of the connector:
 *
 *   - `off`     — numinix_seekmodo_enabled() is false, helper short-
 *                 circuits and the storefront keeps its direct
 *                 Typesense path.
 *   - `shadow`  — call the gateway for observation/telemetry, then
 *                 return null so the storefront's direct path renders
 *                 the dropdown. The impression event still records
 *                 (annotated `extra.shadow=true`) so the verifier can
 *                 confirm gateway visibility.
 *   - `enforce` — return the gateway's lean items and the storefront
 *                 renders them.
 *
 * See `docs/TYPEAHEAD.md` for the recommended client-side JS pattern
 * (including the surface-tagged click beacon that ties typeahead
 * suggestions back to the original search log row).
 */

if (!function_exists('numinix_seekmodo_run_typeahead')) {
    /**
     * Execute a typeahead lookup through the gateway.
     *
     * @param string $q   The shopper's in-progress query (already trimmed).
     * @param int    $max Maximum number of suggestions to return (1-15).
     * @param array  $opts Optional knobs:
     *   - 'include_fields' string  Comma list of Typesense fields the
     *       caller wants back. Default 'products_id,name,model,price'.
     *   - 'query_by' string        Override the prefix-tuned query_by.
     *       Default 'name,model'.
     *   - 'filter_by' string       Extra filter clause to AND. The
     *       connector also AND's any clauses produced by the registered
     *       attribute filters in `$_GET`, so an in-context typeahead
     *       (e.g. category page) automatically scopes to the visible
     *       set.
     *   - 'record_impression' bool Default true. Set false from CLI /
     *       test harness so we don't pollute telemetry with synthetic
     *       impressions.
     *
     * @return array<string,mixed>|null  Envelope:
     *   {
     *     "q": "...",
     *     "items": [ {products_id, value, model, price, url, image?}, ... ],
     *     "total": int,
     *     // Sprint 3 PR 6 additions (present when /v1/suggest succeeds;
     *     // absent on the legacy /v1/search fallback path):
     *     "keywords":   [ {keyword: string, search_count: int}, ... ],
     *     "categories": [ {name: string, count: int}, ... ]
     *   }
     *   or null on off/shadow/failure (caller falls back to direct Typesense).
     */
    function numinix_seekmodo_run_typeahead(string $q, int $max = 8, array $opts = []): ?array
    {
        if (!function_exists('numinix_seekmodo_gateway_enabled') || !numinix_seekmodo_gateway_enabled()) {
            if (function_exists('numinix_seekmodo_run_typeahead_local')) {
                return numinix_seekmodo_run_typeahead_local($q, $max);
            }

            return null;
        }
        // Sprint 12 — tenant domain lock. Same posture as
        // numinix_seekmodo_run_search: short-circuit before any
        // payload construction. Caller already handles a null return
        // by suppressing the autocomplete dropdown (or falling back
        // to its own direct-Typesense / LIKE renderer).
        if (
            function_exists('numinix_seekmodo_is_locked_out')
            && numinix_seekmodo_is_locked_out()
        ) {
            return null;
        }
        $q = trim($q);
        if ($q === '' || mb_strlen($q) < 2 || mb_strlen($q) > 80) {
            return null;
        }
        $max = max(1, min(15, $max));

        $mode = function_exists('numinix_seekmodo_effective_mode')
            ? numinix_seekmodo_effective_mode()
            : numinix_seekmodo_mode();
        if ($mode === 'off') {
            if (function_exists('numinix_seekmodo_run_typeahead_local')) {
                return numinix_seekmodo_run_typeahead_local($q, $max);
            }

            return null;
        }

        // Result cache — same shape and rationale as the SERP cache
        // in numinix_seekmodo_search_lib.php. Shopper sessions
        // typically issue 3-6 typeahead requests per character ("p",
        // "pi", "pin", "pint", ...) and the user backspaces / retypes
        // often, so repeat queries within a 5-minute window are very
        // common. Caching turns the gateway round-trip (~500-900ms)
        // into a single-digit-ms file read.
        //
        // Cache TTL is intentionally short (180s) for typeahead so
        // operator suggestion edits (curated keywords / promoted
        // products on the gateway) propagate quickly. SERP cache
        // uses 300s.
        $cacheTtlS = 180;
        $cacheBypass = isset($_GET['seekmodo_nocache']) && (string)$_GET['seekmodo_nocache'] === '1';
        $useCache = ($mode === 'enforce')
            && !$cacheBypass
            && function_exists('_numinix_seekmodo_search_cache_get');
        $cacheKey = null;
        $cacheBackend = null;
        if ($useCache) {
            $tenant = function_exists('numinix_seekmodo_tenant_id')
                ? (string)numinix_seekmodo_tenant_id()
                : (string)(defined('NUMINIX_SEEKMODO_TENANT_ID') ? NUMINIX_SEEKMODO_TENANT_ID : '');
            $cacheKey = 'sm_typeahead_v1:' . $tenant . ':'
                . sha1(json_encode([
                    'q'    => mb_strtolower($q),
                    'max'  => $max,
                    'opts' => $opts,
                    'lang' => isset($_SESSION['languages_id']) ? (int)$_SESSION['languages_id'] : 1,
                ], JSON_UNESCAPED_UNICODE));
            $cached = _numinix_seekmodo_search_cache_get($cacheKey, $cacheTtlS, $cacheBackend);
            if (is_array($cached) && isset($cached['result']) && is_array($cached['result'])) {
                $GLOBALS['_numinix_seekmodo_last_typeahead_cache'] = 'hit-' . ($cacheBackend ?: 'unknown');
                return $cached['result'];
            }
            $GLOBALS['_numinix_seekmodo_last_typeahead_cache'] = 'miss';
        }

        // Sprint 3 PR 6 — typeahead routes through the gateway's
        // dedicated SuggestTool (/v1/suggest) by default. The legacy
        // /v1/search path is still wired (operators flip with
        // opts.use_search=true or the NUMINIX_SEEKMODO_TYPEAHEAD_USE_SEARCH
        // config flag) so a v1.0.14 connector on a Sprint-2-era
        // gateway can keep typeahead working until the gateway
        // upgrade lands.
        //
        // We do NOT auto-fall-through on a null /v1/suggest response
        // because the dispatcher meters the request when it lands at
        // the gateway: in shadow mode both /v1/suggest AND a
        // fall-through /v1/search would each charge `searches_suggest`
        // and `searches` respectively, doubling the tenant's bill.
        // Better to return null and let the storefront render its
        // own typeahead path (same contract as v1.0.13).
        $useSuggest = !($opts['use_search'] ?? false);
        if ($useSuggest && function_exists('numinix_seekmodo_suggest')) {
            $forceSearch = false;
            if (function_exists('_numinix_seekmodo_cfg')) {
                $forceSearch = ((string)_numinix_seekmodo_cfg(
                    'NUMINIX_SEEKMODO_TYPEAHEAD_USE_SEARCH',
                    'false'
                ) === 'true');
            }
            if (!$forceSearch) {
                $result = _numinix_seekmodo_typeahead_via_suggest($q, $max, $opts);
                if ($useCache && $cacheKey !== null && is_array($result)) {
                    _numinix_seekmodo_search_cache_put(
                        $cacheKey,
                        ['result' => $result, 'cached_at' => time()],
                        $cacheTtlS
                    );
                }
                return $result;
            }
        }

        $result = _numinix_seekmodo_typeahead_via_search($q, $max, $opts);
        if ($useCache && $cacheKey !== null && is_array($result)) {
            _numinix_seekmodo_search_cache_put(
                $cacheKey,
                ['result' => $result, 'cached_at' => time()],
                $cacheTtlS
            );
        }
        return $result;
    }
}

if (!function_exists('_numinix_seekmodo_typeahead_via_suggest')) {
    /**
     * Sprint 3 PR 6 — call /v1/suggest and adapt the three-block
     * envelope (keywords + products + categories) into the helper's
     * legacy `{q, items, total}` shape with the two new arrays
     * appended.
     *
     * Returns:
     *   - The adapted envelope on success (enforce path).
     *   - null on shadow (impression recorded, caller falls back to
     *     direct Typesense for the actual dropdown render).
     *   - null on SuggestTool unavailability (caller will retry via
     *     /v1/search).
     *
     * Mode handling matches the legacy /v1/search path verbatim so
     * the shadow / enforce contract is identical from the storefront's
     * point of view.
     */
    function _numinix_seekmodo_typeahead_via_suggest(string $q, int $max, array $opts): ?array
    {
        $mode = function_exists('numinix_seekmodo_effective_mode')
            ? numinix_seekmodo_effective_mode()
            : numinix_seekmodo_mode();

        // Build the SuggestTool input via the dedicated helper so the
        // payload shape stays in lock-step with the gateway's input
        // schema (`q`, `limit`, plus optional shopper-context).
        if (function_exists('_numinix_seekmodo_build_suggest_payload')) {
            $payload = _numinix_seekmodo_build_suggest_payload($q, $max);
        } else {
            // Defensive — search_lib should always be loaded by the
            // time this file is, but if a caller pulled in the
            // typeahead lib in isolation we still want a working
            // payload.
            $payload = ['q' => $q, 'limit' => $max];
        }

        // Shopper-context attribution. Same fields, same source as the
        // legacy path so bot-check / telemetry / A-B bucketing all
        // anchor on the same session id across typeahead → search →
        // click. Without these the gateway sees the connector host's
        // IP and the bot-check classifier is skipped.
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
        // personalization envelope. SuggestTool itself isn't
        // personalized this sprint (per the deferred-scope note in
        // §6.5), but we forward shopper_context anyway so the gateway
        // can stamp it into the suggest-keystroke telemetry rows —
        // that data feeds the next sprint's personalized typeahead.
        if (function_exists('numinix_seekmodo_shopper_context')) {
            $payload['shopper_context'] = numinix_seekmodo_shopper_context();
        }

        $startMs = (int)(microtime(true) * 1000);
        $resp = numinix_seekmodo_suggest($payload);
        $elapsedMs = (int)(microtime(true) * 1000) - $startMs;

        if (function_exists('numinix_seekmodo_observe')) {
            numinix_seekmodo_observe($resp !== null);
        }

        if ($resp === null) {
            if (function_exists('numinix_seekmodo_run_typeahead_local')) {
                return numinix_seekmodo_run_typeahead_local($q, $max);
            }

            return null;
        }

        $items = _numinix_seekmodo_typeahead_items_from_suggest($resp);
        $keywords = isset($resp['keywords']) && is_array($resp['keywords'])
            ? array_values(array_filter($resp['keywords'], 'is_array'))
            : [];
        $categories = isset($resp['categories']) && is_array($resp['categories'])
            ? array_values(array_filter($resp['categories'], 'is_array'))
            : [];

        if ($mode === 'shadow') {
            if (($opts['record_impression'] ?? true) && $items !== []) {
                _numinix_seekmodo_typeahead_record_impression(
                    $q,
                    $items,
                    ['shadow' => true, 'elapsed_ms' => $elapsedMs, 'surface_id' => 'suggest']
                );
            }
            // Shadow mode = observe-only; the storefront keeps its own
            // typeahead path. Caller treats null as "fall back to direct
            // Typesense / native LIKE", which is what we want here.
            return null;
        }

        if (($opts['record_impression'] ?? true) && $items !== []) {
            _numinix_seekmodo_typeahead_record_impression(
                $q,
                $items,
                ['elapsed_ms' => $elapsedMs, 'surface_id' => 'suggest']
            );
        }

        $countsFound = 0;
        if (isset($resp['meta']['counts']['products'])) {
            $countsFound = (int)$resp['meta']['counts']['products'];
        }
        return [
            'q' => $q,
            'items' => $items,
            // SuggestTool doesn't return a `found` count for the full
            // collection — only the count of the surfaced block.
            // That's fine for typeahead UX (the dropdown shows
            // suggestions, not a total result count).
            'total' => $countsFound === 0 ? count($items) : $countsFound,
            'keywords' => $keywords,
            'categories' => $categories,
        ];
    }
}

if (!function_exists('_numinix_seekmodo_typeahead_via_search')) {
    /**
     * Legacy /v1/search-based typeahead path. Kept verbatim from
     * v1.0.13 so connectors can fall back when the gateway predates
     * Sprint 3's SuggestTool registration (404 on /v1/suggest), or
     * when the operator forces it via opts.use_search=true for a
     * rollback drill.
     */
    function _numinix_seekmodo_typeahead_via_search(string $q, int $max, array $opts): ?array
    {
        $mode = function_exists('numinix_seekmodo_effective_mode')
            ? numinix_seekmodo_effective_mode()
            : numinix_seekmodo_mode();

        $includeFields = isset($opts['include_fields']) && $opts['include_fields'] !== ''
            ? (string)$opts['include_fields']
            : 'products_id,name,model,price';
        $queryBy = isset($opts['query_by']) && $opts['query_by'] !== ''
            ? (string)$opts['query_by']
            : 'name,model';

        // Compose filter_by: ALWAYS the caller's, AND'd with whatever
        // the global filter-mapping registry produces from `$_GET`.
        // That way an in-context typeahead on a category page picks
        // up the current category filter for free.
        $clauses = [];
        if (!empty($opts['filter_by'])) {
            $clauses[] = (string)$opts['filter_by'];
        }
        if (function_exists('numinix_seekmodo_build_filter_by')) {
            $regClause = numinix_seekmodo_build_filter_by();
            if ($regClause !== null && $regClause !== '') {
                $clauses[] = $regClause;
            }
        }
        $filterBy = $clauses === [] ? null : implode(' && ', $clauses);

        $payload = [
            'q' => $q,
            'query_by' => $queryBy,
            // We don't pass query_by_weights/prefix/infix here — the
            // gateway's SearchDefaults (commerce vertical) already
            // applies "all prefix=true, name/model infix=always" via
            // the SearchDefaults overlay when the caller picks the
            // default query_by. If the caller customizes query_by they
            // can pass these themselves.
            'per_page' => $max,
            'page' => 1,
            'include_fields' => $includeFields,
        ];
        if ($filterBy !== null) {
            $payload['filter_by'] = $filterBy;
        }

        // Shopper-context attribution — same as suggest path.
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

        // v1.0.17 — SKU exact-match boost for prefix-typed part
        // numbers. The full-search payload builder applies the same
        // helper in numinix_seekmodo_search_lib.php; the legacy
        // typeahead path doesn't use that builder so we apply it
        // here too. The helper is a no-op for natural-language
        // prefixes (multi-word, spaces, punctuation other than
        // dash/underscore/dot) so a shopper typing "automotive ro…"
        // still gets relevance ranking, while a shopper typing
        // "RLS-1234" gets the exact stand for product RLS-1234
        // floated to position 0.
        if (function_exists('_numinix_seekmodo_apply_sku_boost')) {
            $payload = _numinix_seekmodo_apply_sku_boost($payload, $q);
        }

        $startMs = (int)(microtime(true) * 1000);
        $resp = numinix_seekmodo_search($payload);
        $elapsedMs = (int)(microtime(true) * 1000) - $startMs;

        if (function_exists('numinix_seekmodo_observe')) {
            numinix_seekmodo_observe($resp !== null);
        }

        $items = _numinix_seekmodo_typeahead_items($resp);

        if ($mode === 'shadow') {
            if (($opts['record_impression'] ?? true) && $items !== []) {
                _numinix_seekmodo_typeahead_record_impression(
                    $q,
                    $items,
                    ['shadow' => true, 'elapsed_ms' => $elapsedMs]
                );
            }
            return null;
        }

        if ($resp === null) {
            if (function_exists('numinix_seekmodo_run_typeahead_local')) {
                return numinix_seekmodo_run_typeahead_local($q, $max);
            }

            return null;
        }
        if (($opts['record_impression'] ?? true) && $items !== []) {
            _numinix_seekmodo_typeahead_record_impression(
                $q,
                $items,
                ['elapsed_ms' => $elapsedMs]
            );
        }

        $total = 0;
        if (isset($resp['results']['found'])) {
            $total = (int)$resp['results']['found'];
        } elseif (isset($resp['total'])) {
            $total = (int)$resp['total'];
        }
        return [
            'q' => $q,
            'items' => $items,
            'total' => $total,
        ];
    }
}

if (!function_exists('_numinix_seekmodo_typeahead_items_from_suggest')) {
    /**
     * Pull lean autocomplete items out of the SuggestTool envelope.
     * SuggestTool returns raw Typesense docs directly (no
     * `results.hits[*].document` wrapping), keyed by `products` on the
     * top level. Each doc carries the SuggestTool's
     * INCLUDE_FIELDS_DEFAULT set (id / name / model / brand / price /
     * image / in_stock / categories_breadcrumbs); we flatten down to
     * the same `{products_id, value, model, price, url, image}`
     * shape the legacy path emits so the storefront's JS doesn't
     * have to branch.
     */
    function _numinix_seekmodo_typeahead_items_from_suggest(?array $resp): array
    {
        if (!is_array($resp)) {
            return [];
        }
        $products = $resp['products'] ?? null;
        if (!is_array($products) || $products === []) {
            return [];
        }
        $items = [];
        foreach ($products as $doc) {
            if (!is_array($doc)) {
                continue;
            }
            // SuggestTool uses Typesense's `id` for the doc id —
            // commerce-vertical schemas key on `products_id`-string
            // (cast to int here), so prefer `products_id` when
            // present and fall back to `id`.
            $pid = (int)($doc['products_id'] ?? $doc['id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $item = [
                'products_id' => $pid,
                'value' => (string)($doc['name'] ?? ''),
                'model' => (string)($doc['model'] ?? ''),
            ];
            if (function_exists('zen_get_products_display_price')) {
                $item['price'] = (string)@zen_get_products_display_price($pid);
            } elseif (isset($doc['price'])) {
                $item['price'] = (string)$doc['price'];
            }
            if (function_exists('zen_href_link') && function_exists('zen_get_info_page')) {
                try {
                    $item['url'] = (string)zen_href_link(
                        zen_get_info_page($pid),
                        'products_id=' . $pid
                    );
                } catch (\Throwable $e) {
                    // Some platforms throw if the product is private /
                    // pending; skip the URL but keep the item.
                }
            }
            if (function_exists('zen_get_products_image')) {
                try {
                    $item['image'] = (string)@zen_get_products_image($pid, 60, 60);
                } catch (\Throwable $e) {
                    // image helper is decorative; never fail the row.
                }
            } elseif (isset($doc['image'])) {
                $item['image'] = (string)$doc['image'];
            }
            $items[] = $item;
        }
        return $items;
    }
}

if (!function_exists('_numinix_seekmodo_typeahead_items')) {
    /**
     * Pull lean autocomplete items out of the gateway's Typesense-shaped
     * response. Each item carries:
     *   - products_id (int)
     *   - value (string, product name — used as the dropdown row label)
     *   - model (string, may be empty)
     *   - price (string, formatted by zen_get_products_display_price)
     *   - url   (string, canonical product page link)
     *   - image (string, small product image HTML when available)
     *
     * Storefront rendering code can ignore any field it doesn't need.
     */
    function _numinix_seekmodo_typeahead_items(?array $resp): array
    {
        if (!is_array($resp)) {
            return [];
        }
        $hits = $resp['results']['hits'] ?? null;
        if (!is_array($hits) || $hits === []) {
            return [];
        }
        $items = [];
        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $doc = $hit['document'] ?? null;
            if (!is_array($doc)) {
                continue;
            }
            $pid = (int)($doc['products_id'] ?? $doc['id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $item = [
                'products_id' => $pid,
                'value' => (string)($doc['name'] ?? ''),
                'model' => (string)($doc['model'] ?? ''),
            ];
            // Price + URL + image come from Zen Cart helpers when this
            // is running inside the storefront request. Skip when they
            // aren't available (unit-test harness).
            if (function_exists('zen_get_products_display_price')) {
                $item['price'] = (string)@zen_get_products_display_price($pid);
            } elseif (isset($doc['price'])) {
                $item['price'] = (string)$doc['price'];
            }
            if (function_exists('zen_href_link') && function_exists('zen_get_info_page')) {
                try {
                    $item['url'] = (string)zen_href_link(
                        zen_get_info_page($pid),
                        'products_id=' . $pid
                    );
                } catch (\Throwable $e) {
                    // Some platforms throw if the product is private /
                    // pending; skip the URL but keep the item.
                }
            }
            if (function_exists('zen_get_products_image')) {
                try {
                    $item['image'] = (string)@zen_get_products_image($pid, 60, 60);
                } catch (\Throwable $e) {
                    // image helper is decorative; never fail the row.
                }
            }
            $items[] = $item;
        }
        return $items;
    }
}

if (!function_exists('_numinix_seekmodo_typeahead_record_impression')) {
    /**
     * Mirror a typeahead impression to the gateway via the existing
     * events helper. Tagged `extra.surface='typeahead'` so analytics
     * can keep typeahead and SERP signals distinct.
     *
     * Fire-and-forget — failures never propagate to the storefront.
     */
    function _numinix_seekmodo_typeahead_record_impression(
        string $keyword,
        array $items,
        array $extraMeta = []
    ): void {
        if (!function_exists('numinix_seekmodo_mirror_impression')) {
            return;
        }
        $pids = [];
        foreach ($items as $it) {
            if (isset($it['products_id'])) {
                $pids[] = (int)$it['products_id'];
            }
        }
        if ($pids === []) {
            return;
        }
        $opts = ['surface' => 'typeahead'];
        foreach ($extraMeta as $k => $v) {
            $opts[$k] = $v;
        }
        @numinix_seekmodo_mirror_impression($keyword, $pids, $opts);
    }
}
