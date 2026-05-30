<?php
/**
 * Search replacement helpers for the Seekmodo connector.
 *
 * Used by Zen Cart's storefront search path (typically
 * includes/classes/class.search.php) at the top of whatever helper the
 * storefront uses to talk to Typesense. The swap-point is small:
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
 *     shopper sees the native result.
 *   - off:     not reached — numinix_seekmodo_enabled() is false.
 *
 * ─────────────────────────────────────────────────────────────────────
 * Sidebar / attribute filter integration (v1.0.3+)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Most Zen Cart storefronts ship some form of attribute-filter sidebar
 * (brand checkboxes, size, color, capacity, etc.). The values selected
 * end up as `$_GET` parameters on the search URL. Two ways the storefront
 * can make sure those filters apply to gateway-routed results:
 *
 *   A) GATEWAY-SIDE (preferred when the attribute is indexed). Register
 *      a URL-param-to-Typesense-field mapping once at storefront boot:
 *
 *          numinix_seekmodo_register_filter_mapping('brand', 'brand');
 *          numinix_seekmodo_register_filter_mapping('type',  'p_type');
 *          numinix_seekmodo_register_filter_mapping('capacity_by_lbs',
 *                                                   'capacity');
 *
 *      The connector then builds a Typesense `filter_by` clause from
 *      `$_GET` automatically on every gateway call. Result: filters apply
 *      BEFORE pagination, so the full filtered ID list comes back from
 *      the gateway and the storefront can paginate locally without
 *      "ghost" filter mismatches.
 *
 *   B) STOREFRONT-SIDE (fallback for filters that aren't indexed). The
 *      storefront computes a local product-ID list itself, then calls
 *      `numinix_seekmodo_apply_local_filter($remote, $localIds)` to
 *      intersect the gateway's hits with the local set. Total count and
 *      rank order are recomputed by the helper. Use this for filters
 *      that depend on per-shopper state (saved-for-later lists, custom
 *      permissioning, real-time inventory feeds, etc.) — anything the
 *      indexer can't bake into the Typesense doc.
 *
 * Both approaches stack. A storefront can register the standard fields
 * AND post-filter the result for an obscure custom filter.
 *
 * See `docs/FILTERS.md` for end-to-end examples on Zen Cart, including
 * how to convert an `options_values_id`-based sidebar (the legacy
 * `nmn_filters` pattern Redline uses) to either approach.
 */

if (!function_exists('numinix_seekmodo_register_filter_mapping')) {
    /**
     * Declare that a storefront URL filter parameter maps to a Typesense
     * field that the indexer has populated. Idempotent: registering the
     * same `$urlParam` again replaces the previous mapping (so themes/
     * tenants can override the shipped defaults).
     *
     * Coercion (`$opts['coerce']`) controls how the raw `$_GET` value
     * is turned into a Typesense filter literal:
     *
     *   'int_list' (default for attribute filters)  — underscore- or
     *                comma-separated list of integers becomes `field:=[1,2,3]`.
     *                Picks up `options_values_id`-style sidebars like
     *                Redline's `nmn_filters` plumbing.
     *   'string_list'                                — same shape, but
     *                each token is quoted: `field:=[`a`,`b`]`.
     *   'int'                                         — single integer:
     *                                                  `field:=42`.
     *   'string'                                      — single string:
     *                                                  `field:=`xyz``.
     *   'bool'                                        — accepts
     *                'true/false/1/0/yes/no': `field:=true`.
     *   'range'                                       — single value
     *                'min..max' or paired `*_from`/`*_to`: `field:>=X && field:<=Y`.
     *
     * Coercion `'auto'` (unset) picks `int_list` when every token in the
     * value is numeric, else `string_list`. Most storefronts can use the
     * default.
     *
     * @param array{coerce?:string,multi_sep?:string} $opts
     */
    function numinix_seekmodo_register_filter_mapping(
        string $urlParam,
        string $field,
        array $opts = []
    ): void {
        $reg = &_numinix_seekmodo_filter_registry();
        $reg[$urlParam] = [
            'field' => $field,
            'coerce' => $opts['coerce'] ?? 'auto',
            'multi_sep' => $opts['multi_sep'] ?? '_',
        ];
    }
}

if (!function_exists('numinix_seekmodo_filter_mappings')) {
    /**
     * Return the live filter registry. Mostly useful for tests and the
     * admin status page; storefront code should not have to inspect it.
     *
     * @return array<string,array{field:string,coerce:string,multi_sep:string}>
     */
    function numinix_seekmodo_filter_mappings(): array
    {
        return _numinix_seekmodo_filter_registry();
    }
}

if (!function_exists('numinix_seekmodo_reset_filter_mappings')) {
    /**
     * Drop every registered mapping. Intended for unit tests; production
     * code should never need this. The default mappings are reseeded on
     * the next call to `_numinix_seekmodo_filter_registry()`.
     */
    function numinix_seekmodo_reset_filter_mappings(): void
    {
        $reg = &_numinix_seekmodo_filter_registry();
        $reg = null;
        // Force the registry helper to re-seed defaults on next read.
        _numinix_seekmodo_filter_registry(true);
    }
}

