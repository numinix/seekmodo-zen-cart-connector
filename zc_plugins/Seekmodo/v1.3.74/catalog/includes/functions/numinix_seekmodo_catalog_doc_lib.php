<?php
/**
 * Shared product-document builders for full push and delta indexing.
 *
 * Mirrors the doc shape produced by numinix_seekmodo_push_catalog.php so
 * delta ticks upsert the same fields the full indexer walks.
 */

if (!function_exists('numinix_seekmodo_catalog_doc_resolve_npf_column')) {
    function numinix_seekmodo_catalog_doc_resolve_npf_column(): ?string
    {
        $override = null;
        if (class_exists(\Numinix\Seekmodo\RemoteConfig::class)) {
            $override = \Numinix\Seekmodo\RemoteConfig::indexerOverride(
                'zen_cart',
                'npf_force_oos_column'
            );
        }
        if ($override === '') {
            return null;
        }
        if (is_string($override) && $override !== '') {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $override)) {
                return null;
            }
            return $override;
        }
        return 'out_of_stock';
    }
}

if (!function_exists('numinix_seekmodo_catalog_doc_npf_column')) {
    function numinix_seekmodo_catalog_doc_npf_column(): ?string
    {
        global $db;
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved === false ? null : $resolved;
        }
        $candidate = numinix_seekmodo_catalog_doc_resolve_npf_column();
        if ($candidate === null) {
            $resolved = false;
            return null;
        }
        $check = $db->Execute(
            'SHOW COLUMNS FROM ' . TABLE_PRODUCTS . " LIKE '" . $candidate . "'"
        );
        if ($check && $check->RecordCount() > 0) {
            $resolved = $candidate;
            return $candidate;
        }
        $resolved = false;
        return null;
    }
}

if (!function_exists('numinix_seekmodo_catalog_doc_live_in_stock')) {
    /**
     * Match storefront stock: zen_get_products_stock() when available
     * (KIP master_products_id / attribute masters), else row quantity.
     *
     * Soft-guards PHP warnings from Zen Cart core when products_quantity
     * is missing (orphaned / attribute edge-case product ids on search).
     */
    function numinix_seekmodo_catalog_doc_live_in_stock(int $productsId, int $rowQuantity = 0): bool
    {
        if ($productsId <= 0) {
            return false;
        }
        if (function_exists('zen_get_products_stock')) {
            $stock = null;
            set_error_handler(static function (int $severity, string $message): bool {
                if (
                    ($severity === E_WARNING || $severity === E_NOTICE || $severity === E_USER_WARNING)
                    && str_contains($message, 'products_quantity')
                ) {
                    return true;
                }
                return false;
            });
            try {
                $stock = zen_get_products_stock($productsId);
            } finally {
                restore_error_handler();
            }
            if (is_array($stock)) {
                foreach ($stock as $qty) {
                    if ((int) $qty > 0) {
                        return true;
                    }
                }
                return false;
            }
            if ($stock !== null && $stock !== false && $stock !== '') {
                return (int) $stock > 0;
            }
        }
        return $rowQuantity > 0;
    }
}

if (!function_exists('numinix_seekmodo_catalog_partition_ids_by_stock_flags')) {
    /**
     * Stable-partition ranked ids: in-stock first, then OOS / missing rows.
     *
     * @param int[] $productIds
     * @param array<int, bool> $inStockById
     * @return int[]
     */
    function numinix_seekmodo_catalog_partition_ids_by_stock_flags(array $productIds, array $inStockById): array
    {
        $inStockIds = [];
        $demoted = [];
        foreach ($productIds as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0) {
                continue;
            }
            if (!empty($inStockById[$pid])) {
                $inStockIds[] = $pid;
            } else {
                $demoted[] = $pid;
            }
        }

        return array_merge($inStockIds, $demoted);
    }
}

