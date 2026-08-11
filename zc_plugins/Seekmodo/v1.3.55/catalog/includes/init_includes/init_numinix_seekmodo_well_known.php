<?php
/**
 * Public-MCP discovery — `/.well-known/mcp.json` interceptor (v1.0.11,
 * Sprint 14 PR 4).
 *
 * Goal: make this storefront's anonymous (public-AI) MCP endpoint
 * machine-discoverable so a third-party AI agent (ChatGPT, Claude
 * Desktop, Cursor, etc.) acting on behalf of a shopper can find
 * `https://<tenant_id>.mcp.seekmodo.com/mcp` and call the `search`
 * tool without the shopper having to paste an URL.
 *
 * The standard discovery surface this script implements:
 *   GET  https://<storefront>/.well-known/mcp.json
 *
 * The response is a tiny JSON document advertising the gateway's
 * anonymous tenant subdomain plus a few capability hints. The shape
 * mirrors what mcp-discovery clients are converging on (the
 * `endpoints[]` array is the load-bearing field; the rest is human-
 * readable metadata for crawlers + linters).
 *
 * --- Why early-init? -----------------------------------------------
 *
 * Zen Cart's `application_top.php` resolves the request to a
 * `main_page` early in boot. By the time the default `index` main_page
 * runs, the framework has already booted sessions, hit the DB, loaded
 * templates, etc. A `.well-known/mcp.json` request is supposed to be
 * dirt-cheap, NEVER touch sessions, and NEVER trip a CSRF / login
 * redirect — so we intercept BEFORE the main_page resolver runs by
 * registering at autoLoadConfig[60], earlier than the connector's own
 * boot at [80] and well before any session / template init.
 *
 * If REQUEST_URI matches `/.well-known/mcp.json` we render JSON +
 * `exit;` — Zen Cart never sees the request as a page render.
 *
 * For any other request this script is a no-op (single `preg_match`
 * + early return), so the per-request cost on the hot path is sub-
 * microsecond.
 *
 * --- Routing prerequisite ------------------------------------------
 *
 * For this interceptor to fire, the web server must route
 * `/.well-known/mcp.json` to Zen Cart's `index.php`. The connector
 * doesn't (and can't) manage `.htaccess` on every storefront's
 * docroot, so:
 *
 *   - On stock Zen Cart with the standard `.htaccess`, hidden
 *     paths (`.well-known/`) are typically NOT rewritten to
 *     `index.php`. Apache will look for the file on disk, find
 *     nothing, and 404.
 *
 *   - To enable PHP-driven discovery, operators add a single
 *     `RewriteRule` to the catalog `.htaccess`:
 *
 *       RewriteRule ^\.well-known/mcp\.json$ index.php [L,QSA]
 *
 *     (or the nginx equivalent if running behind nginx as the
 *     edge server).
 *
 *   - As a fallback when the operator can't / won't edit
 *     `.htaccess`, the `<link rel="mcp-server">` head tag is still
 *     injected on every storefront page (see
 *     `NuminixSeekmodoMcpDiscoveryObserver`), which is the more
 *     widely-supported discovery primitive anyway.
 *
 * --- Failure posture -----------------------------------------------
 *
 * Every code path is wrapped in a try/catch. If anything goes wrong
 * the script returns silently without exiting — Zen Cart proceeds
 * with the normal index render, which 404s the path. Failure here
 * MUST NEVER 500 a storefront page.
 */