if (!function_exists('_numinix_seekmodo_filter_registry')) {
    /**
     * Static-by-reference registry holder. First call seeds the platform
     * defaults so a vanilla Zen Cart install gets sensible behavior out
     * of the box; tenant-specific mappings ought to override via
     * `numinix_seekmodo_register_filter_mapping()` in an init include.
     *
     * @return array<string,array{field:string,coerce:string,multi_sep:string}>
     */
    function &_numinix_seekmodo_filter_registry(bool $reseed = false): array
    {
        static $registry = null;
        if ($registry === null || $reseed) {
            $registry = [];
            // Default attribute mappings. These intentionally match what
            // a stock Zen Cart "nmn_filters" sidebox emits: lowercased
            // `products_options_name` with spaces collapsed to '_'.
            // Storefronts whose indexer renames fields (notably the
            // Redline tenant: `type → p_type`, `Capacity by LBS → capacity`)
            // are covered here so an out-of-the-box install Just Works.
            $defaults = [
                ['brand',           'brand',    ['coerce' => 'int_list']],
                ['type',            'p_type',   ['coerce' => 'int_list']],
                ['capacity_by_lbs', 'capacity', ['coerce' => 'int_list']],
            ];
            foreach ($defaults as [$urlParam, $field, $opts]) {
                $registry[$urlParam] = [
                    'field' => $field,
                    'coerce' => $opts['coerce'] ?? 'auto',
                    'multi_sep' => $opts['multi_sep'] ?? '_',
                ];
            }
        }
        return $registry;
    }
}

if (!function_exists('numinix_seekmodo_build_filter_by')) {
    /**
     * Walk the registered filter mappings, pull values from `$_GET`,
     * and emit a Typesense-compatible `filter_by` string (or null when
     * no filters are present).
     *
     * The string format mirrors Typesense's own syntax:
     *
     *   field:=[1,2,3]              // int_list, multi-value
     *   field:=`x`                  // string, single
     *   field:>=10 && field:<=20    // range
     *
     * Clauses for different fields are joined with ` && ` — the gateway's
     * `SearchTool` already ANDs any vertical-default filter_by clause
     * (e.g. `products_status:=true`) on top, so we don't repeat that here.
     */
    function numinix_seekmodo_build_filter_by(): ?string
    {
        $clauses = [];
        foreach (_numinix_seekmodo_filter_registry() as $urlParam => $spec) {
            // Range filters can show up as either `paramName=min..max`
            // OR the storefront's familiar `paramName_from` / `paramName_to`
            // pair (Zen Cart's price filter convention).
            if ($spec['coerce'] === 'range') {
                $clause = _numinix_seekmodo_filter_clause_range($urlParam, $spec['field']);
                if ($clause !== null) {
                    $clauses[] = $clause;
                }
                continue;
            }
            if (!isset($_GET[$urlParam]) || $_GET[$urlParam] === '') {
                continue;
            }
            $clause = _numinix_seekmodo_filter_clause(
                (string)$_GET[$urlParam],
                $spec['field'],
                $spec['coerce'],
                $spec['multi_sep']
            );
            if ($clause !== null) {
                $clauses[] = $clause;
            }
        }
        if ($clauses === []) {
            return null;
        }
        return implode(' && ', $clauses);
    }
}

if (!function_exists('numinix_seekmodo_build_filter_map')) {
    /**
     * Structured-form counterpart to
     * `numinix_seekmodo_build_filter_by()`. Returns a map of
     * `field => value` ready for the gateway's `filters` payload
     * field:
     *
     *   - scalar coerce  → value is a scalar (int / string / bool)
     *   - list coerces   → value is a numerically-indexed array
     *   - range coerce   → value is ['op' => ':>=', 'value' => ['10']]
     *                       merged with a separate '<=' entry when
     *                       the storefront sent both endpoints. The
     *                       gateway's FilterContext understands the
     *                       single-op shape; for `[lo, hi]` ranges
     *                       we emit two entries with field-name
     *                       disambiguators (`field_from`, `field_to`)
     *                       so the signature is stable.
     *
     * Anything that didn't parse cleanly is silently dropped — same
     * "lose a filter from the signature, don't fail the search"
     * policy as the gateway-side FilterContext.
     *
     * @return array<string, mixed>
     */
    function numinix_seekmodo_build_filter_map(): array
    {
        $out = [];
        foreach (_numinix_seekmodo_filter_registry() as $urlParam => $spec) {
            if ($spec['coerce'] === 'range') {
                $range = _numinix_seekmodo_filter_map_range($urlParam, $spec['field']);
                if ($range !== []) {
                    foreach ($range as $field => $entry) {
                        $out[$field] = $entry;
                    }
                }
                continue;
            }
            if (!isset($_GET[$urlParam]) || $_GET[$urlParam] === '') {
                continue;
            }
            $value = _numinix_seekmodo_filter_value(
                (string)$_GET[$urlParam],
                $spec['coerce'],
                $spec['multi_sep']
            );
            if ($value === null) {
                continue;
            }
            $out[$spec['field']] = $value;
        }
        return $out;
    }
}

