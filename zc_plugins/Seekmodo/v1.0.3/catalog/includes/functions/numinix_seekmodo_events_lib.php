<?php
/**
 * Click + impression mirror helpers for the Seekmodo connector.
 *
 * Called by the storefront's click beacon endpoint (typically
 * `ajax/ajax_search_log.php` on Zen Cart) right after the existing
 * `numinix_search_log_record_click()` call. The local row stays — it's
 * the belt-and-suspenders telemetry safety net during shadow and
 * remains a tamper-resistant local audit trail forever. What these
 * helpers add is a SECOND write to the gateway so analytics + LTR
 * training has a centralized event stream.
 *
 * Swap-point shape:
 *
 *     if (function_exists('numinix_seekmodo_enabled') && numinix_seekmodo_enabled()) {
 *         @include_once DIR_FS_CATALOG . 'includes/functions/numinix_seekmodo_events_lib.php';
 *         if (function_exists('numinix_seekmodo_mirror_click')) {
 *             numinix_seekmodo_mirror_click($keyword, $productsId, $position, $botReason,
 *                 ['surface' => $_POST['surface'] ?? 'results']);
 *         }
 *     }
 *
 * The `$opts` array (added in v1.0.3) is backward-compatible — existing
 * callers that don't pass it still work. `surface` is the most useful
 * key: 'results' (SERP click, default) or 'typeahead' (autocomplete
 * dropdown click). The gateway stores the value under
 * `numinix_telemetry_search_events.extra_json.surface`.
 *
 * Mode semantics:
 *   - shadow / enforce: mirror the event to /v1/events. Failures are
 *     swallowed (the beacon is fire-and-forget UX — we never block
 *     the shopper's navigation on a downstream write).
 *   - off:              numinix_seekmodo_enabled() is false, the swap-
 *                       point is a no-op.
 *
 * See `docs/CLICK_ATTRIBUTION.md` for surface conventions and how to
 * wire a typeahead-click beacon end-to-end.
 */

if (!function_exists('numinix_seekmodo_mirror_click')) {
    /**
     * Mirror a click event to the Seekmodo gateway.
     *
     * @param string $keyword     The search keyword the click was attached to.
     * @param int    $productsId  The clicked products_id.
     * @param int    $position    1-based rank in the result list.
     * @param string|null $botReason  Phase-0/Phase-3 bot classification, if any.
     * @param array  $opts        Optional metadata. Recognized keys:
     *   - 'surface' (string)   'results' (default) | 'typeahead' | custom tag.
     *   - 'extra'   (array)    Arbitrary key/value bag merged into the
     *                          gateway event's `extra` field. Useful for
     *                          A/B-test variant labels, source tags, etc.
     */
    function numinix_seekmodo_mirror_click(
        string $keyword,
        int $productsId,
        int $position,
        ?string $botReason,
        array $opts = []
    ): void {
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
        // when the web tier is behind Cloudflare. Falls back to
        // REMOTE_ADDR when the helper isn't loaded (boot order corner
        // case).
        $ip = function_exists('_numinix_seekmodo_client_ip')
            ? _numinix_seekmodo_client_ip()
            : (isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '');

        $surface = isset($opts['surface']) && $opts['surface'] !== ''
            ? (string)$opts['surface']
            : 'results';
        $extra = ['surface' => $surface];
        if (isset($opts['extra']) && is_array($opts['extra'])) {
            foreach ($opts['extra'] as $k => $v) {
                if (is_string($k) && $k !== 'surface') {
                    $extra[$k] = $v;
                }
            }
        }

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
            'extra' => $extra,
        ];
        // Fire-and-forget. numinix_seekmodo_event() already swallows
        // every error mode (auth, transport, circuit-open, non-2xx)
        // and returns null; we don't even check the return because
        // the local row was already written and that is enough.
        @numinix_seekmodo_event($event);
    }
}

if (!function_exists('numinix_seekmodo_mirror_typeahead_click')) {
    /**
     * Convenience wrapper that tags a click as `surface=typeahead`.
     * Storefront integrations that wire a beacon onto autocomplete-
     * dropdown clicks can call this without remembering the surface
     * key. Identical semantics to `numinix_seekmodo_mirror_click()`
     * otherwise.
     */
    function numinix_seekmodo_mirror_typeahead_click(
        string $keyword,
        int $productsId,
        int $position,
        ?string $botReason = null,
        array $opts = []
    ): void {
        $opts['surface'] = 'typeahead';
        numinix_seekmodo_mirror_click($keyword, $productsId, $position, $botReason, $opts);
    }
}

if (!function_exists('numinix_seekmodo_mirror_impression')) {
    /**
     * Mirror an impression (search render) event. Optional — call from
     * the search results template if/when we want richer training data.
     * Same fire-and-forget semantics as `numinix_seekmodo_mirror_click()`.
     *
     * @param array<int,int> $productIds   Products visible in this render
     *     (in rank order). Capped at 100 server-side.
     * @param array $opts   Optional metadata. Recognized keys:
     *   - 'surface' (string)   'results' (default) | 'typeahead' | custom.
     *   - 'extra'   (array)    Arbitrary key/value bag merged into the
     *                          gateway event's `extra` field.
     */
    function numinix_seekmodo_mirror_impression(
        string $keyword,
        array $productIds,
        array $opts = []
    ): void {
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

        $surface = isset($opts['surface']) && $opts['surface'] !== ''
            ? (string)$opts['surface']
            : 'results';
        $extra = ['surface' => $surface];
        if (isset($opts['extra']) && is_array($opts['extra'])) {
            foreach ($opts['extra'] as $k => $v) {
                if (is_string($k) && $k !== 'surface') {
                    $extra[$k] = $v;
                }
            }
        }
        // The shadow-flag from `_numinix_seekmodo_typeahead_record_impression`
        // is a plain bool — surface it via `extra` rather than a
        // top-level key so existing telemetry consumers don't need a
        // schema bump.
        if (isset($opts['shadow'])) {
            $extra['shadow'] = (bool)$opts['shadow'];
        }
        if (isset($opts['elapsed_ms'])) {
            $extra['elapsed_ms'] = (int)$opts['elapsed_ms'];
        }

        $event = [
            'kind' => 'impression',
            'keyword' => substr($keyword, 0, 255),
            'products_ids' => array_values(array_map('intval', array_slice($productIds, 0, 100))),
            'session_id' => $sessionToken,
            'ua' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : '',
            'ip' => $ip,
            'ts' => time(),
            'extra' => $extra,
        ];
        @numinix_seekmodo_event($event);
    }
}