if (!function_exists('numinix_seekmodo_catalog_live_stock_flags_for_ids')) {
    /**
     * Bulk `products_id => in_stock` without hydrating catalog documents.
     *
     * The SERP used to call `docs_for_ids()` here, which joins
     * `products_description` and builds full index docs (categories,
     * breadcrumbs, cleaned HTML). Broad queries (~10k "guitar" hits)
     * exhausted Zen Cart `query_factory` at 1GB (STRIN DEV 500s).
     *
     * @param int[] $productIds
     * @return array<int, bool>
     */
    function numinix_seekmodo_catalog_live_stock_flags_for_ids(array $productIds): array
    {
        global $db;
        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($productIds === [] || !isset($db) || !is_object($db)) {
            return [];
        }
        $npfColumn = function_exists('numinix_seekmodo_catalog_doc_npf_column')
            ? numinix_seekmodo_catalog_doc_npf_column()
            : null;
        $select = 'SELECT p.products_id, p.products_quantity';
        if (is_string($npfColumn) && $npfColumn !== '') {
            $select .= ', p.`' . str_replace('`', '', $npfColumn) . '`';
        }
        $sql = $select
            . ' FROM ' . TABLE_PRODUCTS . ' p'
            . ' WHERE p.products_id IN (' . implode(',', $productIds) . ')';
        $rows = $db->Execute($sql);
        $flags = [];
        if ($rows && (int) $rows->RecordCount() > 0) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $pid = (int) ($row['products_id'] ?? 0);
                if ($pid <= 0) {
                    continue;
                }
                $qty = (int) ($row['products_quantity'] ?? 0);
                $npfForceOos = false;
                if (is_string($npfColumn) && $npfColumn !== '' && array_key_exists($npfColumn, $row)) {
                    $raw = $row[$npfColumn];
                    $npfForceOos = $raw === true || $raw === 1 || $raw === '1'
                        || (is_string($raw) && strtolower($raw) === 'true');
                }
                $flags[$pid] = $qty > 0 && !$npfForceOos;
            }
        }
        if (function_exists('numinix_seekmodo_release_query_result')) {
            numinix_seekmodo_release_query_result($rows);
        }

        return $flags;
    }
}

if (!function_exists('numinix_seekmodo_catalog_partition_product_ids_live_stock')) {
    /**
     * Re-order gateway-ranked product ids using live DB stock flags so
     * stale Typesense docs cannot surface OOS items on the SERP.
     *
     * @param int[] $productIds
     * @return int[]
     */
    function numinix_seekmodo_catalog_partition_product_ids_live_stock(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($productIds === []) {
            return $productIds;
        }
        if (!function_exists('numinix_seekmodo_catalog_live_stock_flags_for_ids')
            || !function_exists('numinix_seekmodo_catalog_partition_ids_by_stock_flags')
        ) {
            return $productIds;
        }
        $flags = numinix_seekmodo_catalog_live_stock_flags_for_ids($productIds);

        return numinix_seekmodo_catalog_partition_ids_by_stock_flags($productIds, $flags);
    }
}

if (!function_exists('numinix_seekmodo_catalog_partition_rows_by_product_ids')) {
    /**
     * Re-order associative rows (typeahead items or gateway product docs)
     * to match a gateway-ranked products_id list after live-stock partition.
     *
     * @template T of array<string, mixed>
     * @param list<T> $rows
     * @param int[] $orderedIds
     * @param callable(T): int $idResolver
     * @return list<T>
     */
    function numinix_seekmodo_catalog_partition_rows_by_product_ids(
        array $rows,
        array $orderedIds,
        callable $idResolver
    ): array {
        if ($rows === [] || $orderedIds === []) {
            return $rows;
        }
        $byId = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $pid = (int) $idResolver($row);
            if ($pid > 0) {
                $byId[$pid] = $row;
            }
        }
        $ordered = [];
        $seen = [];
        foreach ($orderedIds as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0 || !isset($byId[$pid]) || isset($seen[$pid])) {
                continue;
            }
            $seen[$pid] = true;
            $ordered[] = $byId[$pid];
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $pid = (int) $idResolver($row);
            if ($pid > 0 && !isset($seen[$pid])) {
                $ordered[] = $row;
            }
        }
        return $ordered;
    }
}

