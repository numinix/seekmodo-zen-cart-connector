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
            // Default URL-param → Typesense-field mappings.
            //
            // Three groups, in order:
            //
            //   1. Zen Cart core SERP filters whose target Typesense
            //      field exists in the connector's commerce schema —
            //      `categories_id` and its `cPath` alias both narrow
            //      the result set to a category subtree, and the
            //      schema has `category_id` (int32[]) for it.
            //
            //   2. Numinix-flavoured aliases of the same. Numinix's
            //      `default_filter.php` exposes `filter_id` as a
            //      cross-cut filter that, when `manufacturers_id`
            //      isn't set, stands in for `manufacturers_id`. We do
            //      NOT register it because the Typesense schema does
            //      not yet carry an integer manufacturer_id facet —
            //      the gateway-side translation happens via the
            //      `manufacturers_id` top-level payload key
            //      (see _numinix_seekmodo_build_search_payload).
            //
            //   3. Tenant-specific attribute filters — the legacy
            //      "nmn_filters" sidebox uses `products_options_name`
            //      lowercased with spaces collapsed to '_'. The
            //      Redline tenant's indexer renames a couple of these
            //      (`type → p_type`, `Capacity by LBS → capacity`),
            //      so registering both the URL param and the renamed
            //      field keeps an out-of-the-box install behaving the
            //      same way it did before the connector landed.
            //
            // `pfrom` / `pto` (Zen Cart price slider) are NOT in this
            // list because `_numinix_seekmodo_build_search_payload`
            // already forwards them as top-level `price_from` /
            // `price_to` payload keys + a structured `filters` entry —
            // duplicating that here would attach the constraint twice
            // and shave the result set incorrectly.
            //
            // A storefront that wants to add or override a mapping
            // calls `numinix_seekmodo_register_filter_mapping(
            //   $urlParam, $field, $opts)` from an init include —
            // re-registering an existing urlParam replaces the
            // default. Tenant init includes are the right place for
            // each storefront's own facet vocabulary; the registry
            // here is the lowest-common-denominator default.
            $defaults = [
                // Group 1 — Zen Cart core SERP filters with matching schema fields.
                ['categories_id', 'category_id', ['coerce' => 'int']],
                ['cPath',         'category_id', ['coerce' => 'int']],
                // Group 3 — legacy nmn_filters defaults.
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
        // Sprint 12 — tenant domain lock. Short-circuit BEFORE we
        // touch the FSM / build payloads / spend any breaker budget.
        // Same posture as MODE=off: return null and let the caller's
        // existing native-fallback path serve the request.
        if (
            function_exists('numinix_seekmodo_is_locked_out')
            && numinix_seekmodo_is_locked_out()
        ) {
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

        // Enforce-mode result cache (APCu, short TTL). Shopper-facing
        // SERPs / typeahead can hit the gateway dozens of times for a
        // single user session (initial search + every sortby toggle +
        // every pagination click), and the gateway round-trip is the
        // single largest contributor to listing latency (~500–900ms in
        // production). For shopper-facing requests, a hit on this
        // cache turns a SERP from ~1s to ~150ms.
        //
        // Scope and safety:
        //   - Only active in enforce mode. Shadow-mode prospects MUST
        //     ship every storefront search to the gateway so the
        //     trainer has full coverage; off/locked already short-
        //     circuit above.
        //   - Cached envelope retains the gateway's `meta` so the
        //     click beacon's `search_event_id` and filter signature
        //     replay correctly on cached hits (slightly less granular
        //     attribution, but the trainer can dedupe by search_row).
        //   - Result-set is the merged response BEFORE shopper sorting
        //     / pagination — those happen entirely in MySQL against
        //     the IN-list, so a single cached entry serves all sort
        //     orders and page numbers for that query+filter combo.
        //   - Failures (null result) are NOT cached, to avoid masking
        //     transient gateway outages.
        //   - Disabled when the `seekmodo_nocache=1` debug param is
        //     present so operators can compare cached vs uncached
        //     timings without flushing the cache.
        $cacheTtlS = 300;
        $cacheBypass = isset($_GET['seekmodo_nocache']) && (string)$_GET['seekmodo_nocache'] === '1';
        $useCache = ($mode === 'enforce') && !$cacheBypass;
        $cacheKey = null;
        $cacheBackend = null; // 'apcu' | 'file' | null
        if ($useCache) {
            $tenant = function_exists('numinix_seekmodo_tenant_id')
                ? (string)numinix_seekmodo_tenant_id()
                : (string)(defined('NUMINIX_SEEKMODO_TENANT_ID') ? NUMINIX_SEEKMODO_TENANT_ID : '');
            $keyParts = [
                'kw'   => isset($params['keyword']) ? mb_strtolower(trim((string)$params['keyword'])) : '',
                'desc' => isset($params['search_in_description']) ? (int)$params['search_in_description'] : 0,
                'cat'  => isset($params['categories_id']) ? (int)$params['categories_id'] : 0,
                'mfr'  => isset($params['manufacturers_id']) ? (int)$params['manufacturers_id'] : 0,
                'pfr'  => isset($params['pfrom']) ? (float)$params['pfrom'] : 0.0,
                'pto'  => isset($params['pto']) ? (float)$params['pto'] : 0.0,
                'lang' => isset($_SESSION['languages_id']) ? (int)$_SESSION['languages_id'] : 1,
            ];
            $cacheKey = 'sm_search_v2:' . $tenant . ':' . sha1(json_encode($keyParts, JSON_UNESCAPED_UNICODE));
            $cached = _numinix_seekmodo_search_cache_get($cacheKey, $cacheTtlS, $cacheBackend);
            if (is_array($cached) && isset($cached['result']) && is_array($cached['result'])) {
                if (isset($cached['meta']['search_event_id'])
                    && function_exists('_numinix_seekmodo_remember_search_event')) {
                    _numinix_seekmodo_remember_search_event([
                        'search_event_id' => (int)$cached['meta']['search_event_id'],
                        'keyword'         => isset($params['keyword']) ? (string)$params['keyword'] : '',
                        'filter_by'       => isset($cached['meta']['filter_by']) ? (string)$cached['meta']['filter_by'] : '',
                        'filters'         => isset($cached['meta']['filters']) && is_array($cached['meta']['filters'])
                            ? $cached['meta']['filters'] : [],
                        'filter_hash'     => isset($cached['meta']['filters']['hash'])
                            ? (string)$cached['meta']['filters']['hash'] : null,
                        'recorded_at'     => time(),
                    ]);
                }
                $GLOBALS['_numinix_seekmodo_last_search_cache'] = 'hit-' . ($cacheBackend ?: 'unknown');
                return $cached['result'];
            }
            $GLOBALS['_numinix_seekmodo_last_search_cache'] = 'miss';
        } else {
            $GLOBALS['_numinix_seekmodo_last_search_cache'] = $cacheBypass ? 'bypass' : 'disabled';
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
            if (!$sawFailure && $firstResp !== null) {
                _numinix_seekmodo_shadow_finalize_context($params, $firstResp);
            }
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
        // Fix-pack #5 -- preserve Typesense `request_params` from the
        // first page so the normalizer can surface
        // `corrected_search_query` (Typesense >=v27) as the "Did you
        // mean: ..." prompt on the SERP. Without this carry-over the
        // multi-page merge dropped the field on the floor and the
        // template never saw a corrected_query, so the SERP went silent
        // on typos that the gateway had actually flagged.
        if (isset($firstResp['results']['request_params'])
            && is_array($firstResp['results']['request_params'])
        ) {
            $merged['results']['request_params'] = $firstResp['results']['request_params'];
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

        $normalized = _numinix_seekmodo_normalize_response($merged, $params);

        // Store the normalized result + a slim copy of the gateway
        // meta envelope so future hits can replay the search_event_id
        // for click-beacon attribution. Only cache real results --
        // null normalizes (empty hits) shouldn't sit in cache long.
        if ($useCache && $cacheKey !== null && is_array($normalized)) {
            $metaSlim = [];
            if (isset($firstResp['meta']) && is_array($firstResp['meta'])) {
                if (isset($firstResp['meta']['search_event_id'])) {
                    $metaSlim['search_event_id'] = $firstResp['meta']['search_event_id'];
                }
                if (isset($firstResp['meta']['filters'])) {
                    $metaSlim['filters'] = $firstResp['meta']['filters'];
                }
            }
            if (isset($payload['filter_by'])) {
                $metaSlim['filter_by'] = (string)$payload['filter_by'];
            }
            _numinix_seekmodo_search_cache_put($cacheKey, [
                'result'    => $normalized,
                'meta'      => $metaSlim,
                'cached_at' => time(),
            ], $cacheTtlS);
        }

        return $normalized;
    }
}

if (!function_exists('_numinix_seekmodo_search_cache_dir')) {
    /**
     * Resolve and lazily create the file-cache directory used by the
     * search response cache when APCu is unavailable. We tier through
     * three location candidates so the cache works whether the host
     * uses cPanel's per-user tmp dir, Zen Cart's SQL cache dir, or
     * the OS temp dir as a last resort.
     */
    function _numinix_seekmodo_search_cache_dir(): ?string
    {
        static $resolved;
        if ($resolved !== null) {
            return $resolved === '' ? null : $resolved;
        }
        $candidates = [];
        if (defined('DIR_FS_SQL_CACHE') && DIR_FS_SQL_CACHE !== '') {
            $candidates[] = rtrim(DIR_FS_SQL_CACHE, '/') . '/numinix_seekmodo';
        }
        if (defined('DIR_FS_CATALOG') && DIR_FS_CATALOG !== '') {
            $candidates[] = rtrim(DIR_FS_CATALOG, '/') . '/cache/numinix_seekmodo';
        }
        $candidates[] = sys_get_temp_dir() . '/numinix_seekmodo';
        foreach ($candidates as $dir) {
            if (is_dir($dir) || @mkdir($dir, 0775, true)) {
                if (is_writable($dir)) {
                    $resolved = $dir;
                    return $resolved;
                }
            }
        }
        $resolved = '';
        return null;
    }
}

if (!function_exists('_numinix_seekmodo_search_cache_get')) {
    /**
     * Read a cached search response. Tries APCu first (microseconds)
     * and falls back to a per-tenant file cache (~1ms). The TTL is
     * authoritative on read: stale files are deleted on demand so we
     * don't accumulate dead entries on idle environments.
     */
    function _numinix_seekmodo_search_cache_get(string $key, int $ttlS, ?string &$backend = null)
    {
        $backend = null;
        if (function_exists('apcu_fetch')) {
            $ok = false;
            $val = apcu_fetch($key, $ok);
            if ($ok && is_array($val)) {
                $backend = 'apcu';
                return $val;
            }
        }
        $dir = _numinix_seekmodo_search_cache_dir();
        if ($dir === null) {
            return null;
        }
        $file = $dir . '/' . sha1($key) . '.cache';
        if (!is_file($file)) {
            return null;
        }
        $age = time() - (int)@filemtime($file);
        if ($age > $ttlS) {
            @unlink($file);
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($decoded)) {
            return null;
        }
        $backend = 'file';
        return $decoded;
    }
}

if (!function_exists('_numinix_seekmodo_search_cache_put')) {
    /**
     * Write a cached search response to both APCu (if available) and
     * the file cache. The file backend gives us cross-FPM-worker
     * sharing on hosts where APCu isn't installed (which is the
     * default for cPanel ea-php7x FPM pools — common in the
     * connector's hosted shared-hosting target).
     */
    function _numinix_seekmodo_search_cache_put(string $key, array $value, int $ttlS): void
    {
        if (function_exists('apcu_store')) {
            @apcu_store($key, $value, $ttlS);
        }
        $dir = _numinix_seekmodo_search_cache_dir();
        if ($dir === null) {
            return;
        }
        $file = $dir . '/' . sha1($key) . '.cache';
        $tmp = $file . '.tmp.' . getmypid();
        $payload = @serialize($value);
        if ($payload === false) {
            return;
        }
        if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
            @chmod($tmp, 0664);
            @rename($tmp, $file);
        }
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
        if (!isset($row['session_id']) || (string) $row['session_id'] === '') {
            $row['session_id'] = function_exists('_numinix_seekmodo_session_id')
                ? _numinix_seekmodo_session_id()
                : '';
        }
        static $current = null;
        $current = $row;
        $GLOBALS['_numinix_seekmodo_current_search_event'] = $row;
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['numinix_seekmodo_search_event'] = $row;
        }
    }
}

