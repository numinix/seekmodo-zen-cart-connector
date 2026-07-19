<?php
/**
 * Indexer-replacement helpers for the Seekmodo connector.
 *
 * Used by Redline's includes/functions/typesense_indexer_lib.php at
 * the top of numinix_typesense_indexer_bulk_upsert($batch, $collection).
 * The swap-point is small:
 *
 *     if (function_exists('numinix_seekmodo_enabled') && numinix_seekmodo_enabled()) {
 *         @include_once DIR_FS_CATALOG . 'includes/functions/numinix_seekmodo_indexer_lib.php';
 *         if (function_exists('numinix_seekmodo_run_bulk_upsert')) {
 *             $smResult = numinix_seekmodo_run_bulk_upsert($batch, $collection);
 *             if ($smResult !== null) {
 *                 return $smResult;
 *             }
 *         }
 *     }
 *
 * Mode semantics (matches the search helper):
 *   - enforce: route the entire batch through /v1/index in 500-doc
 *     chunks. Return the gateway's aggregate counts on success;
 *     null on partial / total failure so the cron retries via
 *     direct Typesense.
 *   - shadow:  fire the chunks at the gateway for observation, then
 *     return null so the cron writes via the native /import path
 *     (the source of truth stays Typesense during shadow).
 *   - off:     not reached — numinix_seekmodo_enabled() short-circuits.
 *
 * RED-SUGGEST fix-pack #4 -- batch enrichment.
 *   Before forwarding the batch we run
 *   `numinix_seekmodo_indexer_enrich_batch()` which fills in
 *   `category_id` (int32[]) and `category_breadcrumbs` (string[])
 *   when the upstream indexer omitted them. The gateway's
 *   SuggestTool categories block reads these two parallel arrays
 *   per doc -- legacy Zen Cart indexers (Redline's
 *   `typesense_indexer_lib.php`, the AKS catalogue indexer) emit
 *   only the legacy `category_id` shape, which left those
 *   tenants with `categories: []` in the typeahead even on a
 *   complete reindex. The enrichment is idempotent (docs that
 *   already carry both arrays pass through untouched) and
 *   fail-open (any DB-level surprise leaves the batch unchanged
 *   rather than blocking the cron).
 */

if (!function_exists('numinix_seekmodo_run_bulk_upsert')) {
    /**
     * @param array<int, array<string, mixed>> $batch
     * @return array{count_ok:int, count_failed:int, errors:array<int,string>}|null
     */
    function numinix_seekmodo_run_bulk_upsert(array $batch, string $collection): ?array
    {
        if ($batch === []) {
            return ['count_ok' => 0, 'count_failed' => 0, 'errors' => []];
        }
        if (!function_exists('numinix_seekmodo_enabled') || !numinix_seekmodo_enabled()) {
            return null;
        }
        // Sprint 12 — tenant domain lock. Critical to gate here even
        // though enabled() also checks: an indexer run on a dev /
        // staging clone of the storefront would otherwise overwrite
        // the production tenant's Typesense index with this clone's
        // product set. Returning null short-circuits to the native
        // /import path, leaving the production index untouched.
        if (
            function_exists('numinix_seekmodo_is_locked_out')
            && numinix_seekmodo_is_locked_out()
        ) {
            return null;
        }
        if (
            function_exists('numinix_seekmodo_can_index')
            && !numinix_seekmodo_can_index()
        ) {
            return null;
        }
        $mode = numinix_seekmodo_mode();
        if ($mode === 'off') {
            return null;
        }

        // M7.1 cold-start rerank schema upgrade: detect when the
        // connector hasn't yet completed a full reindex against the
        // gateway's M7.1 schema (new fields: is_primary,
        // is_accessory_heuristic, title_token_count, head_noun,
        // price_pct_in_category — all computed gateway-side, but the
        // index needs to repopulate them on every doc). The flag is
        // owned by the connector so we don't have to ping the gateway
        // every batch.
        _numinix_seekmodo_indexer_request_first_run_if_needed();

        // RED-SUGGEST fix-pack #4 -- enrich the batch with
        // category_id + category_breadcrumbs when the upstream
        // indexer omitted them. The SuggestTool categories block
        // depends on these two parallel arrays being present in
        // every product doc; legacy storefront indexers (Redline's
        // transfer_products.php / typesense_indexer_lib.php) only
        // emit `category_id` (or even nothing at all), so without
        // this enrichment the gateway's per-doc breadcrumb walk
        // returns `categories: []` even on a fully reindexed
        // collection. Idempotent: docs that already carry both
        // arrays are passed through untouched.
        $batch = numinix_seekmodo_indexer_enrich_batch($batch);

        $startMs = (int)(microtime(true) * 1000);
        $result = numinix_seekmodo_index_chunked($batch);
        $elapsedMs = (int)(microtime(true) * 1000) - $startMs;

        _numinix_seekmodo_indexer_log($mode, $collection, $batch, $result, $elapsedMs);

        // Once per indexer run (APCu-throttled to ~5 min), push the
        // FSM snapshot up to the gateway so the admin UI's connector
        // status card doesn't go stale. Best-effort.
        _numinix_seekmodo_indexer_push_snapshot();

        if ($mode === 'shadow') {
            // Observation only — Typesense remains the source of truth
            // until enforce. The cron's $stats accounting comes from
            // the direct path.
            return null;
        }

        // enforce
        if (!$result['ok']) {
            // Partial / total failure — fall back to native /import.
            // The cron's existing stats logic handles the retry; we
            // logged what happened above.
            return null;
        }
        return [
            'count_ok' => $result['sent'],
            'count_failed' => $result['failed'],
            'errors' => $result['errors'],
        ];
    }
}

