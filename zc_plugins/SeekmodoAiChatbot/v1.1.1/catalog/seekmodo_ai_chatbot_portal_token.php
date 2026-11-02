<?php
/**
 * Mint a short-lived shopper portal token for the logged-in customer.
 *
 * Same-origin only. The email comes from the Zen Cart session, never
 * from the request body. External portals send the token to
 * Seekmodo POST /v1/inbox/shopper.
 */
declare(strict_types=1);

$applicationTopCandidates = [
    __DIR__ . '/includes/application_top.php',
    __DIR__ . '/../../../../includes/application_top.php',
];
$applicationTopPath = null;
foreach ($applicationTopCandidates as $candidate) {
    if (is_file($candidate)) {
        $applicationTopPath = $candidate;
        break;
    }
}
if ($applicationTopPath === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'application_top_not_found']);
    return;
}
$includesDir = dirname((string) realpath($applicationTopPath));
$catalogRoot = dirname($includesDir);
if ($catalogRoot !== '' && is_dir($catalogRoot)) {
    chdir($catalogRoot);
}
require $applicationTopPath;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    return;
}

$customersId = (int) ($_SESSION['customer_id'] ?? 0);
if ($customersId < 1 || !isset($db) || !is_object($db) || !defined('TABLE_CUSTOMERS')) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'login_required']);
    return;
}

$row = $db->Execute(
    'SELECT customers_email_address FROM ' . TABLE_CUSTOMERS
    . ' WHERE customers_id = ' . $customersId . ' LIMIT 1'
);
$email = ($row && !$row->EOF) ? strtolower(trim((string) $row->fields['customers_email_address'])) : '';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'customer_email_missing']);
    return;
}

$libBase = null;
$candidates = [];
if (defined('DIR_FS_CATALOG') && is_string(DIR_FS_CATALOG) && DIR_FS_CATALOG !== '') {
    $candidates = glob(rtrim(str_replace('\\', '/', DIR_FS_CATALOG), '/')
        . '/zc_plugins/SeekmodoAiChatbot/v*/catalog/includes/library/SeekmodoAiChatbot') ?: [];
}
$candidates[] = __DIR__ . '/includes/library/SeekmodoAiChatbot';
rsort($candidates, SORT_NATURAL);
foreach ($candidates as $dir) {
    if (is_dir($dir) && is_file($dir . '/PortalToken.php')) {
        $libBase = $dir . '/';
        break;
    }
}
if ($libBase === null) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'library_not_found']);
    return;
}
require_once $libBase . 'PortalToken.php';

use SeekmodoAiChatbot\PortalToken;

$secret = PortalToken::sharedSecret();
if ($secret === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'not_paired']);
    return;
}

try {
    $token = PortalToken::mint($email, $secret);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'mint_failed']);
    return;
}

echo json_encode([
    'ok' => true,
    'token' => $token,
    'token_type' => 'Seekmodo-Portal',
    'expires_in' => PortalToken::TTL_DEFAULT,
    'email' => $email,
], JSON_UNESCAPED_SLASHES);