if (!function_exists('numinix_seekmodo_catalog_partition_typeahead_items_live_stock')) {
    /**
     * Mirror SERP live-stock demotion for connector typeahead rows.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    function numinix_seekmodo_catalog_partition_typeahead_items_live_stock(array $items): array
    {
        if ($items === [] || !function_exists('numinix_seekmodo_catalog_partition_product_ids_live_stock')) {
            return $items;
        }
        $ids = [];
        foreach ($items as $item) {
            $pid = (int) ($item['products_id'] ?? 0);
            if ($pid > 0) {
                $ids[] = $pid;
            }
        }
        if ($ids === []) {
            return $items;
        }
        $orderedIds = numinix_seekmodo_catalog_partition_product_ids_live_stock($ids);
        return numinix_seekmodo_catalog_partition_rows_by_product_ids(
            $items,
            $orderedIds,
            static fn (array $row): int => (int) ($row['products_id'] ?? 0)
        );
    }
}

if (!function_exists('numinix_seekmodo_catalog_doc_clean_description')) {
    function numinix_seekmodo_catalog_doc_clean_description(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $stripped = strip_tags($raw);
        $stripped = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = preg_replace('/\s+/u', ' ', $stripped);
        return trim((string) $stripped);
    }
}

if (!function_exists('numinix_seekmodo_catalog_doc_prime_category_ids')) {
    /**
     * Prefetch products_to_categories for a page of product ids so the
     * indexer does one IN() query instead of N unique SELECTs (each of
     * which Zen Cart QueryCache would otherwise retain).
     *
     * @param int[] $productIds
     */
    function numinix_seekmodo_catalog_doc_prime_category_ids(array $productIds): void
    {
        $store = &numinix_seekmodo_catalog_doc_category_id_store();
        $store = [];
        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($productIds === []) {
            return;
        }
        global $db;
        if (!isset($db) || !is_object($db)) {
            return;
        }
        foreach ($productIds as $pid) {
            $store[$pid] = [];
        }
        $rows = $db->Execute(
            'SELECT products_id, categories_id FROM ' . TABLE_PRODUCTS_TO_CATEGORIES
            . ' WHERE products_id IN (' . implode(',', $productIds) . ')'
        );
        if ($rows) {
            foreach ($rows as $r) {
                $pid = (int) ($r['products_id'] ?? 0);
                if ($pid <= 0) {
                    continue;
                }
                $store[$pid][] = (int) ($r['categories_id'] ?? 0);
            }
        }
        if (function_exists('numinix_seekmodo_release_query_result')) {
            numinix_seekmodo_release_query_result($rows);
        }
    }
}

if (!function_exists('numinix_seekmodo_catalog_doc_category_id_store')) {
    /**
     * @return array<int, int[]>
     */
    function &numinix_seekmodo_catalog_doc_category_id_store(): array
    {
        static $store = [];
        return $store;
    }
}

if (!function_exists('numinix_seekmodo_catalog_doc_category_ids')) {
    /**
     * @return int[]
     */
    function numinix_seekmodo_catalog_doc_category_ids(int $productsId): array
    {
        $store = &numinix_seekmodo_catalog_doc_category_id_store();
        if (array_key_exists($productsId, $store)) {
            return $store[$productsId];
        }
        global $db;
        $rows = $db->Execute(
            'SELECT categories_id FROM ' . TABLE_PRODUCTS_TO_CATEGORIES
            . ' WHERE products_id = ' . $productsId
        );
        $ids = [];
        if ($rows) {
            foreach ($rows as $r) {
                $ids[] = (int) $r['categories_id'];
            }
        }
        if (function_exists('numinix_seekmodo_release_query_result')) {
            numinix_seekmodo_release_query_result($rows);
        }
        $store[$productsId] = $ids;
        return $ids;
    }
}

