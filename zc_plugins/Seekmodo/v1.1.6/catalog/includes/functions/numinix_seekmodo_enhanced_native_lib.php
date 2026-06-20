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
            . 'ORDER BY p.products_ordered DESC, p.products_viewed DESC '
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
