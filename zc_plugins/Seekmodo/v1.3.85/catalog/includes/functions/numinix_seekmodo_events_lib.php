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
 * ─────────────────────────────────────────────────────────────────────
 * v1.0.4 — filter-aware LTR plumbing
 * ─────────────────────────────────────────────────────────────────────
 *
 * Three new `$opts` keys, all optional:
 *
 *   - 'search_event_id' (int)    FK back to the originating gateway
 *                                 search row. Storefronts that store
 *                                 the id on render (e.g. hidden input
 *                                 + JS POSTs it back) should always
 *                                 send this. When absent, the helpers
 *                                 fall back to whatever
 *                                 `numinix_seekmodo_current_search_event()`
 *                                 returned during the current request /
 *                                 session — covers same-request paths.
 *
 *   - 'filter_by'       (string) The Typesense `filter_by` clause that
 *                                 was in effect when this click /
 *                                 impression happened. Same auto-
 *                                 fallback as `search_event_id`.
 *
 *   - 'filters'         (array)  The structured filter map. Same
 *                                 auto-fallback as `search_event_id`.
 *
 * Trainer joins `(search_event_id, product_id)` for clicks → search
 * row directly. Without these, every click event lands with no filter
 * context and the trainer has to approximate via session+keyword time-
 * window heuristics (Redline's pre-RED-1612 behaviour, the thing
 * RED-1612 explicitly fixed for the local LTR pipeline).
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

if (!function_exists('_numinix_seekmodo_event_filter_context')) {
    /**
     * Resolve `(search_event_id, filter_by, filters)` for an event.
     *
     * Caller-supplied `$opts` wins for any of the three. Otherwise
     * we consult `numinix_seekmodo_current_search_event()` (the
     * memo + session stash populated by the search call earlier in
     * the request / session).
     *
     * Returns a 3-tuple `[search_event_id, filter_by, filters]` where
     * each slot may be null / '' / [] when unknown.
     *
     * @param array<string, mixed> $opts
     * @return array{0:?int, 1:string, 2:array<string,mixed>}
     */
    function _numinix_seekmodo_event_filter_context(array $opts): array
    {
        $searchEventId = null;
        if (isset($opts['search_event_id']) && (int)$opts['search_event_id'] > 0) {
            $searchEventId = (int)$opts['search_event_id'];
        }
        $filterBy = isset($opts['filter_by']) ? (string)$opts['filter_by'] : '';
        $filters = isset($opts['filters']) && is_array($opts['filters']) ? $opts['filters'] : [];

        if ($searchEventId === null) {
            $kwHint = isset($opts['keyword']) ? trim((string)$opts['keyword']) : '';
            if ($kwHint !== '' && session_status() === PHP_SESSION_ACTIVE
                && isset($_SESSION['numinix_seekmodo_suggest_by_keyword'])
                && is_array($_SESSION['numinix_seekmodo_suggest_by_keyword'])) {
                $norm = function_exists('mb_strtolower')
                    ? mb_strtolower($kwHint, 'UTF-8')
                    : strtolower($kwHint);
                if (isset($_SESSION['numinix_seekmodo_suggest_by_keyword'][$norm])) {
                    $searchEventId = (int)$_SESSION['numinix_seekmodo_suggest_by_keyword'][$norm];
                }
            }
        }

        if ($searchEventId === null || $filterBy === '' || $filters === []) {
            $memo = function_exists('numinix_seekmodo_current_search_event')
                ? numinix_seekmodo_current_search_event()
                : null;
            if (is_array($memo)) {
                if ($searchEventId === null && isset($memo['search_event_id'])) {
                    $searchEventId = (int)$memo['search_event_id'];
                }
                if ($filterBy === '' && isset($memo['filter_by'])) {
                    $filterBy = (string)$memo['filter_by'];
                }
                if ($filters === [] && isset($memo['filters']) && is_array($memo['filters'])) {
                    $filters = $memo['filters'];
                }
            }
        }
        return [$searchEventId, $filterBy, $filters];
    }
}

