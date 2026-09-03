<?php

if (!function_exists('numinix_seekmodo_log_append')) {
    $___smLogLib = __DIR__ . '/numinix_seekmodo_log_lib.php';
    if (is_file($___smLogLib)) {
        require_once $___smLogLib;
    }
}
/**
 * Seekmodo connector -- category-redirect resolver.
 *
 * v1.0.19 (search-features-plan Sprint 6 PR 1). Klevu / Algolia parity:
 * when a shopper's search query closely matches a single storefront
 * category, redirect the request to that category page instead of
 * rendering an advanced_search_result SERP. The intuition is that a
 * shopper typing "personalised mugs" almost certainly wants the
 * Personalised Mugs category landing page (with its full filter rail,
 * editorial copy, pagination, sort options) rather than a thin SERP
 * fragment that happens to surface the same products.
 *
 * Tenant kill-switch lives at
 * <code>NUMINIX_SEEKMODO_CATEGORY_REDIRECT_ENABLED</code>; default
 * 'true'. Mirrored from the gateway tenant snapshot's
 * `category_redirect_enabled` field by RemoteConfig::writeThrough, so
 * an operator can flip the feature off from admin.seekmodo.com without
 * a redeploy.
 *
 * Wiring: NuminixSeekmodoObserver hooks
 * <code>NOTIFY_HEADER_START_ADVANCED_SEARCH_RESULTS</code> (fires as
 * the very first line of
 * includes/modules/pages/advanced_search_result/header_php.php) and
 * calls <code>numinix_seekmodo_resolve_category_redirect($q)</code>.
 * If we return a non-null URL, the observer issues a 302 Location
 * header and exits before any of Zen Cart's search SQL is built.
 *
 * Matching is deliberately conservative: a high min-similarity floor
 * + a clear-winner gap so we only redirect when there's genuinely no
 * ambiguity. Anything below the floor falls through and the shopper
 * gets the normal Seekmodo SERP. False negatives (we COULD have
 * redirected but didn't) are fine -- the shopper still sees relevant
 * results. False positives (we redirect to the wrong category) are
 * bad -- they're a confusing landing.
 *
 * Performance: category list is APCu-cached for an hour keyed by
 * tenant + language; the per-query resolver result is APCu-cached for
 * 5 minutes keyed by tenant + language + normalised query. Cold cache
 * pulls only the categories_id + name + parent_id columns and runs in
 * ~5ms even on a 500-category catalog. Warm cache is a single APCu
 * fetch + similar_text scan that's well under 1ms.
 *
 * Privacy posture: the resolver writes a structured log line via
 * numinix_seekmodo_log_observation('category_redirect', ...) when a
 * match clears the threshold so admin.seekmodo.com can chart redirect
 * rate / which categories absorb the most traffic. No PII / no
 * session id is captured -- the trainer joins this with the
 * shopper-context envelope on its own side via the search row's
 * event id.
 */