if (!function_exists('_numinix_seekmodo_filter_value')) {
    /**
     * Scalar / list coercion in structured form (no Typesense
     * filter-clause string assembly). Mirrors the type table in
     * `_numinix_seekmodo_filter_clause()` but emits PHP values
     * instead of `field:=...` syntax.
     *
     * Returns null when nothing parsable was found.
     */
    function _numinix_seekmodo_filter_value(
        string $raw,
        string $coerce,
        string $multiSep
    ) {
        $tokens = preg_split('/[' . preg_quote($multiSep, '/') . ',]/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false || $tokens === []) {
            return null;
        }
        if ($coerce === 'auto') {
            $allInt = true;
            foreach ($tokens as $t) {
                if (!ctype_digit(ltrim((string)$t, '-'))) {
                    $allInt = false;
                    break;
                }
            }
            $coerce = $allInt ? 'int_list' : 'string_list';
        }
        switch ($coerce) {
            case 'int_list':
                $ints = [];
                foreach ($tokens as $t) {
                    $i = (int)$t;
                    if ($i !== 0 || (string)$t === '0') {
                        $ints[] = $i;
                    }
                }
                return $ints === [] ? null : $ints;
            case 'string_list':
                return array_map('strval', $tokens);
            case 'int':
                return (int)$tokens[0];
            case 'string':
                return (string)$tokens[0];
            case 'bool':
                $v = strtolower((string)$tokens[0]);
                return in_array($v, ['1', 'true', 'yes', 'on'], true);
            default:
                return null;
        }
    }
}

if (!function_exists('_numinix_seekmodo_filter_map_range')) {
    /**
     * Range coercion in structured form. Reads both `$urlParam` (as
     * `min..max`) and `$urlParam.'_from'`/`_to` (the Zen Cart price
     * pair). Returns a 0/1/2-entry map:
     *
     *   { "$field_from": [':>=', value], "$field_to": [':<=', value] }
     *
     * Field-name disambiguation (`_from` / `_to`) keeps the gateway's
     * FilterContext signature stable across permutations.
     *
     * @return array<string, array{op:string, value:array<int,string>}>
     */
    function _numinix_seekmodo_filter_map_range(string $urlParam, string $field): array
    {
        $lo = null;
        $hi = null;
        if (isset($_GET[$urlParam]) && is_string($_GET[$urlParam])
            && strpos($_GET[$urlParam], '..') !== false) {
            $bits = explode('..', (string)$_GET[$urlParam], 2);
            $lo = $bits[0] !== '' ? $bits[0] : null;
            $hi = $bits[1] !== '' ? $bits[1] : null;
        }
        if ($lo === null && isset($_GET[$urlParam . '_from']) && $_GET[$urlParam . '_from'] !== '') {
            $lo = (string)$_GET[$urlParam . '_from'];
        }
        if ($hi === null && isset($_GET[$urlParam . '_to']) && $_GET[$urlParam . '_to'] !== '') {
            $hi = (string)$_GET[$urlParam . '_to'];
        }
        $out = [];
        if ($lo !== null && (float)$lo > 0) {
            $out[$field . '_from'] = ['op' => ':>=', 'value' => [(string)((float)$lo)]];
        }
        if ($hi !== null && (float)$hi > 0) {
            $out[$field . '_to'] = ['op' => ':<=', 'value' => [(string)((float)$hi)]];
        }
        return $out;
    }
}

if (!function_exists('_numinix_seekmodo_filter_clause')) {
    /**
     * Convert one URL-param value into a Typesense filter clause.
     */
    function _numinix_seekmodo_filter_clause(
        string $raw,
        string $field,
        string $coerce,
        string $multiSep
    ): ?string {
        $tokens = preg_split('/[' . preg_quote($multiSep, '/') . ',]/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false || $tokens === []) {
            return null;
        }
        if ($coerce === 'auto') {
            // Treat as int_list when every token parses as an integer;
            // string_list otherwise.
            $allInt = true;
            foreach ($tokens as $t) {
                if (!ctype_digit(ltrim((string)$t, '-'))) {
                    $allInt = false;
                    break;
                }
            }
            $coerce = $allInt ? 'int_list' : 'string_list';
        }
        switch ($coerce) {
            case 'int_list':
                $ints = [];
                foreach ($tokens as $t) {
                    $i = (int)$t;
                    // Keep literal "0" (some indexers use 0 as a
                    // sentinel for "unset attribute"); drop anything
                    // that didn't parse to an int.
                    if ($i !== 0 || (string)$t === '0') {
                        $ints[] = $i;
                    }
                }
                if ($ints === []) {
                    return null;
                }
                return $field . ':=[' . implode(',', $ints) . ']';
            case 'string_list':
                $quoted = [];
                foreach ($tokens as $t) {
                    $quoted[] = '`' . _numinix_seekmodo_escape_filter_string((string)$t) . '`';
                }
                return $field . ':=[' . implode(',', $quoted) . ']';
            case 'int':
                $i = (int)$tokens[0];
                return $field . ':=' . $i;
            case 'string':
                return $field . ':=`' . _numinix_seekmodo_escape_filter_string((string)$tokens[0]) . '`';
            case 'bool':
                $v = strtolower((string)$tokens[0]);
                $b = in_array($v, ['1', 'true', 'yes', 'on'], true) ? 'true' : 'false';
                return $field . ':=' . $b;
            default:
                return null;
        }
    }
}