if (!function_exists('numinix_seekmodo_catalog_doc_breadcrumbs')) {
    /**
     * @return string[]
     */
    function numinix_seekmodo_catalog_doc_breadcrumbs(int $productsId, int $languageId): array
    {
        global $db;
        static $catNameCache = [];
        static $catParentCache = [];

        $linkedCats = numinix_seekmodo_catalog_doc_category_ids($productsId);
        if ($linkedCats === []) {
            return [];
        }

        if ($catNameCache === []) {
            $rows = $db->Execute(
                'SELECT cd.categories_id, cd.categories_name, c.parent_id'
                . ' FROM ' . TABLE_CATEGORIES . ' c'
                . ' INNER JOIN ' . TABLE_CATEGORIES_DESCRIPTION . ' cd'
                . '   ON cd.categories_id = c.categories_id AND cd.language_id = ' . $languageId
            );
            if ($rows) {
                foreach ($rows as $r) {
                    $cid = (int) $r['categories_id'];
                    $catNameCache[$cid] = (string) $r['categories_name'];
                    $catParentCache[$cid] = (int) $r['parent_id'];
                }
                if (function_exists('numinix_seekmodo_release_query_result')) {
                    numinix_seekmodo_release_query_result($rows);
                }
            }
        }

        $crumbs = [];
        foreach ($linkedCats as $cid) {
            $path = [];
            $cursor = $cid;
            $guard = 0;
            while ($cursor > 0 && $guard < 16) {
                if (!isset($catNameCache[$cursor])) {
                    break;
                }
                array_unshift($path, $catNameCache[$cursor]);
                $cursor = $catParentCache[$cursor] ?? 0;
                $guard++;
            }
            if ($path !== []) {
                $crumbs[] = implode(' > ', $path);
            }
        }
        return array_values(array_unique($crumbs));
    }
}

if (!function_exists('numinix_seekmodo_catalog_doc_encode_image_path')) {
    function numinix_seekmodo_catalog_doc_encode_image_path(string $path): string
    {
        if ($path === '') {
            return '';
        }
        $segments = explode('/', $path);
        foreach ($segments as $i => $seg) {
            if ($seg === '') {
                continue;
            }
            if (preg_match('/%[0-9a-fA-F]{2}/', $seg) === 1) {
                continue;
            }
            $segments[$i] = rawurlencode($seg);
        }
        return implode('/', $segments);
    }
}

if (!function_exists('numinix_seekmodo_catalog_doc_image_url')) {
    function numinix_seekmodo_catalog_doc_image_url(string $rawImage): string
    {
        $rel = trim($rawImage);
        if ($rel === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $rel) === 1) {
            return $rel;
        }
        // Optimized thumbs (Numinix stores) live under /cache/optimized_images/
        // at the catalog root — not under /images/.
        $isOptimizedCache = preg_match('#^(?:/)?cache/optimized_images/#i', $rel) === 1;
        if (!$isOptimizedCache && defined('DIR_WS_IMAGES') && stripos($rel, DIR_WS_IMAGES) !== 0) {
            $rel = ltrim((string) DIR_WS_IMAGES, '/') . ltrim($rel, '/');
        }
        $rel = numinix_seekmodo_catalog_doc_encode_image_path($rel);
        if (defined('HTTPS_SERVER') && defined('DIR_WS_HTTPS_CATALOG')
            && defined('ENABLE_SSL_CATALOG') && (string) ENABLE_SSL_CATALOG === 'true'
        ) {
            return rtrim((string) HTTPS_SERVER, '/')
                . (string) DIR_WS_HTTPS_CATALOG
                . ltrim($rel, '/');
        }
        if (defined('HTTP_SERVER') && defined('DIR_WS_CATALOG')) {
            return rtrim((string) HTTP_SERVER, '/')
                . (string) DIR_WS_CATALOG
                . ltrim($rel, '/');
        }
        return '';
    }
}

if (!function_exists('numinix_seekmodo_catalog_doc_product_url')) {
    function numinix_seekmodo_catalog_doc_product_url(int $productsId): string
    {
        if (function_exists('zen_href_link') && function_exists('zen_get_info_page')) {
            try {
                return (string) zen_href_link(
                    zen_get_info_page($productsId),
                    'products_id=' . $productsId,
                    'NONSSL',
                    false
                );
            } catch (\Throwable $e) {
                // Fall through.
            }
        }
        $base = (defined('HTTP_SERVER') ? HTTP_SERVER : '') . (defined('DIR_WS_CATALOG') ? DIR_WS_CATALOG : '/');
        return rtrim($base, '/') . '/index.php?main_page=product_info&products_id=' . $productsId;
    }
}

