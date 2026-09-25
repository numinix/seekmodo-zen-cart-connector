<?php
/**
 * Public-MCP discovery — `<link rel="mcp-server">` head injector
 * (v1.0.11, Sprint 14 PR 4).
 *
 * Companion to `init_numinix_seekmodo_well_known.php`. That init file
 * answers `/.well-known/mcp.json`, which is the formal MCP discovery
 * surface but requires a working `.htaccess` rewrite to reach PHP on
 * a stock Zen Cart install. The head-injection arm here is the
 * always-on fallback: every storefront page emits a tiny
 *
 *   <link rel="mcp-server"
 *         href="https://<tenant_id>.mcp.seekmodo.com/mcp"
 *         type="application/json">
 *
 * tag inside `<head>` so any crawler that fetches an HTML page on
 * the storefront sees the MCP endpoint without needing a dedicated
 * discovery request. Web standards convention (HTML5 + `<link rel>`
 * IANA registry) — the same pattern used by `rel="manifest"`,
 * `rel="webmention"`, `rel="alternate"`.
 *
 * We also emit a redundant `<meta name="mcp-server" content="…">` so
 * crawlers that only inspect `<meta>` (e.g. some lightweight
 * scrapers) pick it up too.
 *
 * --- When this fires -----------------------------------------------
 *
 * Hook: `NOTIFY_HTML_HEAD_END`. Zen Cart fires this from
 * `tpl_main_page.php` (the default page template) inside `<head>`,
 * after the storefront's own CSS / JS / favicon `<link>` tags.
 * Output is captured via `output_buffering` style — the notifier
 * doesn't pass a buffer by reference, so we `echo` directly inline.
 *
 * Conditions for emission (any false → no tag):
 *   1. `numinix_seekmodo_enabled()` returns true (connector paired,
 *      mode != off, locked-domain matches). When the connector is
 *      effectively off there is no useful endpoint to advertise.
 *   2. A tenant id is resolvable from the configuration table.
 *
 * --- Failure posture -----------------------------------------------
 *
 * The whole `update()` body is wrapped in try/catch. Any exception
 * is swallowed; the page renders without the discovery tag. This
 * observer MUST NEVER 500 a storefront page — same posture as the
 * sibling NuminixSeekmodoObserver.
 *
 * --- Why not a single observer file? -------------------------------
 *
 * `NuminixSeekmodoObserver` is the hot-path SQL-rewriter / event-
 * mirror — it touches search, click, cart, purchase notifiers and is
 * the load-bearing integration. Discovery is a wholly separate
 * concern (a `<head>` decoration) with a different failure-domain
 * (a missing link tag is invisible; a broken SQL rewrite would
 * blank the SERP). Splitting them lets discovery be enabled /
 * disabled / debugged independently of the search hot path.
 */

class NuminixSeekmodoMcpDiscoveryObserver extends \base
{
    /**
     * Per-request memo of the resolved tag HTML — built on first
     * invocation, reused on every subsequent NOTIFY_HTML_HEAD_END
     * fire in the same request (the notifier can fire multiple
     * times in some template stacks).
     */
    private ?string $cachedHeadHtml = null;

    /**
     * Cache the connector-enabled / tenant-id resolution outcome so
     * we don't re-query for every notifier hit. Three states:
     *   null  = not yet resolved
     *   false = resolution failed / connector disabled
     *   array = resolved tenant context
     *
     * @var array{tenant_id: string, gateway_host: string}|false|null
     */
    private $cachedContext = null;

    public function __construct()
    {
        $this->attach($this, ['NOTIFY_HTML_HEAD_END']);
    }

    /**
     * @param object $class    Notifier emitter (ignored).
     * @param string $eventID  Notifier event name.
     */
    public function update(&$class, $eventID, &$param1 = null, &$param2 = null, &$param3 = null)
    {
        if ($eventID !== 'NOTIFY_HTML_HEAD_END') {
            return;
        }
        try {
            $html = $this->buildHeadHtml();
            if ($html !== '') {
                echo $html;
            }
            // Sprint 14 PR 4 (v1.0.12) — opportunistic refresh of
            // the static `.well-known/mcp.json` file. Gated by an
            // APCu "wrote-recently" marker so disk I/O happens at
            // most once per hour per FPM worker. Without APCu we
            // skip entirely — the file gets re-dropped on the next
            // Pair / snapshot poll instead, and the head-tag arm
            // here keeps working regardless.
            $this->maybeRefreshWellKnown();
        } catch (\Throwable $e) {
            // Discovery is best-effort; never let a head-injection
            // problem 500 the page render.
            return;
        }
    }