if (!function_exists('_numinix_seekmodo_filter_clause_range')) {
    /**
     * Range coercion. Accepts either `$_GET[$urlParam]='min..max'` or
     * the paired `$_GET[$urlParam.'_from'] / _to` shape Zen Cart uses
     * for its price filter (the price-from/price-to are handled
     * separately by `_numinix_seekmodo_build_search_payload`; this
     * helper is for storefronts that register additional range filters).
     */
    function _numinix_seekmodo_filter_clause_range(string $urlParam, string $field): ?string
    {
        $min = null;
        $max = null;
        if (isset($_GET[$urlParam]) && is_string($_GET[$urlParam]) && strpos($_GET[$urlParam], '..') !== false) {
            [$rawMin, $rawMax] = array_pad(explode('..', $_GET[$urlParam], 2), 2, '');
            if ($rawMin !== '' && is_numeric($rawMin)) {
                $min = $rawMin + 0;
            }
            if ($rawMax !== '' && is_numeric($rawMax)) {
                $max = $rawMax + 0;
            }
        }
        if (isset($_GET[$urlParam . '_from']) && is_numeric($_GET[$urlParam . '_from'])) {
            $min = $_GET[$urlParam . '_from'] + 0;
        }
        if (isset($_GET[$urlParam . '_to']) && is_numeric($_GET[$urlParam . '_to'])) {
            $max = $_GET[$urlParam . '_to'] + 0;
        }
        $parts = [];
        if ($min !== null) {
            $parts[] = $field . ':>=' . $min;
        }
        if ($max !== null) {
            $parts[] = $field . ':<=' . $max;
        }
        if ($parts === []) {
            return null;
        }
        return implode(' && ', $parts);
    }
}

if (!function_exists('_numinix_seekmodo_escape_filter_string')) {
    /**
     * Typesense filter strings are backtick-delimited. Drop characters
     * that would break the parser. We're intentionally aggressive here —
     * filter values come from `$_GET` and we don't want injection
     * surfaces.
     */
    function _numinix_seekmodo_escape_filter_string(string $s): string
    {
        $s = str_replace(['`', '\\'], '', $s);
        // Strip anything that isn't a printable ASCII char + a few common
        // accented chars; tenants with non-Latin catalog values should
        // register a custom coerce.
        $s = preg_replace('/[^\x20-\x7E\xC0-\xFF]/', '', $s);
        return substr((string)$s, 0, 128);
    }
}

if (!function_exists('numinix_seekmodo_apply_local_filter')) {
    /**
     * Storefront-side intersection helper. When the storefront has a
     * filter that ISN'T indexed in Typesense (Redline's nmn_filters
     * sidebar parses `options_values_id`-style URL params directly
     * against `products_attributes`, for instance), it computes a
     * `$localIds` list itself and calls this helper to align the
     * gateway result with the local filter set.
     *
     * The remote rank order is preserved — products in `$localIds` keep
     * their position from the gateway response, with `total` rewritten
     * to the intersection's size. Returns a NEW result envelope; the
     * input is not mutated.
     *
     * Usage:
     *
     *     $remote = numinix_seekmodo_run_search($params);
     *     if ($remote !== null && !empty($localIds)) {
     *         $remote = numinix_seekmodo_apply_local_filter($remote, $localIds);
     *     }
     *
     * @param array<string,mixed> $remoteResult The envelope returned by
     *     `numinix_seekmodo_run_search()`.
     * @param array<int>|array<string> $localIds The locally-computed
     *     allow-list. Strings are coerced to int.
     * @return array<string,mixed>
     */
    function numinix_seekmodo_apply_local_filter(array $remoteResult, array $localIds): array
    {
        if (!isset($remoteResult['products']) || !is_array($remoteResult['products'])) {
            return $remoteResult;
        }
        $allow = [];
        foreach ($localIds as $id) {
            $i = (int)$id;
            if ($i > 0) {
                $allow[$i] = true;
            }
        }
        if ($allow === []) {
            // Local filter is "match nothing" — return an empty result
            // but keep the metadata so the caller's branching stays
            // sane.
            return [
                'products' => [],
                'total' => 0,
                'corrected_query' => $remoteResult['corrected_query'] ?? null,
                'variant' => $remoteResult['variant'] ?? 'lexical',
                'semantic_shadow' => $remoteResult['semantic_shadow'] ?? '',
            ] + $remoteResult;
        }
        $kept = [];
        foreach ($remoteResult['products'] as $pid) {
            $i = (int)$pid;
            if (isset($allow[$i])) {
                $kept[] = $i;
            }
        }
        $out = $remoteResult;
        $out['products'] = $kept;
        $out['total'] = count($kept);
        return $out;
    }
}