if (!function_exists('_numinix_seekmodo_indexer_log')) {
    /**
     * Append one observation row per batch to logs/numinix_seekmodo.log.
     * Cheap; writes nothing when DIR_FS_LOGS isn't writable.
     *
     * @param array<int,array<string,mixed>> $batch
     * @param array{ok:bool,chunks:int,sent:int,failed:int,errors:array<int,string>} $result
     */
    function _numinix_seekmodo_indexer_log(string $mode, string $collection, array $batch, array $result, int $elapsedMs): void
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
        $row = [
            'ts' => date('c'),
            'msg' => 'indexer_batch',
            'mode' => $mode,
            'collection' => $collection,
            'batch_size' => count($batch),
            'chunks' => $result['chunks'],
            'sent' => $result['sent'],
            'failed' => $result['failed'],
            'ok' => $result['ok'],
            'elapsed_ms' => $elapsedMs,
            'errors' => array_slice($result['errors'], 0, 5),
        ];
        $line = json_encode($row, JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }
        @file_put_contents($logDir . '/numinix_seekmodo.log', $line . PHP_EOL, FILE_APPEND);
    }
}

if (!function_exists('_numinix_seekmodo_indexer_request_first_run_if_needed')) {
    /**
     * Schedule a one-shot full reindex the first time this connector
     * upserts a batch against the M7.1+ gateway schema. The marker
     * `NUMINIX_SEEKMODO_RERANK_REINDEX_DONE` lives in
     * zen_configuration and is set to 1 by the indexer cron's
     * tooling once a full pass has completed. The first run after a
     * deploy where the marker is absent / "0" enqueues a full
     * reindex by clearing the indexer's "last_indexed_at" cursor
     * (the cron's own logic picks up the empty cursor and walks the
     * entire catalog).
     *
     * Idempotent: subsequent batches see the marker = "1" and
     * short-circuit immediately.
     */
    function _numinix_seekmodo_indexer_request_first_run_if_needed(): void
    {
        global $db;
        if (!isset($db) || !is_object($db)) {
            return;
        }
        $apcuKey = 'numinix.seekmodo.rerank_reindex_check';
        if (function_exists('apcu_fetch')) {
            $last = (int) apcu_fetch($apcuKey);
            // Once we've decided per FPM worker that the marker is
            // set, we don't need to re-read configuration every
            // batch. Cache for 15 min.
            if ($last === 1) {
                return;
            }
        }
        $value = null;
        try {
            $row = $db->Execute(
                "SELECT configuration_value FROM " . TABLE_CONFIGURATION
                . " WHERE configuration_key = 'NUMINIX_SEEKMODO_RERANK_REINDEX_DONE' LIMIT 1"
            );
            if (!$row->EOF) {
                $value = (string) $row->fields['configuration_value'];
            }
        } catch (\Throwable $e) {
            return;
        }
        if ($value === '1') {
            if (function_exists('apcu_store')) {
                apcu_store($apcuKey, 1, 900);
            }
            return;
        }
        // Marker missing → first run after M7.1 deploy. Insert the
        // row (so subsequent batches see it) and clear the indexer's
        // catch-up cursor so the next cron pass walks the entire
        // catalog. We mark the row as "1" optimistically: if the
        // reindex partially fails the cron retries naturally, and
        // we don't want a single batch to schedule N more full
        // walks.
        try {
            if ($value === null) {
                $db->Execute(
                    "INSERT IGNORE INTO " . TABLE_CONFIGURATION
                    . " (configuration_key, configuration_value, configuration_title, "
                    . "  configuration_description, configuration_group_id, sort_order, "
                    . "  date_added) VALUES "
                    . " ('NUMINIX_SEEKMODO_RERANK_REINDEX_DONE', '1', "
                    . "  'Seekmodo M7.1 Rerank Reindex Done', "
                    . "  'Set to 1 once the connector has completed a full reindex against the M7.1 gateway schema. Manage via the indexer cron.', "
                    . "  6, 1, NOW())"
                );
            } else {
                $db->Execute(
                    "UPDATE " . TABLE_CONFIGURATION
                    . " SET configuration_value = '1' "
                    . " WHERE configuration_key = 'NUMINIX_SEEKMODO_RERANK_REINDEX_DONE'"
                );
            }
            // Clear the indexer's last-indexed timestamp so the next
            // cron pass walks every product. The key here matches
            // the connector's own indexer-state config row.
            $db->Execute(
                "UPDATE " . TABLE_CONFIGURATION
                . " SET configuration_value = '' "
                . " WHERE configuration_key = 'NUMINIX_TYPESENSE_LAST_INDEXED_AT'"
            );
        } catch (\Throwable $e) {
            return;
        }
        if (function_exists('apcu_store')) {
            apcu_store($apcuKey, 1, 900);
        }
    }
}