    /**
     * Drop the static discovery file on disk if we haven't done so
     * within the last hour (per FPM worker, tracked via APCu). Cheap
     * to call repeatedly. Returns silently on any failure.
     */
    private function maybeRefreshWellKnown(): void
    {
        if (!function_exists('apcu_fetch')) {
            // Without APCu, fall back to a 1-in-100 sampling so a
            // busy storefront doesn't hammer the disk on every
            // request but a paired storefront still self-heals
            // within a few page views.
            if (random_int(1, 100) !== 1) {
                return;
            }
        } else {
            $ok = false;
            $marker = apcu_fetch('seekmodo:wellknown:wrote_at', $ok);
            if ($ok && is_int($marker) && (time() - $marker) < 3600) {
                return;
            }
        }
        $ctx = $this->resolveContext();
        if ($ctx === false) {
            return;
        }
        if (!class_exists('Numinix\\Seekmodo\\WellKnownWriter')) {
            return;
        }
        \Numinix\Seekmodo\WellKnownWriter::writeFor(
            $ctx['tenant_id'],
            $ctx['gateway_host']
        );
        if (function_exists('apcu_store')) {
            apcu_store('seekmodo:wellknown:wrote_at', time(), 3600);
        }
    }

    /**
     * Build (or return cached) discovery HTML. Empty string when
     * the connector isn't in a state where there's a useful endpoint
     * to advertise.
     */
    private function buildHeadHtml(): string
    {
        if ($this->cachedHeadHtml !== null) {
            return $this->cachedHeadHtml;
        }
        $ctx = $this->resolveContext();
        if ($ctx === false) {
            $this->cachedHeadHtml = '';
            return $this->cachedHeadHtml;
        }
        $anonymousHost = $ctx['tenant_id'] . '.' . $ctx['gateway_host'];
        $anonymousEndpoint = 'https://' . $anonymousHost . '/mcp';
        $hrefAttr = htmlspecialchars($anonymousEndpoint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $titleAttr = 'Seekmodo product search';
        $titleAttrEsc = htmlspecialchars($titleAttr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // Two tags so both `<link rel>` crawlers (the standards-
        // following majority) and `<meta name>` crawlers (a long
        // tail of older scrapers) find us. The `<meta>` tag's
        // content is the bare endpoint URL; the `<link>` tag also
        // advertises a discovery-doc fallback URL.
        $wellKnownUrl = $this->buildWellKnownUrl();
        $wellKnownAttr = $wellKnownUrl === ''
            ? ''
            : (' data-discovery="' . htmlspecialchars($wellKnownUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"');
        $this->cachedHeadHtml = "\n"
            . '<link rel="mcp-server"'
            . ' href="' . $hrefAttr . '"'
            . ' type="application/json"'
            . ' title="' . $titleAttrEsc . '"'
            . $wellKnownAttr
            . '>' . "\n"
            . '<meta name="mcp-server" content="' . $hrefAttr . '">' . "\n";
        return $this->cachedHeadHtml;
    }

    /**
     * Resolve tenant id + gateway host. Returns false when the
     * connector is disabled / unpaired / domain-locked-out.
     *
     * @return array{tenant_id: string, gateway_host: string}|false
     */
    private function resolveContext()
    {
        if ($this->cachedContext !== null) {
            return $this->cachedContext;
        }
        // The connector's main boot (slot 80) defines this helper. If
        // it isn't present we're running too early or the plugin
        // tree is incomplete — either way, don't advertise.
        if (!function_exists('numinix_seekmodo_enabled')) {
            $this->cachedContext = false;
            return $this->cachedContext;
        }
        if (!numinix_seekmodo_enabled()) {
            // Off / domain-locked / unpaired — nothing useful to
            // advertise.
            $this->cachedContext = false;
            return $this->cachedContext;
        }
        $tenantId = defined('NUMINIX_SEEKMODO_TENANT_ID')
            ? trim((string) constant('NUMINIX_SEEKMODO_TENANT_ID'))
            : '';
        if ($tenantId === '') {
            $this->cachedContext = false;
            return $this->cachedContext;
        }
        $gatewayHost = 'mcp.seekmodo.com';
        if (defined('NUMINIX_SEEKMODO_URL')) {
            $url = trim((string) constant('NUMINIX_SEEKMODO_URL'));
            if ($url !== '') {
                $h = (string) parse_url($url, PHP_URL_HOST);
                if ($h !== '') {
                    $gatewayHost = strtolower($h);
                }
            }
        }
        $this->cachedContext = [
            'tenant_id'    => $tenantId,
            'gateway_host' => $gatewayHost,
        ];
        return $this->cachedContext;
    }

    /**
     * Compute the absolute URL of `/.well-known/mcp.json` on this
     * storefront. Used as a `data-discovery` attribute hint on the
     * `<link>` tag for crawlers that prefer the formal discovery doc
     * over the inline href. Returns '' when we can't safely derive
     * an origin (CLI context, missing HTTP_HOST).
     */
    private function buildWellKnownUrl(): string
    {
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        if ($host === '') {
            return '';
        }
        $scheme = 'http';
        if (
            (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
                && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        ) {
            $scheme = 'https';
        }
        return $scheme . '://' . $host . '/.well-known/mcp.json';
    }
}