if (!function_exists('numinix_seekmodo_resolve_category_redirect')) {
    /**
     * Resolve a shopper query to a single high-confidence category
     * landing page URL, or null if no match clears the configured
     * similarity threshold.
     *
     * @param string $q       Raw shopper query (will be normalised
     *                        internally; no caller pre-processing
     *                        required).
     * @param array  $opts    Optional overrides:
     *                          - min_similarity : float threshold
     *                          - clear_winner_gap : float
     *                          - language_id : int (defaults to
     *                            session languages_id)
     *                          - record_observation : bool
     *                            (default true; pass false from
     *                            self-tests so we don't pollute the
     *                            telemetry stream).
     * @return string|null    Absolute or root-relative storefront URL
     *                        to redirect to, or null on no match.
     */
    function numinix_seekmodo_resolve_category_redirect(string $q, array $opts = []): ?string
    {
        // The whole feature is gated behind the existing connector
        // enable / mode / locked-domain machinery. Bypass without
        // pretending the call happened so admin.seekmodo.com can tell
        // "tenant disabled" from "no match".
        if (!function_exists('numinix_seekmodo_enabled') || !numinix_seekmodo_enabled()) {
            return null;
        }
        if (
            function_exists('numinix_seekmodo_is_locked_out')
            && numinix_seekmodo_is_locked_out()
        ) {
            return null;
        }

        // Tenant kill-switch. Falls back to true so the feature is on
        // by default for fresh installs and for snapshots whose gateway
        // pre-dates the `category_redirect_enabled` field.
        $enabled = function_exists('_numinix_seekmodo_cfg')
            ? (string) _numinix_seekmodo_cfg('NUMINIX_SEEKMODO_CATEGORY_REDIRECT_ENABLED', 'true')
            : 'true';
        if ($enabled !== 'true') {
            return null;
        }

        $q = trim($q);
        if ($q === '' || mb_strlen($q) < 2 || mb_strlen($q) > 80) {
            return null;
        }

        $minSim = isset($opts['min_similarity'])
            ? (float) $opts['min_similarity']
            : (function_exists('_numinix_seekmodo_cfg')
                ? (float) _numinix_seekmodo_cfg('NUMINIX_SEEKMODO_CATEGORY_REDIRECT_MIN_SIMILARITY', '0.92')
                : 0.92);
        $winnerGap = isset($opts['clear_winner_gap'])
            ? (float) $opts['clear_winner_gap']
            : (function_exists('_numinix_seekmodo_cfg')
                ? (float) _numinix_seekmodo_cfg('NUMINIX_SEEKMODO_CATEGORY_REDIRECT_CLEAR_WINNER_GAP', '0.05')
                : 0.05);
        // Tighten the floor at the input edge: anything below 0.80
        // would surface too many false positives regardless of
        // operator override, so clamp.
        if ($minSim < 0.80) {
            $minSim = 0.80;
        }
        if ($minSim > 1.0) {
            $minSim = 1.0;
        }

        $languageId = isset($opts['language_id'])
            ? (int) $opts['language_id']
            : (int) ($_SESSION['languages_id'] ?? 1);

        $tenantId = function_exists('_numinix_seekmodo_cfg')
            ? (string) _numinix_seekmodo_cfg('NUMINIX_SEEKMODO_TENANT_ID', '')
            : '';

        $normQ = _numinix_seekmodo_normalize_for_category_match($q);
        if ($normQ === '') {
            return null;
        }

        // Per-query memoization. Same query in two surfaces inside a
        // single request shouldn't hit the resolver twice.
        $perQueryCacheKey = 'numinix_seekmodo_catredir:' . $tenantId
            . ':' . $languageId . ':' . md5($normQ);
        if (function_exists('apcu_fetch')) {
            $hit = false;
            $cached = apcu_fetch($perQueryCacheKey, $hit);
            if ($hit) {
                // null is a legitimate negative cache entry; only
                // return it if it's a string (URL) OR explicit false
                // (meaning "we looked, nothing matched").
                if ($cached === false) {
                    return null;
                }
                if (is_string($cached) && $cached !== '') {
                    return $cached;
                }
            }
        }

        $categories = _numinix_seekmodo_load_active_categories($languageId, $tenantId);
        if ($categories === []) {
            if (function_exists('apcu_store')) {
                apcu_store($perQueryCacheKey, false, 300);
            }
            return null;
        }

        $best = null;       // [score, cat]
        $secondBest = 0.0;
        foreach ($categories as $cat) {
            $score = _numinix_seekmodo_category_match_score($normQ, $cat);
            if ($score <= 0.0) {
                continue;
            }
            if ($best === null || $score > $best[0]) {
                $secondBest = $best === null ? 0.0 : $best[0];
                $best = [$score, $cat];
            } elseif ($score > $secondBest) {
                $secondBest = $score;
            }
        }

        if ($best === null) {
            if (function_exists('apcu_store')) {
                apcu_store($perQueryCacheKey, false, 300);
            }
            return null;
        }

        [$bestScore, $bestCat] = $best;
        if ($bestScore < $minSim) {
            if (function_exists('apcu_store')) {
                apcu_store($perQueryCacheKey, false, 300);
            }
            return null;
        }
        if (($bestScore - $secondBest) < $winnerGap && $bestScore < 1.0) {
            // Tie or near-tie -- can't pick a clear winner. Fall
            // through to the regular SERP so the shopper at least
            // sees both candidates.
            if (function_exists('apcu_store')) {
                apcu_store($perQueryCacheKey, false, 300);
            }
            return null;
        }

        $url = _numinix_seekmodo_category_url((int) $bestCat['categories_id']);
        if ($url === null) {
            if (function_exists('apcu_store')) {
                apcu_store($perQueryCacheKey, false, 300);
            }
            return null;
        }

        if (function_exists('apcu_store')) {
            apcu_store($perQueryCacheKey, $url, 300);
        }

        if (
            ($opts['record_observation'] ?? true)
            && function_exists('numinix_seekmodo_log_observation')
        ) {
            // Structured signal for the trainer / admin charts. No
            // PII -- shopper attribution comes from the search row
            // that fires on the SERP we're skipping, joined gateway-
            // side.
            numinix_seekmodo_log_observation('category_redirect', [
                'q' => $q,
                'norm_q' => $normQ,
                'category_id' => (int) $bestCat['categories_id'],
                'category_name' => (string) $bestCat['name'],
                'score' => $bestScore,
                'second_best' => $secondBest,
                'gap' => $bestScore - $secondBest,
                'language_id' => $languageId,
            ]);
        }

        return $url;
    }
}