if (!function_exists('numinix_seekmodo_catalog_doc_occasion_peak_months')) {
    /**
     * @return array<string, int>
     */
    function numinix_seekmodo_catalog_doc_occasion_peak_months(): array
    {
        return [
            'christmas'   => 12,
            'valentines'  => 2,
            'easter'      => 4,
            'mothers_day' => 3,
            'fathers_day' => 6,
            'halloween'   => 10,
            'wedding'     => 6,
            'communion'   => 5,
        ];
    }
}

if (!function_exists('numinix_seekmodo_catalog_doc_occasion_aliases')) {
    /**
     * @return array<string, list<string>>
     */
    function numinix_seekmodo_catalog_doc_occasion_aliases(): array
    {
        return [
            'christmas'   => ['christmas', 'xmas', 'noel', 'santa'],
            'valentines'  => ['valentine', 'valentines'],
            'easter'      => ['easter'],
            'mothers_day' => ['mothers', 'mother', 'mum', 'mom', 'mam'],
            'fathers_day' => ['fathers', 'father', 'dad', 'daddy', 'papa'],
            'halloween'   => ['halloween', 'spooky'],
            'wedding'     => ['wedding', 'bride', 'groom', 'hen', 'stag'],
            'communion'   => ['communion', 'christening', 'baptism'],
            'anniversary' => ['anniversary'],
            'birthday'    => ['birthday'],
        ];
    }
}

if (!function_exists('numinix_seekmodo_catalog_doc_occasion_tags_from_text')) {
    /**
     * @return list<string>
     */
    function numinix_seekmodo_catalog_doc_occasion_tags_from_text(string $text): array
    {
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text) ?? '';
        $tokens = preg_split('/\s+/u', trim($text)) ?: [];
        $tokenSet = array_fill_keys(array_filter($tokens, static fn ($t) => $t !== ''), true);
        $found = [];
        foreach (numinix_seekmodo_catalog_doc_occasion_aliases() as $tag => $aliases) {
            foreach ($aliases as $alias) {
                if (isset($tokenSet[$alias]) && !in_array($tag, $found, true)) {
                    $found[] = $tag;
                    break;
                }
            }
        }
        return $found;
    }
}

if (!function_exists('numinix_seekmodo_catalog_doc_occasion_tags')) {
    /**
     * @param string[] $crumbs
     * @return list<string>
     */
    function numinix_seekmodo_catalog_doc_occasion_tags(array $crumbs, string $name, string $description = ''): array
    {
        $parts = [$name];
        if ($description !== '') {
            $parts[] = $description;
        }
        foreach ($crumbs as $crumb) {
            if (is_string($crumb) && $crumb !== '') {
                $parts[] = $crumb;
            }
        }
        $found = [];
        foreach ($parts as $part) {
            foreach (numinix_seekmodo_catalog_doc_occasion_tags_from_text($part) as $tag) {
                if (!in_array($tag, $found, true)) {
                    $found[] = $tag;
                }
            }
        }
        return $found;
    }
}

if (!function_exists('numinix_seekmodo_catalog_doc_occasion_peak_month')) {
    /**
     * @param list<string> $tags
     */
    function numinix_seekmodo_catalog_doc_occasion_peak_month(array $tags): ?int
    {
        $peaks = numinix_seekmodo_catalog_doc_occasion_peak_months();
        $best = null;
        foreach ($tags as $tag) {
            if (!isset($peaks[$tag])) {
                continue;
            }
            $month = (int) $peaks[$tag];
            if ($best === null) {
                $best = $month;
                continue;
            }
            $distNew = min(abs(7 - $month), 12 - abs(7 - $month));
            $distOld = min(abs(7 - $best), 12 - abs(7 - $best));
            if ($distNew > $distOld) {
                $best = $month;
            }
        }
        return $best;
    }
}

