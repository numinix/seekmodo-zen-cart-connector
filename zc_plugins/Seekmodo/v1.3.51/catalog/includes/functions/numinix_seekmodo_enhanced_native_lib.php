<?php
/**
 * Enhanced Native search for Zen Cart — connector-owned SQL retrieval.
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

if (!function_exists('numinix_seekmodo_enhanced_native_enabled')) {
    function numinix_seekmodo_enhanced_native_enabled(): bool
    {
        return true;
    }
}

if (!function_exists('numinix_seekmodo_gateway_enabled')) {
    function numinix_seekmodo_gateway_enabled(): bool
    {
        return function_exists('numinix_seekmodo_enabled') && numinix_seekmodo_enabled();
    }
}

if (!function_exists('numinix_seekmodo_should_attempt_gateway_search')) {
    /**
     * Whether the SERP observer should call the Seekmodo gateway first.
     *
     * False when unpaired / mode=off / locked-out, or when sticky unpaid
     * (trial_expired / over_quota / cancelled) says prefer Enhanced Native
     * locally — matching typeahead's Client::shouldPreferLocalSuggest().
     */
    function numinix_seekmodo_should_attempt_gateway_search(): bool
    {
        if (!function_exists('numinix_seekmodo_enabled') || !numinix_seekmodo_enabled()) {
            return false;
        }
        if (
            class_exists('\\Numinix\\Seekmodo\\Client')
            && \Numinix\Seekmodo\Client::shouldPreferLocalSuggest()
        ) {
            return false;
        }

        return true;
    }
}

if (!function_exists('numinix_seekmodo_enhanced_native_order_sql')) {
    /**
     * Popularity ORDER BY for Enhanced Native retrieval.
     *
     * Forks disagree on where `products_viewed` lives (core ZC: products
     * table on some builds; Numinix: products_description). Probe once per
     * request lifecycle and cache in a static.
     */
    function numinix_seekmodo_enhanced_native_order_sql(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $parts = ['p.products_ordered DESC'];
        global $db;
        if (isset($db) && is_object($db) && method_exists($db, 'Execute')) {
            $descViewed = $db->Execute(
                'SHOW COLUMNS FROM ' . TABLE_PRODUCTS_DESCRIPTION . ' LIKE \'products_viewed\''
            );
            if ($descViewed && !$descViewed->EOF) {
                $parts[] = 'pd.products_viewed DESC';
            } else {
                $productsViewed = $db->Execute(
                    'SHOW COLUMNS FROM ' . TABLE_PRODUCTS . ' LIKE \'products_viewed\''
                );
                if ($productsViewed && !$productsViewed->EOF) {
                    $parts[] = 'p.products_viewed DESC';
                }
            }
        }
        $parts[] = 'p.products_date_added DESC';
        $cached = implode(', ', $parts);

        return $cached;
    }
}


if (!function_exists('numinix_seekmodo_enhanced_native_normalize_query')) {
    function numinix_seekmodo_enhanced_native_normalize_query(string $q): string
    {
        return trim(preg_replace('/\s+/u', ' ', $q) ?? '');
    }
}

if (!function_exists('numinix_seekmodo_enhanced_native_token_clauses')) {
    /**
     * Multi-word: each token must match name/description/model/brand (AND).
     *
     * @return list<string>|null
     */
    function numinix_seekmodo_enhanced_native_token_clauses(string $q): ?array
    {
        $q = numinix_seekmodo_enhanced_native_normalize_query($q);
        if ($q === '' || mb_strlen($q) < 2) {
            return null;
        }
        $tokens = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokenClauses = [];
        foreach ($tokens as $tok) {
            $tok = trim((string) $tok);
            if ($tok === '') {
                continue;
            }
            $like = '%' . zen_db_input($tok) . '%';
            $tokenClauses[] = '(pd.products_name LIKE \'' . $like
                . '\' OR pd.products_description LIKE \'' . $like
                . '\' OR p.products_model LIKE \'' . $like
                . '\' OR IFNULL(m.manufacturers_name, \'\') LIKE \'' . $like . '\')';
        }

        return $tokenClauses === [] ? null : $tokenClauses;
    }
}