if (!function_exists('_numinix_seekmodo_normalize_for_category_match')) {
    /**
     * Reduce a free-text query / category name to a stable comparison
     * form: lowercased, punctuation collapsed to spaces, single-space
     * whitespace, stopwords + UK->US spellings normalised, trailing
     * 's' clipped from each token to fold simple plurals.
     *
     * Stopword list is deliberately tiny and English-only -- the
     * connector ships to UK / US storefronts almost exclusively and a
     * heavier list (NLTK-sized) starts dropping signal that matters
     * for category names ("gifts for him" -> "gifts him" if "for"
     * is dropped is fine; "the kitchen sink" -> "kitchen sink" is
     * fine; we don't try to handle Spanish / German tokens).
     */
    function _numinix_seekmodo_normalize_for_category_match(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        // UK -> US spellings; matters because category names vary
        // store-by-store ("Personalised Mugs" vs "Personalized Mugs").
        $s = strtr($s, [
            'ised'  => 'ized',
            'ising' => 'izing',
            'isation' => 'ization',
            'colour' => 'color',
            'favour' => 'favor',
            'flavour' => 'flavor',
        ]);
        // Common ampersand variants -> "and".
        $s = str_replace(['&amp;', '&', ' & '], [' and ', ' and ', ' and '], $s);
        // Strip ASCII punctuation except dash (kept so SKU-ish tokens
        // stay intact -- this lib is shared with the category-name
        // path which can contain "T-Shirts" etc.).
        $s = preg_replace('/[^\p{L}\p{N}\s\-]+/u', ' ', $s);
        $s = preg_replace('/\s+/', ' ', (string) $s);
        $s = trim((string) $s);

        if ($s === '') {
            return '';
        }

        static $stopwords = [
            'the' => true, 'a' => true, 'an' => true,
            'for' => true, 'and' => true, 'of' => true,
            'in' => true, 'on' => true, 'to' => true,
            'with' => true, 'by' => true,
        ];

        $tokens = [];
        foreach (explode(' ', $s) as $token) {
            if ($token === '') {
                continue;
            }
            if (isset($stopwords[$token])) {
                // Keep stopwords if the whole query is just one
                // stopword (defensive).
                continue;
            }
            // Plural -> singular by clipping trailing 's' on tokens
            // longer than 3 chars. Avoids "yes" -> "ye", "gas" ->
            // "ga", etc. Doesn't try to handle irregular plurals
            // ("mice" / "feet") -- the scoring function compares
            // both normalised AND unnormalised forms.
            if (mb_strlen($token) > 3 && substr($token, -1) === 's') {
                $token = substr($token, 0, -1);
            }
            $tokens[] = $token;
        }

        return implode(' ', $tokens);
    }
}

