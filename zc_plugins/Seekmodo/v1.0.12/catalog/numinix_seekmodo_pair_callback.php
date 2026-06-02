<?php
/**
 * Storefront-side callback for the M5 plugin pairing flow.
 *
 * seekmodo.com POSTs a JSON body of the shape `{token, install_token}`
 * to this URL after the merchant confirms the pairing on
 * seekmodo.com/connect. We verify the JWT against seekmodo.com's
 * published JWKS, cross-check the X-Seekmodo-Install-Token header,
 * and on success write `NUMINIX_SEEKMODO_TENANT_ID` +
 * `NUMINIX_SEEKMODO_SHARED_SECRET` into the configuration table.
 *
 * The response body includes a `platform` field so the marketing-site
 * can stamp `tenants.connector_platform = 'zen-cart'` after a
 * successful pair. CORS is not required — this is a server-to-server
 * POST; the browser is on seekmodo.com when this fires.
 */

declare(strict_types=1);

require 'includes/application_top.php';

use Numinix\Seekmodo\Pairing;
use Numinix\Seekmodo\WellKnownWriter;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
    return;
}

// Determine where to fetch JWKS from. Default to the public marketing
// site. The configured gateway base is mcp.<root> in production; we
// strip the `mcp.` prefix to get back to the apex.
$gatewayBase = defined('NUMINIX_SEEKMODO_URL') ? NUMINIX_SEEKMODO_URL : 'https://mcp.seekmodo.com';
$jwksHost = preg_replace('~^https?://(?:mcp\.|admin\.)?~i', 'https://', rtrim((string)$gatewayBase, '/'));
$jwksHost = preg_replace('~/v1.*$~', '', (string)$jwksHost);
$jwksUrl = rtrim((string)$jwksHost, '/') . '/.well-known/jwks.json';

try {
    $claims = Pairing::verify_pair_callback($jwksUrl);
    Pairing::persist_credentials($claims);
} catch (\Throwable $e) {
    error_log('[seekmodo-pair-callback] ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    return;
}

// Sprint 14 PR 4 (v1.0.12) — drop the static `.well-known/mcp.json`
// discovery file on disk immediately, so AI agents can find the
// gateway endpoint the moment Connect finishes. Best-effort; pair
// success is the load-bearing outcome of this callback so a writer
// failure must not 400 the pair.
$wellKnownResults = [];
try {
    $gatewayHost = '';
    if (!empty($claims['mcp_url'])) {
        $h = (string) parse_url((string) $claims['mcp_url'], PHP_URL_HOST);
        if ($h !== '') {
            $gatewayHost = $h;
        }
    }
    $wellKnownResults = WellKnownWriter::writeFor(
        (string) ($claims['sub'] ?? ''),
        $gatewayHost
    );
} catch (\Throwable $e) {
    error_log('[seekmodo-pair-callback] well-known writer threw: ' . $e->getMessage());
}

http_response_code(200);
echo json_encode([
    'ok' => true,
    'platform' => 'zen-cart',
    'tenant_id' => $claims['sub'] ?? null,
    'well_known_mcp_json' => $wellKnownResults,
]);