if (!function_exists('numinix_seekmodo_enhanced_native_from_where')) {
    /**
     * Shared FROM/JOIN/WHERE for EN count + id paging (products + description + mfr).
     */
    function numinix_seekmodo_enhanced_native_from_where(string $q): ?string
    {
        $clauses = numinix_seekmodo_enhanced_native_token_clauses($q);
        if ($clauses === null) {
            return null;
        }
        $lang = isset($_SESSION['languages_id']) ? (int) $_SESSION['languages_id'] : 1;

        return ' FROM ' . TABLE_PRODUCTS . ' p '
            . 'LEFT JOIN ' . TABLE_PRODUCTS_DESCRIPTION . ' pd ON p.products_id = pd.products_id AND pd.language_id = ' . $lang . ' '
            . 'LEFT JOIN ' . TABLE_MANUFACTURERS . ' m ON p.manufacturers_id = m.manufacturers_id '
            . 'WHERE p.products_status = 1 '
            . 'AND ' . implode(' AND ', $clauses);
    }
}

if (!function_exists('numinix_seekmodo_enhanced_native_count')) {
    function numinix_seekmodo_enhanced_native_count(string $q): int
    {
        if (!numinix_seekmodo_enhanced_native_enabled()) {
            return 0;
        }
        global $db;
        if (!isset($db) || !is_object($db)) {
            return 0;
        }
        $fromWhere = numinix_seekmodo_enhanced_native_from_where($q);
        if ($fromWhere === null) {
            return 0;
        }
        $sql = 'SELECT COUNT(DISTINCT p.products_id) AS c' . $fromWhere;
        $row = $db->Execute($sql);
        if (!$row || $row->EOF) {
            return 0;
        }

        return (int) $row->fields['c'];
    }
}


if (!function_exists('numinix_seekmodo_enhanced_native_listing_order_sql')) {
    /**
     * SERP ORDER BY for Enhanced Native listing SQL.
     *
     * Uses the Numinix Reloaded / SBM sortby codes (shared by many ZC
     * storefronts): 1=newest, 2=popular, 3=price high, 4=price low,
     * 5=name, 8/default=SBM sort_order. Falls back to EN popularity.
     */
    function numinix_seekmodo_enhanced_native_listing_order_sql(): string
    {
        if (isset($_GET['sortby']) && (string) $_GET['sortby'] !== '') {
            switch ((int) $_GET['sortby']) {
                case 1:
                    return ' ORDER BY p.products_date_added DESC, pd.products_name ASC';
                case 2:
                    return ' ORDER BY p.products_ordered DESC, pd.products_name ASC';
                case 3:
                    return ' ORDER BY p.products_price_sorter DESC, pd.products_name ASC';
                case 4:
                    return ' ORDER BY p.products_price_sorter ASC, pd.products_name ASC';
                case 5:
                    return ' ORDER BY pd.products_name ASC';
                case 6:
                    return ' ORDER BY pd.products_name DESC';
                case 8:
                case 0:
                    return ' ORDER BY p.products_sort_order ASC, pd.products_name ASC';
            }
        }
        // No shopper sort (or "best match") — EN popularity ranking.
        return ' ORDER BY ' . numinix_seekmodo_enhanced_native_order_sql();
    }
}