if (!function_exists('_numinix_seekmodo_load_active_categories')) {
    /**
     * Fetch the active-category catalog with categories_id + leaf
     * name + parent_id, hour-cached in APCu per tenant + language.
     *
     * Returns rows shaped as
     *   ['categories_id' => int, 'name' => string,
     *    'parent_id' => int, 'normalised' => string].
     *
     * The pre-normalised form is computed once at cache-write time so
     * the per-query resolver loop is a string compare, not a regex
     * tournament across N categories.
     */
    function _numinix_seekmodo_load_active_categories(int $languageId, string $tenantId): array
    {
        $cacheKey = 'numinix_seekmodo_catlist:' . $tenantId . ':' . $languageId;
        if (function_exists('apcu_fetch')) {
            $hit = false;
            $cached = apcu_fetch($cacheKey, $hit);
            if ($hit && is_array($cached)) {
                return $cached;
            }
        }

        if (!isset($GLOBALS['db']) || !defined('TABLE_CATEGORIES') || !defined('TABLE_CATEGORIES_DESCRIPTION')) {
            return [];
        }

        $sql = 'SELECT c.categories_id, c.parent_id, cd.categories_name'
            . ' FROM ' . TABLE_CATEGORIES . ' c'
            . ' JOIN ' . TABLE_CATEGORIES_DESCRIPTION . ' cd USING(categories_id)'
            . ' WHERE c.categories_status = 1'
            . '   AND cd.language_id = ' . (int) $languageId;

        try {
            $result = $GLOBALS['db']->Execute($sql);
        } catch (\Throwable $e) {
            return [];
        }
        if (!$result) {
            return [];
        }

        $rows = [];
        while (!$result->EOF) {
            $name = (string) $result->fields['categories_name'];
            if ($name !== '') {
                $rows[] = [
                    'categories_id' => (int) $result->fields['categories_id'],
                    'parent_id'     => (int) $result->fields['parent_id'],
                    'name'          => $name,
                    'normalised'    => _numinix_seekmodo_normalize_for_category_match($name),
                ];
            }
            $result->MoveNext();
        }

        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $rows, 3600);
        }
        return $rows;
    }
}

if (!function_exists('_numinix_seekmodo_category_match_score')) {
    /**
     * Score a normalised query against a single category row.
     *
     * Tiered scoring so high-confidence layers (exact / token-set)
     * outrank fuzzy ones:
     *
     *   1.00 -- exact normalised match
     *   0.95 -- all query tokens present in category name (any order)
     *   0.90 -- all category tokens present in query (any order, used
     *           for queries like "personalised mugs uk" -> "personalised
     *           mugs")
     *   0.80-0.92 -- similar_text() percent / 100 if ratio >= 0.80
     *
     * Returns 0.0 when nothing scores above the floor so the resolver
     * can skip the row entirely.
     */
    function _numinix_seekmodo_category_match_score(string $normQ, array $cat): float
    {
        $normCat = (string) ($cat['normalised'] ?? '');
        if ($normCat === '') {
            return 0.0;
        }
        if ($normQ === $normCat) {
            return 1.0;
        }

        $qTokens = array_filter(explode(' ', $normQ), 'strlen');
        $cTokens = array_filter(explode(' ', $normCat), 'strlen');
        if ($qTokens === [] || $cTokens === []) {
            return 0.0;
        }
        // Order-invariant token equality.
        sort($qTokens);
        sort($cTokens);
        if ($qTokens === $cTokens) {
            return 1.0;
        }

        $cSet = array_flip($cTokens);
        $qSet = array_flip($qTokens);
        $qAllInC = true;
        foreach ($qTokens as $t) {
            if (!isset($cSet[$t])) {
                $qAllInC = false;
                break;
            }
        }
        if ($qAllInC) {
            return 0.95;
        }
        $cAllInQ = true;
        foreach ($cTokens as $t) {
            if (!isset($qSet[$t])) {
                $cAllInQ = false;
                break;
            }
        }
        if ($cAllInQ) {
            return 0.90;
        }

        // Fuzzy fallback. similar_text writes percent by-ref. Cap at
        // 0.92 so a fuzzy match can never beat an order-invariant
        // token equality.
        $pct = 0.0;
        @similar_text($normQ, $normCat, $pct);
        $score = $pct / 100.0;
        if ($score >= 0.80) {
            return min($score, 0.92);
        }
        return 0.0;
    }
}