if (!function_exists('numinix_seekmodo_run_search')) {
    /**
     * @param array<string,mixed> $params Same shape Zen Cart's storefront
     *     search passes through: {keyword, search_in_description,
     *     categories_id, manufacturers_id, pfrom, pto}.
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

        // Stash the gateway's search_event_id (and the filter
        // signature, for cross-checking) on the request so the page
        // render can emit it into the HTML for the click beacon to
        // pick up. The trainer joins click → search_row precisely
        // via this id.
        if (isset($firstResp['meta']['search_event_id'])) {
            _numinix_seekmodo_remember_search_event([
                'search_event_id' => (int)$firstResp['meta']['search_event_id'],
                'keyword' => isset($params['keyword']) ? (string)$params['keyword'] : '',
                'filter_by' => isset($payload['filter_by']) ? (string)$payload['filter_by'] : '',
                'filters' => isset($payload['filters']) && is_array($payload['filters'])
                    ? $payload['filters']
                    : [],
                'filter_hash' => isset($firstResp['meta']['filters']['hash'])
                    ? (string)$firstResp['meta']['filters']['hash']
                    : null,
                'recorded_at' => time(),
            ]);
        }

        return _numinix_seekmodo_normalize_response($merged, $params);
    }
}

if (!function_exists('_numinix_seekmodo_remember_search_event')) {
    /**
     * Per-request memo + per-session stash of the gateway's
     * search-row id so the page render (and the click beacon in JS)
     * can echo it back.
     *
     * Two storage layers:
     *
     *   1. Static within the request — covers the common case where
     *      `numinix_seekmodo_run_search()` runs, then header_php.php
     *      renders the page, then the impression beacon fires —
     *      all inside one PHP request.
     *
     *   2. `$_SESSION['numinix_seekmodo_search_event']` — survives a
     *      redirect or a no-cookie AJAX poll. Trimmed to the most
     *      recent search; the click beacon is only meaningful within
     *      a single SERP render, so we don't keep a history.
     *
     * @param array{search_event_id:int, keyword:string, filter_by:string, filters:array, filter_hash:?string, recorded_at:int} $row
     */
    function _numinix_seekmodo_remember_search_event(array $row): void
    {
        static $current = null;
        $current = $row;
        $GLOBALS['_numinix_seekmodo_current_search_event'] = $row;
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['numinix_seekmodo_search_event'] = $row;
        }
    }
}

if (!function_exists('numinix_seekmodo_current_search_event')) {
    /**
     * Storefront-facing accessor for the most recent gateway search-
     * row id + the filter state at the moment of search. Returns
     * null when no gateway search has run in this request / session.
     *
     * Templates render this id into a hidden input so the JS click
     * beacon (`ajax_search_log.php` on Zen Cart) can ship it back
     * with every click. The trainer reads it as the `search_event_id`
     * FK on the click telemetry row.
     *
     * Shape:
     *
     *     [
     *       'search_event_id' => 123456,
     *       'keyword'         => 'automotive rotisserie',
     *       'filter_by'       => 'in_stock:=true && brand:=[Acme]',
     *       'filters'         => ['brand' => ['Acme'], ...],
     *       'filter_hash'     => 'a1b2c3d4e5f60718', // 16-char gateway hash
     *       'recorded_at'     => 1717000000,
     *     ]
     *
     * @return array<string, mixed>|null
     */
    function numinix_seekmodo_current_search_event(): ?array
    {
        if (isset($GLOBALS['_numinix_seekmodo_current_search_event'])
            && is_array($GLOBALS['_numinix_seekmodo_current_search_event'])) {
            return $GLOBALS['_numinix_seekmodo_current_search_event'];
        }
        if (session_status() === PHP_SESSION_ACTIVE
            && isset($_SESSION['numinix_seekmodo_search_event'])
            && is_array($_SESSION['numinix_seekmodo_search_event'])) {
            return $_SESSION['numinix_seekmodo_search_event'];
        }
        return null;
    }
}

