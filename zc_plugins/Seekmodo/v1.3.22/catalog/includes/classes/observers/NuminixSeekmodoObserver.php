<?php
/**
 * Numinix Seekmodo connector — Zen Cart notifier observer.
 *
 * v1.0.9 (Sprint 13). Zero-touch integration with stock Zen Cart so
 * a storefront does NOT need to hand-edit `class.search.php`,
 * `ajax/ajax_search.php`, the SERP page render, or the cart / checkout
 * pages to talk to mcp.seekmodo.com. Up to v1.0.8 every storefront
 * shipping the connector had to splice in `numinix_seekmodo_run_search`,
 * `numinix_seekmodo_mirror_serp_impression`, `numinix_seekmodo_mirror_click`,
 * `numinix_seekmodo_mirror_add_to_cart`, and `numinix_seekmodo_mirror_purchase`
 * by hand at the right callsites. That worked for the redlinestands fork
 * (which baked the swap-points into its `class.search.php`) but every
 * un-patched storefront — including numinix.com itself, where the
 * connector has been installed and paired since 2026-06-01 — silently
 * served stock LIKE search and never emitted a single gateway request.
 *
 * The observer hooks five core notifiers so the integration becomes
 * automatic for any v158 / v200 storefront:
 *
 *   NOTIFY_SEARCH_RESULTS
 *     Fires from `includes/modules/pages/search_result/header_php.php`
 *     after Zen Cart has built `$listing_sql` + the `splitPageResults`
 *     object. We call `numinix_seekmodo_run_search()`, take the
 *     ordered products_id list back from the gateway, and rewrite
 *     `$result->sql_query` to a `SELECT ... WHERE products_id IN (...)
 *     ORDER BY FIELD(products_id, ...)` clause so the rest of the page
 *     render (pagination, listing template) pulls from the gateway-
 *     ranked subset. Same notifier covers `ajax/ajax_search.php`
 *     because that file `require`s the same header_php.php.
 *
 *   NOTIFY_HEADER_END_ADVANCED_SEARCH_RESULTS
 *     Fires at the bottom of the same header_php.php. We harvest the
 *     visible products_ids (best effort: the splitPageResults object's
 *     current page) and ship a SERP impression beacon, threaded
 *     through `numinix_seekmodo_current_search_event()` so the
 *     trainer can join impression -> originating search row precisely.
 *
 *   NOTIFY_HEADER_START_PRODUCT_INFO
 *     Fires at the top of the product detail page. When the HTTP
 *     Referer is the search page, treat the visit as a click on the
 *     SERP entry and call `numinix_seekmodo_mirror_click()`. The
 *     position (1-based rank in the SERP) is recovered from a
 *     short-lived session map populated by the search-results swap.
 *     Without a referer match (deep link, bookmarked product) we no-op.
 *
 *   NOTIFY_CART_ADD_CART_END
 *     Fires when the cart object's add() method finishes. Routed to
 *     `numinix_seekmodo_mirror_add_to_cart()` (LTR-P6 graded label).
 *
 *   NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_CREATE_ADD_PRODUCTS
 *     Fires once per line during checkout finalization. Routed to
 *     `numinix_seekmodo_mirror_purchase()` (LTR-P6 strongest label).
 *
 * The observer is fully idempotent against the legacy "edit
 * class.search.php by hand" integration: when a storefront's
 * `numinix_elastic_search_results()` already routed through the
 * gateway and produced a `WHERE products_id IN (...)` SQL clause,
 * the SEARCH_RESULTS hook detects that signature and skips the
 * second swap. Same for the click beacon — `mirror_click()` short-
 * circuits without a current search event, which is the common case
 * for shoppers landing directly on a product page.
 *
 * Failure posture: every hook is wrapped in a try/catch. Anything
 * that goes wrong inside the observer is swallowed and (when DEBUG
 * is on) logged to `logs/numinix_seekmodo.log`. The rest of the page
 * render proceeds with the stock Zen Cart behaviour. We must NEVER
 * 500 a storefront page because of a Seekmodo failure — same posture
 * as the existing `numinix_seekmodo_run_search()` -> null fallback.
 */

class NuminixSeekmodoObserver extends \base
{
    /**
     * Per-request memo: maps products_id -> 1-based position from the
     * last gateway-driven search. Used by the click hook to attribute
     * a product-info page view to its SERP rank without re-running
     * the search. Stashed in $_SESSION so it survives the redirect
     * from /index.php?main_page=advanced_search_result to /index.php
     * ?main_page=product_info.
     *
     * Truncated to the most recent search; the click beacon is only
     * meaningful within a single SERP -> click sequence, so we don't
     * carry a history.
     */
    private const SESSION_POSITION_MAP_KEY = '_numinix_seekmodo_serp_positions';

    /** Keyword stashed when category redirect 302s away from the SERP. */
    private const SESSION_CATEGORY_REDIRECT_KW = '_numinix_seekmodo_category_redirect_kw';

    /** Short-lived cookie mirror — survives 302 before session write flush. */
    private const COOKIE_CATEGORY_REDIRECT_KW = '_sm_rd_kw';

    /** Query param echoed on redirect landing pages for click beacon context. */
    private const QUERY_CATEGORY_REDIRECT_KW = 'sm_rd_kw';

    /**
     * Hard cap on how many products_ids we ever stash in the session
     * position map — protects $_SESSION growth on a 250-result SERP.
     */
    private const POSITION_MAP_LIMIT = 250;

    public function __construct()
    {
        $this->attach($this, [
            // v1.0.19 (search-features-plan Sprint 6 PR 1) -- category
            // landing-page redirect. Fires as the FIRST line of
            // includes/modules/pages/advanced_search_result/header_php.php,
            // before any of Zen Cart's search SQL build / pagination.
            // When the resolver returns a non-null URL we 302 + exit
            // here so the rest of the page never runs (no SERP build,
            // no impression beacon, no swap-point work). Must be
            // listed before NOTIFY_SEARCH_RESULTS so the attach order
            // is intuitive in tail -f.
            'NOTIFY_HEADER_START_ADVANCED_SEARCH_RESULTS',
            'NOTIFY_SEARCH_RESULTS',
            'NOTIFY_HEADER_END_ADVANCED_SEARCH_RESULTS',
            'NOTIFY_HEADER_START_PRODUCT_INFO',
            'NOTIFY_CART_ADD_CART_END',
            // Numinix / KIP Zen Cart forks emit NOTIFIER_* (not NOTIFY_*).
            'NOTIFIER_CART_ADD_CART_END',
            'NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_CREATE_ADD_PRODUCTS',
            // Sprint 4 PR 6 — recommendations + bundle placement
            // injection. We hook the "end of product info template"
            // notifier on the PDP, the "end of shopping cart template"
            // on the cart page, and the body-end notifier (everywhere)
            // for trending placements on the homepage / category
            // listings. The hook only fires the markup when
            // NUMINIX_SEEKMODO_RECOMMENDATIONS_ENABLED='true', so
            // operators flip the whole feature at once.
            'NOTIFY_FOOTER_END',
            'NOTIFY_HEADER_END_SHOPPING_CART',
            'NOTIFY_HEADER_END_INDEX',
        ]);
    }

