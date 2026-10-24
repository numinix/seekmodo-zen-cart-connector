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


if (!function_exists('numinix_seekmodo_href_link_raw')) {
    /**
     * zen_href_link() emits HTML-safe &amp; for template use. JSON / Location
     * headers need a raw URL with &.
     */
    function numinix_seekmodo_href_link_raw(string $page, string $parameters = '', string $connection = 'NONSSL'): string
    {
        if (!function_exists('zen_href_link')) {
            return '';
        }
        $url = (string) zen_href_link($page, $parameters, $connection);
        return htmlspecialchars_decode($url, ENT_QUOTES);
    }
}

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
        // Unpaid / over-quota sticky: skip the gateway entirely so the
        // storefront never round-trips /v1/suggest while Enhanced Native
        // can answer locally. Cleared on the next successful cloud
        // search/suggest after resubscribe.
        if (
            class_exists('\\Numinix\\Seekmodo\\Client')
            && \Numinix\Seekmodo\Client::shouldPreferLocalSuggest()
            && function_exists('numinix_seekmodo_run_typeahead_local')
        ) {
            return numinix_seekmodo_run_typeahead_local($q, $max);
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
        $cacheEnabled = ($mode === 'enforce')
            && function_exists('_numinix_seekmodo_search_cache_get');
        $useCacheRead = $cacheEnabled && !$cacheBypass;
        $cacheKey = null;
        $cacheBackend = null;
        if ($cacheEnabled) {
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
            if ($useCacheRead) {
                $cached = _numinix_seekmodo_search_cache_get($cacheKey, $cacheTtlS, $cacheBackend);
                if (is_array($cached) && isset($cached['result']) && is_array($cached['result'])) {
                    $GLOBALS['_numinix_seekmodo_last_typeahead_cache'] = 'hit-' . ($cacheBackend ?: 'unknown');
                    return $cached['result'];
                }
                $GLOBALS['_numinix_seekmodo_last_typeahead_cache'] = 'miss';
            } else {
                $GLOBALS['_numinix_seekmodo_last_typeahead_cache'] = $cacheBypass ? 'bypass' : 'disabled';
            }
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
                if ($cacheEnabled && $cacheKey !== null && is_array($result)
                    && function_exists('_numinix_seekmodo_search_cache_put')
                ) {
                    _numinix_seekmodo_search_cache_put(
                        $cacheKey,
                        ['result' => $result, 'cached_at' => time()],
                        $cacheTtlS
                    );
                    if ($cacheBypass) {
                        $GLOBALS['_numinix_seekmodo_last_typeahead_cache'] = 'bypass-write';
                    }
                }
                return $result;
            }
        }

        $result = _numinix_seekmodo_typeahead_via_search($q, $max, $opts);
        if ($cacheEnabled && $cacheKey !== null && is_array($result)
            && function_exists('_numinix_seekmodo_search_cache_put')
        ) {
            _numinix_seekmodo_search_cache_put(
                $cacheKey,
                ['result' => $result, 'cached_at' => time()],
                $cacheTtlS
            );
            if ($cacheBypass) {
                $GLOBALS['_numinix_seekmodo_last_typeahead_cache'] = 'bypass-write';
            }
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
        if (function_exists('numinix_seekmodo_current_language_code')) {
            $lang = numinix_seekmodo_current_language_code();
            if ($lang !== null && $lang !== '') {
                $payload['lang'] = $lang;
            }
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

        if (function_exists('_numinix_seekmodo_remember_suggest_search_event')) {
            _numinix_seekmodo_remember_suggest_search_event($q, $resp);
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

        // v1.3.44 — exact model/sku filter for complete part tokens
        // (AKS parity; prevents drop_tokens dumps on missing SKUs).
        if (function_exists('_numinix_seekmodo_apply_exact_sku_filter')) {
            $payload = _numinix_seekmodo_apply_exact_sku_filter($payload, $q);
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

if (!function_exists('numinix_seekmodo_catalog_base_currency')) {
    /**
     * Store default ISO currency for indexed catalog docs (base
     * products_price unit). Session currency is NOT used here.
     */
    function numinix_seekmodo_catalog_base_currency(): string
    {
        if (defined('DEFAULT_CURRENCY') && (string) DEFAULT_CURRENCY !== '') {
            return strtoupper((string) DEFAULT_CURRENCY);
        }
        if (function_exists('zen_get_currency_code')) {
            $code = trim((string) @zen_get_currency_code());
            if ($code !== '') {
                return strtoupper($code);
            }
        }

        return 'USD';
    }
}

if (!function_exists('numinix_seekmodo_shopper_currency')) {
    /**
     * Active shopper ISO currency (session multicurrency or default).
     */
    function numinix_seekmodo_shopper_currency(): string
    {
        if (isset($_SESSION['currency']) && is_string($_SESSION['currency'])) {
            $code = trim($_SESSION['currency']);
            if ($code !== '') {
                return strtoupper($code);
            }
        }

        return numinix_seekmodo_catalog_base_currency();
    }
}

if (!function_exists('numinix_seekmodo_suggest_image_px')) {
    /**
     * Target pixel size for suggest dropdown / grid thumbnails.
     * Wide split-rail layouts render ~120–180px cells on mobile;
     * 240px covers 2x DPR without shipping full originals.
     */
    function numinix_seekmodo_suggest_image_px(): int
    {
        if (defined('NUMINIX_SEEKMODO_SUGGEST_IMAGE_PX')) {
            $v = (int) constant('NUMINIX_SEEKMODO_SUGGEST_IMAGE_PX');
            if ($v >= 80 && $v <= 512) {
                return $v;
            }
        }

        return 240;
    }
}

if (!function_exists('numinix_seekmodo_is_no_picture_url')) {
    /**
     * Zen Cart placeholder image (images/no_picture.gif etc).
     * Treat as a miss — never ship as a suggest thumb URL.
     */
    function numinix_seekmodo_is_no_picture_url(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        return preg_match('#(?:^|/)no_picture\.(?:gif|png|jpe?g|webp)$#i', $url) === 1;
    }
}

if (!function_exists('numinix_seekmodo_is_placeholder_suggest_image_url')) {
    /**
     * Suggest hydrator must not paint these over a working gateway thumb.
     *
     * Covers:
     * - Zen `no_picture.*` (KIP / empty local /images)
     * - Template spacer `x.gif` and any path under `includes/templates/`
     *   (STRIN SBM2015: zen_get_products_image(240) returns
     *   /images/includes/templates/SBM2015/images/x.gif — a 1×1 pixel
     *   that 200s, so onerror cannot restore the real photo)
     *
     * Does **not** treat Image Handler / bmz cache paths as placeholders;
     * those stay on the cache-miss branch in prefer_catalog so Cannapot
     * 403s still fall through to the catalog original.
     */
    function numinix_seekmodo_is_placeholder_suggest_image_url(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        if (numinix_seekmodo_is_no_picture_url($url)) {
            return true;
        }
        if (stripos($url, '/includes/templates/') !== false) {
            return true;
        }
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? $url);
        if ($path === '') {
            $path = $url;
        }

        return preg_match('#(?:^|/)x\.gif$#i', $path) === 1;
    }
}

if (!function_exists('numinix_seekmodo_prefer_catalog_over_placeholder_suggest_image')) {
    /**
     * Prefer catalog products_image originals over Image Handler cache
     * paths and Zen placeholders (no_picture, template spacer x.gif).
     * Never returns a placeholder URL.
     *
     * @param string $localAbs Absolute (or root-relative) URL from zen_get_products_image
     * @param string $catalogUrl Absolute catalog original URL (may be empty)
     */
    function numinix_seekmodo_prefer_catalog_over_placeholder_suggest_image(
        string $localAbs,
        string $catalogUrl
    ): string {
        $catalogOk = $catalogUrl !== '' && !numinix_seekmodo_is_placeholder_suggest_image_url($catalogUrl);

        if ($localAbs === '') {
            return $catalogOk ? $catalogUrl : '';
        }

        $isMiss = stripos($localAbs, '/bmz_cache/') !== false
            || stripos($localAbs, '/images/cache/') !== false
            || preg_match('#\.image\.\d+x\d+\.(jpe?g|png|gif|webp)$#i', $localAbs) === 1
            || numinix_seekmodo_is_placeholder_suggest_image_url($localAbs);

        if (!$isMiss) {
            return $localAbs;
        }

        if ($catalogOk) {
            return $catalogUrl;
        }

        if (numinix_seekmodo_is_placeholder_suggest_image_url($localAbs)) {
            return '';
        }

        return $localAbs;
    }
}

if (!function_exists('numinix_seekmodo_suggest_product_image_url_catalog')) {
    /**
     * Original catalog image path (no Image Handler bmz_cache). Mirror
     * hosts like numinix.ca rsync /images/ but often lack generated
     * bmz_cache thumbs, so hydration must fall back here.
     */
    function numinix_seekmodo_suggest_product_image_url_catalog(int $pid): string
    {
        if ($pid <= 0 || !function_exists('numinix_seekmodo_catalog_doc_image_url')) {
            return '';
        }
        global $db;
        if (!isset($db) || !defined('TABLE_PRODUCTS')) {
            return '';
        }
        $rs = $db->Execute(
            'SELECT products_image FROM ' . TABLE_PRODUCTS
            . ' WHERE products_id = ' . (int) $pid . ' LIMIT 1'
        );
        if (!$rs || $rs->EOF) {
            return '';
        }
        $raw = trim((string) ($rs->fields['products_image'] ?? ''));
        if ($raw === '') {
            return '';
        }

        return numinix_seekmodo_catalog_doc_image_url($raw);
    }
}

if (!function_exists('numinix_seekmodo_suggest_product_image_url')) {
    /**
     * Storefront-optimized absolute image URL for one product.
     * Uses zen_get_products_image() so Image Handler / Numinix
     * automatic optimization return the right cached size.
     */
    function numinix_seekmodo_suggest_product_image_url(int $pid, int $px = 0): string
    {
        if ($pid <= 0) {
            return '';
        }
        if ($px <= 0) {
            $px = numinix_seekmodo_suggest_image_px();
        }
        if (function_exists('zen_get_products_image')) {
            try {
                $html = (string) @zen_get_products_image($pid, $px, $px);
                if ($html !== '' && $html !== 'false'
                    && preg_match('#\ssrc=(["\'])([^"\']+)\1#i', $html, $m) === 1
                ) {
                    $parsed = trim($m[2]);
                    if ($parsed !== '') {
                        $abs = '';
                        if (preg_match('#^https?://#i', $parsed) === 1) {
                            $abs = $parsed;
                        } elseif (function_exists('numinix_seekmodo_catalog_doc_image_url')) {
                            $abs = numinix_seekmodo_catalog_doc_image_url($parsed);
                        } elseif ($parsed[0] === '/') {
                            $abs = $parsed;
                        }
                        if ($abs !== '') {
                            // Prefer the durable catalog original over Image
                            // Handler / bmz resized cache URLs. Some hosts
                            // (e.g. Cannapot NS-26042) return 403 for
                            // /images/cache/.../*.image.240x240.jpg while the
                            // original /images/*.jpg works — hydration then
                            // flashes the gateway thumb and replaces it with
                            // a broken img.
                            //
                            // Also treat Zen's no_picture placeholder as a
                            // miss. On hosts where local /images is empty (or
                            // Image Handler thumbs are missing) but the
                            // catalog original is still HTTP-reachable (CDN /
                            // origin-pull), zen_get_products_image() returns
                            // no_picture.gif — without this fallthrough
                            // hydration overwrites good Typesense thumbs.
                            $catalog = numinix_seekmodo_suggest_product_image_url_catalog($pid);
                            return numinix_seekmodo_prefer_catalog_over_placeholder_suggest_image(
                                $abs,
                                $catalog
                            );
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Fall through to catalog URL resolver.
            }
        }
        $item = ['products_id' => $pid];
        _numinix_seekmodo_typeahead_attach_image_url($item, $pid, null);

        return isset($item['image_url']) && is_string($item['image_url'])
            ? trim($item['image_url'])
            : '';
    }
}

if (!function_exists('numinix_seekmodo_suggest_product_images')) {
    /**
     * Resolve optimized thumbnail URLs for a batch of products_id values.
     *
     * @param list<int> $productIds
     * @return array<string, string> map of products_id string => absolute image_url
     */
    function numinix_seekmodo_suggest_product_images(array $productIds, int $px = 0): array
    {
        $out = [];
        foreach ($productIds as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0) {
                continue;
            }
            $url = numinix_seekmodo_suggest_product_image_url($pid, $px);
            if ($url !== '') {
                $out[(string) $pid] = $url;
            }
        }

        return $out;
    }
}

if (!function_exists('numinix_seekmodo_suggest_product_names')) {
    /**
     * Session-language product names for suggest title hydration.
     *
     * Browser `<seekmodo-suggest>` reads gateway document `name` (indexed
     * in a single catalog language). Re-resolve via zen_get_products_name
     * so DE/ES storefronts show the active language title.
     *
     * @param list<int> $productIds
     * @return array<string, string> map of products_id string => name
     */
    function numinix_seekmodo_suggest_product_names(array $productIds): array
    {
        $out = [];
        foreach ($productIds as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0) {
                continue;
            }
            $name = '';
            if (function_exists('zen_get_products_name')) {
                try {
                    $name = trim((string) @zen_get_products_name($pid));
                } catch (\Throwable $e) {
                    $name = '';
                }
            }
            if ($name !== '') {
                $out[(string) $pid] = $name;
            }
        }

        return $out;
    }
}

if (!function_exists('numinix_seekmodo_plain_price_text')) {
    /**
     * Strip Zen Cart price HTML (e.g. productBasePrice spans) down to
     * shopper-facing text. Typeahead JSON is rendered with text escaping,
     * so leaving those tags in would print them in the dropdown.
     */
    function numinix_seekmodo_plain_price_text(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $raw = preg_replace('/<br\s*\/?>/i', ' ', $raw) ?? $raw;
        $stripped = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = preg_replace('/\s+/u', ' ', $stripped) ?? $stripped;
        return trim($stripped);
    }
}

if (!function_exists('numinix_seekmodo_plain_display_price')) {
    /**
     * Session-aware Zen Cart display price as plain text.
     */
    function numinix_seekmodo_plain_display_price(int $pid): string
    {
        if ($pid <= 0 || !function_exists('zen_get_products_display_price')) {
            return '';
        }
        return numinix_seekmodo_plain_price_text((string) @zen_get_products_display_price($pid));
    }
}

if (!function_exists('numinix_seekmodo_suggest_product_prices')) {
    /**
     * Session-aware display prices for suggest hydration.
     *
     * @param list<int> $productIds
     * @return array<string, array{display: string, currency: string, price: ?float}>
     */
    function numinix_seekmodo_suggest_product_prices(array $productIds): array
    {
        $currency = numinix_seekmodo_shopper_currency();
        $out = [];
        foreach ($productIds as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0) {
                continue;
            }
            $display = function_exists('numinix_seekmodo_plain_display_price')
                ? numinix_seekmodo_plain_display_price($pid)
                : '';
            $numeric = null;
            if (function_exists('zen_get_products_price')) {
                $raw = @zen_get_products_price($pid);
                if (is_numeric($raw)) {
                    $taxClassId = 0;
                    global $db;
                    if (isset($db) && is_object($db) && method_exists($db, 'Execute')) {
                        $taxRow = $db->Execute(
                            'SELECT products_tax_class_id FROM ' . TABLE_PRODUCTS
                            . ' WHERE products_id = ' . (int) $pid . ' LIMIT 1'
                        );
                        if ($taxRow && !$taxRow->EOF) {
                            $taxClassId = (int) ($taxRow->fields['products_tax_class_id'] ?? 0);
                        }
                    }
                    $numeric = function_exists('numinix_seekmodo_catalog_doc_index_price')
                        ? numinix_seekmodo_catalog_doc_index_price($pid, (float) $raw, $taxClassId)
                        : (float) $raw;
                }
            }
            $out[(string) $pid] = [
                'display' => $display,
                'currency' => $currency,
                'price' => $numeric,
            ];
        }

        return $out;
    }
}

if (!function_exists('_numinix_seekmodo_typeahead_attach_image_url')) {
    /**
     * `<seekmodo-suggest>` reads image_url (absolute URL). Legacy
     * typeahead JS still accepts pre-rendered HTML in `image`.
     */
    function _numinix_seekmodo_typeahead_attach_image_url(array &$item, int $pid, ?array $doc = null): void
    {
        $imageUrl = '';
        if (is_array($doc)) {
            if (!empty($doc['image_url']) && is_string($doc['image_url'])) {
                $imageUrl = trim($doc['image_url']);
            } elseif (!empty($doc['image']) && is_string($doc['image'])) {
                $rawImage = trim($doc['image']);
                if (preg_match('#^https?://#i', $rawImage) === 1) {
                    $imageUrl = $rawImage;
                } elseif (strpos($rawImage, '<') === false
                    && function_exists('numinix_seekmodo_catalog_doc_image_url')
                ) {
                    $imageUrl = numinix_seekmodo_catalog_doc_image_url($rawImage);
                }
            }
        }
        // Gateway / stale index docs sometimes carry Zen placeholders
        // (no_picture, template spacer). Prefer products_image from
        // the live catalog instead.
        if ($imageUrl !== ''
            && numinix_seekmodo_is_placeholder_suggest_image_url($imageUrl)
        ) {
            $imageUrl = '';
        }
        if ($imageUrl === '' && $pid > 0 && function_exists('numinix_seekmodo_catalog_doc_image_url')) {
            global $db;
            if (isset($db) && is_object($db)) {
                $look = $db->Execute(
                    'SELECT products_image FROM ' . TABLE_PRODUCTS
                    . ' WHERE products_id = ' . (int) $pid . ' LIMIT 1'
                );
                if (!$look->EOF) {
                    $imageUrl = numinix_seekmodo_catalog_doc_image_url(
                        (string) ($look->fields['products_image'] ?? '')
                    );
                }
            }
        }
        if ($imageUrl !== ''
            && numinix_seekmodo_is_placeholder_suggest_image_url($imageUrl)
        ) {
            $imageUrl = '';
        }
        if ($imageUrl !== '') {
            $item['image_url'] = $imageUrl;
        }
        if (!isset($item['image']) && function_exists('zen_get_products_image')) {
            try {
                $html = (string) @zen_get_products_image($pid, 60, 60);
                if ($html !== '' && $html !== 'false'
                    && preg_match('#(?:^|/)(?:no_picture\.(?:gif|png|jpe?g|webp)|x\.gif)#i', $html) !== 1
                    && stripos($html, '/includes/templates/') === false
                ) {
                    $item['image'] = $html;
                }
            } catch (\Throwable $e) {
                // Decorative only.
            }
        }
        if (isset($item['image']) && is_string($item['image'])
            && preg_match('#\ssrc=(["\'])([^"\']+)\1#i', $item['image'], $m) === 1
        ) {
            $parsed = trim($m[2]);
            if ($parsed !== ''
                && !numinix_seekmodo_is_placeholder_suggest_image_url($parsed)
            ) {
                if (preg_match('#^https?://#i', $parsed) === 1) {
                    $item['image_url'] = $parsed;
                } elseif (function_exists('numinix_seekmodo_catalog_doc_image_url')) {
                    $abs = numinix_seekmodo_catalog_doc_image_url($parsed);
                    if ($abs !== '') {
                        $item['image_url'] = $abs;
                    }
                }
            }
        }
        // Legacy `image` HTML for older typeahead JS — only after image_url is final.
        if (!isset($item['image']) && !empty($item['image_url']) && is_string($item['image_url'])) {
            $alt = isset($item['value']) && is_string($item['value'])
                ? htmlspecialchars($item['value'], ENT_QUOTES, 'UTF-8')
                : '';
            $src = htmlspecialchars($item['image_url'], ENT_QUOTES, 'UTF-8');
            $item['image'] = '<img src="' . $src . '" alt="' . $alt . '" />';
        }
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
            if (function_exists('zen_get_products_name')) {
                try {
                    $localName = trim((string) @zen_get_products_name($pid));
                    if ($localName !== '') {
                        $item['value'] = $localName;
                    }
                } catch (\Throwable $e) {
                    // keep gateway name
                }
            }
            if (function_exists('numinix_seekmodo_plain_display_price')) {
                $item['price'] = numinix_seekmodo_plain_display_price($pid);
            } elseif (isset($doc['price'])) {
                $item['price'] = numinix_seekmodo_plain_price_text((string) $doc['price']);
            }
            if (function_exists('zen_href_link') && function_exists('zen_get_info_page')) {
                try {
                    $item['url'] = numinix_seekmodo_href_link_raw(
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
            _numinix_seekmodo_typeahead_attach_image_url($item, $pid, $doc);
            $items[] = $item;
        }
        if (function_exists('numinix_seekmodo_catalog_partition_typeahead_items_live_stock')) {
            $items = numinix_seekmodo_catalog_partition_typeahead_items_live_stock($items);
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
            if (function_exists('zen_get_products_name')) {
                try {
                    $localName = trim((string) @zen_get_products_name($pid));
                    if ($localName !== '') {
                        $item['value'] = $localName;
                    }
                } catch (\Throwable $e) {
                    // keep gateway name
                }
            }
            // Price + URL + image come from Zen Cart helpers when this
            // is running inside the storefront request. Skip when they
            // aren't available (unit-test harness).
            if (function_exists('numinix_seekmodo_plain_display_price')) {
                $item['price'] = numinix_seekmodo_plain_display_price($pid);
            } elseif (isset($doc['price'])) {
                $item['price'] = numinix_seekmodo_plain_price_text((string) $doc['price']);
            }
            if (function_exists('zen_href_link') && function_exists('zen_get_info_page')) {
                try {
                    $item['url'] = numinix_seekmodo_href_link_raw(
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
            _numinix_seekmodo_typeahead_attach_image_url($item, $pid, $doc);
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