if (!function_exists('numinix_seekmodo_catalog_doc_index_price')) {
    /**
     * Selling price for the gateway index.
     *
     * Zen Cart stores products_price net of tax. When the storefront
     * shows tax-inclusive prices (DISPLAY_PRICE_WITH_TAX=true — typical
     * EU B2C), index the same gross amount shoppers see on the PDP so
     * suggest/filters/recos do not flash the net figure (e.g. 26.50 vs
     * 29.95 at 13% VAT).
     *
     * Prefers an active specials price when present.
     */
    function numinix_seekmodo_catalog_doc_index_price(int $productId, float $basePrice, int $taxClassId): float
    {
        $price = $basePrice;
        if ($productId > 0 && function_exists('zen_get_products_special_price')) {
            $special = @zen_get_products_special_price($productId, true);
            if ($special !== false && $special !== null && is_numeric($special) && (float) $special > 0) {
                $price = (float) $special;
            }
        }
        if (
            defined('DISPLAY_PRICE_WITH_TAX')
            && (string) DISPLAY_PRICE_WITH_TAX === 'true'
            && $taxClassId > 0
            && function_exists('zen_get_tax_rate')
            && function_exists('zen_add_tax')
        ) {
            $rate = (float) @zen_get_tax_rate($taxClassId);
            if ($rate > 0) {
                $price = (float) zen_add_tax($price, $rate);
            }
        }

        // Match storefront money rounding (EUR 26.50 + 13% -> 29.95).
        // Stabilize float artifacts before 2dp (29.944999... vs 29.945).
        if (function_exists('zen_round')) {
            return (float) zen_round($price, 2);
        }

        return (float) number_format((float) sprintf('%.4f', $price), 2, '.', '');
    }
}

if (!function_exists('numinix_seekmodo_catalog_doc_from_row')) {
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    function numinix_seekmodo_catalog_doc_from_row(array $row, int $languageId, ?string $npfForceOosColumn): ?array
    {
        $pid = (int) ($row['products_id'] ?? 0);
        if ($pid <= 0) {
            return null;
        }
        $name = trim((string) ($row['products_name'] ?? ''));
        if ($name === '') {
            return null;
        }
        $doc = [
            'id'   => (string) $pid,
            'name' => $name,
        ];
        if (!empty($row['products_model'])) {
            $doc['model'] = (string) $row['products_model'];
            $doc['sku']   = (string) $row['products_model'];
        }
        $desc = numinix_seekmodo_catalog_doc_clean_description((string) ($row['products_description'] ?? ''));
        if ($desc !== '') {
            $doc['description'] = $desc;
        }
        if (!empty($row['manufacturers_name'])) {
            $doc['brand'] = (string) $row['manufacturers_name'];
        }
        $catIds = numinix_seekmodo_catalog_doc_category_ids($pid);
        if ($catIds !== []) {
            $doc['category_id'] = $catIds;
        }
        if (isset($row['products_type']) && (int) $row['products_type'] > 0) {
            $doc['p_type'] = (int) $row['products_type'];
        }
        $crumbs = numinix_seekmodo_catalog_doc_breadcrumbs($pid, $languageId);
        if ($crumbs !== []) {
            $doc['category_breadcrumbs'] = $crumbs;
        }
        if (isset($row['products_price'])) {
            $doc['price'] = numinix_seekmodo_catalog_doc_index_price(
                $pid,
                (float) $row['products_price'],
                (int) ($row['products_tax_class_id'] ?? 0)
            );
        }
        if (function_exists('numinix_seekmodo_catalog_base_currency')) {
            $doc['currency'] = numinix_seekmodo_catalog_base_currency();
        }
        $inStock = numinix_seekmodo_catalog_doc_live_in_stock(
            $pid,
            (int) ($row['products_quantity'] ?? 0)
        );
        $allowCart = !isset($row['allow_add_to_cart'])
            || (string) $row['allow_add_to_cart'] !== 'N';
        $stockAllowCheckout = defined('STOCK_ALLOW_CHECKOUT') && STOCK_ALLOW_CHECKOUT === 'true';
        $isCall = (int) ($row['product_is_call'] ?? 0) === 1;
        $npfForceOos = false;
        if ($npfForceOosColumn !== null && array_key_exists($npfForceOosColumn, $row)) {
            $npfForceOos = (int) $row[$npfForceOosColumn] === 1;
        }
        $doc['purchasable'] = $allowCart
            && !$isCall
            && !$npfForceOos
            && ($inStock || $stockAllowCheckout);
        $doc['in_stock'] = $inStock && !$npfForceOos;
        if (!$doc['purchasable']) {
            $doc['in_stock'] = false;
        }
        $doc['url'] = numinix_seekmodo_catalog_doc_product_url($pid);
        $imageUrl = numinix_seekmodo_catalog_doc_image_url((string) ($row['products_image'] ?? ''));
        if ($imageUrl !== '') {
            $doc['image_url'] = $imageUrl;
        }
        if (isset($row['products_ordered']) && is_numeric($row['products_ordered'])) {
            $doc['units_sold_lifetime'] = max(0, (int) $row['products_ordered']);
        }
        $occasionTags = numinix_seekmodo_catalog_doc_occasion_tags($crumbs, $name, $desc);
        if ($occasionTags !== []) {
            $doc['occasion_tags'] = $occasionTags;
            $peakMonth = numinix_seekmodo_catalog_doc_occasion_peak_month($occasionTags);
            if ($peakMonth !== null) {
                $doc['occasion_peak_month'] = $peakMonth;
            }
        }
        return $doc;
    }
}

