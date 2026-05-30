<?php
/**
 * Click-beacon mirror for the Seekmodo connector.
 *
 * Used by Redline's ajax/ajax_search_log.php right after the existing
 * numinix_search_log_record_click() call. The local row stays — it's
 * the belt-and-suspenders telemetry safety net during shadow and
 * remains valuable as a tamper-resistant local audit trail forever.
 * What this helper adds is a SECOND write to seek-db01 via the
 * gateway, so the Seekmodo side has the impressions/clicks needed
 * to feed the deferred LTR scorer.
 *
 * Swap-point shape (added to the bottom of ajax_search_log.php
 * after the existing INSERT):
 *
 *     if (function_exists('numinix_seekmodo_enabled') && numinix_seekmodo_enabled()) {
 *         @include_once DIR_FS_CATALOG . 'includes/functions/numinix_seekmodo_events_lib.php';
 *         if (function_exists('numinix_seekmodo_mirror_click')) {
 *             numinix_seekmodo_mirror_click($keyword, $productsId, $position, $botReason);
 *         }
 *     }
 *
 * Mode semantics:
 *   - shadow / enforce: mirror the click to /v1/events. Failures are
 *     swallowed (the beacon is fire-and-forget UX — we never block
 *     the shopper's navigation on a downstream write).
 *   - off:              numinix_seekmodo_enabled() is false, swap-point
 *                       is a no-op.
 */

if (!function_exists('numinix_seekmodo_mirror_click')) {
    /**
     * Mirror a click event to the Seekmodo gateway.
     *
     * @param string $keyword   The search keyword the click was attached to.
     * @param int $productsId   The clicked products_id.
     * @param int $position     1-based rank in the result list.
     * @param string|null $botReason  Phase-0/Phase-3 bot classification, if any.
     */
    function numinix_seekmodo_mirror_click(string $keyword, int $productsId, int $position, ?string $botReason): void
    {
        if (!function_exists('numinix_seekmodo_enabled') || !numinix_seekmodo_enabled()) {
            return;
        }
        if ($productsId <= 0 || $keyword === '') {
            return;
        }
        $sessionToken = function_exists('numinix_search_log_session_token')
            ? numinix_search_log_session_token()
            : '';
        // IP resolution: prefer the proxy-aware helper from search_lib
        // so we record the real shopper IP (not Cloudflare's edge IP)
        // when web03 is behind Cloudflare. Falls back to REMOTE_ADDR
        // when the helper isn't loaded (boot order corner case).
        $ip = function_exists('_numinix_seekmodo_client_ip')
            ? _numinix_seekmodo_client_ip()
            : (isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '');
        $event = [
            'kind' => 'click',
            'keyword' => substr($keyword, 0, 255),
            'products_id' => $productsId,
            'position' => max(0, $position),
            'session_id' => $sessionToken,
            'is_bot' => $botReason !== null,
            'bot_reason' => $botReason,
            'ua' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : '',
            'ip' => $ip,
            'ts' => time(),
        ];
        // Fire-and-forget. numinix_seekmodo_event() already swallows
        // every error mode (auth, transport, circuit-open, non-2xx)
        // and returns null; we don't even check the return because
        // the local row was already written and that is enough.
        @numinix_seekmodo_event($event);
    }
}

if (!function_exists('numinix_seekmodo_mirror_impression')) {
    /**
     * Mirror an impression (search render) event. Optional — call from
     * the search results template if/when we want richer training data.
     * Same fire-and-forget semantics as numinix_seekmodo_mirror_click().
     *
     * @param array<int,int> $productIds   Products visible in this render (in rank order).
     */
    function numinix_seekmodo_mirror_impression(string $keyword, array $productIds): void
    {
        if (!function_exists('numinix_seekmodo_enabled') || !numinix_seekmodo_enabled()) {
            return;
        }
        if ($keyword === '' || $productIds === []) {
            return;
        }
        $sessionToken = function_exists('numinix_search_log_session_token')
            ? numinix_search_log_session_token()
            : '';
        $ip = function_exists('_numinix_seekmodo_client_ip')
            ? _numinix_seekmodo_client_ip()
            : (isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '');
        $event = [
            'kind' => 'impression',
            'keyword' => substr($keyword, 0, 255),
            'products_ids' => array_values(array_map('intval', array_slice($productIds, 0, 100))),
            'session_id' => $sessionToken,
            'ua' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : '',
            'ip' => $ip,
            'ts' => time(),
        ];
        @numinix_seekmodo_event($event);
    }
}