if (!function_exists('_numinix_seekmodo_indexer_push_snapshot')) {
    /**
     * APCu-throttled FSM snapshot push. Called once per indexer batch;
     * the throttle gates it down to one real network call per ~5 min
     * regardless of how many batches a single cron run produces.
     */
    function _numinix_seekmodo_indexer_push_snapshot(): void
    {
        $key = 'numinix.seekmodo.indexer_push_at';
        if (function_exists('apcu_fetch')) {
            $last = (int) apcu_fetch($key);
            if ($last > 0 && (time() - $last) < 300) {
                return;
            }
        }
        if (!class_exists(\Numinix\Seekmodo\AutoPromoter::class)) {
            return;
        }
        try {
            (new \Numinix\Seekmodo\AutoPromoter())->pushSnapshot('indexer_run');
        } catch (\Throwable $e) {
            return;
        }
        if (function_exists('apcu_store')) {
            apcu_store($key, time(), 600);
        }
    }
}

if (!function_exists('numinix_seekmodo_indexer_enrich_batch')) {
    /**
     * RED-SUGGEST fix-pack #4 -- enrich an outbound indexer batch
     * with `category_id` (int32[]) and `category_breadcrumbs`
     * (string[]) parallel arrays when the upstream indexer didn't
     * already populate them.
     *
     * Why this matters: the gateway's `SuggestTool` categories
     * block does a per-doc walk over these two parallel arrays. A
     * tenant whose docs are missing both fields (Redline, AKS, any
     * Zen Cart store driving the connector through a legacy
     * `transfer_products.php` cron whose
     * `typesense_indexer_lib.php` predates breadcrumb support)
     * silently surfaces an empty categories panel in the
     * typeahead -- even when the same shopper query unambiguously
     * maps to a storefront category. The standalone v1.0.10+
     * `numinix_seekmodo_push_catalog.php` already emits both
     * arrays at index time, but the *swap-point* path (where the
     * upstream indexer builds the docs and we just relay them to
     * the gateway) didn't, leaving these tenants stuck.
     *
     * Posture:
     *   - Idempotent. Docs that already carry both arrays are
     *     short-circuited without a DB read.
     *   - Best-effort. Any DB failure / Zen Cart context-missing
     *     / language mis-detection silently passes the batch
     *     through unchanged -- never makes the upstream indexer's
     *     batch worse.
     *   - Bulk-friendly. One `products_to_categories` query per
     *     batch (vs N per-doc), one full category-tree query
     *     statically cached per request.
     *   - Language-aware. Uses the active session's
     *     `languages_id`; falls back to 1 (Zen Cart default) when
     *     the CLI session hasn't bootstrapped one.
     *
     * @internal Exposed `public` so the standalone push script
     *           and unit tests can reuse the helper without
     *           duplicating the tree-walk logic.
     * @param array<int,array<string,mixed>> $batch
     * @return array<int,array<string,mixed>>
     */
    function numinix_seekmodo_indexer_enrich_batch(array $batch): array
    {
        if ($batch === []) {
            return $batch;
        }
        // Quick exit when every doc already has both arrays --
        // common case for the standalone push script and the WP /
        // BC connectors, which produce well-formed docs upstream.
        $needsEnrich = false;
        $productIds = [];
        foreach ($batch as $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $hasIds = isset($doc['category_id']) && is_array($doc['category_id']) && $doc['category_id'] !== [];
            $hasCrumbs = isset($doc['category_breadcrumbs']) && is_array($doc['category_breadcrumbs']) && $doc['category_breadcrumbs'] !== [];
            if ($hasIds && $hasCrumbs) {
                continue;
            }
            $needsEnrich = true;
            $pid = isset($doc['id']) ? (int) $doc['id'] : (isset($doc['products_id']) ? (int) $doc['products_id'] : 0);
            if ($pid > 0) {
                $productIds[$pid] = true;
            }
        }
        if (!$needsEnrich) {
            return $batch;
        }
        // We need the Zen Cart DB + table constants. Bail (return
        // untouched batch) when running outside a Zen Cart context.
        if (!isset($GLOBALS['db']) || !is_object($GLOBALS['db']) || !defined('TABLE_PRODUCTS_TO_CATEGORIES')) {
            return $batch;
        }
        $languageId = _numinix_seekmodo_indexer_resolve_language_id();
        $categoryMap = _numinix_seekmodo_indexer_collect_category_map(array_keys($productIds));
        if ($categoryMap === []) {
            return $batch;
        }
        [$nameMap, $parentMap] = _numinix_seekmodo_indexer_category_tree($languageId);
        if ($nameMap === []) {
            // Can't build breadcrumbs without category names. Best
            // we can do is still attach the (possibly already
            // present) category_id list so the gateway has at
            // least the id surface to filter on.
            foreach ($batch as $i => $doc) {
                if (!is_array($doc)) {
                    continue;
                }
                $pid = isset($doc['id']) ? (int) $doc['id'] : (isset($doc['products_id']) ? (int) $doc['products_id'] : 0);
                if ($pid <= 0) {
                    continue;
                }
                $ids = $categoryMap[$pid] ?? [];
                if ($ids !== [] && (!isset($doc['category_id']) || !is_array($doc['category_id']) || $doc['category_id'] === [])) {
                    $batch[$i]['category_id'] = $ids;
                }
            }
            return $batch;
        }
        foreach ($batch as $i => $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $pid = isset($doc['id']) ? (int) $doc['id'] : (isset($doc['products_id']) ? (int) $doc['products_id'] : 0);
            if ($pid <= 0) {
                continue;
            }
            $ids = $categoryMap[$pid] ?? [];
            if ($ids === []) {
                continue;
            }
            $hasIds = isset($doc['category_id']) && is_array($doc['category_id']) && $doc['category_id'] !== [];
            $hasCrumbs = isset($doc['category_breadcrumbs']) && is_array($doc['category_breadcrumbs']) && $doc['category_breadcrumbs'] !== [];
            if (!$hasIds) {
                $batch[$i]['category_id'] = $ids;
            }
            if (!$hasCrumbs) {
                $crumbs = [];
                foreach ($ids as $cid) {
                    $crumb = _numinix_seekmodo_indexer_build_breadcrumb((int) $cid, $nameMap, $parentMap);
                    if ($crumb !== '') {
                        $crumbs[] = $crumb;
                    }
                }
                if ($crumbs !== []) {
                    $batch[$i]['category_breadcrumbs'] = array_values(array_unique($crumbs));
                }
            }
        }
        return $batch;
    }
}