if (!function_exists('_numinix_seekmodo_category_url')) {
    /**
     * Build a storefront category URL for the given leaf category id.
     *
     * Resolves the parent chain so the cPath param Zen Cart needs is
     * shaped as `parent_id1_parent_id2_..._leaf_id` -- otherwise the
     * default-page template renders the breadcrumb wrong and SEO-URL
     * plugins (Ceon, Numinix-NEO) can't translate it to a rewritten
     * URL.
     *
     * Returns null when zen_href_link / FILENAME_DEFAULT aren't
     * available (e.g. called from a non-storefront context like a
     * unit test) so the caller can decline gracefully.
     */
    function _numinix_seekmodo_category_url(int $categoryId): ?string
    {
        if ($categoryId <= 0) {
            return null;
        }
        if (!function_exists('zen_href_link') || !defined('FILENAME_DEFAULT')) {
            return null;
        }

        $cPath = _numinix_seekmodo_build_cpath($categoryId);
        if ($cPath === '') {
            return null;
        }

        try {
            return (string) zen_href_link(FILENAME_DEFAULT, 'cPath=' . $cPath);
        } catch (\Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('_numinix_seekmodo_build_cpath')) {
    /**
     * Walk the categories table from leaf -> root and join the chain
     * with underscores. Same shape Zen Cart's own
     * zen_get_parent_categories_id_string($cat) returns, re-implemented
     * here to avoid depending on a function whose name has drifted
     * across Zen Cart 1.5 / 2.0 / 2.2 releases.
     *
     * Bounded to 16 levels of nesting so a malformed parent_id
     * self-cycle can't lock the PHP worker.
     */
    function _numinix_seekmodo_build_cpath(int $catId): string
    {
        if ($catId <= 0 || !isset($GLOBALS['db']) || !defined('TABLE_CATEGORIES')) {
            return '';
        }
        $chain = [];
        $current = $catId;
        $seen = [];
        for ($i = 0; $i < 16 && $current > 0; $i++) {
            if (isset($seen[$current])) {
                break;
            }
            $seen[$current] = true;
            array_unshift($chain, $current);
            try {
                $row = $GLOBALS['db']->Execute(
                    'SELECT parent_id FROM ' . TABLE_CATEGORIES
                    . ' WHERE categories_id = ' . (int) $current
                    . ' LIMIT 1'
                );
            } catch (\Throwable $e) {
                break;
            }
            if (!$row || $row->EOF) {
                break;
            }
            $current = (int) $row->fields['parent_id'];
        }
        return implode('_', $chain);
    }
}

if (!function_exists('numinix_seekmodo_log_observation')) {
    /**
     * Lightweight structured-log writer for observability hooks
     * that don't have a dedicated /v1/events mirror yet. Writes to
     * logs/numinix_seekmodo.log when the connector's DEBUG flag is
     * on; otherwise no-op.
     *
     * Pulled into a standalone helper so callers don't have to
     * recreate the timestamp + JSON-encode + file-handle dance.
     * The events_lib path takes care of richer telemetry; this is
     * for "I want to see this in tail -f without a gateway round
     * trip" cases.
     */
    function numinix_seekmodo_log_observation(string $event, array $fields = []): void
    {
        $debug = function_exists('_numinix_seekmodo_cfg')
            ? (string) _numinix_seekmodo_cfg('NUMINIX_SEEKMODO_DEBUG', 'false')
            : 'false';
        if ($debug !== 'true') {
            return;
        }
        $logsDir = defined('DIR_FS_LOGS') ? DIR_FS_LOGS : (defined('DIR_FS_CATALOG') ? DIR_FS_CATALOG . 'logs' : null);
        if ($logsDir === null) {
            return;
        }
        $line = json_encode(['ts' => gmdate('c'), 'event' => $event] + $fields, JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }
        if (function_exists('numinix_seekmodo_log_append')) {
            numinix_seekmodo_log_append($line);
        } else {
            @file_put_contents(
                rtrim($logsDir, '/\\') . DIRECTORY_SEPARATOR . 'numinix_seekmodo.log',
                $line . "\n",
                FILE_APPEND
            );
        }
    }
}