if (!function_exists('_numinix_seekmodo_remember_suggest_search_event')) {
    /**
     * Stash the gateway search_event_id from a /v1/suggest response so
     * typeahead clicks can link back to the originating search row.
     *
     * @param array<string,mixed> $resp
     */
    function _numinix_seekmodo_remember_suggest_search_event(string $keyword, array $resp): void
    {
        if (!isset($resp['meta']['search_event_id'])) {
            return;
        }
        $searchEventId = (int) $resp['meta']['search_event_id'];
        if ($searchEventId <= 0) {
            return;
        }
        $kw = trim($keyword);
        if ($kw === '') {
            return;
        }
        _numinix_seekmodo_remember_search_event([
            'search_event_id' => $searchEventId,
            'keyword'         => $kw,
            'filter_by'       => '',
            'filters'         => [],
            'filter_hash'     => null,
            'recorded_at'     => time(),
        ]);
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $norm = function_exists('mb_strtolower')
            ? mb_strtolower($kw, 'UTF-8')
            : strtolower($kw);
        if (!isset($_SESSION['numinix_seekmodo_suggest_by_keyword'])
            || !is_array($_SESSION['numinix_seekmodo_suggest_by_keyword'])) {
            $_SESSION['numinix_seekmodo_suggest_by_keyword'] = [];
        }
        $_SESSION['numinix_seekmodo_suggest_by_keyword'][$norm] = $searchEventId;
        if (count($_SESSION['numinix_seekmodo_suggest_by_keyword']) > 32) {
            $_SESSION['numinix_seekmodo_suggest_by_keyword'] = array_slice(
                $_SESSION['numinix_seekmodo_suggest_by_keyword'],
                -32,
                null,
                true
            );
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
     *   1. PHP's own `session_id()` when a session is active — must
     *      match between search and click in the same storefront visit.
     *      Preferring the click-log token first caused searches to land
     *      with the PHP session while later clicks used the token.
     *   2. Storefront's `numinix_search_log_session_token()` when no
     *      PHP session is available.
     *   3. Last-resort hash of UA + REMOTE_ADDR.
     *
     * Returns '' only when none of the three are available, which
     * means we're being called from a CLI cron or unit test — bot
     * classification is correctly skipped in that case.
     */
    function _numinix_seekmodo_session_id(): string
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $sid = (string) session_id();
            if ($sid !== '') {
                return substr($sid, 0, 64);
            }
        }
        if (function_exists('numinix_search_log_session_token')) {
            $t = (string) numinix_search_log_session_token();
            if ($t !== '') {
                return substr($t, 0, 64);
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

if (!function_exists('_numinix_seekmodo_typesense_tuning_params')) {
    /**
     * Storefront Typesense tuning constants (RED-1612) shared by full
     * SERP requests and suggest SERP-preview passthrough.
     *
     * @return array<string, mixed>
     */
    function _numinix_seekmodo_typesense_tuning_params(bool $forKeywordSearch = true): array
    {
        $payload = [];
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
        if ($forKeywordSearch && defined('NUMINIX_TYPESENSE_KEYWORD_SORT_BY')
            && NUMINIX_TYPESENSE_KEYWORD_SORT_BY !== '') {
            $payload['sort_by'] = (string)NUMINIX_TYPESENSE_KEYWORD_SORT_BY;
        } elseif (!$forKeywordSearch && defined('NUMINIX_TYPESENSE_BROWSE_SORT_BY')
            && NUMINIX_TYPESENSE_BROWSE_SORT_BY !== '') {
            $payload['sort_by'] = (string)NUMINIX_TYPESENSE_BROWSE_SORT_BY;
        }
        if (!empty($payload['sort_by'])) {
            $payload['sort_by'] = _numinix_seekmodo_apply_sort_deprecations(
                (string)$payload['sort_by']
            );
        }
        return $payload;
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

        // RED-1612 tuning — shared with suggest SERP-preview passthrough.
        $payload = array_merge(
            $payload,
            _numinix_seekmodo_typesense_tuning_params($keyword !== '')
        );
        if (!empty($params['sort_by'])) {
            $payload['sort_by'] = _numinix_seekmodo_apply_sort_deprecations((string)$params['sort_by']);
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

        // v1.0.16 (search-features-plan Sprint 5 PR 6) — per-shopper
        // personalization envelope. Sends the logged-in customer id,
        // the sm_pid cookie value (when the cookie master switch is
        // on and the shopper hasn't opted out), and the
        // Do-Not-Personalize signal so the gateway's
        // ShopperContextResolver can pick the right LTR model tier.
        // No new metering — personalization piggybacks on the parent
        // search call's existing billing per §6.5.
        if (function_exists('numinix_seekmodo_shopper_context')) {
            $payload['shopper_context'] = numinix_seekmodo_shopper_context();
        }

        // v1.3.15 — bare products_id lookup (store admin paste). Zen
        // Cart operators often search by numeric id; route those to an
        // exact `products_id:=` filter instead of BM25 text search.
        $payload = _numinix_seekmodo_apply_products_id_lookup($payload, $keyword);
        if ($payload['q'] === '*' && isset($payload['filter_by'])
            && str_contains((string) $payload['filter_by'], 'products_id:=')
        ) {
            $pidIds = _numinix_seekmodo_parse_products_id_query($keyword);
            if ($pidIds !== null) {
                $structuredFilters['products_id'] = count($pidIds) === 1
                    ? $pidIds[0]
                    : $pidIds;
            }
        }

        // v1.0.17 — SKU / part-number exact-match boost (port of the
        // AKS connector's Sprint 2 EzNumberBooster). When the
        // shopper's query looks like a product code / SKU
        // (alphanumeric + dashes/underscores, 2-32 chars, leading
        // letter or digit), set `prioritize_exact_match=true` so an
        // exact match on a SKU-bearing field jumps to position 0
        // regardless of textual relevance scoring. Without this,
        // Typesense weighs every `query_by` field roughly equally,
        // and a typo in a product description body can outrank the
        // SKU's own field on small/quirky catalogues.
        $payload = _numinix_seekmodo_apply_sku_boost($payload, $keyword);

        return $payload;
    }
}

if (!function_exists('_numinix_seekmodo_parse_products_id_query')) {
    /**
     * Detect bare numeric products_id queries (and comma-separated lists).
     *
     * @return list<int>|null null when the keyword is not a pure id list
     */
    function _numinix_seekmodo_parse_products_id_query(string $keyword): ?array
    {
        $trimmed = trim($keyword);
        if ($trimmed === '') {
            return null;
        }

        $parts = str_contains($trimmed, ',') ? explode(',', $trimmed) : [$trimmed];
        $ids = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || !ctype_digit($part)) {
                return null;
            }
            $id = (int) $part;
            if ($id <= 0) {
                return null;
            }
            $ids[] = $id;
        }

        return $ids === [] ? null : $ids;
    }
}

if (!function_exists('_numinix_seekmodo_apply_products_id_lookup')) {
    /**
     * Route bare products_id queries to an exact Typesense filter.
     *
     * Mirrors AKS `NumericItemIdQuery::resolveFilterSpec()` but without
     * the 5-digit minimum — Zen Cart gift/catalog stores commonly use
     * 2–4 digit ids (e.g. KIP admin searches for "167", "1898").
     *
     * Returns a new array; never mutates the input.
     */
    function _numinix_seekmodo_apply_products_id_lookup(array $payload, string $keyword): array
    {
        if (defined('NUMINIX_SEEKMODO_PRODUCTS_ID_SEARCH_ENABLED')
            && (string) NUMINIX_SEEKMODO_PRODUCTS_ID_SEARCH_ENABLED !== 'true'
        ) {
            return $payload;
        }

        $existingFilter = isset($payload['filter_by']) ? trim((string) $payload['filter_by']) : '';
        if ($existingFilter !== ''
            && (str_contains($existingFilter, 'products_id:=')
                || preg_match('/\bid:=/i', $existingFilter) === 1)
        ) {
            return $payload;
        }

        $ids = _numinix_seekmodo_parse_products_id_query($keyword);
        if ($ids === null) {
            return $payload;
        }

        if (count($ids) === 1) {
            $clause = 'products_id:=' . $ids[0];
        } else {
            $clause = 'products_id:=[' . implode(',', $ids) . ']';
        }

        if ($existingFilter !== '') {
            $payload['filter_by'] = '(' . $existingFilter . ') && ' . $clause;
        } else {
            $payload['filter_by'] = $clause;
        }
        $payload['q'] = '*';

        return $payload;
    }
}

if (!function_exists('_numinix_seekmodo_apply_sku_boost')) {
    /**
     * v1.0.17 — apply `prioritize_exact_match` to the search payload
     * when the shopper's query looks like a product code / SKU.
     * Generic port of the AKS connector's
     * `Numinix\AksSeekmodo\Search\EzNumberBooster` helper.
     *
     * Behaviour:
     *
     *   1. No-op when the master switch
     *      `NUMINIX_SEEKMODO_SKU_BOOST_ENABLED` is not `true`.
     *      (Default: `true`. Safe-on rollout — the boost is
     *      additive: it only kicks in for queries that match the
     *      SKU-shape regex AND only when the gateway's Typesense
     *      backend actually has an exact-match candidate.)
     *
     *   2. No-op when `$keyword` is empty (browse mode) or fails
     *      the SKU-shape regex
     *      (`NUMINIX_SEEKMODO_SKU_BOOST_TRIGGER_REGEX`,
     *      default `/^[A-Za-z0-9][A-Za-z0-9_\-\.]{1,31}$/`).
     *      The regex deliberately allows letters, digits, dashes,
     *      underscores, and dots — covers AKS-style EZ#s
     *      ("EZ-LK99"), Redline-style stand part numbers
     *      ("STD-1234"), OEM crosses ("12345-678A"), etc., while
     *      excluding multi-word natural-language queries
     *      ("automotive rotisserie") that should rank by relevance.
     *
     *   3. No-op when the caller has already set
     *      `prioritize_exact_match` on the payload (so a
     *      future caller that wants to disable the boost
     *      per-call can do so by passing `false` explicitly).
     *
     * Returns a new array; never mutates the input.
     */
    function _numinix_seekmodo_apply_sku_boost(array $payload, string $keyword): array
    {
        if (defined('NUMINIX_SEEKMODO_SKU_BOOST_ENABLED')
            && (string)NUMINIX_SEEKMODO_SKU_BOOST_ENABLED !== 'true'
        ) {
            return $payload;
        }
        if (array_key_exists('prioritize_exact_match', $payload)) {
            return $payload;
        }
        $trimmed = trim($keyword);
        if ($trimmed === '') {
            return $payload;
        }
        $regex = defined('NUMINIX_SEEKMODO_SKU_BOOST_TRIGGER_REGEX')
                && (string)NUMINIX_SEEKMODO_SKU_BOOST_TRIGGER_REGEX !== ''
            ? (string)NUMINIX_SEEKMODO_SKU_BOOST_TRIGGER_REGEX
            : '/^[A-Za-z0-9][A-Za-z0-9_\-\.]{1,31}$/';
        // Defensive: a malformed admin override regex must NOT take
        // the storefront down. A failed match is treated as a no-op.
        $matched = @preg_match($regex, $trimmed);
        if ($matched !== 1) {
            return $payload;
        }
        $payload['prioritize_exact_match'] = true;
        return $payload;
    }
}

if (!function_exists('_numinix_seekmodo_build_suggest_payload')) {
    /**
     * Sprint 3 PR 6 — translate a shopper-typed prefix into the
     * gateway's /v1/suggest payload shape.
     *
     * Why this helper sits next to `_numinix_seekmodo_build_search_payload`
     * even though it's only seven lines: keeping the two payload
     * builders side by side makes it obvious which connector knobs
     * apply to which surface (typeahead is intentionally minimal —
     * the gateway derives query_by, prefix tuning, and the bot-
     * gate from the SuggestTool's own defaults), and the in-context
     * shopper attribution fields (session_id / ua / ip / referer)
     * are stamped by exactly the same {@see _numinix_seekmodo_run_typeahead}
     * caller path that wraps the full-search payload, so both
     * surfaces look identical to the gateway's bot-check classifier.
     *
     * `limit` is clamped at the SuggestTool's hard cap (15 per the
     * gateway's MAX_LIMIT constant) so a typo on the caller's side
     * doesn't 4xx the request.
     */
    function _numinix_seekmodo_build_suggest_payload(string $q, int $limit = 8): array
    {
        $q = trim($q);
        $limit = max(1, min(15, $limit));
        $payload = [
            'q'     => $q,
            'limit' => $limit,
        ];
        $payload = _numinix_seekmodo_apply_products_id_lookup($payload, $q);
        if ($payload['q'] === '*') {
            $payload['complete'] = true;
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
        // Fix-pack #5 -- "Did you mean: ..." restoration.
        //
        // Three-tier extraction so the SERP recovers the prompt even when
        // the gateway envelope evolves:
        //
        //   (1) Flat `corrected_query` at the envelope root -- the path
        //       a future gateway shape change might use.
        //   (2) Typesense's `request_params.corrected_search_query`
        //       passed through under `results` -- Typesense >=v27 sets
        //       this whenever its typo tokenizer rewrote the query
        //       (single-token edits like "moter" -> "motor").
        //   (3) Local Levenshtein fallback against the returned doc
        //       names -- mirrors the same per-token + concat-token
        //       sweep `class.search.php::buildLocalDidYouMeanSuggestion`
        //       runs on the native (non-gateway) code path, so split-
        //       token typos like "moter cycle" -> "motorcycle" and
        //       per-token recoveries the gateway didn't flag still
        //       surface under enforce mode.
        $corrected = isset($resp['corrected_query']) ? trim((string)$resp['corrected_query']) : '';
        if ($corrected === '' && isset($resp['results']['request_params']['corrected_search_query'])) {
            $corrected = trim((string)$resp['results']['request_params']['corrected_search_query']);
        }
        $keywordParam = isset($params['keyword']) ? trim((string)$params['keyword']) : '';
        if ($corrected !== '' && $keywordParam !== '' && strcasecmp($corrected, $keywordParam) === 0) {
            $corrected = '';
        }
        if ($corrected === '' && $keywordParam !== '' && isset($resp['results']['hits']) && is_array($resp['results']['hits'])) {
            $localGuess = _numinix_seekmodo_build_local_did_you_mean(
                $keywordParam,
                $resp['results']['hits']
            );
            if ($localGuess !== null && $localGuess !== '' && strcasecmp($localGuess, $keywordParam) !== 0) {
                $corrected = $localGuess;
            }
        }
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

if (!function_exists('_numinix_seekmodo_build_local_did_you_mean')) {
    /**
     * Levenshtein "Did you mean: ..." fallback for the gateway-routed
     * SERP. Used by `_numinix_seekmodo_normalize_response()` when neither
     * the flat envelope nor Typesense's `request_params.corrected_search_query`
     * supplies a correction.
     *
     * Mirrors the per-token + concat-token sweep that
     * `class.search.php::buildLocalDidYouMeanSuggestion()` runs on the
     * native (non-gateway) path:
     *
     *   1. Tokenize the shopper's query on non-alphanumeric runs.
     *   2. Tally lowercase title tokens of >=4 chars across the first
     *      ~60 returned docs.
     *   3. CONCAT TIER -- when the query has 2+ tokens, glue them
     *      together and look for a single title token within
     *      Levenshtein distance 1. Catches "moter cycle" -> "motorcycle",
     *      "air ram" -> "airram", etc. -- the user fat-fingered a
     *      single word as two.
     *   4. PER-TOKEN TIER -- for each query token >=4 chars that is
     *      not already an exact title token, pick the closest title
     *      token within distance <=2, weighted by frequency. Catches
     *      "moter cylce" -> "motor cycle".
     *
     * Tie-breaks are deterministic (lower distance, then higher freq,
     * then alphabetic) so the same query yields the same suggestion
     * across requests.
     *
     * @param string $keyword  Raw shopper query.
     * @param array  $hits     `$resp['results']['hits']` -- gateway hit envelopes
     *                         shaped `{document: {name: ...}, ...}`.
     * @return string|null     Corrected phrase or null when nothing close was found.
     */
    function _numinix_seekmodo_build_local_did_you_mean(string $keyword, array $hits): ?string
    {
        $keyword = strtolower(trim($keyword));
        if ($keyword === '') {
            return null;
        }
        $queryTokens = preg_split('/[^a-z0-9]+/', $keyword, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($queryTokens === []) {
            return null;
        }

        $titleTokenFrequency = [];
        $sampled = 0;
        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            if ($sampled++ >= 60) {
                break;
            }
            $doc = $hit['document'] ?? null;
            if (!is_array($doc)) {
                continue;
            }
            $name = strtolower((string)($doc['name'] ?? ''));
            $tokens = preg_split('/[^a-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($tokens as $t) {
                if (strlen($t) < 4) {
                    continue;
                }
                $titleTokenFrequency[$t] = ($titleTokenFrequency[$t] ?? 0) + 1;
            }
        }
        if ($titleTokenFrequency === []) {
            return null;
        }

        // Concat-token tier — "moter cycle" -> "motorcycle".
        if (count($queryTokens) >= 2) {
            $joined = implode('', $queryTokens);
            if (strlen($joined) >= 4) {
                $bestJoined = null;
                $bestJoinedScore = PHP_INT_MAX;
                foreach ($titleTokenFrequency as $tt => $freq) {
                    if (abs(strlen($tt) - strlen($joined)) > 4) {
                        continue;
                    }
                    $d = levenshtein($joined, $tt);
                    if ($d > 1) {
                        continue;
                    }
                    $score = ($d * 1_000_000) - $freq;
                    if ($score < $bestJoinedScore
                        || ($score === $bestJoinedScore && $bestJoined !== null && strcmp($tt, $bestJoined) < 0)) {
                        $bestJoinedScore = $score;
                        $bestJoined = $tt;
                    }
                }
                if ($bestJoined !== null && $bestJoined !== $joined) {
                    return $bestJoined;
                }
            }
        }

        // Per-token tier — "moter cylce" -> "motor cycle".
        $changed = false;
        $corrected = [];
        foreach ($queryTokens as $qt) {
            if (strlen($qt) < 4 || isset($titleTokenFrequency[$qt])) {
                $corrected[] = $qt;
                continue;
            }
            $best = null;
            $bestScore = PHP_INT_MAX;
            foreach ($titleTokenFrequency as $tt => $freq) {
                if (abs(strlen($tt) - strlen($qt)) > 2) {
                    continue;
                }
                $d = levenshtein($qt, $tt);
                if ($d > 2) {
                    continue;
                }
                $score = ($d * 1_000_000) - $freq;
                if ($score < $bestScore
                    || ($score === $bestScore && $best !== null && strcmp($tt, $best) < 0)) {
                    $bestScore = $score;
                    $best = $tt;
                }
            }
            if ($best !== null && $best !== $qt) {
                $changed = true;
                $corrected[] = $best;
            } else {
                $corrected[] = $qt;
            }
        }

        return $changed ? implode(' ', $corrected) : null;
    }
}

if (!function_exists('_numinix_seekmodo_shadow_finalize_context')) {
    /**
     * After a successful shadow search, stash gateway click context
     * (search_event_id + rank map + impression) so LTR training can
     * join clicks even when a competitor engine (e.g. Klevu) renders
     * the visible SERP.
     */
    function _numinix_seekmodo_shadow_finalize_context(array $params, ?array $resp): void
    {
        if (!is_array($resp)) {
            return;
        }
        $keyword = isset($params['keyword']) ? trim((string) $params['keyword']) : '';
        if ($keyword === '') {
            return;
        }

        if (isset($resp['meta']['search_event_id'])
            && function_exists('_numinix_seekmodo_remember_search_event')
        ) {
            _numinix_seekmodo_remember_search_event([
                'search_event_id' => (int) $resp['meta']['search_event_id'],
                'keyword'         => $keyword,
                'filter_by'       => isset($resp['meta']['filter_by'])
                    ? (string) $resp['meta']['filter_by'] : '',
                'filters'         => isset($resp['meta']['filters']) && is_array($resp['meta']['filters'])
                    ? $resp['meta']['filters'] : [],
                'filter_hash'     => isset($resp['meta']['filters']['hash'])
                    ? (string) $resp['meta']['filters']['hash'] : null,
                'recorded_at'     => time(),
            ]);
        }

        $productIds = [];
        if (isset($resp['results']['hits']) && is_array($resp['results']['hits'])) {
            foreach ($resp['results']['hits'] as $hit) {
                if (!is_array($hit)) {
                    continue;
                }
                $doc = $hit['document'] ?? null;
                if (!is_array($doc)) {
                    continue;
                }
                $pid = $doc['products_id'] ?? $doc['id'] ?? null;
                if ($pid !== null && (int) $pid > 0) {
                    $productIds[] = (int) $pid;
                }
            }
        }
        if (count($productIds) > 250) {
            $productIds = array_slice($productIds, 0, 250);
        }

        if ($productIds !== [] && session_status() === PHP_SESSION_ACTIVE) {
            $map = [];
            $rank = 0;
            foreach ($productIds as $pid) {
                $rank++;
                $map[$pid] = $rank;
            }
            $_SESSION['_numinix_seekmodo_serp_positions'] = $map;
        }

        if ($productIds !== [] && function_exists('numinix_seekmodo_mirror_serp_impression')) {
            numinix_seekmodo_mirror_serp_impression($keyword, $productIds, [
                'surface' => 'results',
                'extra'   => ['shadow' => true],
            ]);
        }
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

if (!function_exists('numinix_seekmodo_build_listing_sql')) {
    /**
     * Build a listing SQL envelope that includes products_image and the
     * other columns Zen Cart's product_listing module dereferences.
     *
     * @param int[] $productIds Gateway-ranked product IDs.
     */
    function numinix_seekmodo_build_listing_sql(array $productIds): ?string
    {
        if ($productIds === []) {
            return null;
        }
        if (count($productIds) > 12500) {
            $productIds = array_slice($productIds, 0, 12500);
        }
        $idCsv = implode(',', array_map('intval', $productIds));
        $langId = isset($_SESSION['languages_id']) ? (int) $_SESSION['languages_id'] : 1;

        return 'SELECT /* numinix_seekmodo_observer */'
            . ' p.products_id, p.products_image, p.products_type, p.master_categories_id,'
            . ' p.products_quantity, p.products_quantity_order_min,'
            . ' p.products_quantity_order_units, pd.products_name,'
            . ' pd.products_description, p.products_model, p.products_price,'
            . ' p.products_tax_class_id, p.products_priced_by_attribute,'
            . ' p.product_is_call, p.product_is_always_free_shipping,'
            . ' p.products_qty_box_status, p.manufacturers_id, m.manufacturers_name,'
            . ' p.products_date_added, p.products_status, p.products_sort_order,'
            . ' IF(s.status = 1, s.specials_new_products_price, p.products_price) AS final_price'
            . ' FROM ' . TABLE_PRODUCTS . ' p'
            . ' LEFT JOIN ' . TABLE_MANUFACTURERS . ' m ON p.manufacturers_id = m.manufacturers_id'
            . ' LEFT JOIN ' . TABLE_SPECIALS . ' s ON s.products_id = p.products_id'
            . ' INNER JOIN ' . TABLE_PRODUCTS_DESCRIPTION . ' pd ON pd.products_id = p.products_id'
            . ' WHERE p.products_status = 1'
            . ' AND pd.language_id = ' . $langId
            . ' AND p.products_id IN (' . $idCsv . ')'
            . ' ORDER BY FIELD(p.products_id, ' . $idCsv . ')';
    }
}