if (!function_exists('_numinix_seekmodo_session_id')) {
    /**
     * Resolve the shopper's session id for the gateway payload.
     *
     * Why this is needed: the gateway's bot-check classifier
     * (`SearchTool::execute` → `BotCheck\InlineClient::classify`)
     * requires a non-empty session id; without it the classifier
     * is skipped and every search row lands with `is_bot = NULL`,
     * which makes the "Bots blocked (24h)" admin tile read 0
     * forever. (P0-1.)
     *
     * Resolution order, first non-empty wins:
     *   1. Storefront's existing `numinix_search_log_session_token()` —
     *      stable per-shopper identifier used for the local click log.
     *   2. PHP's own `session_id()` when a session is active (Zen
     *      Cart starts one on every storefront request).
     *   3. Last-resort hash of UA + REMOTE_ADDR. Coarser than a real
     *      session token but stable enough for bot-check stickiness
     *      across the same render's clicks.
     *
     * Returns '' only when none of the three are available, which
     * means we're being called from a CLI cron or unit test — bot
     * classification is correctly skipped in that case.
     */
    function _numinix_seekmodo_session_id(): string
    {
        if (function_exists('numinix_search_log_session_token')) {
            $t = (string)numinix_search_log_session_token();
            if ($t !== '') {
                return substr($t, 0, 64);
            }
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $sid = (string)session_id();
            if ($sid !== '') {
                return substr($sid, 0, 64);
            }
        }
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ua === '' && $ip === '') {
            return '';
        }
        return 'h_' . substr(hash('sha256', $ua . '|' . $ip), 0, 16);
    }
}

if (!function_exists('_numinix_seekmodo_client_ip')) {
    /**
     * Resolve the originating shopper IP for the gateway payload.
     *
     * Most production storefronts sit behind Cloudflare or another
     * reverse proxy, so the bare `REMOTE_ADDR` is the edge IP — no use
     * to the bot-check classifier. Walk the standard reverse-proxy
     * headers in priority order:
     *
     *   1. CF-Connecting-IP — Cloudflare's canonical client header,
     *      single IP, not user-spoofable past the edge.
     *   2. X-Forwarded-For — generic proxy header, can be a chain;
     *      take the leftmost entry which is the originator.
     *   3. REMOTE_ADDR — last resort when no proxy header is set
     *      (cron, direct origin hits).
     *
     * Capped at 64 chars (longest plausible IPv6 + zone id) so a
     * malformed header can't bloat the payload.
     */
    function _numinix_seekmodo_client_ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
            if (empty($_SERVER[$k])) {
                continue;
            }
            $v = (string)$_SERVER[$k];
            if (strpos($v, ',') !== false) {
                $v = trim(explode(',', $v, 2)[0]);
            }
            if ($v !== '') {
                return substr($v, 0, 64);
            }
        }
        return '';
    }
}

if (!function_exists('_numinix_seekmodo_apply_sort_deprecations')) {
    /**
     * Rewrite known-stale sort-field tokens to their canonical
     * Typesense schema names (PR 7b Layer 2).
     *
     * The keyword/browse sort_by constants in
     * `NUMINIX_TYPESENSE_KEYWORD_SORT_BY` and
     * `NUMINIX_TYPESENSE_BROWSE_SORT_BY` are storefront-admin
     * configurable strings. Older Seekmodo installs (Redline pre-PR-7b
     * is the documented case) configured them with Zen Cart database
     * column names like `products_instock:desc` instead of the
     * canonical Typesense schema names (`in_stock:desc`). Without
     * rewriting, the gateway forwards the stale value to Typesense,
     * which 404s with "Could not find a field named ... in the schema
     * for sorting." and the storefront falls back to legacy CMS
     * search.
     *
     * Per-token replacement is intentional: the constant value is
     * a comma-separated list (`a:desc,b:asc`) and we want to fix
     * exactly the field name on either side of the colon, never
     * the direction or the comma boundary.
     *
     * Logs once per request to `numinix_seekmodo.log` when any
     * substitution lands so admins can see the bridge is in effect
     * (and update the constant in admin → Modules → Seekmodo).
     */
    function _numinix_seekmodo_apply_sort_deprecations(string $sortBy): string
    {
        if ($sortBy === '') {
            return $sortBy;
        }
        // canonical schema field name <- legacy / misnamed token.
        // Keep additions narrow: every entry should be a documented
        // field rename in the gateway's commerce schema.
        static $deprecated = [
            'products_instock' => 'in_stock',
        ];
        $clauses = explode(',', $sortBy);
        $applied = [];
        foreach ($clauses as $i => $clause) {
            $trimmed = trim($clause);
            if ($trimmed === '') {
                continue;
            }
            $colonAt = strpos($trimmed, ':');
            $field = $colonAt === false ? $trimmed : substr($trimmed, 0, $colonAt);
            $rest = $colonAt === false ? '' : substr($trimmed, $colonAt);
            if (isset($deprecated[$field])) {
                $clauses[$i] = $deprecated[$field] . $rest;
                $applied[] = $field . ' -> ' . $deprecated[$field];
            } else {
                $clauses[$i] = $trimmed;
            }
        }
        if ($applied === []) {
            return $sortBy;
        }
        $logDir = '';
        if (defined('DIR_FS_LOGS')) {
            $logDir = rtrim(DIR_FS_LOGS, '/\\');
        } elseif (defined('DIR_FS_CATALOG')) {
            $logDir = rtrim(DIR_FS_CATALOG, '/\\') . '/logs';
        }
        if ($logDir !== '' && is_dir($logDir)) {
            $row = [
                'ts'      => date('c'),
                'msg'     => 'sort_deprecation_applied',
                'before'  => $sortBy,
                'after'   => implode(',', $clauses),
                'rewrites' => $applied,
            ];
            $line = json_encode($row, JSON_UNESCAPED_SLASHES);
            if ($line !== false) {
                @file_put_contents($logDir . '/numinix_seekmodo.log', $line . PHP_EOL, FILE_APPEND);
            }
        }
        return implode(',', $clauses);
    }
}

