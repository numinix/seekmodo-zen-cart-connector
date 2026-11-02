<?php
/**
 * Seekmodo cloud Inbox HMAC API.
 *
 * Gateway admin.seekmodo.com proxies agent actions here. Auth matches the
 * connector envelope: body-only HMAC-SHA256 with the tenant shared_secret.
 *
 *   POST seekmodo_ai_chatbot_inbox_api.php
 *   Headers:
 *     X-Seekmodo-Tenant: <tenant_id>
 *     X-Seekmodo-Signature: <hex hmac of raw body>
 *     X-Seekmodo-Timestamp: <unix seconds>
 *   Body JSON: { "action": "list|get|poll|reply|…", … }
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

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'empty_body']);
    return;
}

$tenantHeader = (string) ($_SERVER['HTTP_X_SEEKMODO_TENANT'] ?? '');
$sigHeader = strtolower((string) ($_SERVER['HTTP_X_SEEKMODO_SIGNATURE'] ?? ''));
$tsHeader = (string) ($_SERVER['HTTP_X_SEEKMODO_TIMESTAMP'] ?? '');

// Prefer the chatbot's Seekmodo pairing (cloud Inbox is a chatbot feature).
// Fall back to the Seekmodo connector pair — some stores use different
// tenant ids for search (e.g. kip-dev) vs chatbot (e.g. kip).
$authCandidates = [];
$chatbotTenant = defined('SEEKMODO_AI_CHATBOT_SEEKMODO_TENANT_ID')
    ? trim((string) SEEKMODO_AI_CHATBOT_SEEKMODO_TENANT_ID) : '';
$chatbotSecret = defined('SEEKMODO_AI_CHATBOT_SEEKMODO_SHARED_SECRET')
    ? trim((string) SEEKMODO_AI_CHATBOT_SEEKMODO_SHARED_SECRET) : '';
if ($chatbotTenant !== '' && $chatbotSecret !== '') {
    $authCandidates[] = [$chatbotTenant, $chatbotSecret];
}
$connectorTenant = defined('NUMINIX_SEEKMODO_TENANT_ID')
    ? trim((string) NUMINIX_SEEKMODO_TENANT_ID) : '';
$connectorSecret = defined('NUMINIX_SEEKMODO_SHARED_SECRET')
    ? trim((string) NUMINIX_SEEKMODO_SHARED_SECRET) : '';
if (
    $connectorTenant !== ''
    && $connectorSecret !== ''
    && ($chatbotTenant === '' || !hash_equals($chatbotTenant, $connectorTenant))
) {
    $authCandidates[] = [$connectorTenant, $connectorSecret];
}

if ($authCandidates === []) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'not_paired']);
    return;
}
if ($tenantHeader === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'missing_tenant']);
    return;
}
$secret = '';
foreach ($authCandidates as [$candidateTenant, $candidateSecret]) {
    if (hash_equals($candidateTenant, $tenantHeader)) {
        $secret = $candidateSecret;
        break;
    }
}
if ($secret === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'tenant_mismatch']);
    return;
}
if ($sigHeader === '' || !ctype_xdigit($sigHeader) || strlen($sigHeader) !== 64) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'bad_signature_format']);
    return;
}
if ($tsHeader === '' || !ctype_digit($tsHeader)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'missing_timestamp']);
    return;
}
if (abs(time() - (int) $tsHeader) > 300) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'timestamp_out_of_window']);
    return;
}
$expected = hash_hmac('sha256', $raw, $secret);
if (!hash_equals($expected, $sigHeader)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'signature_mismatch']);
    return;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    return;
}

$libBase = null;
$candidates = [];
if (defined('DIR_FS_CATALOG') && is_string(DIR_FS_CATALOG) && DIR_FS_CATALOG !== '') {
    $candidates = array_merge(
        $candidates,
        glob(rtrim(str_replace('\\', '/', DIR_FS_CATALOG), '/') . '/zc_plugins/SeekmodoAiChatbot/v*/catalog/includes/library/SeekmodoAiChatbot') ?: []
    );
}
$candidates[] = __DIR__ . '/includes/library/SeekmodoAiChatbot';
$candidates[] = __DIR__ . '/../includes/library/SeekmodoAiChatbot';
foreach ($candidates as $dir) {
    if (is_dir($dir) && is_file($dir . '/TicketInboxService.php')) {
        $libBase = $dir . '/';
        break;
    }
}
if ($libBase === null) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'library_not_found']);
    return;
}

spl_autoload_register(static function (string $class) use ($libBase): void {
    $prefix = 'SeekmodoAiChatbot\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative = substr($class, $len);
    $file = $libBase . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use SeekmodoAiChatbot\TicketInboxService;

$action = (string) ($payload['action'] ?? '');
try {
    $svc = new TicketInboxService();
    $result = $svc->dispatch($action, $payload);
    if (!isset($result['ok'])) {
        $result = array_merge(['ok' => true], $result);
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage() !== '' ? $e->getMessage() : 'server_error']);
}

// Let resume_ai (and similar) finish LLM work after the gateway has the JSON.
if (function_exists('fastcgi_finish_request')) {
    @fastcgi_finish_request();
} else {
    if (function_exists('session_write_close')) {
        @session_write_close();
    }
    @ob_end_flush();
    @flush();
}