if (!function_exists('_numinix_seekmodo_indexer_resolve_language_id')) {
    /**
     * Resolve the language id to use for the breadcrumb name
     * lookup. Prefer the active Zen Cart session
     * (`languages_id`); fall back to the storefront's default
     * language constant; final fallback is 1 (every stock Zen
     * Cart install ships with `languages_id=1` for English).
     */
    function _numinix_seekmodo_indexer_resolve_language_id(): int
    {
        if (isset($_SESSION['languages_id']) && (int) $_SESSION['languages_id'] > 0) {
            return (int) $_SESSION['languages_id'];
        }
        if (defined('DEFAULT_LANGUAGE')) {
            $code = (string) constant('DEFAULT_LANGUAGE');
            if ($code !== '' && isset($GLOBALS['db']) && is_object($GLOBALS['db']) && defined('TABLE_LANGUAGES')) {
                try {
                    $row = $GLOBALS['db']->Execute(
                        'SELECT languages_id FROM ' . TABLE_LANGUAGES
                        . " WHERE code = '" . zen_db_input($code) . "' LIMIT 1"
                    );
                    if ($row && !$row->EOF) {
                        $id = (int) $row->fields['languages_id'];
                        if ($id > 0) {
                            return $id;
                        }
                    }
                } catch (\Throwable $e) {
                    // fall through
                }
            }
        }
        return 1;
    }
}