(static function (): void {
    try {
        // Cheap up-front filter: only fire when REQUEST_URI looks like
        // /.well-known/mcp.json. Anything else is a no-op return.
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ($uri === '') {
            return;
        }
        // Match the path component, ignoring query string + anchor.
        $path = (string) parse_url($uri, PHP_URL_PATH);
        if ($path === '') {
            $path = $uri;
        }
        if (preg_match('#(?:^|/)\.well-known/mcp\.json/?$#i', $path) !== 1) {
            return;
        }

        // Only GET / HEAD make sense for a discovery doc.
        $method = isset($_SERVER['REQUEST_METHOD'])
            ? strtoupper((string) $_SERVER['REQUEST_METHOD'])
            : 'GET';
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            http_response_code(405);
            header('Allow: GET, HEAD, OPTIONS');
            header('Content-Type: application/json; charset=utf-8');
            echo '{"error":"method_not_allowed"}';
            exit;
        }

        // CORS — the whole point of a discovery doc is cross-origin
        // crawling by AI clients. Open it up explicitly; the doc is
        // public information anyway.
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
        header('Access-Control-Allow-Headers: *');
        header('Vary: Origin');

        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        // Look up the tenant id + gateway base URL out of the Zen Cart
        // `configuration` table directly. Doing it via a raw PDO query
        // instead of going through the procedural client lets us
        // intercept this before the connector's main boot (slot 80)
        // has run, which is the whole point of running at slot 60.
        // Falling through to "no tenant configured" is fine — we
        // respond with a 404 in that case.
        $tenantId = '';
        $gatewayHost = 'mcp.seekmodo.com';

        $configResolved = false;
        if (defined('DB_SERVER') && defined('DB_DATABASE') && defined('DB_SERVER_USERNAME')) {
            $mysqli = @new mysqli(
                DB_SERVER,
                DB_SERVER_USERNAME,
                defined('DB_SERVER_PASSWORD') ? DB_SERVER_PASSWORD : '',
                DB_DATABASE
            );
            if ($mysqli instanceof mysqli && !$mysqli->connect_error) {
                $stmt = @$mysqli->prepare(
                    'SELECT configuration_key, configuration_value'
                    . ' FROM configuration'
                    . ' WHERE configuration_key IN (?, ?)'
                );
                if ($stmt instanceof mysqli_stmt) {
                    $a = 'NUMINIX_SEEKMODO_TENANT_ID';
                    $b = 'NUMINIX_SEEKMODO_URL';
                    $stmt->bind_param('ss', $a, $b);
                    if (@$stmt->execute()) {
                        $res = @$stmt->get_result();
                        if ($res instanceof mysqli_result) {
                            while ($row = $res->fetch_assoc()) {
                                if ($row['configuration_key'] === 'NUMINIX_SEEKMODO_TENANT_ID') {
                                    $tenantId = trim((string) $row['configuration_value']);
                                } elseif ($row['configuration_key'] === 'NUMINIX_SEEKMODO_URL') {
                                    $url = trim((string) $row['configuration_value']);
                                    if ($url !== '') {
                                        $h = (string) parse_url($url, PHP_URL_HOST);
                                        if ($h !== '') {
                                            $gatewayHost = strtolower($h);
                                        }
                                    }
                                }
                            }
                            $configResolved = true;
                        }
                    }
                    $stmt->close();
                }
                $mysqli->close();
            }
        }

        if (!$configResolved || $tenantId === '') {
            // Connector not paired (yet) — there is no public-MCP
            // endpoint to advertise. 404 is the truthful answer:
            // an AI agent that polled discovery will simply fall
            // back to the storefront's normal search UX.
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            // Short cache so a flaky AI client doesn't hammer us.
            header('Cache-Control: public, max-age=300');
            echo json_encode(
                ['error' => 'mcp_not_configured'],
                JSON_UNESCAPED_SLASHES
            );
            exit;
        }

        // Normalize the tenant id into a DNS label. The gateway's
        // anonymous resolver matches against the configuration table
        // (UUID-ish strings), but the public DNS pattern is
        // `<tenant_id>.mcp.seekmodo.com`. We pass the value through
        // unchanged — the gateway side is the authority on lookup,
        // and any operator who renamed `mcp.seekmodo.com` to a
        // private base URL still gets the right derived host because
        // we re-use `gatewayHost` as the suffix.
        //
        // The host we advertise is `<tenant_id>.<gatewayHost>` —
        // e.g. `redline-001.mcp.seekmodo.com` for the production
        // tenant. Mirror this in the head observer + the runbook.
        $anonymousHost = $tenantId . '.' . $gatewayHost;
        $anonymousEndpoint = 'https://' . $anonymousHost . '/mcp';

        $payload = [
            'name'        => 'Seekmodo product search',
            'description' => 'Read-only product catalog search for this storefront,'
                . ' provided by Seekmodo. Anonymous tier — no authentication,'
                . ' per-IP rate-limited.',
            'tenant_id'   => $tenantId,
            'endpoints'   => [
                [
                    'type'      => 'mcp',
                    'transport' => 'http',
                    'url'       => $anonymousEndpoint,
                    'auth'      => 'none',
                ],
            ],
            // Hints for crawlers — the gateway is the source of
            // truth, but advertising the most useful tool up front
            // lets a discovery client decide whether to talk at all.
            'tools'       => ['search'],
            'rate_limits' => [
                'per_ip_per_minute'        => 60,
                'per_tenant_ip_per_day'    => 500,
                'notes'                    => 'Server-side enforced by Seekmodo gateway;'
                    . ' a 429 with Retry-After is returned when the budget is exhausted.'
                    . ' Treat the limits above as approximate — the gateway is authoritative.',
            ],
            'docs'        => 'https://seekmodo.com/docs/mcp',
            'generator'   => 'numinix-seekmodo-zen-cart-connector/v1.0.11',
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            // Defensive: an encode failure on a static payload is
            // basically impossible, but be safe.
            $json = '{"error":"encoding_failed"}';
            http_response_code(500);
        }

        header('Content-Type: application/json; charset=utf-8');
        // Discovery docs change rarely — let the edge cache for an
        // hour and serve stale up to a day if upstream is sad.
        header('Cache-Control: public, max-age=3600, stale-while-revalidate=86400');
        header('X-Content-Type-Options: nosniff');
        // ETag lets clients short-circuit re-fetches.
        header('ETag: "' . substr(hash('sha256', $json), 0, 16) . '"');

        if ($method !== 'HEAD') {
            echo $json;
        }
        exit;
    } catch (\Throwable $e) {
        // Swallow everything — a discovery-endpoint failure must
        // NEVER 500 a storefront page. Falling through here lets
        // Zen Cart's normal index render fire (which will 404 the
        // path because no main_page matches).
        return;
    }
})();