if (!defined('NUMINIX_SEEKMODO_LTR_COOKIE_NAME')) {
    // First-party attribution cookie: last search_event_id + recent
    // product→seid map. Survives PHP session regenerate on login so
    // buy-now / checkout purchases still link to the SERP that
    // produced a click. Not gated behind personalization consent —
    // values are opaque gateway ids, not shopper PII.
    define('NUMINIX_SEEKMODO_LTR_COOKIE_NAME', 'sm_ltr');
}
if (!defined('NUMINIX_SEEKMODO_LTR_COOKIE_TTL_S')) {
    define('NUMINIX_SEEKMODO_LTR_COOKIE_TTL_S', 60 * 60 * 24 * 7);
}

if (!function_exists('_numinix_seekmodo_is_automation_ua')) {
    /**
     * Cron / CLI clients (wget recurring billing, curl probes) must
     * not emit graded conversion labels.
     */
    function _numinix_seekmodo_is_automation_ua(?string $ua = null): bool
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return true;
        }
        $ua = $ua !== null ? $ua : (isset($_SERVER['HTTP_USER_AGENT'])
            ? (string) $_SERVER['HTTP_USER_AGENT']
            : '');
        $ua = trim($ua);
        if ($ua === '') {
            return true;
        }
        return (bool) preg_match(
            '/^(wget|curl|python-requests|python-urllib|go-http-client|java\/|libwww-perl|httpie|scrapy)\b/i',
            $ua
        );
    }
}

if (!function_exists('_numinix_seekmodo_is_likely_add_to_cart_request')) {
    /**
     * Plain GET document views and passive cart restores must not emit
     * add_to_cart telemetry. Real shopper adds arrive via POST, AJAX,
     * or explicit ?products_id= / ?pid= cart actions.
     */
    function _numinix_seekmodo_is_likely_add_to_cart_request(): bool
    {
        if (_numinix_seekmodo_is_automation_ua()) {
            return false;
        }
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return true;
        }
        foreach (['products_id', 'pid'] as $key) {
            if (isset($_GET[$key]) && is_numeric($_GET[$key]) && (int) $_GET[$key] > 0) {
                return true;
            }
        }
        if (isset($_POST['products_id']) && is_numeric($_POST['products_id']) && (int) $_POST['products_id'] > 0) {
            return true;
        }
        $action = isset($_GET['action']) ? strtolower((string) $_GET['action']) : '';
        if ($action !== '' && in_array($action, ['add_product', 'buy_now', 'multiple_products_add_product'], true)) {
            return true;
        }
        if (isset($_GET['main_page']) && strtolower((string) $_GET['main_page']) === 'product_info') {
            return isset($_GET['products_id']) || isset($_GET['pid']);
        }

        return false;
    }
}

if (!function_exists('_numinix_seekmodo_atc_rate_store')) {
    /**
     * @return bool true when key was newly set inside TTL (claim won)
     */
    function _numinix_seekmodo_atc_rate_store(string $key, int $ttlSeconds): bool
    {
        if ($ttlSeconds <= 0) {
            return false;
        }
        if (function_exists('apcu_exists') && function_exists('apcu_store')) {
            if (apcu_exists($key)) {
                return false;
            }
            return apcu_store($key, 1, $ttlSeconds);
        }
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return true;
        }
        if (!isset($_SESSION['numinix_seekmodo_atc_rate']) || !is_array($_SESSION['numinix_seekmodo_atc_rate'])) {
            $_SESSION['numinix_seekmodo_atc_rate'] = [];
        }
        $now = time();
        $bucket = &$_SESSION['numinix_seekmodo_atc_rate'];
        if (isset($bucket[$key]) && (int) $bucket[$key] > $now) {
            return false;
        }
        $bucket[$key] = $now + $ttlSeconds;

        return true;
    }
}

