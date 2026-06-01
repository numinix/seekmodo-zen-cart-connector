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
     *   { "q": "...", "items": [ {products_id, value, model, price, url, image?}, ... ], "total": int }
     *   or null on off/shadow/failure (caller falls back to direct Typesense).
     */
    function numinix_seekmodo_run_typeahead(string $q, int $max = 8, array $opts = []): ?array
    {
        if (!function_exists('numinix_seekmodo_enabled') || !numinix_seekmodo_enabled()) {
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
            return null;
        }

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

        // Shopper-context attribution. Identical to the search lib so
        // bot-check / telemetry / A-B bucketing all anchor on the same
        // session id across typeahead → search-result → click. Without
        // this the gateway sees only the connector host's IP and the
        // bot-check classifier is skipped.
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

        $startMs = (int)(microtime(true) * 1000);
        $resp = numinix_seekmodo_search($payload);
        $elapsedMs = (int)(microtime(true) * 1000) - $startMs;

        if (function_exists('numinix_seekmodo_observe')) {
            numinix_seekmodo_observe($resp !== null);
        }

        // Even in shadow mode the impression is interesting telemetry —
        // it tells us "did the gateway succeed at producing typeahead
        // results", which is what the verifier looks for. We tag with
        // extra.shadow=true so dashboards don't conflate shadow
        // impressions with enforce ones.
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

        // enforce
        if ($resp === null) {
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
