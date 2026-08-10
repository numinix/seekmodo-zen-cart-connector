<?php
/**
 * Enhanced Native search for Zen Cart — connector-owned SQL retrieval.
 */

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

if (!function_exists('numinix_seekmodo_run_enhanced_native_search')) {
    /**
     * @return array{product_ids: array<int>, total: int, source: string}|null
     */
    function numinix_seekmodo_run_enhanced_native_search(string $q, int $page = 1, int $perPage = 20): ?array
    {
        if (!numinix_seekmodo_enhanced_native_enabled()) {
            return null;
        }
        $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '');
        if ($q === '' || mb_strlen($q) < 2) {
            return null;
        }

        global $db;
        if (!isset($db) || !is_object($db)) {
            return null;
        }

        $like = '%' . zen_db_input($q) . '%';
        $lang = isset($_SESSION['languages_id']) ? (int)$_SESSION['languages_id'] : 1;
        $offset = max(0, ($page - 1) * $perPage);
        $sql = 'SELECT p.products_id FROM ' . TABLE_PRODUCTS . ' p '
            . 'LEFT JOIN ' . TABLE_PRODUCTS_DESCRIPTION . ' pd ON p.products_id = pd.products_id AND pd.language_id = ' . $lang
            . ' LEFT JOIN ' . TABLE_MANUFACTURERS . ' m ON p.manufacturers_id = m.manufacturers_id '
            . 'WHERE p.products_status = 1 '
            . 'AND (pd.products_name LIKE \'' . $like . '\' OR p.products_model LIKE \'' . $like . '\' OR m.manufacturers_name LIKE \'' . $like . '\') '
            . 'ORDER BY ' . numinix_seekmodo_enhanced_native_order_sql() . ' '
            . 'LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;

        $ids = [];
        $rows = $db->Execute($sql);
        if ($rows && !$rows->EOF) {
            while (!$rows->EOF) {
                $ids[] = (int)$rows->fields['products_id'];
                $rows->MoveNext();
            }
        }

        return [
            'product_ids' => $ids,
            'total' => count($ids),
            'source' => 'enhanced_native',
        ];
    }
}

if (!function_exists('numinix_seekmodo_run_typeahead_local')) {
    /**
     * Local prefix typeahead (Enhanced Native Tier 1).
     *
     * @return array{q: string, items: array<int, array<string, mixed>>}|null
     */
    function numinix_seekmodo_run_typeahead_local(string $q, int $max = 8): ?array
    {
        if (!numinix_seekmodo_enhanced_native_enabled()) {
            return null;
        }
        $search = numinix_seekmodo_run_enhanced_native_search($q, 1, max(1, min(15, $max)));
        if ($search === null || $search['product_ids'] === []) {
            return null;
        }
        $items = [];
        foreach ($search['product_ids'] as $pid) {
            $name = '';
            if (function_exists('zen_get_products_name')) {
                $name = (string) zen_get_products_name((int) $pid);
            }
            $items[] = [
                'products_id' => (int) $pid,
                'value'       => $name,
                'url'         => function_exists('zen_href_link')
                    ? (string) zen_href_link(FILENAME_PRODUCT_INFO, 'products_id=' . (int) $pid)
                    : '',
            ];
        }

        return [
            'q'     => $q,
            'items' => $items,
        ];
    }
}
