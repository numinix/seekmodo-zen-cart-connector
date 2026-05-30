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
        $mode = numinix_seekmodo_mode();
        if ($mode === 'off') {
            return null;
        }

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
        } catch (\Throwable) {
            return;
        }
        if (function_exists('apcu_store')) {
            apcu_store($key, time(), 600);
        }
    }
}