if (!function_exists('_numinix_seekmodo_atc_rate_increment')) {
    /**
     * @return bool true when count stays strictly below $cap after increment
     */
    function _numinix_seekmodo_atc_rate_increment(string $key, int $ttlSeconds, int $cap): bool
    {
        if ($cap <= 0) {
            return false;
        }
        if (function_exists('apcu_inc')) {
            $ok = false;
            $count = apcu_inc($key, 1, $ok, $ttlSeconds);
            if (!$ok) {
                apcu_store($key, 1, $ttlSeconds);
                $count = 1;
            }

            return (int) $count <= $cap;
        }
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return true;
        }
        if (!isset($_SESSION['numinix_seekmodo_atc_counters']) || !is_array($_SESSION['numinix_seekmodo_atc_counters'])) {
            $_SESSION['numinix_seekmodo_atc_counters'] = [];
        }
        $now = time();
        $entry = $_SESSION['numinix_seekmodo_atc_counters'][$key] ?? null;
        if (!is_array($entry) || (int) ($entry['exp'] ?? 0) <= $now) {
            $_SESSION['numinix_seekmodo_atc_counters'][$key] = ['count' => 1, 'exp' => $now + $ttlSeconds];

            return true;
        }
        $next = (int) ($entry['count'] ?? 0) + 1;
        if ($next > $cap) {
            return false;
        }
        $_SESSION['numinix_seekmodo_atc_counters'][$key] = ['count' => $next, 'exp' => (int) $entry['exp']];

        return true;
    }
}