if (!function_exists('_numinix_seekmodo_indexer_collect_category_map')) {
    /**
     * Bulk lookup of (products_id => [categories_id, ...]) for the
     * supplied product id list. Single indexed query against
     * `products_to_categories`, capped at the batch size of the
     * caller so the IN-list stays sane.
     *
     * @param list<int> $productIds
     * @return array<int, list<int>>
     */
    function _numinix_seekmodo_indexer_collect_category_map(array $productIds): array
    {
        if ($productIds === [] || !isset($GLOBALS['db']) || !is_object($GLOBALS['db']) || !defined('TABLE_PRODUCTS_TO_CATEGORIES')) {
            return [];
        }
        $clean = [];
        foreach ($productIds as $pid) {
            $pid = (int) $pid;
            if ($pid > 0) {
                $clean[] = $pid;
            }
        }
        if ($clean === []) {
            return [];
        }
        $inList = implode(',', $clean);
        $map = [];
        try {
            $rows = $GLOBALS['db']->Execute(
                'SELECT products_id, categories_id FROM ' . TABLE_PRODUCTS_TO_CATEGORIES
                . ' WHERE products_id IN (' . $inList . ')'
            );
            if ($rows) {
                foreach ($rows as $r) {
                    $pid = (int) ($r['products_id'] ?? 0);
                    $cid = (int) ($r['categories_id'] ?? 0);
                    if ($pid <= 0 || $cid <= 0) {
                        continue;
                    }
                    if (!isset($map[$pid])) {
                        $map[$pid] = [];
                    }
                    $map[$pid][] = $cid;
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        // Dedupe + reindex per-product.
        foreach ($map as $pid => $ids) {
            $map[$pid] = array_values(array_unique($ids));
        }
        return $map;
    }
}

if (!function_exists('_numinix_seekmodo_indexer_category_tree')) {
    /**
     * Statically-cached snapshot of the full category tree at a
     * given language: `[categories_id => name, categories_id => parent_id]`.
     * Reads the whole `categories` + `categories_description` join in
     * a single query the first time it's hit per request. On Redline
     * (~300 categories) this is a single millisecond-scale query;
     * subsequent batches in the same cron run reuse the cache.
     *
     * @return array{0: array<int, string>, 1: array<int, int>}
     */
    function _numinix_seekmodo_indexer_category_tree(int $languageId): array
    {
        static $cacheByLang = [];
        if (isset($cacheByLang[$languageId])) {
            return $cacheByLang[$languageId];
        }
        $nameMap = [];
        $parentMap = [];
        if (
            !isset($GLOBALS['db']) || !is_object($GLOBALS['db'])
            || !defined('TABLE_CATEGORIES') || !defined('TABLE_CATEGORIES_DESCRIPTION')
        ) {
            return $cacheByLang[$languageId] = [$nameMap, $parentMap];
        }
        try {
            $rows = $GLOBALS['db']->Execute(
                'SELECT cd.categories_id, cd.categories_name, c.parent_id'
                . ' FROM ' . TABLE_CATEGORIES . ' c'
                . ' INNER JOIN ' . TABLE_CATEGORIES_DESCRIPTION . ' cd'
                . '   ON cd.categories_id = c.categories_id AND cd.language_id = ' . (int) $languageId
            );
            if ($rows) {
                foreach ($rows as $r) {
                    $cid = (int) ($r['categories_id'] ?? 0);
                    if ($cid <= 0) {
                        continue;
                    }
                    $nameMap[$cid] = (string) ($r['categories_name'] ?? '');
                    $parentMap[$cid] = (int) ($r['parent_id'] ?? 0);
                }
            }
        } catch (\Throwable $e) {
            // leave maps empty -- caller falls back to id-only enrich
        }
        return $cacheByLang[$languageId] = [$nameMap, $parentMap];
    }
}

if (!function_exists('_numinix_seekmodo_indexer_build_breadcrumb')) {
    /**
     * Walk a category id up to the root, returning the
     * " > "-joined breadcrumb path ("Lifts > Parts & Accessories
     * > Motorcycle Lift Wheel Vise"). Mirrors the format the
     * standalone push script (`numinix_seekmodo_push_catalog.php`)
     * emits so the gateway's per-doc walk treats both sources
     * uniformly. Guards against parent-id cycles (16-step cap)
     * and missing entries (returns '' rather than a half-walk).
     *
     * @param array<int, string> $nameMap
     * @param array<int, int>    $parentMap
     */
    function _numinix_seekmodo_indexer_build_breadcrumb(int $cid, array $nameMap, array $parentMap): string
    {
        if ($cid <= 0 || !isset($nameMap[$cid])) {
            return '';
        }
        $path = [];
        $cursor = $cid;
        $guard = 0;
        while ($cursor > 0 && $guard < 16) {
            if (!isset($nameMap[$cursor])) {
                break;
            }
            $name = trim($nameMap[$cursor]);
            if ($name === '') {
                break;
            }
            array_unshift($path, $name);
            $cursor = $parentMap[$cursor] ?? 0;
            $guard++;
        }
        if ($path === []) {
            return '';
        }
        return implode(' > ', $path);
    }
}

if (!function_exists('_numinix_seekmodo_config_json_queue_read')) {
    /**
     * @return array<int, int>
     */
    function _numinix_seekmodo_config_json_queue_read(string $key): array
    {
        if ($key === '' || !isset($GLOBALS['db']) || !is_object($GLOBALS['db'])) {
            return [];
        }
        try {
            $row = $GLOBALS['db']->Execute(
                "SELECT configuration_value FROM " . TABLE_CONFIGURATION
                . " WHERE configuration_key = '" . zen_db_input($key) . "' LIMIT 1"
            );
            if (!$row || $row->EOF) {
                return [];
            }
            $decoded = json_decode((string) $row->fields['configuration_value'], true);
            if (!is_array($decoded)) {
                return [];
            }
            return array_values(array_unique(array_map('intval', $decoded)));
        } catch (\Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('_numinix_seekmodo_config_json_queue_write')) {
    /**
     * @param int[] $ids
     */
    function _numinix_seekmodo_config_json_queue_write(string $key, array $ids): void
    {
        if ($key === '' || !isset($GLOBALS['db']) || !is_object($GLOBALS['db'])) {
            return;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        $encoded = json_encode($ids);
        if ($encoded === false) {
            return;
        }
        try {
            $row = $GLOBALS['db']->Execute(
                "SELECT configuration_key FROM " . TABLE_CONFIGURATION
                . " WHERE configuration_key = '" . zen_db_input($key) . "' LIMIT 1"
            );
            if ($row && !$row->EOF) {
                $GLOBALS['db']->Execute(
                    "UPDATE " . TABLE_CONFIGURATION
                    . " SET configuration_value = '" . zen_db_input($encoded) . "'"
                    . " WHERE configuration_key = '" . zen_db_input($key) . "'"
                );
            } else {
                $GLOBALS['db']->Execute(
                    "INSERT INTO " . TABLE_CONFIGURATION
                    . " (configuration_key, configuration_value, configuration_title,"
                    . "  configuration_description, configuration_group_id, sort_order, date_added)"
                    . " VALUES ('" . zen_db_input($key) . "', '" . zen_db_input($encoded) . "',"
                    . "  'Seekmodo config queue',"
                    . "  'Internal Seekmodo connector queue payload.',"
                    . "  6, 1, NOW())"
                );
            }
        } catch (\Throwable $e) {
            // best-effort
        }
    }
}

if (!function_exists('numinix_seekmodo_queue_catalog_dirty')) {
    /**
     * Queue a product id for delta upsert on the next indexer tick.
     */
    function numinix_seekmodo_queue_catalog_dirty(int $productsId): void
    {
        if ($productsId <= 0) {
            return;
        }
        $key = 'NUMINIX_SEEKMODO_DIRTY_PRODUCTS';
        $pending = _numinix_seekmodo_config_json_queue_read($key);
        $pending[] = $productsId;
        _numinix_seekmodo_config_json_queue_write($key, $pending);
    }
}

if (!function_exists('numinix_seekmodo_flush_dirty_products')) {
    /**
     * Upsert dirty product ids via the gateway index path.
     *
     * @return array{flushed:int, failed:int, remaining:int}
     */
    function numinix_seekmodo_flush_dirty_products(int $batchLimit = 50): array
    {
        $out = ['flushed' => 0, 'failed' => 0, 'remaining' => 0];
        if ($batchLimit <= 0) {
            $batchLimit = 50;
        }
        $key = 'NUMINIX_SEEKMODO_DIRTY_PRODUCTS';
        $pending = _numinix_seekmodo_config_json_queue_read($key);
        if ($pending === []) {
            return $out;
        }
        if (!function_exists('numinix_seekmodo_catalog_docs_for_ids')) {
            return $out;
        }
        $languageId = 1;
        if (isset($_SESSION['languages_id'])) {
            $languageId = (int) $_SESSION['languages_id'];
        }
        if ($languageId <= 0) {
            $languageId = 1;
        }
        $slice = array_slice($pending, 0, $batchLimit);
        $docs = numinix_seekmodo_catalog_docs_for_ids($slice, $languageId);
        $flushedIds = [];
        $failedIds = [];
        if ($docs !== [] && function_exists('numinix_seekmodo_index_chunked')) {
            $result = numinix_seekmodo_index_chunked($docs);
            if (!empty($result['ok'])) {
                $flushedIds = $slice;
                $out['flushed'] = count($docs);
            } else {
                $failedIds = $slice;
                $out['failed'] = count($slice);
            }
        } elseif ($docs === []) {
            // No buildable docs (empty name, etc.) — drop from queue.
            $flushedIds = $slice;
        } else {
            $failedIds = $slice;
            $out['failed'] = count($slice);
        }
        $remaining = $pending;
        foreach ($flushedIds as $id) {
            $remaining = array_values(array_diff($remaining, [$id]));
        }
        _numinix_seekmodo_config_json_queue_write($key, $remaining);
        $out['remaining'] = count($remaining);
        return $out;
    }
}

if (!function_exists('numinix_seekmodo_queue_catalog_delete')) {
    /**
     * Queue a product id for near-real-time eviction on the next
     * indexer tick. Persists in zen_configuration as a JSON array
     * under NUMINIX_SEEKMODO_PENDING_DELETES.
     */
    function numinix_seekmodo_queue_catalog_delete(int $productsId): void
    {
        if ($productsId <= 0 || !isset($GLOBALS['db']) || !is_object($GLOBALS['db'])) {
            return;
        }
        $key = 'NUMINIX_SEEKMODO_PENDING_DELETES';
        $pending = [];
        try {
            $row = $GLOBALS['db']->Execute(
                "SELECT configuration_value FROM " . TABLE_CONFIGURATION
                . " WHERE configuration_key = '" . zen_db_input($key) . "' LIMIT 1"
            );
            if ($row && !$row->EOF) {
                $decoded = json_decode((string) $row->fields['configuration_value'], true);
                if (is_array($decoded)) {
                    $pending = $decoded;
                }
            }
        } catch (\Throwable $e) {
            return;
        }
        $pending[] = $productsId;
        $pending = array_values(array_unique(array_map('intval', $pending)));
        $encoded = json_encode($pending);
        if ($encoded === false) {
            return;
        }
        try {
            if ($row && !$row->EOF) {
                $GLOBALS['db']->Execute(
                    "UPDATE " . TABLE_CONFIGURATION
                    . " SET configuration_value = '" . zen_db_input($encoded) . "'"
                    . " WHERE configuration_key = '" . zen_db_input($key) . "'"
                );
            } else {
                $GLOBALS['db']->Execute(
                    "INSERT INTO " . TABLE_CONFIGURATION
                    . " (configuration_key, configuration_value, configuration_title,"
                    . "  configuration_description, configuration_group_id, sort_order, date_added)"
                    . " VALUES ('" . zen_db_input($key) . "', '" . zen_db_input($encoded) . "',"
                    . "  'Seekmodo pending catalog deletes',"
                    . "  'Product ids queued for gateway eviction between full index runs.',"
                    . "  6, 1, NOW())"
                );
            }
        } catch (\Throwable $e) {
            return;
        }
    }
}

if (!function_exists('numinix_seekmodo_flush_pending_catalog_deletes')) {
    /**
     * Flush queued delete tombstones via /v1/index with
     * products_status=0 (gateway evicts immediately).
     *
     * @return array{flushed:int, failed:int}
     */
    function numinix_seekmodo_flush_pending_catalog_deletes(): array
    {
        $out = ['flushed' => 0, 'failed' => 0];
        if (!isset($GLOBALS['db']) || !is_object($GLOBALS['db'])) {
            return $out;
        }
        $key = 'NUMINIX_SEEKMODO_PENDING_DELETES';
        $pending = [];
        try {
            $row = $GLOBALS['db']->Execute(
                "SELECT configuration_value FROM " . TABLE_CONFIGURATION
                . " WHERE configuration_key = '" . zen_db_input($key) . "' LIMIT 1"
            );
            if (!$row || $row->EOF) {
                return $out;
            }
            $decoded = json_decode((string) $row->fields['configuration_value'], true);
            if (!is_array($decoded) || $decoded === []) {
                return $out;
            }
            $pending = array_values(array_unique(array_map('intval', $decoded)));
        } catch (\Throwable $e) {
            return $out;
        }
        if ($pending === [] || !function_exists('numinix_seekmodo_index_chunked')) {
            return $out;
        }
        $docs = [];
        foreach ($pending as $pid) {
            if ($pid <= 0) {
                continue;
            }
            $docs[] = [
                'id' => (string) $pid,
                'products_id' => $pid,
                'products_status' => 0,
                'name' => 'deleted',
            ];
        }
        if ($docs === []) {
            return $out;
        }
        $result = numinix_seekmodo_index_chunked($docs);
        if ($result['ok']) {
            $out['flushed'] = count($docs);
            try {
                $GLOBALS['db']->Execute(
                    "UPDATE " . TABLE_CONFIGURATION
                    . " SET configuration_value = '[]'"
                    . " WHERE configuration_key = '" . zen_db_input($key) . "'"
                );
            } catch (\Throwable $e) {
                // best-effort
            }
        } else {
            $out['failed'] = count($docs);
        }
        return $out;
    }
}

if (!function_exists('numinix_seekmodo_record_indexer_run')) {
    /**
     * Persist last indexer run metadata for gateway advisor signals.
     */
    function numinix_seekmodo_record_indexer_run(string $kind, int $durationS, int $docCount): void
    {
        if (!isset($GLOBALS['db']) || !is_object($GLOBALS['db'])) {
            return;
        }
        $now = time();
        $writes = [];
        if ($kind === 'full_push') {
            $writes = [
                'NUMINIX_SEEKMODO_LAST_FULL_PUSH_AT' => (string) $now,
                'NUMINIX_SEEKMODO_LAST_FULL_PUSH_DURATION_S' => (string) max(0, $durationS),
                'NUMINIX_SEEKMODO_LAST_FULL_PUSH_DOC_COUNT' => (string) max(0, $docCount),
            ];
        }
        foreach ($writes as $key => $value) {
            try {
                $GLOBALS['db']->Execute(
                    'UPDATE ' . TABLE_CONFIGURATION
                    . " SET configuration_value = '" . zen_db_input($value) . "', last_modified = NOW()"
                    . " WHERE configuration_key = '" . zen_db_input($key) . "'"
                );
            } catch (\Throwable $e) {
                // best-effort
            }
        }
        if (class_exists(\Numinix\Seekmodo\AutoPromoter::class)) {
            try {
                (new \Numinix\Seekmodo\AutoPromoter())->pushSnapshot('indexer_run');
            } catch (\Throwable $e) {
                // best-effort
            }
        }
    }
}

if (!function_exists('numinix_seekmodo_catalog_prune')) {
    /**
     * Post-full-index orphan eviction via gateway catalog.prune.
     *
     * @return array{deleted:int,scanned:int,has_more:bool,errors:array<int,mixed>}|null
     */
    function numinix_seekmodo_catalog_prune(int $cutoffEpoch, ?int $activeCount = null): ?array
    {
        if ($cutoffEpoch <= 0) {
            return null;
        }
        if (!function_exists('numinix_seekmodo_enabled') || !numinix_seekmodo_enabled()) {
            return null;
        }
        if (
            function_exists('numinix_seekmodo_is_locked_out')
            && numinix_seekmodo_is_locked_out()
        ) {
            return null;
        }
        if (numinix_seekmodo_mode() === 'shadow') {
            return null;
        }
        $client = function_exists('_numinix_seekmodo_client') ? _numinix_seekmodo_client() : null;
        if ($client === null || !method_exists($client, 'callTool')) {
            return null;
        }

        $totalDeleted = 0;
        $totalScanned = 0;
        $errors = [];
        $page = 1;
        $hasMore = false;

        do {
            $args = [
                'cutoff_epoch' => $cutoffEpoch,
                'page' => $page,
            ];
            if ($activeCount !== null && $activeCount >= 0) {
                $args['active_count'] = $activeCount;
            }
            $resp = $client->callTool('catalog.prune', $args);
            if (!is_array($resp)) {
                return null;
            }
            $totalDeleted += (int) ($resp['deleted'] ?? 0);
            $totalScanned += (int) ($resp['scanned'] ?? 0);
            if (!empty($resp['errors']) && is_array($resp['errors'])) {
                foreach ($resp['errors'] as $err) {
                    if (count($errors) < 20) {
                        $errors[] = $err;
                    }
                }
            }
            $hasMore = !empty($resp['has_more']);
            $nextPage = isset($resp['next_page']) ? (int) $resp['next_page'] : ($page + 1);
            $page = $hasMore ? max($page + 1, $nextPage) : $page;
        } while ($hasMore && $page <= 100);

        return [
            'deleted' => $totalDeleted,
            'scanned' => $totalScanned,
            'has_more' => $hasMore,
            'errors' => $errors,
        ];
    }
}