if (!function_exists('numinix_seekmodo_build_enhanced_native_listing_sql')) {
    /**
     * Full listing SQL for EN SERPs — no ID list / no artificial result-count
     * cap. Zen Cart splitPageResults paginates this query natively.
     */
    function numinix_seekmodo_build_enhanced_native_listing_sql(string $q): ?string
    {
        if (!numinix_seekmodo_enhanced_native_enabled()) {
            return null;
        }
        $clauses = numinix_seekmodo_enhanced_native_token_clauses($q);
        if ($clauses === null) {
            return null;
        }
        $lang = isset($_SESSION['languages_id']) ? (int) $_SESSION['languages_id'] : 1;

        return 'SELECT /* numinix_seekmodo_enhanced_native */'
            . ' p.products_id, p.products_image, p.products_type, p.master_categories_id,'
            . ' p.products_quantity, p.products_quantity_order_min,'
            . ' p.products_quantity_order_units, pd.products_name,'
            . ' pd.products_description, p.products_model, p.products_price,'
            . ' p.products_tax_class_id, p.products_priced_by_attribute,'
            . ' p.product_is_call, p.product_is_always_free_shipping,'
            . ' p.products_qty_box_status, p.manufacturers_id, m.manufacturers_name,'
            . ' p.products_date_added, p.products_status, p.products_sort_order,'
            . ' p.products_price_sorter, p.products_ordered,'
            . ' IF(s.status = 1, s.specials_new_products_price, p.products_price) AS final_price'
            . ' FROM ' . TABLE_PRODUCTS . ' p'
            . ' LEFT JOIN ' . TABLE_MANUFACTURERS . ' m ON p.manufacturers_id = m.manufacturers_id'
            . ' LEFT JOIN ' . TABLE_SPECIALS . ' s ON s.products_id = p.products_id'
            . ' INNER JOIN ' . TABLE_PRODUCTS_DESCRIPTION . ' pd ON pd.products_id = p.products_id'
            . ' WHERE p.products_status = 1'
            . ' AND pd.language_id = ' . $lang
            . ' AND ' . implode(' AND ', $clauses)
            . numinix_seekmodo_enhanced_native_listing_order_sql();
    }
}

if (!function_exists('numinix_seekmodo_run_enhanced_native_search')) {
    /**
     * Paged id retrieval for typeahead / position maps.
     * `total` is always the full matching count (not the page size).
     *
     * @return array{product_ids: array<int>, total: int, source: string}|null
     */
    function numinix_seekmodo_run_enhanced_native_search(string $q, int $page = 1, int $perPage = 20): ?array
    {
        if (!numinix_seekmodo_enhanced_native_enabled()) {
            return null;
        }
        global $db;
        if (!isset($db) || !is_object($db)) {
            return null;
        }
        $fromWhere = numinix_seekmodo_enhanced_native_from_where($q);
        if ($fromWhere === null) {
            return null;
        }

        $total = numinix_seekmodo_enhanced_native_count($q);
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $sql = 'SELECT DISTINCT p.products_id' . $fromWhere
            . ' ORDER BY ' . numinix_seekmodo_enhanced_native_order_sql() . ' '
            . 'LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;

        $ids = [];
        $rows = $db->Execute($sql);
        if ($rows && !$rows->EOF) {
            while (!$rows->EOF) {
                $ids[] = (int) $rows->fields['products_id'];
                $rows->MoveNext();
            }
        }

        return [
            'product_ids' => $ids,
            'total' => $total,
            'source' => 'enhanced_native',
        ];
    }
}


if (!function_exists('numinix_seekmodo_run_typeahead_local')) {
    /**
     * Local typeahead (Enhanced Native Tier 1).
     *
     * @return array{q: string, items: array<int, array<string, mixed>>, keywords: array, categories: array, total: int}|null
     */
    function numinix_seekmodo_run_typeahead_local(string $q, int $max = 8): ?array
    {
        if (!numinix_seekmodo_enhanced_native_enabled()) {
            return null;
        }
        $search = numinix_seekmodo_run_enhanced_native_search($q, 1, max(1, min(15, $max)));
        if ($search === null) {
            return [
                'q' => $q,
                'items' => [],
                'keywords' => [],
                'categories' => [],
                'total' => 0,
            ];
        }
        $items = [];
        foreach ($search['product_ids'] as $pid) {
            $name = '';
            if (function_exists('zen_get_products_name')) {
                $name = (string) zen_get_products_name((int) $pid);
            }
            $url = '';
            if (function_exists('numinix_seekmodo_href_link_raw')) {
                $url = numinix_seekmodo_href_link_raw(FILENAME_PRODUCT_INFO, 'products_id=' . (int) $pid);
            } elseif (function_exists('zen_href_link')) {
                $url = htmlspecialchars_decode(
                    (string) zen_href_link(FILENAME_PRODUCT_INFO, 'products_id=' . (int) $pid),
                    ENT_QUOTES
                );
            }
            $items[] = [
                'products_id' => (int) $pid,
                'value' => $name,
                'url' => $url,
            ];
        }

        return [
            'q' => $q,
            'items' => $items,
            'keywords' => [],
            'categories' => [],
            'total' => (int) ($search['total'] ?? count($items)),
        ];
    }
}