if (!function_exists('numinix_seekmodo_catalog_docs_for_ids')) {
    /**
     * Load product rows and build gateway index documents.
     *
     * @param int[] $productIds
     * @return array<int, array<string, mixed>>
     */
    function numinix_seekmodo_catalog_docs_for_ids(array $productIds, int $languageId = 1): array
    {
        global $db;
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
        if ($productIds === [] || !isset($db) || !is_object($db)) {
            return [];
        }
        if ($languageId <= 0) {
            $languageId = 1;
        }
        $npfColumn = numinix_seekmodo_catalog_doc_npf_column();
        $idList = implode(',', $productIds);
        $sql = "SELECT p.products_id, p.products_model, p.products_type, p.products_price,"
            . " p.products_tax_class_id, p.products_quantity, p.products_status, p.master_categories_id,"
            . " p.manufacturers_id, p.products_image, p.product_is_call,"
            . " p.products_ordered,"
            . " pt.allow_add_to_cart,"
            . " pd.products_name, pd.products_description,"
            . " m.manufacturers_name"
            . " FROM " . TABLE_PRODUCTS . " p"
            . " INNER JOIN " . TABLE_PRODUCTS_DESCRIPTION . " pd"
            . "   ON pd.products_id = p.products_id AND pd.language_id = " . $languageId
            . " LEFT JOIN " . TABLE_MANUFACTURERS . " m"
            . "   ON m.manufacturers_id = p.manufacturers_id"
            . " LEFT JOIN " . TABLE_PRODUCT_TYPES . " pt"
            . "   ON pt.type_id = p.products_type"
            . " WHERE p.products_id IN (" . $idList . ")";
        if ($npfColumn !== null) {
            $sql = str_replace(
                ' p.product_is_call,',
                ' p.product_is_call, p.`' . $npfColumn . '`,',
                $sql
            );
        }
        $rows = $db->Execute($sql);
        if (!$rows || $rows->RecordCount() === 0) {
            if (function_exists('numinix_seekmodo_release_query_result')) {
                numinix_seekmodo_release_query_result($rows);
            }
            return [];
        }
        if (function_exists('numinix_seekmodo_catalog_doc_prime_category_ids')) {
            numinix_seekmodo_catalog_doc_prime_category_ids($productIds);
        }
        $docs = [];
        foreach ($rows as $row) {
            $doc = numinix_seekmodo_catalog_doc_from_row($row, $languageId, $npfColumn);
            if ($doc !== null) {
                $docs[] = $doc;
            }
        }
        if (function_exists('numinix_seekmodo_release_query_result')) {
            numinix_seekmodo_release_query_result($rows);
        }
        if (function_exists('numinix_seekmodo_catalog_doc_prime_category_ids')) {
            numinix_seekmodo_catalog_doc_prime_category_ids([]);
        }
        return $docs;
    }
}