    /**
     * Zen Cart's notifier dispatcher calls `update($class, $eventID, $param1, ...)`
     * but for backwards compatibility a single `update()` method that
     * branches on $eventID is the standard pattern. We delegate to
     * per-event handlers so each hook is independently readable +
     * testable.
     *
     * @param object $class    The notifier emitter (ignored).
     * @param string $eventID  Notifier event name.
     * @param mixed  $param1   Event-specific (passed by reference where Zen Cart allows).
     * @param mixed  $param2   Event-specific.
     * @param mixed  $param3   Event-specific.
     */
    public function update(&$class, $eventID, &$param1, &$param2 = null, &$param3 = null)
    {
        try {
            switch ($eventID) {
                case 'NOTIFY_HEADER_START_ADVANCED_SEARCH_RESULTS':
                    // v1.0.19 -- category landing-page redirect.
                    // Header notifiers don't carry the query as a
                    // param; we read $_GET['keyword'] directly inside
                    // the handler. On a redirect the handler `exit`s
                    // before the rest of the page builds.
                    $this->onAdvancedSearchStart();
                    break;
                case 'NOTIFY_SEARCH_RESULTS':
                    // header_php.php: notify($eventID, $listing_sql, $keywords, $result)
                    $this->onSearchResults($param1, $param2, $param3);
                    break;
                case 'NOTIFY_HEADER_END_ADVANCED_SEARCH_RESULTS':
                    // header_php.php: notify($eventID, $keywords)
                    $this->onSerpRendered($param1);
                    break;
                case 'NOTIFY_HEADER_START_PRODUCT_INFO':
                    $this->onProductInfo();
                    break;
                case 'NOTIFY_CART_ADD_CART_END':
                case 'NOTIFIER_CART_ADD_CART_END':
                    // Stock ZC NOTIFY_* passes ($products_id, $qty). Numinix/KIP
                    // NOTIFIER_* passes (array(), $products_id, $qty, …) — see
                    // shopping_cart.php notify() call sites.
                    $this->onCartAdd($param1, $param2, $param3);
                    break;
                case 'NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_CREATE_ADD_PRODUCTS':
                    // checkout_process.php signatures vary by fork:
                    //   stock ZC per-line: notify($event, $orders_id, $insert_id, $line)
                    //   Redline fork:      notify($event, $insert_id, $order)
                    $this->onCheckoutProductsAdded($param1, $param2, $param3);
                    break;
                case 'NOTIFY_FOOTER_END':
                    $this->emitSerpClickBeacon();
                    // Sprint 4 PR 6 — page-end recommendation placement
                    // injection. We branch on the active main_page so
                    // PDP gets pdp-related/also_bought/also_viewed,
                    // home gets home-trending, category gets
                    // category-trending.
                    $this->onPageFooter();
                    break;
                case 'NOTIFY_HEADER_END_SHOPPING_CART':
                    // Sprint 4 PR 6 — cart-page placement injection.
                    $this->onShoppingCartHeader();
                    break;
                case 'NOTIFY_HEADER_END_INDEX':
                    // Category / tag landing pages after a search redirect
                    // use main_page=index — emit click beacon here because
                    // NOTIFY_FOOTER_END on some Numinix templates runs too
                    // late or skips plugin observers on SEO tag URLs.
                    $this->emitSerpClickBeacon();
                    break;
            }
        } catch (\Throwable $e) {
            $this->debug('observer_update_threw', [
                'event' => (string) $eventID,
                'msg'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * NOTIFY_SEARCH_RESULTS handler. Replaces `$result->sql_query`
     * with a gateway-driven `WHERE products_id IN (...)` clause so
     * Zen Cart's pagination + listing renderer pulls from the
     * Seekmodo-ranked subset.
     *
     * Skipped (and the stock listing_sql passes through unchanged) when:
     *   - the connector is not enabled (mode=off, missing tenant id,
     *     locked-domain mismatch, etc — `numinix_seekmodo_run_search`
     *     returns null in all those cases);
     *   - a legacy storefront has already routed through the gateway
     *     via its hand-patched `numinix_elastic_search_results()` and
     *     the incoming SQL already contains `WHERE products_id IN (...)`;
     *   - the gateway returned zero hits (we don't want to display an
     *     empty SERP when stock LIKE would have matched something — Zen
     *     Cart's "no results found" path also fires `NOTIFY_SEARCH_RESULTS`
     *     before showing the message; falling through is the safer
     *     default).
     *
     * Non-empty hits → we rewrite `$result->sql_query`, refresh the
     * splitPageResults pagination so `$result->number_of_rows`
     * matches the gateway's total, and stash the products_id ->
     * position map in $_SESSION for the click hook.
     *
     * @param string &$listingSql  Native SQL (read-only here; we mutate $result instead).
     * @param string  $keywords    Raw keyword string from $_GET['keyword'].
     * @param object &$result      splitPageResults instance (passed by ref by Zen Cart).
     */

    /**
     * NOTIFY_HEADER_START_ADVANCED_SEARCH_RESULTS handler. Klevu /
     * Algolia parity: if the shopper's query closely matches a single
     * storefront category, redirect to the category landing page
     * instead of rendering an advanced_search_result SERP.
     *
     * The resolver in numinix_seekmodo_category_redirect_lib.php owns
     * the matching logic and the kill-switch
     * (NUMINIX_SEEKMODO_CATEGORY_REDIRECT_ENABLED). This handler is
     * the thinnest possible glue: read the query, ask the resolver,
     * redirect on a hit, otherwise let the rest of the page render
     * normally.
     *
     * A redirect here also avoids the per-tenant
     * `numinix_seekmodo_run_search()` call that the swap-point below
     * would have made -- saves a gateway round-trip + a billed search
     * row when the shopper's intent was navigational, not exploratory.
     */
    private function onAdvancedSearchStart(): void
    {
        $keyword = isset($_GET['keyword']) ? trim((string) $_GET['keyword']) : '';
        if ($keyword === '') {
            return;
        }
        // v1.1.1 fix-pack #2 -- the `<seekmodo-suggest>` dropdown stamps
        // `seekmodo_skip_category_redirect=1` on the view-all URL it
        // navigates to when the shopper clicked a keyword-style row
        // (keywords / trending / recent / did_you_mean). The dropdown's
        // own Categories block is the route for "I want the category
        // landing page"; a click on a keyword row is "search the whole
        // catalog for this phrase" intent and should not be overridden
        // by the Klevu/Algolia-parity category redirect below. We honor
        // the marker BEFORE the structured-filter check so the SERP
        // renders even on the bare ?keyword=... shape the suggest
        // bundle emits.
        if (($_GET['seekmodo_skip_category_redirect'] ?? '') === '1') {
            return;
        }
        // Don't redirect when the shopper has narrowed by structured
        // filter (category, manufacturer, price range, date range) --
        // they've expressed a more specific intent than a bare query
        // string and a redirect would silently drop those filters.
        $structuredKeys = [
            'categories_id', 'manufacturers_id', 'pfrom', 'pto',
            'dfrom', 'dto', 'inc_subcat',
        ];
        foreach ($structuredKeys as $k) {
            if (isset($_GET[$k]) && (string) $_GET[$k] !== '' && (string) $_GET[$k] !== '0') {
                return;
            }
        }

        // v1.3.9 — merchandising keyword redirect beats auto category redirect.
        if (function_exists('numinix_seekmodo_resolve_merchandising_redirect')) {
            try {
                $merchUrl = numinix_seekmodo_resolve_merchandising_redirect($keyword);
            } catch (\Throwable $e) {
                $this->debug('merch_redirect_resolver_threw', [
                    'msg' => $e->getMessage(),
                ]);
                $merchUrl = null;
            }
            if ($merchUrl !== null && $merchUrl !== '') {
                $this->issueCategoryRedirect($merchUrl, $keyword);
            }
        }

        if (!function_exists('numinix_seekmodo_resolve_category_redirect')) {
            return;
        }

        try {
            $url = numinix_seekmodo_resolve_category_redirect($keyword);
        } catch (\Throwable $e) {
            $this->debug('category_redirect_resolver_threw', [
                'msg' => $e->getMessage(),
            ]);
            return;
        }
        if ($url === null || $url === '') {
            return;
        }

        $this->issueCategoryRedirect($url, $keyword);
    }

    private function issueCategoryRedirect(string $url, string $keyword): void
    {
        $this->stashCategoryRedirectKeyword($keyword);
        $url = $this->redirectUrlWithKeywordContext($url, $keyword);
        if (function_exists('numinix_seekmodo_issue_redirect')) {
            numinix_seekmodo_issue_redirect($url);
        } elseif (!headers_sent()) {
            header('Location: ' . $url, true, 302);
            exit;
        }
        echo '<script>window.location.href=' . json_encode($url) . ';</script>';
        exit;
    }

    private function redirectUrlWithKeywordContext(string $url, string $keyword): string
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return $url;
        }
        $fragment = '';
        $hashPos = strpos($url, '#');
        if ($hashPos !== false) {
            $fragment = substr($url, $hashPos);
            $url = substr($url, 0, $hashPos);
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url . $fragment;
        }
        $query = [];
        if (!empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }
        $query[self::QUERY_CATEGORY_REDIRECT_KW] = $keyword;
        $path = (string) ($parts['path'] ?? '');
        $built = $path !== '' ? $path : '/';
        $queryString = http_build_query($query);
        if ($queryString !== '') {
            $built .= '?' . $queryString;
        }
        if (!empty($parts['scheme']) && !empty($parts['host'])) {
            $built = $parts['scheme'] . '://' . $parts['host'] . $built;
        }
        return $built . $fragment;
    }

    private function onSearchResults(&$listingSql, $keywords, &$result): void
    {
        if (!is_object($result) || !function_exists('numinix_seekmodo_run_search')) {
            return;
        }
        if (!function_exists('numinix_seekmodo_enabled') || !numinix_seekmodo_enabled()) {
            return;
        }
        // Idempotency guard: if a hand-patched storefront already
        // rewrote the SQL to a gateway-driven IN(...) clause, leave
        // it alone. Belt-and-suspenders for redlinestands; harmless
        // on stock Zen Cart where the native LIKE clause never
        // contains both `products_id IN` and a Seekmodo marker.
        $existingSql = isset($result->sql_query) ? (string) $result->sql_query : (string) $listingSql;
        if ($existingSql !== '' && stripos($existingSql, '/* numinix_seekmodo_observer */') !== false) {
            return;
        }
        $kw = is_string($keywords) ? trim($keywords) : '';
        if ($kw === '') {
            $kw = isset($_GET['keyword']) ? trim((string) $_GET['keyword']) : '';
        }
        if ($kw === '') {
            return;
        }
        $params = [
            'keyword'                => $kw,
            'search_in_description'  => isset($_GET['search_in_description'])
                ? (string) $_GET['search_in_description']
                : '',
            'categories_id'          => isset($_GET['categories_id']) ? (int) $_GET['categories_id'] : 0,
            'manufacturers_id'       => isset($_GET['manufacturers_id']) ? (int) $_GET['manufacturers_id'] : 0,
            'pfrom'                  => isset($_GET['pfrom']) && is_numeric($_GET['pfrom'])
                ? (float) $_GET['pfrom'] : 0,
            'pto'                    => isset($_GET['pto']) && is_numeric($_GET['pto'])
                ? (float) $_GET['pto'] : 0,
        ];
        if (($_GET['seekmodo_skip_category_redirect'] ?? '') === '1') {
            $params['skip_merchandising_redirect'] = true;
        }
        $envelope = numinix_seekmodo_run_search($params);
        // Null = mode=off / shadow / breaker open — try Enhanced Native.
        if (!is_array($envelope) || empty($envelope['products']) || !is_array($envelope['products'])) {
            if (function_exists('numinix_seekmodo_run_enhanced_native_search')) {
                $enhanced = numinix_seekmodo_run_enhanced_native_search($kw, 1, 48);
                if (is_array($enhanced) && !empty($enhanced['product_ids'])) {
                    $envelope = [
                        'products' => $enhanced['product_ids'],
                        'total' => (int) ($enhanced['total'] ?? count($enhanced['product_ids'])),
                    ];
                }
            }
        }
        if (!is_array($envelope) || empty($envelope['products']) || !is_array($envelope['products'])) {
            return;
        }
        $productIds = [];
        foreach ($envelope['products'] as $pid) {
            $intPid = (int) $pid;
            if ($intPid > 0) {
                $productIds[] = $intPid;
            }
        }
        if ($productIds === []) {
            return;
        }
        if (function_exists('numinix_seekmodo_catalog_partition_product_ids_live_stock')) {
            $productIds = numinix_seekmodo_catalog_partition_product_ids_live_stock($productIds);
        }
        $rewritten = $this->buildListingSql($productIds);
        if ($rewritten === null) {
            return;
        }
        $total = isset($envelope['total']) ? (int) $envelope['total'] : count($productIds);
        $this->rewriteSplitPageResults($result, $rewritten, $total);
        $this->stashPositionMap($productIds);
    }

    /**
     * NOTIFY_HEADER_END_ADVANCED_SEARCH_RESULTS handler. Best-effort
     * SERP impression beacon. We have access to the keyword param
     * and read the position-map we just stashed in $_SESSION (only
     * populated when the gateway swap actually fired) to recover the
     * visible products_ids.
     */
    private function onSerpRendered($keywords): void
    {
        // Competitor-rendered SERPs (Klevu) never populate the session
        // position-map in shadow mode, but shoppers still click through.
        // Inject the JS beacon here (header phase) so it is present even
        // when NOTIFY_FOOTER_END output is swallowed by a custom template.
        $this->emitSerpClickBeacon();

        if (!function_exists('numinix_seekmodo_mirror_serp_impression')) {
            return;
        }
        $positions = $_SESSION[self::SESSION_POSITION_MAP_KEY] ?? null;
        if (!is_array($positions) || $positions === []) {
            return;
        }
        $kw = is_string($keywords) ? trim($keywords) : '';
        if ($kw === '') {
            $kw = isset($_GET['keyword']) ? trim((string) $_GET['keyword']) : '';
        }
        if ($kw === '') {
            return;
        }
        // Stable rank order: positions map values are 1-based ranks;
        // sort by value asc to recover the original SERP order.
        asort($positions, SORT_NUMERIC);
        $productIds = array_map('intval', array_keys($positions));
        // Trim to the rendered page size — splitPageResults' default
        // is MAX_DISPLAY_PRODUCTS_LISTING but reading that constant
        // is an extra coupling; we cap at the position-map limit.
        if (count($productIds) > self::POSITION_MAP_LIMIT) {
            $productIds = array_slice($productIds, 0, self::POSITION_MAP_LIMIT);
        }
        numinix_seekmodo_mirror_serp_impression($kw, $productIds, [
            'surface' => 'results',
        ]);
    }

    /**
     * NOTIFY_HEADER_START_PRODUCT_INFO handler. When the shopper
     * arrives on a product page from one of our gateway-driven
     * surfaces (search results page OR ajax search suggest dropdown),
     * fire a click beacon with the right `surface` label.
     *
     * Detection has two arms:
     *
     *   surface=results — the canonical SERP click. Both must hold:
     *     1. Session position-map has the products_id.
     *     2. HTTP_REFERER points at the search-results page on this
     *        storefront (advanced_search_result / FILENAME_SEARCH).
     *
     *   surface=suggest — the ajax suggest dropdown click. The
     *     dropdown is rendered from `ajax/ajax_search.php` which
     *     also hits `pages/search_result/header_php.php` and fires
     *     `NOTIFY_SEARCH_RESULTS`, so the same SQL-rewrite path
     *     stashes a position-map for the ajax response. When the
     *     shopper clicks an item the navigation comes from whatever
     *     page hosted the search bar (homepage, category, cart…),
     *     NOT from a SERP — so the local-SERP referer check fails.
     *     We light the suggest path when:
     *       1. Position-map has the products_id.
     *       2. Referer is the same storefront BUT NOT the SERP
     *          (i.e. the shopper came from an in-site page that
     *          contained the search bar, which is the only place
     *          ajax suggest results are shown).
     *
     * A shopper deep-linking into a product or arriving from an
     * external search engine has no position-map for that products_id
     * and falls through both arms — no click is recorded.
     *
     * The `surface` field flows through into the trainer's graded-
     * label data so search clicks and suggest clicks can be valued
     * differently in LTR. (See `numinix_seekmodo_events_lib.php`
     * `numinix_seekmodo_mirror_click()` — the meta array is forwarded
     * to the gateway's `/v1/events click` body.)
     */
    private function onProductInfo(): void
    {
        if (!function_exists('numinix_seekmodo_mirror_click')) {
            return;
        }
        $productsId = $this->productsIdFromRequest();
        if ($productsId <= 0) {
            return;
        }
        $isLocalSerp = $this->referrerIsLocalSerp();
        $isLocalReferer = $this->referrerIsSameHost();
        $current = function_exists('numinix_seekmodo_current_search_event')
            ? numinix_seekmodo_current_search_event()
            : null;
        $kw = is_array($current) && isset($current['keyword'])
            ? (string) $current['keyword']
            : '';
        if ($kw === '') {
            $kw = $this->keywordFromReferer();
        }
        $positions = $_SESSION[self::SESSION_POSITION_MAP_KEY] ?? null;
        $hasPosition = is_array($positions) && isset($positions[$productsId]);

        // Competitor-rendered SERP (Klevu / legacy search UI): attribute
        // when the referer is our search-results page and we have a
        // gateway search context from the shadow or enforce pass.
        if ($isLocalSerp && $kw !== '') {
            $position = $hasPosition ? (int) $positions[$productsId] : 0;
            $surface = $hasPosition ? 'results' : 'competitor_serp';
            numinix_seekmodo_mirror_click(
                $kw,
                $productsId,
                $position,
                null,
                ['surface' => $surface]
            );
            return;
        }

        if (!$hasPosition || !$isLocalReferer || $kw === '') {
            return;
        }
        $position = (int) $positions[$productsId];
        $surface = $isLocalSerp ? 'results' : 'suggest';
        numinix_seekmodo_mirror_click(
            $kw,
            $productsId,
            $position,
            null,
            ['surface' => $surface]
        );
    }

    /**
     * Recover the search keyword from HTTP_REFERER when the session
     * search-event row has expired between SERP render and click.
     */
    private function keywordFromReferer(): string
    {
        $referer = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
        if ($referer === '') {
            return '';
        }
        $parts = parse_url($referer);
        if (!is_array($parts) || empty($parts['query'])) {
            return '';
        }
        parse_str((string) $parts['query'], $query);
        foreach (['keyword', 'search_query', 'q'] as $key) {
            if (!isset($query[$key])) {
                continue;
            }
            $kw = trim((string) $query[$key]);
            if ($kw !== '') {
                return $kw;
            }
        }
        return '';
    }

    /**
     * Resolve products_id from query string or SEO slug paths
     * (Numinix-style `/product-name-921` URLs without ?products_id=).
     */
    private function productsIdFromRequest(): int
    {
        if (isset($_GET['products_id']) && is_numeric($_GET['products_id'])) {
            $id = (int) $_GET['products_id'];
            if ($id > 0) {
                return $id;
            }
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ($uri === '') {
            return 0;
        }
        if (preg_match('#/(\d+)(?:\.html|/|$|\?)#', $uri, $m)) {
            return (int) $m[1];
        }
        if (preg_match('#-(\d+)(?:\?|\#|/|$)#', $uri, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    /**
     * NOTIFY_CART_ADD_CART_END handler. Zen Cart passes the cart uprid as
     * $param1 and quantity as $param2; Numinix forks may also add via ?pid=
     * or multi-add POST arrays where superglobals lack products_id.
     */
    private function onCartAdd($param1, $param2 = null, $param3 = null): void
    {
        if (!function_exists('numinix_seekmodo_mirror_add_to_cart')) {
            return;
        }
        [$productsRaw, $qtyRaw] = $this->resolveCartNotifierPayload($param1, $param2, $param3);
        $productsId = $this->resolveCartProductsId($productsRaw);
        if ($productsId <= 0) {
            return;
        }
        $opts = [];
        $qty = 0;
        if ($qtyRaw !== null && is_numeric($qtyRaw)) {
            $qty = (int) $qtyRaw;
        } elseif (isset($_POST['cart_quantity']) && is_numeric($_POST['cart_quantity'])) {
            $qty = (int) $_POST['cart_quantity'];
        } elseif (isset($_GET['cart_quantity']) && is_numeric($_GET['cart_quantity'])) {
            $qty = (int) $_GET['cart_quantity'];
        }
        if ($qty > 0) {
            $opts['qty'] = $qty;
        }
        numinix_seekmodo_mirror_add_to_cart($productsId, $opts);
    }

    /**
     * Map cart notifier args to (products_id/uprid, qty). NOTIFIER_* forks
     * reserve $param1 for an empty params array; products_id is $param2.
     *
     * @return array{0:mixed,1:mixed}
     */
    private function resolveCartNotifierPayload($param1, $param2, $param3): array
    {
        if ($this->resolveCartProductsId($param1) > 0) {
            return [$param1, $param2];
        }
        if ($param2 !== null && $this->resolveCartProductsId($param2) > 0) {
            return [$param2, $param3];
        }
        return [$param1, $param2];
    }

    /**
     * Resolve a numeric products_id from the cart notifier arg and request
     * fallbacks. Handles attribute uprids via zen_get_prid() when available.
     */
    private function resolveCartProductsId($param): int
    {
        $fromParam = $this->normalizeProductsId($param);
        if ($fromParam > 0) {
            return $fromParam;
        }
        foreach (['products_id', 'pid'] as $key) {
            if (isset($_GET[$key]) && is_numeric($_GET[$key])) {
                $id = (int) $_GET[$key];
                if ($id > 0) {
                    return $id;
                }
            }
        }
        if (isset($_POST['products_id']) && is_numeric($_POST['products_id'])) {
            $id = (int) $_POST['products_id'];
            if ($id > 0) {
                return $id;
            }
        }
        if (isset($_POST['products_id']) && is_array($_POST['products_id'])) {
            foreach ($_POST['products_id'] as $key => $val) {
                if (!is_numeric($val) || (float) $val <= 0) {
                    continue;
                }
                $id = $this->normalizeProductsId($key);
                if ($id > 0) {
                    return $id;
                }
            }
        }
        if (isset($_POST['cart']) && is_array($_POST['cart'])) {
            foreach (array_keys($_POST['cart']) as $key) {
                $id = $this->normalizeProductsId($key);
                if ($id > 0) {
                    return $id;
                }
            }
        }
        return 0;
    }

    /**
     * @param mixed $raw products_id int, uprid string, or line array
     */
    private function normalizeProductsId($raw): int
    {
        if (is_array($raw)) {
            if (isset($raw['products_id'])) {
                return $this->normalizeProductsId($raw['products_id']);
            }
            if (isset($raw['id'])) {
                return $this->normalizeProductsId($raw['id']);
            }
            return 0;
        }
        if (is_int($raw)) {
            return $raw > 0 ? $raw : 0;
        }
        if (is_float($raw)) {
            $n = (int) $raw;
            return $n > 0 ? $n : 0;
        }
        if (!is_string($raw) && !is_numeric($raw)) {
            return 0;
        }
        $s = trim((string) $raw);
        if ($s === '' || !preg_match('/^[0-9a-f:.]+$/i', $s)) {
            return 0;
        }
        if (function_exists('zen_get_prid')) {
            return (int) zen_get_prid($s);
        }
        if (preg_match('/^(\d+)/', $s, $m)) {
            return (int) $m[1];
        }
        return is_numeric($s) ? (int) $s : 0;
    }

    /**
     * NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_CREATE_ADD_PRODUCTS dispatcher.
     * Zen Cart cores and Numinix forks disagree on notifier arity — accept
     * every shape we've seen in production so purchase events actually fire.
     */
    private function onCheckoutProductsAdded($param1, $param2, $param3 = null): void
    {
        // Stock ZC per-line notify: ($orders_id, $insert_id, $lineArray)
        if (is_array($param3) && (isset($param3['products_id']) || isset($param3['id']))) {
            $this->onPurchaseLine($param1, $param3);
            return;
        }
        // Per-line with the product row in param2.
        if (is_array($param2) && (isset($param2['products_id']) || isset($param2['id']))) {
            $this->onPurchaseLine($param1, $param2);
            return;
        }
        // Redline / fork: single notify($insert_id, $order) after create_add_products().
        if (is_object($param2) && isset($param2->products) && is_array($param2->products)) {
            $orderId = 0;
            if (isset($param2->orderId) && is_numeric($param2->orderId)) {
                $orderId = (int) $param2->orderId;
            } elseif (isset($param2->info['order_id']) && is_numeric($param2->info['order_id'])) {
                $orderId = (int) $param2->info['order_id'];
            } elseif (is_numeric($param1)) {
                $orderId = (int) $param1;
            }
            foreach ($param2->products as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $this->onPurchaseLine($orderId, $line);
            }
            return;
        }

        // PayPal saved-card recurring and some fork cores fire
        // NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_CREATE_ADD_PRODUCTS with
        // zero args after create_add_products() — recover from $order.
        global $order;
        if (!is_object($order) || !isset($order->products) || !is_array($order->products)) {
            return;
        }
        $orderId = 0;
        if (isset($_SESSION['order_number_created']) && is_numeric($_SESSION['order_number_created'])) {
            $orderId = (int) $_SESSION['order_number_created'];
        } elseif (isset($order->orderId) && is_numeric($order->orderId)) {
            $orderId = (int) $order->orderId;
        } elseif (is_numeric($param1)) {
            $orderId = (int) $param1;
        }
        foreach ($order->products as $line) {
            if (!is_array($line)) {
                continue;
            }
            $this->onPurchaseLine($orderId, $line);
        }
    }

    /**
     * Ship one purchase graded-label event for a single order line.
     *
     * @param int   $ordersId
     * @param array $orderProductsArray  Zen Cart line tuple (`products_id`
     *                                   or cart-style `id`).
     */
    private function onPurchaseLine($ordersId, $orderProductsArray): void
    {
        if (!function_exists('numinix_seekmodo_mirror_purchase')) {
            return;
        }
        if (!is_array($orderProductsArray)) {
            return;
        }
        $productsId = 0;
        if (isset($orderProductsArray['products_id'])) {
            $productsId = (int) $orderProductsArray['products_id'];
        } elseif (isset($orderProductsArray['id'])) {
            $productsId = (int) $orderProductsArray['id'];
        }
        if ($productsId <= 0) {
            return;
        }
        $opts = [];
        if (isset($orderProductsArray['qty']) && is_numeric($orderProductsArray['qty'])) {
            $opts['qty'] = max(1, (int) $orderProductsArray['qty']);
        }
        if (isset($orderProductsArray['final_price']) && is_numeric($orderProductsArray['final_price'])) {
            $opts['price'] = (float) $orderProductsArray['final_price'];
        } elseif (isset($orderProductsArray['price']) && is_numeric($orderProductsArray['price'])) {
            $opts['price'] = (float) $orderProductsArray['price'];
        }
        if ($ordersId > 0) {
            $opts['extra'] = ['order_id' => (string) (int) $ordersId];
        }
        numinix_seekmodo_mirror_purchase($productsId, $opts);
    }

    /**
     * Build a `SELECT ... FROM products p ... WHERE p.products_id IN (...)
     * ORDER BY FIELD(p.products_id, ...)` clause that mirrors the column
     * shape Zen Cart's stock listing template expects.
     *
     * The stock listing module (`includes/modules/product_listing.php`)
     * iterates `$listing` row-at-a-time and dereferences:
     *
     *   p.products_id, p.products_image, p.products_type, p.master_categories_id,
     *   p.products_quantity, p.products_quantity_order_min,
     *   p.products_quantity_order_units, pd.products_name,
     *   pd.products_description, p.products_model, p.products_price,
     *   p.products_tax_class_id, p.products_priced_by_attribute,
     *   p.product_is_call, p.product_is_always_free_shipping,
     *   p.products_qty_box_status, p.manufacturers_id, m.manufacturers_name,
     *   p.products_date_added, p.products_status, p.products_sort_order,
     *   p.final_price (alias)
     *
     * Producing exactly that envelope keeps us drop-in compatible with
     * any storefront template the operator has customised. We also
     * bind `language_id = (int) $_SESSION['languages_id']` so multi-
     * language sites stay sane.
     *
     * Returns null when the products list is empty or oversized
     * (defensive — a runaway list would blow the SQL parser anyway).
     *
     * @param int[] $productIds  Already-validated, ordered.
     */
    private function buildListingSql(array $productIds): ?string
    {
        if (function_exists('numinix_seekmodo_build_listing_sql')) {
            return numinix_seekmodo_build_listing_sql($productIds);
        }
        if ($productIds === []) {
            return null;
        }
        // Hard cap matches the gateway connector's max_pages * page_size
        // ceiling (50 * 250 = 12,500). MariaDB's max_allowed_packet
        // and ORDER BY FIELD() arg count both stay comfortably under
        // their defaults at this size.
        if (count($productIds) > 12500) {
            $productIds = array_slice($productIds, 0, 12500);
        }
        $idCsv = implode(',', $productIds);
        $langId = isset($_SESSION['languages_id']) ? (int) $_SESSION['languages_id'] : 1;
        $sql = "SELECT /* numinix_seekmodo_observer */"
            . " p.products_id, p.products_image, p.products_type, p.master_categories_id,"
            . " p.products_quantity, p.products_quantity_order_min,"
            . " p.products_quantity_order_units, pd.products_name,"
            . " pd.products_description, p.products_model, p.products_price,"
            . " p.products_tax_class_id, p.products_priced_by_attribute,"
            . " p.product_is_call, p.product_is_always_free_shipping,"
            . " p.products_qty_box_status, p.manufacturers_id, m.manufacturers_name,"
            . " p.products_date_added, p.products_status, p.products_sort_order,"
            . " IF(s.status = 1, s.specials_new_products_price, p.products_price) AS final_price"
            . " FROM " . TABLE_PRODUCTS . " p"
            . " LEFT JOIN " . TABLE_MANUFACTURERS . " m ON p.manufacturers_id = m.manufacturers_id"
            . " LEFT JOIN " . TABLE_SPECIALS . " s ON s.products_id = p.products_id"
            . " INNER JOIN " . TABLE_PRODUCTS_DESCRIPTION . " pd ON pd.products_id = p.products_id"
            . " WHERE p.products_status = 1"
            . " AND pd.language_id = " . $langId
            . " AND p.products_id IN (" . $idCsv . ")"
            . (function_exists('_numinix_seekmodo_listing_order_sql')
                ? _numinix_seekmodo_listing_order_sql($productIds)
                : " ORDER BY FIELD(p.products_id, " . $idCsv . ")");
        return $sql;
    }

    /**
     * Push the rewritten SQL into the splitPageResults instance and
     * refresh the pagination so number_of_rows reflects the gateway's
     * total. We deliberately don't re-execute the count query —
     * splitPageResults' constructor would otherwise issue a `SELECT
     * COUNT(...)` against the rewritten clause, which we already know
     * the answer to.
     *
     * splitPageResults exposes `sql_query`, `number_of_rows`,
     * `number_of_pages`, and `current_page_number` as public
     * properties on every Zen Cart version that has the class (which
     * is all of v1.5.x and v2.x). We update them in place; the
     * paginator HTML and the listing fetch both read from these.
     */
    private function rewriteSplitPageResults($result, string $sql, int $total): void
    {
        $result->sql_query = $sql;
        $result->number_of_rows = $total;
        $perPage = 0;
        if (defined('MAX_DISPLAY_PRODUCTS_LISTING')) {
            $perPage = (int) MAX_DISPLAY_PRODUCTS_LISTING;
        }
        if ($perPage <= 0) {
            $perPage = 20;
        }
        $result->number_of_pages = (int) max(1, ceil($total / $perPage));
        // current_page_number was already initialised by the
        // splitPageResults constructor from the `page` GET param;
        // leave it. number_of_rows and number_of_pages are the only
        // values the listing template + pagination links consult.
    }

    /**
     * Stash the products_id -> 1-based position map in $_SESSION so
     * the click hook can attribute a product-info page view to its
     * SERP rank. Capped at POSITION_MAP_LIMIT to bound session
     * growth.
     *
     * @param int[] $productIds  Ordered (rank 1 first).
     */
    private function stashPositionMap(array $productIds): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $map = [];
        $rank = 0;
        foreach ($productIds as $pid) {
            $rank++;
            if ($rank > self::POSITION_MAP_LIMIT) {
                break;
            }
            $map[(int) $pid] = $rank;
        }
        $_SESSION[self::SESSION_POSITION_MAP_KEY] = $map;
    }

    /**
     * True when HTTP_REFERER points at this same storefront's
     * hostname. Looser than `referrerIsLocalSerp()` — used as the
     * "click came from somewhere on our site" signal for the
     * suggest-dropdown attribution arm. Like the SERP check, this
     * is best-effort and not a security boundary.
     */
    private function referrerIsSameHost(): bool
    {
        $referer = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
        if ($referer === '') {
            return false;
        }
        $parts = parse_url($referer);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }
        $refHost = strtolower((string) $parts['host']);
        $localHost = '';
        if (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])) {
            $localHost = strtolower((string) $_SERVER['HTTP_HOST']);
            if (str_contains($localHost, ':')) {
                $localHost = (string) strstr($localHost, ':', true);
            }
        }
        if ($localHost === '') {
            return false;
        }
        return $refHost === $localHost;
    }

    /**
     * True when HTTP_REFERER (best-effort) points at the search
     * results page on this same storefront. We don't trust the
     * referer for security decisions; this is purely a "did the
     * shopper just come from a SERP we rendered" attribution heuristic.
     */
    private function referrerIsLocalSerp(): bool
    {
        $referer = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
        if ($referer === '') {
            return false;
        }
        $parts = parse_url($referer);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }
        $refHost = strtolower((string) $parts['host']);
        $localHost = '';
        if (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])) {
            $localHost = strtolower((string) $_SERVER['HTTP_HOST']);
            if (str_contains($localHost, ':')) {
                $localHost = (string) strstr($localHost, ':', true);
            }
        }
        if ($localHost === '' || $refHost !== $localHost) {
            return false;
        }
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        $query = isset($parts['query']) ? (string) $parts['query'] : '';
        return $this->refererLooksLikeSearchResults($path, $query);
    }

    /**
     * True when the current request is rendering a search-results page.
     * Zen Cart 1.5.8+ uses `search_result`; older stores used
     * `advanced_search_result`.
     */
    private function isSearchResultsPage(string $mainPage): bool
    {
        if ($mainPage === 'advanced_search_result' || $mainPage === 'search_result') {
            return true;
        }
        if (defined('FILENAME_SEARCH_RESULT') && $mainPage === strtolower((string) FILENAME_SEARCH_RESULT)) {
            return true;
        }
        return false;
    }

    /**
     * @param string $path  Referer path component.
     * @param string $query Referer query string.
     */
    private function refererLooksLikeSearchResults(string $path, string $query): bool
    {
        // ?main_page=advanced_search_result OR ?main_page=search_result
        // (ZC 1.5.8+ alias — live on www.numinix.com).
        if (stripos($query, 'main_page=advanced_search_result') !== false) {
            return true;
        }
        if (stripos($query, 'main_page=search_result') !== false) {
            return true;
        }
        if (defined('FILENAME_SEARCH') && stripos($path, (string) FILENAME_SEARCH) !== false) {
            return true;
        }
        if (defined('FILENAME_SEARCH_RESULT') && stripos($path, (string) FILENAME_SEARCH_RESULT) !== false) {
            return true;
        }
        return false;
    }

    /**
     * Emit a lightweight click beacon on search-results pages so
     * competitor-rendered SERPs (Klevu) still feed LTR when the
     * shopper clicks before navigating to product_info.
     */
    private function stashCategoryRedirectKeyword(string $keyword): void
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESSION_CATEGORY_REDIRECT_KW] = $keyword;
        }
        if (headers_sent()) {
            return;
        }
        $params = [
            'expires' => time() + 600,
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        if (defined('COOKIE_SAMESITE')) {
            $sameSite = (string) COOKIE_SAMESITE;
            if (in_array($sameSite, ['lax', 'strict', 'none'], true)) {
                $params['samesite'] = ucfirst($sameSite);
            }
        }
        setcookie(self::COOKIE_CATEGORY_REDIRECT_KW, $keyword, $params);
    }

    private function readCategoryRedirectKeyword(): string
    {
        $queryKey = self::QUERY_CATEGORY_REDIRECT_KW;
        $keyword = isset($_GET[$queryKey]) ? trim((string) $_GET[$queryKey]) : '';
        if ($keyword !== '') {
            return $keyword;
        }
        $keyword = isset($_GET['keyword']) ? trim((string) $_GET['keyword']) : '';
        if ($keyword !== '') {
            return $keyword;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $keyword = trim((string) ($_SESSION[self::SESSION_CATEGORY_REDIRECT_KW] ?? ''));
            if ($keyword !== '') {
                return $keyword;
            }
        }
        return trim((string) ($_COOKIE[self::COOKIE_CATEGORY_REDIRECT_KW] ?? ''));
    }

    private function hasCategoryRedirectKeyword(): bool
    {
        return $this->readCategoryRedirectKeyword() !== '';
    }

    private function emitSerpClickBeacon(): void
    {
        static $emitted = false;
        if ($emitted) {
            return;
        }
        if (!function_exists('numinix_seekmodo_enabled') || !numinix_seekmodo_enabled()) {
            return;
        }
        $mainPage = $this->currentMainPage();
        $onSerp = $this->isSearchResultsPage($mainPage);
        $keyword = $this->readCategoryRedirectKeyword();
        if ($keyword === '') {
            return;
        }
        if (!$onSerp && !$this->hasCategoryRedirectKeyword()) {
            return;
        }
        $clickUrl = 'numinix_seekmodo_click.php';
        if (defined('DIR_WS_CATALOG')) {
            $clickUrl = (string) DIR_WS_CATALOG . $clickUrl;
        }
        $event = function_exists('numinix_seekmodo_current_search_event')
            ? numinix_seekmodo_current_search_event()
            : null;
        $searchEventId = is_array($event) && isset($event['search_event_id'])
            ? (int) $event['search_event_id']
            : 0;
        $positions = $_SESSION[self::SESSION_POSITION_MAP_KEY] ?? null;
        $posMap = is_array($positions) ? $positions : [];
        $kwJson = json_encode($keyword, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $urlJson = json_encode($clickUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $posJson = json_encode($posMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $emitted = true;
        echo '<script>(function(){'
            . 'var kw=' . $kwJson . ',url=' . $urlJson . ',seid=' . $searchEventId . ',posMap=' . $posJson . ';'
            . 'function pidFromHref(href){'
            . 'var m=href.match(/[?&]products_id=(\\d+)/);if(m)return m[1];'
            . 'm=href.match(/-(\\d+)(?:\\?|#|\\/|$)/);return m?m[1]:null;'
            . '}'
            . 'document.addEventListener("click",function(ev){'
            . 'var a=ev.target&&ev.target.closest?ev.target.closest("a[href]"):null;'
            . 'if(!a||!a.href)return;'
            . 'var pid=pidFromHref(a.href);if(!pid)return;'
            . 'var pos=posMap&&posMap[pid]?parseInt(posMap[pid],10):0;'
            . 'var surface=pos>0?"results":(seid>0?"category_redirect_js":"competitor_serp_js");'
            . 'var fd=new FormData();fd.append("keyword",kw);fd.append("products_id",pid);'
            . 'fd.append("position",String(pos||0));fd.append("surface",surface);'
            . 'if(seid>0)fd.append("search_event_id",String(seid));'
            . 'if(navigator.sendBeacon){navigator.sendBeacon(url,fd);}'
            . 'else{fetch(url,{method:"POST",body:fd,keepalive:true,credentials:"same-origin"});}'
            . '},true);})();</script>';
    }

    /**
     * Sprint 4 PR 6 — emit the recommendations placement markup at
     * the bottom of the active page. Branches on `main_page` (PDP /
     * home / category) and prints empty divs that the recommendations
     * JS module fills in via AJAX. The JS is itself auto-included
     * by Zen Cart's `jscript_loader` (any file matching
     * `jscript_*.js` in the active template's `jscript/` folder).
     *
     * Gated by the NUMINIX_SEEKMODO_RECOMMENDATIONS_ENABLED config
     * key — operators flip the whole feature at once during a rollout.
     */
    private function onPageFooter(): void
    {
        if (!$this->recommendationsEnabled()) {
            return;
        }
        $page = $this->currentMainPage();
        if ($page === 'product_info') {
            $productsId = isset($_GET['products_id']) ? (int) $_GET['products_id'] : 0;
            if ($productsId <= 0) {
                return;
            }
            $this->emitPlacements([
                ['key' => 'pdp-related',     'doc_id' => $productsId, 'limit' => 8],
                ['key' => 'pdp-also-bought', 'doc_id' => $productsId, 'limit' => 8],
                ['key' => 'pdp-bundle',      'doc_id' => $productsId, 'limit' => 3,
                 'bundle_size' => 3],
            ]);
            return;
        }
        if ($page === 'index') {
            // index covers both the homepage AND category landing
            // pages. We emit different placements depending on
            // whether a category id is present.
            $categoryId = isset($_GET['cPath']) ? (string) $_GET['cPath'] : '';
            if ($categoryId === '') {
                $this->emitPlacements([
                    ['key' => 'home-trending', 'limit' => 8],
                ]);
            } else {
                $this->emitPlacements([
                    ['key' => 'category-trending', 'limit' => 8],
                ]);
            }
        }
    }

    /**
     * Sprint 4 PR 6 — emit cart-page placement divs above the cart
     * subtotal so add-on suggestions appear inline.
     */
    private function onShoppingCartHeader(): void
    {
        if (!$this->recommendationsEnabled()) {
            return;
        }
        // Cart placements anchor on the first line in the cart (most
        // recently added). The cart object exposes `contents` keyed
        // by products_id; pick the first one. When the cart is empty
        // we skip.
        $anchor = 0;
        if (isset($GLOBALS['cart']) && is_object($GLOBALS['cart'])
            && isset($GLOBALS['cart']->contents) && is_array($GLOBALS['cart']->contents)) {
            foreach ($GLOBALS['cart']->contents as $pid => $_row) {
                $anchor = (int) $pid;
                if ($anchor > 0) {
                    break;
                }
            }
        }
        if ($anchor <= 0) {
            return;
        }
        $this->emitPlacements([
            ['key' => 'cart-also-bought', 'doc_id' => $anchor, 'limit' => 6],
        ]);
    }

    /**
     * Echo a `<div data-seekmodo-placement="...">` placeholder per
     * entry. The JS module picks them up on DOMContentLoaded; each
     * placeholder is filled with one fetch.
     *
     * @param array<int, array{key: string, doc_id?: int, limit?: int, bundle_size?: int}> $rows
     */
    private function emitPlacements(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        foreach ($rows as $row) {
            $key = isset($row['key']) ? (string) $row['key'] : '';
            if ($key === '') {
                continue;
            }
            $attrs = ['data-seekmodo-placement="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '"'];
            if (!empty($row['doc_id'])) {
                $attrs[] = 'data-seekmodo-doc-id="' . (int) $row['doc_id'] . '"';
            }
            if (!empty($row['limit'])) {
                $attrs[] = 'data-seekmodo-limit="' . (int) $row['limit'] . '"';
            }
            if (!empty($row['bundle_size'])) {
                $attrs[] = 'data-seekmodo-bundle-size="' . (int) $row['bundle_size'] . '"';
            }
            echo '<div ' . implode(' ', $attrs) . '></div>' . "\n";
        }
    }

    private function recommendationsEnabled(): bool
    {
        if (!function_exists('numinix_seekmodo_enabled') || !numinix_seekmodo_enabled()) {
            return false;
        }
        if (!function_exists('_numinix_seekmodo_cfg')) {
            return false;
        }
        return ((string) _numinix_seekmodo_cfg(
            'NUMINIX_SEEKMODO_RECOMMENDATIONS_ENABLED',
            'false'
        )) === 'true';
    }

    private function currentMainPage(): string
    {
        if (isset($_GET['main_page']) && is_string($_GET['main_page'])) {
            return strtolower((string) $_GET['main_page']);
        }
        if (defined('SCRIPT_NAME')) {
            return strtolower((string) SCRIPT_NAME);
        }
        return '';
    }

    /**
     * Append a structured debug line to logs/numinix_seekmodo.log
     * when NUMINIX_SEEKMODO_DEBUG=true. Failures swallowed.
     *
     * @param array<string, mixed> $ctx
     */
    private function debug(string $msg, array $ctx = []): void
    {
        if (
            !defined('NUMINIX_SEEKMODO_DEBUG')
            || strtolower((string) NUMINIX_SEEKMODO_DEBUG) !== 'true'
        ) {
            return;
        }
        $logDir = null;
        if (defined('DIR_FS_LOGS')) {
            $logDir = rtrim(DIR_FS_LOGS, '/\\');
        } elseif (defined('DIR_FS_CATALOG')) {
            $logDir = rtrim(DIR_FS_CATALOG, '/\\') . '/logs';
        }
        if ($logDir === null || !is_dir($logDir)) {
            return;
        }
        $line = json_encode(
            [
                'ts'    => date('c'),
                'level' => 'debug',
                'msg'   => $msg,
                'ctx'   => $ctx,
            ],
            JSON_UNESCAPED_SLASHES
        );
        if ($line === false) {
            return;
        }
        @file_put_contents(
            $logDir . '/numinix_seekmodo.log',
            $line . PHP_EOL,
            FILE_APPEND
        );
    }
}