if (!function_exists('_numinix_seekmodo_claim_add_to_cart_telemetry')) {
    /**
     * Session/product dedupe + daily session cap + hourly IP cap so cart
     * restore loops and scrapers cannot flood gateway add_to_cart rows.
     */
    function _numinix_seekmodo_claim_add_to_cart_telemetry(int $productsId, string $sessionId, string $ip): bool
    {
        if ($productsId <= 0) {
            return false;
        }
        if ($sessionId === '') {
            $sessionId = 'anon';
        }
        $productKey = 'seekmodo_zc_atc_sp_' . md5($sessionId . ':' . $productsId);
        if (!_numinix_seekmodo_atc_rate_store($productKey, 900)) {
            return false;
        }
        $sessionKey = 'seekmodo_zc_atc_sess_' . md5($sessionId);
        if (!_numinix_seekmodo_atc_rate_increment($sessionKey, 86400, 30)) {
            return false;
        }
        $ip = trim($ip);
        if ($ip !== '') {
            $ipKey = 'seekmodo_zc_atc_ip_' . md5($ip);
            if (!_numinix_seekmodo_atc_rate_increment($ipKey, 3600, 120)) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('_numinix_seekmodo_ltr_cookie_read')) {
    /**
     * @return array{seid?:int, pids?:array<string,int>, ts?:int}
     */
    function _numinix_seekmodo_ltr_cookie_read(): array
    {
        $raw = isset($_COOKIE[NUMINIX_SEEKMODO_LTR_COOKIE_NAME])
            ? (string) $_COOKIE[NUMINIX_SEEKMODO_LTR_COOKIE_NAME]
            : '';
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('_numinix_seekmodo_ltr_cookie_write')) {
    /**
     * @param array{seid?:int, pids?:array<string,int>, ts?:int} $payload
     */
    function _numinix_seekmodo_ltr_cookie_write(array $payload): void
    {
        $payload['ts'] = time();
        if (isset($payload['pids']) && is_array($payload['pids']) && count($payload['pids']) > 24) {
            $payload['pids'] = array_slice($payload['pids'], -24, null, true);
        }
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            return;
        }
        $name = NUMINIX_SEEKMODO_LTR_COOKIE_NAME;
        $expire = time() + NUMINIX_SEEKMODO_LTR_COOKIE_TTL_S;
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
                && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
        if (PHP_VERSION_ID >= 70300) {
            setcookie($name, $json, [
                'expires' => $expire,
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            setcookie($name, $json, $expire, '/', '', $secure, true);
        }
        $_COOKIE[$name] = $json;
    }
}

if (!function_exists('numinix_seekmodo_ltr_remember_search_event_id')) {
    /** Persist last search_event_id for conversion attribution. */
    function numinix_seekmodo_ltr_remember_search_event_id(int $searchEventId): void
    {
        if ($searchEventId <= 0) {
            return;
        }
        $payload = _numinix_seekmodo_ltr_cookie_read();
        $payload['seid'] = $searchEventId;
        if (!isset($payload['pids']) || !is_array($payload['pids'])) {
            $payload['pids'] = [];
        }
        _numinix_seekmodo_ltr_cookie_write($payload);
    }
}

if (!function_exists('numinix_seekmodo_ltr_remember_product_click')) {
    /** Map products_id → search_event_id for buy-now / checkout linkage. */
    function numinix_seekmodo_ltr_remember_product_click(int $productsId, int $searchEventId): void
    {
        if ($productsId <= 0 || $searchEventId <= 0) {
            return;
        }
        $payload = _numinix_seekmodo_ltr_cookie_read();
        if (!isset($payload['pids']) || !is_array($payload['pids'])) {
            $payload['pids'] = [];
        }
        $payload['seid'] = $searchEventId;
        $payload['pids'][(string) $productsId] = $searchEventId;
        _numinix_seekmodo_ltr_cookie_write($payload);
    }
}

if (!function_exists('numinix_seekmodo_ltr_lookup_search_event_id')) {
    /** Cookie fallback when session memo is empty after login regenerate. */
    function numinix_seekmodo_ltr_lookup_search_event_id(int $productsId = 0): ?int
    {
        $payload = _numinix_seekmodo_ltr_cookie_read();
        if ($productsId > 0
            && isset($payload['pids'])
            && is_array($payload['pids'])
            && isset($payload['pids'][(string) $productsId])
            && (int) $payload['pids'][(string) $productsId] > 0
        ) {
            return (int) $payload['pids'][(string) $productsId];
        }
        if (isset($payload['seid']) && (int) $payload['seid'] > 0) {
            return (int) $payload['seid'];
        }
        return null;
    }
}

if (!function_exists('_numinix_seekmodo_event_session_id')) {
    /**
     * Session id for click / impression / conversion events.
     *
     * Must match `_numinix_seekmodo_session_id()` used on gateway search
     * calls. Click/purchase helpers previously used only
     * `numinix_search_log_session_token()`, which is often empty on the
     * first request — searches fell back to PHP session_id() while clicks
     * and purchases did not, breaking session-aware LTR linkage and
     * search-attributed revenue rollups.
     */
    function _numinix_seekmodo_event_session_id(): string
    {
        $memo = function_exists('numinix_seekmodo_current_search_event')
            ? numinix_seekmodo_current_search_event()
            : null;
        if (is_array($memo) && !empty($memo['session_id'])) {
            return substr((string) $memo['session_id'], 0, 64);
        }
        if (session_status() === PHP_SESSION_ACTIVE
            && isset($_SESSION['numinix_seekmodo_search_event']['session_id'])
            && (string) $_SESSION['numinix_seekmodo_search_event']['session_id'] !== '') {
            return substr((string) $_SESSION['numinix_seekmodo_search_event']['session_id'], 0, 64);
        }
        if (function_exists('_numinix_seekmodo_session_id')) {
            return _numinix_seekmodo_session_id();
        }
        if (function_exists('numinix_search_log_session_token')) {
            $t = (string) numinix_search_log_session_token();
            if ($t !== '') {
                return substr($t, 0, 64);
            }
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $sid = (string) session_id();
            if ($sid !== '') {
                return substr($sid, 0, 64);
            }
        }
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        return 'h_' . substr(hash('sha256', $ua . '|' . $ip), 0, 16);
    }
}

if (!function_exists('numinix_seekmodo_mirror_click')) {
    /**
     * Mirror a click event to the Seekmodo gateway.
     *
     * @param string $keyword     The search keyword the click was attached to.
     * @param int    $productsId  The clicked products_id.
     * @param int    $position    1-based rank in the result list.
     * @param string|null $botReason  Phase-0/Phase-3 bot classification, if any.
     * @param array  $opts        Optional metadata. Recognized keys:
     *   - 'surface'         (string) 'results' (default) | 'typeahead' | custom tag.
     *   - 'search_event_id' (int)    FK to the originating gateway search row.
     *   - 'filter_by'       (string) Typesense filter_by clause at click time.
     *   - 'filters'         (array)  Structured filter map at click time.
     *   - 'extra'           (array)  Free-form bag merged into gateway `extra`.
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
        // Production-only analytics / LTR: satellites may read search
        // but must not post click/impression/conversion events.
        if (
            function_exists('numinix_seekmodo_can_events')
            && !numinix_seekmodo_can_events()
        ) {
            return;
        }
        if (
            function_exists('numinix_seekmodo_is_locked_out')
            && numinix_seekmodo_is_locked_out()
        ) {
            return;
        }
        if ($productsId <= 0 || $keyword === '') {
            return;
        }
        if (_numinix_seekmodo_is_automation_ua()) {
            return;
        }
        $sessionToken = _numinix_seekmodo_event_session_id();
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

        [$searchEventId, $filterBy, $filters] = _numinix_seekmodo_event_filter_context(
            array_merge($opts, ['keyword' => $keyword])
        );
        if ($searchEventId !== null && (int) $searchEventId > 0) {
            numinix_seekmodo_ltr_remember_product_click($productsId, (int) $searchEventId);
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
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $event['referer'] = substr((string)$_SERVER['HTTP_REFERER'], 0, 255);
        }
        if ($searchEventId !== null) {
            $event['search_event_id'] = $searchEventId;
        }
        if ($filterBy !== '') {
            $event['filter_by'] = $filterBy;
        }
        if ($filters !== []) {
            $event['filters'] = $filters;
        }
        // v1.0.16 — per-shopper personalization envelope. EventsTool
        // mirrors the resolved shopper context into the click row's
        // extra_json so the trainer can join the click signal back
        // to a shopper identity at training time.
        if (function_exists('numinix_seekmodo_shopper_context')) {
            $event['shopper_context'] = numinix_seekmodo_shopper_context();
        }
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
     *   - 'surface'         (string) 'results' (default) | 'typeahead' | custom.
     *   - 'search_event_id' (int)    FK to the originating gateway search row.
     *   - 'filter_by'       (string) Typesense filter_by clause at render time.
     *   - 'filters'         (array)  Structured filter map at render time.
     *   - 'extra'           (array)  Free-form bag merged into gateway `extra`.
     */
    function numinix_seekmodo_mirror_impression(
        string $keyword,
        array $productIds,
        array $opts = []
    ): void {
        if (!function_exists('numinix_seekmodo_enabled') || !numinix_seekmodo_enabled()) {
            return;
        }
        if (
            function_exists('numinix_seekmodo_can_events')
            && !numinix_seekmodo_can_events()
        ) {
            return;
        }
        if ($keyword === '' || $productIds === []) {
            return;
        }
        $sessionToken = _numinix_seekmodo_event_session_id();
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
        if (isset($opts['shadow'])) {
            $extra['shadow'] = (bool)$opts['shadow'];
        }
        if (isset($opts['elapsed_ms'])) {
            $extra['elapsed_ms'] = (int)$opts['elapsed_ms'];
        }

        [$searchEventId, $filterBy, $filters] = _numinix_seekmodo_event_filter_context($opts);

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
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $event['referer'] = substr((string)$_SERVER['HTTP_REFERER'], 0, 255);
        }
        if ($searchEventId !== null) {
            $event['search_event_id'] = $searchEventId;
        }
        if ($filterBy !== '') {
            $event['filter_by'] = $filterBy;
        }
        if ($filters !== []) {
            $event['filters'] = $filters;
        }
        // v1.0.16 — per-shopper personalization envelope. See
        // numinix_seekmodo_mirror_click() for the contract.
        if (function_exists('numinix_seekmodo_shopper_context')) {
            $event['shopper_context'] = numinix_seekmodo_shopper_context();
        }
        @numinix_seekmodo_event($event);
    }
}

if (!function_exists('numinix_seekmodo_mirror_serp_impression')) {
    /**
     * Log a SERP-surface impression for the current request's
     * gateway search. Canonical call for "the storefront just
     * rendered N products for this query" — handles the
     * `search_event_id` + `filter_by` + `filters` + `surface='results'`
     * defaults from `numinix_seekmodo_current_search_event()` so the
     * caller only needs to pass the keyword and the visible
     * products_ids[].
     *
     * Returns immediately when there's no current gateway search
     * (e.g. the storefront's native LIKE path ran instead, or the
     * gateway returned null in shadow mode). That way the storefront
     * template can call this unconditionally.
     *
     * Use this from `includes/modules/pages/search_result/header_php.php`
     * right after the result page has built its `$products` array, or
     * any equivalent storefront integration point.
     *
     * @param array<int, int> $productIds  Products visible on this render.
     * @param array $opts                   Same as mirror_impression $opts.
     */
    function numinix_seekmodo_mirror_serp_impression(
        string $keyword,
        array $productIds,
        array $opts = []
    ): void {
        $current = function_exists('numinix_seekmodo_current_search_event')
            ? numinix_seekmodo_current_search_event()
            : null;
        if (!is_array($current)) {
            // No gateway search ran for this request — nothing to
            // attribute the impression to. Native-fallback paths
            // fall here in `enforce` mode after a transient gateway
            // failure; we don't want to write an impression that
            // can't be FK-joined to anything.
            return;
        }
        $opts['surface'] = $opts['surface'] ?? 'results';
        if (!isset($opts['search_event_id']) && isset($current['search_event_id'])) {
            $opts['search_event_id'] = (int)$current['search_event_id'];
        }
        if (!isset($opts['filter_by']) && isset($current['filter_by'])) {
            $opts['filter_by'] = (string)$current['filter_by'];
        }
        if (!isset($opts['filters']) && isset($current['filters']) && is_array($current['filters'])) {
            $opts['filters'] = $current['filters'];
        }
        numinix_seekmodo_mirror_impression($keyword, $productIds, $opts);
    }
}

if (!function_exists('numinix_seekmodo_mirror_conversion')) {
    /**
     * Internal helper for shipping graded conversion events
     * (add_to_cart, purchase) up to the gateway. Connector v1.0.4
     * (LTR-P6).
     *
     * Mirrors the click-beacon contract so the gateway joins
     * conversion events onto the originating search row via
     * `search_event_id` the same way it joins clicks. Conversion
     * events carry no `position` (the shopper may have clicked
     * the product days ago and converted from a cart page later)
     * — but DO carry `keyword` + `search_event_id` so the
     * trainer can attribute the upgrade label to the right SERP.
     *
     * @param string $kind       'add_to_cart' | 'purchase'
     * @param int    $productsId Affected product.
     * @param array  $opts       Recognized keys:
     *   - 'qty'             (int)    optional units; default 1.
     *   - 'price'           (float)  optional line value (currency neutral).
     *   - 'keyword'         (string) original SERP keyword if known. When
     *                                omitted, falls back to the
     *                                current search event's keyword (if
     *                                the user is still on the SERP).
     *   - 'search_event_id' (int)    explicit FK; falls back to current
     *                                search event.
     *   - 'filter_by'       (string) filter context if known.
     *   - 'filters'         (array)  structured filter map.
     *   - 'extra'           (array)  free-form bag merged into extra_json.
     */
    function numinix_seekmodo_mirror_conversion(
        string $kind,
        int $productsId,
        array $opts = []
    ): void {
        if (!function_exists('numinix_seekmodo_enabled') || !numinix_seekmodo_enabled()) {
            return;
        }
        if (
            function_exists('numinix_seekmodo_can_events')
            && !numinix_seekmodo_can_events()
        ) {
            return;
        }
        if ($kind !== 'add_to_cart' && $kind !== 'purchase') {
            return;
        }
        if ($productsId <= 0) {
            return;
        }
        // PayPal recurring / store-credit crons hit cart+checkout via
        // wget and must not train LTR or inflate conversion funnels.
        if (_numinix_seekmodo_is_automation_ua()) {
            return;
        }
        if ($kind === 'add_to_cart') {
            if (!_numinix_seekmodo_is_likely_add_to_cart_request()) {
                return;
            }
            $sessionToken = _numinix_seekmodo_event_session_id();
            $ip = function_exists('_numinix_seekmodo_client_ip')
                ? _numinix_seekmodo_client_ip()
                : (isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '');
            if (!_numinix_seekmodo_claim_add_to_cart_telemetry($productsId, $sessionToken, $ip)) {
                return;
            }
        }
        // Backfill keyword + search_event_id from the request's
        // current search context when the caller didn't provide
        // them. Cart-page add-to-cart calls usually know neither;
        // SERP page-level add-to-cart buttons typically know both.
        $current = function_exists('numinix_seekmodo_current_search_event')
            ? numinix_seekmodo_current_search_event()
            : null;
        $keyword = isset($opts['keyword']) ? (string)$opts['keyword'] : '';
        if ($keyword === '' && is_array($current) && isset($current['keyword'])) {
            $keyword = (string)$current['keyword'];
        }
        // Cart-page and checkout hooks usually lack an in-request
        // keyword. The gateway attributes search_event_id via the
        // shopper session (7-day last-touch) and backfills keyword
        // from the linked search row — so we still mirror the event.
        if (!isset($opts['search_event_id']) && is_array($current) && isset($current['search_event_id'])) {
            $opts['search_event_id'] = (int)$current['search_event_id'];
        }
        // sm_ltr cookie survives PHP session regenerate on login.
        if (!isset($opts['search_event_id']) || (int) $opts['search_event_id'] <= 0) {
            $fromCookie = numinix_seekmodo_ltr_lookup_search_event_id($productsId);
            if ($fromCookie !== null) {
                $opts['search_event_id'] = $fromCookie;
            }
        }
        if (!isset($opts['filter_by']) && is_array($current) && isset($current['filter_by'])) {
            $opts['filter_by'] = (string)$current['filter_by'];
        }
        if (!isset($opts['filters']) && is_array($current) && isset($current['filters'])) {
            $opts['filters'] = $current['filters'];
        }

        $sessionToken = _numinix_seekmodo_event_session_id();
        $ip = function_exists('_numinix_seekmodo_client_ip')
            ? _numinix_seekmodo_client_ip()
            : (isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '');

        $extra = [];
        if (isset($opts['extra']) && is_array($opts['extra'])) {
            $extra = $opts['extra'];
        }
        if (isset($opts['qty']) && (int)$opts['qty'] > 0) {
            $extra['qty'] = (int)$opts['qty'];
        }
        if (isset($opts['price'])) {
            $extra['price'] = (float)$opts['price'];
        }

        [$searchEventId, $filterBy, $filters] = _numinix_seekmodo_event_filter_context($opts);

        $event = [
            'kind' => $kind,
            'keyword' => substr($keyword, 0, 255),
            'products_id' => $productsId,
            'session_id' => $sessionToken,
            'is_bot' => false,
            'ua' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : '',
            'ip' => $ip,
            'ts' => time(),
            'extra' => $extra,
        ];
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $event['referer'] = substr((string)$_SERVER['HTTP_REFERER'], 0, 255);
        }
        if ($searchEventId !== null) {
            $event['search_event_id'] = $searchEventId;
        }
        if ($filterBy !== '') {
            $event['filter_by'] = $filterBy;
        }
        if ($filters !== []) {
            $event['filters'] = $filters;
        }
        // v1.0.16 — per-shopper personalization envelope. Conversions
        // are the strongest training signal, so it's especially
        // important the gateway can attribute them to a returning
        // shopper id when one is resolvable.
        if (function_exists('numinix_seekmodo_shopper_context')) {
            $event['shopper_context'] = numinix_seekmodo_shopper_context();
        }
        @numinix_seekmodo_event($event);
    }
}

if (!function_exists('numinix_seekmodo_mirror_add_to_cart')) {
    /**
     * Mirror an add-to-cart event (LTR-P6). The trainer treats
     * AtC as a 2x label over a click — strong signal that the
     * shopper liked what they saw enough to pay friction cost.
     *
     * Call from the host platform's add-to-cart hook (Zen Cart:
     * `NOTIFY_CART_ADD_CART_END`). The `keyword` argument is the
     * search term the shopper used to find the product — if
     * unknown, the helper falls back to the current request's
     * gateway search event.
     */
    function numinix_seekmodo_mirror_add_to_cart(
        int $productsId,
        array $opts = []
    ): void {
        numinix_seekmodo_mirror_conversion('add_to_cart', $productsId, $opts);
    }
}

if (!function_exists('numinix_seekmodo_mirror_purchase')) {
    /**
     * Mirror a purchase event (LTR-P6). The trainer treats a
     * purchase as a 4x label over a click — the strongest signal
     * we measure.
     *
     * Call from the platform's order-completed hook (Zen Cart:
     * `NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_CREATE_ADD_PRODUCTS`),
     * one call per purchased line item. The `keyword` argument
     * is the search term that originally surfaced this product —
     * if you don't track it past add-to-cart, omit the arg and
     * we'll skip the LTR signal cleanly.
     */
    function numinix_seekmodo_mirror_purchase(
        int $productsId,
        array $opts = []
    ): void {
        numinix_seekmodo_mirror_conversion('purchase', $productsId, $opts);
    }
}