if (!function_exists('_numinix_seekmodo_build_search_payload')) {
    /**
     * Translate the storefront's search params into the gateway's
     * /v1/search payload shape. Anything we don't recognize is dropped
     * — the gateway has a strict input schema.
     *
     * v1.0.3 change: attribute filters now flow as a Typesense
     * `filter_by` clause built from the runtime filter-mapping registry
     * (`numinix_seekmodo_build_filter_by()`). The previous `facets`
     * payload field was never consumed by the gateway and is removed.
     *
     * Shopper-context fields (`session_id`, `ua`, `ip`, `referer`) are
     * attached on every storefront-originated call so the gateway's
     * bot-check classifier and per-shopper telemetry get real
     * attribution. Without these the gateway sees only the connector
     * host's IP and an empty UA / session, and bot-check is skipped
     * entirely (the §0.6 P0-1 regression). The gateway's `SearchTool`
     * accepts these as body args (mirroring `EventsTool`) and falls
     * back to its own `$_SERVER`-derived context only when absent.
     */
    function _numinix_seekmodo_build_search_payload(array $params): array
    {
        $keyword = isset($params['keyword']) ? trim((string)$params['keyword']) : '';
        $payload = [
            'q' => $keyword === '' ? '*' : $keyword,
            'search_in_description' => !empty($params['search_in_description']),
        ];

        // Structured filter map (v1.0.4). Mirrors whatever ends up in
        // `filter_by` below, but in already-parsed form so the gateway
        // can normalize + hash without lexing the filter_by string.
        // The gateway's FilterContext::build prefers `filters` when
        // both are present, so this dictates the canonical signature.
        $structuredFilters = [];

        if (!empty($params['categories_id'])) {
            $payload['categories_id'] = (string)$params['categories_id'];
            $structuredFilters['categories_id'] = (int)$params['categories_id'];
        }
        if (!empty($params['manufacturers_id'])) {
            $payload['manufacturers_id'] = (int)$params['manufacturers_id'];
            $structuredFilters['manufacturers_id'] = (int)$params['manufacturers_id'];
        }
        if (isset($params['pfrom']) && (float)$params['pfrom'] > 0) {
            $payload['price_from'] = (float)$params['pfrom'];
            $structuredFilters['price_from'] = ['op' => ':>=', 'value' => [(string)((float)$params['pfrom'])]];
        }
        if (isset($params['pto']) && (float)$params['pto'] > 0) {
            $payload['price_to'] = (float)$params['pto'];
            $structuredFilters['price_to'] = ['op' => ':<=', 'value' => [(string)((float)$params['pto'])]];
        }

        // Attribute filters → Typesense `filter_by` clause. Built from
        // the runtime registry so each storefront can declare its own
        // filter→field mappings without forking this file. See the
        // header docblock for the integration contract.
        $filterBy = numinix_seekmodo_build_filter_by();
        if ($filterBy !== null && $filterBy !== '') {
            // If the caller passed in their own filter_by (rare — only
            // the typeahead helper does today), AND ours onto the back
            // so both clauses apply. Matches the gateway's own merge
            // strategy with vertical defaults.
            if (!empty($params['filter_by'])) {
                $payload['filter_by'] = (string)$params['filter_by'] . ' && ' . $filterBy;
            } else {
                $payload['filter_by'] = $filterBy;
            }
        } elseif (!empty($params['filter_by'])) {
            $payload['filter_by'] = (string)$params['filter_by'];
        }

        // Layer the registry's per-mapping structured view onto
        // $structuredFilters. The registry already parsed `$_GET`
        // into per-field values, so this is a cheap copy.
        if (function_exists('numinix_seekmodo_build_filter_map')) {
            foreach (numinix_seekmodo_build_filter_map() as $field => $values) {
                if ($field === '' || $values === []) {
                    continue;
                }
                $structuredFilters[$field] = $values;
            }
        }
        if ($structuredFilters !== []) {
            $payload['filters'] = $structuredFilters;
        }

        // RED-1612-tuning: forward the storefront's Typesense
        // tuning constants so the gateway calls Typesense with the
        // same typo/drop thresholds and field-weighting the
        // storefront would use if it talked to Typesense directly.
        //
        // Without these, the gateway falls back to commerce-vertical
        // defaults from SearchDefaults::for() — currently
        // drop_tokens_threshold=1, typo_tokens_threshold=1, plus a
        // 4-field query_by ("name, model, products_description, etc.")
        // tuned for a generic medium catalog. Small / quirky catalogs
        // (Redline has ~3.2k SKUs with niche keywords) need a higher
        // token-drop threshold to recall partial matches. Symptom seen
        // on redlinestands.com production: `keyword=automotive
        // rotisserie` returned 9 hits via gateway but 177 hits via
        // direct Typesense (which DID send these tuning params),
        // because the gateway never received drop=10/typo=10 from the
        // connector.
        //
        // The gateway accepts these params unconditionally — see
        // SearchTool::execute() PASS_THROUGH list. Two safety rules:
        //
        // 1. Scalars (drop_tokens_threshold, typo_tokens_threshold)
        //    are always safe: gateway uses them when present, falls
        //    back to defaults otherwise. No field-count alignment
        //    concern.
        //
        // 2. Per-field arrays (query_by_weights, prefix, infix) must
        //    have the same comma-count as query_by. If we send
        //    misaligned arrays Typesense 400s the whole request. So
        //    we only set the per-field bundle when (a) query_by is
        //    defined AND (b) each storefront constant's field count
        //    matches query_by's. Otherwise we leave the param off and
        //    the gateway's defaults take over for that one field.
        if (defined('NUMINIX_TYPESENSE_TYPO_TOKENS_THRESHOLD')) {
            $payload['typo_tokens_threshold'] = (int)NUMINIX_TYPESENSE_TYPO_TOKENS_THRESHOLD;
        }
        if (defined('NUMINIX_TYPESENSE_DROP_TOKENS_THRESHOLD')) {
            $payload['drop_tokens_threshold'] = (int)NUMINIX_TYPESENSE_DROP_TOKENS_THRESHOLD;
        }
        if (defined('NUMINIX_TYPESENSE_QUERY_BY') && NUMINIX_TYPESENSE_QUERY_BY !== '') {
            $qBy = (string)NUMINIX_TYPESENSE_QUERY_BY;
            $payload['query_by'] = $qBy;
            $fieldCount = substr_count($qBy, ',') + 1;
            $perField = [
                'query_by_weights' => 'NUMINIX_TYPESENSE_QUERY_BY_WEIGHTS',
                'prefix'           => 'NUMINIX_TYPESENSE_PREFIX',
                'infix'            => 'NUMINIX_TYPESENSE_INFIX',
            ];
            foreach ($perField as $param => $constName) {
                if (defined($constName)) {
                    $val = constant($constName);
                    if (is_string($val) && $val !== ''
                        && (substr_count($val, ',') + 1) === $fieldCount) {
                        $payload[$param] = $val;
                    }
                }
            }
        }
        // Keyword-vs-browse sort: storefront has two configured sort
        // strings (KEYWORD_SORT_BY for "shopper typed a query",
        // BROWSE_SORT_BY for "shopper clicked a category with no
        // query"). Forward the appropriate one when defined so the
        // gateway's reranker has the right starting order. Skip if
        // the caller already passed in a sort_by (storefront
        // categories pre-build their own).
        if (empty($payload['sort_by'])) {
            if ($keyword !== '' && defined('NUMINIX_TYPESENSE_KEYWORD_SORT_BY')
                && NUMINIX_TYPESENSE_KEYWORD_SORT_BY !== '') {
                $payload['sort_by'] = (string)NUMINIX_TYPESENSE_KEYWORD_SORT_BY;
            } elseif ($keyword === '' && defined('NUMINIX_TYPESENSE_BROWSE_SORT_BY')
                && NUMINIX_TYPESENSE_BROWSE_SORT_BY !== '') {
                $payload['sort_by'] = (string)NUMINIX_TYPESENSE_BROWSE_SORT_BY;
            }
        }

        // Sort-field deprecation map (PR 7b). Storefront admins on
        // older Seekmodo deployments configured the keyword/browse
        // sort constants with Zen Cart database column names (e.g.
        // `products_instock:desc`) that don't match the canonical
        // Typesense schema field names (`in_stock`). Pre-PR-7b the
        // gateway forwarded the misconfigured value to Typesense
        // verbatim, which then 404'd with "Could not find a field
        // named ... in the schema for sorting." — every shopper's
        // category browse fell over to the legacy CMS-search path.
        //
        // Rewrite known-stale tokens in-place. Logged once per
        // request so admins can see the migration is happening (and
        // get a hint to update their constant directly).
        if (!empty($payload['sort_by'])) {
            $payload['sort_by'] = _numinix_seekmodo_apply_sort_deprecations(
                (string)$payload['sort_by']
            );
        }

        // Shopper context — see helper docblocks above. Always set
        // session_id and ip (even if to ''); the gateway's empty-string
        // skip handles those correctly. `ua` only when the request
        // actually carried one. `referer` is omitted when absent to
        // keep the body small.
        $payload['session_id'] = _numinix_seekmodo_session_id();
        if (isset($_SERVER['HTTP_USER_AGENT']) && $_SERVER['HTTP_USER_AGENT'] !== '') {
            $payload['ua'] = substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 512);
        } else {
            $payload['ua'] = '';
        }
        $payload['ip'] = _numinix_seekmodo_client_ip();
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $payload['referer'] = substr((string)$_SERVER['HTTP_REFERER'], 0, 255);
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
