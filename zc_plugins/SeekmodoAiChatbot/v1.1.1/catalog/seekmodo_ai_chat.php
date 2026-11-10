<?php
/**
 * Seekmodo AI Chatbot API endpoint.
 *
 * Deploy/copy this file to the Zen Cart catalog root (same directory as index.php).
 * Zen Cart blocks direct HTTP access to PHP inside zc_plugins/, so the public
 * endpoint must live at catalog root (same pattern as numinix_mcp.php).
 *
 * POST {message, session_id} → {reply} | {error}
 *
 * Requires PHP 8.1+ (see .htaccess <Files> handler override).
 */

declare(strict_types=1);

$catalogRoot = __DIR__;
$pluginCatalog = null;
$normalized = str_replace('\\', '/', $catalogRoot);

if (strpos($normalized, '/zc_plugins/') !== false) {
    // Running from plugin tree during development.
    $pluginCatalog = $catalogRoot;
    $resolved = realpath($catalogRoot . '/../../../..');
    $catalogRoot = $resolved !== false ? $resolved : dirname($catalogRoot, 4);
} else {
    $pluginCatalog = seekmodo_ai_chat_find_plugin_catalog($catalogRoot);
}

// Prefer the highest filesystem plugin version's library even when Plugin Manager
// still points at an older installed version (undefined-method fatals otherwise).
if (is_string($pluginCatalog) && $pluginCatalog !== '') {
    seekmodo_ai_chat_preload_plugin_library($pluginCatalog);
}

chdir($catalogRoot);
require $catalogRoot . '/includes/application_top.php';

$initFile = null;
if (is_string($pluginCatalog) && $pluginCatalog !== '') {
    $initFile = $pluginCatalog . '/includes/init_includes/init_seekmodo_ai_chatbot.php';
}
if (!is_string($initFile) || !is_file($initFile)) {
    // application_top should already have registered the plugin autoloader;
    // fall back to scanning installed plugin versions.
    $initFile = seekmodo_ai_chat_find_plugin_init($catalogRoot);
}
// Avoid re-including a newer init after Plugin Manager already loaded an older
// observer class (different file path → fatal "Cannot redeclare class").
if (
    is_string($initFile)
    && is_file($initFile)
    && !class_exists('\\SeekmodoAiChatbot\\Observers\\ChatbotWidgetObserver', false)
) {
    require_once $initFile;
}
if (!class_exists('\\SeekmodoAiChatbot\\ChatAgent', false) && is_string($pluginCatalog) && $pluginCatalog !== '') {
    seekmodo_ai_chat_preload_plugin_library($pluginCatalog);
}

header('Content-Type: application/json; charset=utf-8');
seekmodo_ai_chat_send_cors_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

if (!defined('SEEKMODO_AI_CHATBOT_ENABLED') || SEEKMODO_AI_CHATBOT_ENABLED !== 'true') {
    http_response_code(503);
    echo json_encode(['error' => 'chatbot_disabled']);
    exit;
}

$seekmodoGate = class_exists('\\SeekmodoAiChatbot\\SeekmodoEntitlementGate')
    ? \SeekmodoAiChatbot\SeekmodoEntitlementGate::evaluate()
    : ['ok' => false, 'reason' => 'gate_missing', 'message' => 'Seekmodo entitlement gate unavailable.'];
if (empty($seekmodoGate['ok'])) {
    http_response_code(403);
    echo json_encode([
        'error' => 'seekmodo_required',
        'reason' => (string) ($seekmodoGate['reason'] ?? 'unpaired'),
        'message' => (string) ($seekmodoGate['message'] ?? 'A paired paid Seekmodo account is required.'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    echo json_encode([
        'error' => 'php_version_unsupported',
        'message' => 'seekmodo_ai_chat.php requires PHP 8.1+ (got ' . PHP_VERSION . ')',
    ]);
    exit;
}

// Rate limiting
$rateLimit = defined('SEEKMODO_AI_CHATBOT_RATE_LIMIT_RPM') ? (int) SEEKMODO_AI_CHATBOT_RATE_LIMIT_RPM : 30;
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!seekmodo_ai_chat_check_rate_limit($ip, $rateLimit)) {
    http_response_code(429);
    echo json_encode(['error' => 'rate_limit_exceeded']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_json']);
    exit;
}

$action = trim((string) ($body['action'] ?? 'message'));
$sessionId = (string) ($body['session_id'] ?? 'default');
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $sessionId)) {
    $sessionId = 'default';
}

$shopper = seekmodo_ai_chat_resolve_shopper();

if ($action === 'list_chats') {
    $rows = \SeekmodoAiChatbot\ChatArchive::listForShopper(
        (int) ($shopper['customers_id'] ?? 0),
        (string) ($shopper['email'] ?? '')
    );
    $chats = [];
    foreach ($rows as $row) {
        $chats[] = seekmodo_ai_chat_public_chat_row($row);
    }
    echo json_encode(['chats' => $chats], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'list_tickets') {
    $rows = \SeekmodoAiChatbot\ChatArchive::listTicketsForShopper(
        (int) ($shopper['customers_id'] ?? 0),
        (string) ($shopper['email'] ?? '')
    );
    $tickets = [];
    foreach ($rows as $row) {
        $tickets[] = seekmodo_ai_chat_public_chat_row($row);
    }
    echo json_encode(['tickets' => $tickets], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'chat_status') {
    $chat = seekmodo_ai_chat_verify_session_owner($sessionId, $shopper);
    if ($chat === null) {
        http_response_code(404);
        echo json_encode(['error' => 'chat_not_found']);
        exit;
    }
    $chatId = (int) ($chat['chat_id'] ?? 0);
    if ($chatId > 0) {
        \SeekmodoAiChatbot\ChatArchive::touchShopperPresence($chatId);
        $chat['shopper_presence_at'] = date('Y-m-d H:i:s');
    }
    $recovery = \SeekmodoAiChatbot\ChatAgent::recoverTimedOutEscalationForSession($sessionId);
    if ($recovery !== null && !empty($recovery['recovered'])) {
        $chat = \SeekmodoAiChatbot\ChatArchive::getBySessionId($sessionId) ?? $chat;
    }
    $payload = seekmodo_ai_chat_public_chat_row($chat);
    if ($recovery !== null && !empty($recovery['recovered'])) {
        $payload['escalation_timed_out'] = true;
        $payload['needs_ticket_confirm'] = !empty($recovery['needs_ticket_confirm']);
        $payload['needs_email'] = !empty($recovery['needs_email']);
        $payload['recovery_message'] = (string) ($recovery['recovery_message'] ?? '');
        $payload['contact_email'] = (string) ($recovery['contact_email'] ?? '');
        $payload['status'] = 'open';
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'resolve_chat') {
    $chat = seekmodo_ai_chat_verify_session_owner($sessionId, $shopper);
    if ($chat === null) {
        http_response_code(404);
        echo json_encode(['error' => 'chat_not_found']);
        exit;
    }
    $chatId = (int) ($chat['chat_id'] ?? 0);
    if ($chatId < 1 || !\SeekmodoAiChatbot\ChatArchive::setStatus($chatId, 'resolved', 0)) {
        http_response_code(500);
        echo json_encode(['error' => 'resolve_failed']);
        exit;
    }
    \SeekmodoAiChatbot\ChatAgent::stampSessionResolved($sessionId);
    echo json_encode([
        'ok' => true,
        'session_id' => $sessionId,
        'status' => 'resolved',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'reopen_chat') {
    $chat = seekmodo_ai_chat_verify_session_owner($sessionId, $shopper);
    if ($chat === null) {
        http_response_code(404);
        echo json_encode(['error' => 'chat_not_found']);
        exit;
    }
    $chatId = (int) ($chat['chat_id'] ?? 0);
    $newStatus = $chatId > 0 ? \SeekmodoAiChatbot\ChatArchive::reopenChat($chatId, 0) : false;
    if ($newStatus === false) {
        http_response_code(400);
        echo json_encode(['error' => 'reopen_failed']);
        exit;
    }
    if ($newStatus === 'handed_off') {
        \SeekmodoAiChatbot\ChatAgent::stampSessionHandedOff($sessionId);
    } else {
        \SeekmodoAiChatbot\ChatAgent::stampSessionOpen($sessionId);
    }
    echo json_encode([
        'ok' => true,
        'session_id' => $sessionId,
        'chat_id' => $chatId,
        'status' => $newStatus,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Message / offline-handoff actions: never continue another shopper's archived session.
$sessionGuard = seekmodo_ai_chat_ensure_owned_session($sessionId, $shopper);
$sessionId = $sessionGuard['session_id'];
$sessionForcedReset = !empty($sessionGuard['session_reset']);

$apiKey = defined('SEEKMODO_AI_CHATBOT_API_KEY') ? SEEKMODO_AI_CHATBOT_API_KEY : '';
$model = defined('SEEKMODO_AI_CHATBOT_MODEL') ? SEEKMODO_AI_CHATBOT_MODEL : 'gpt-4o-mini';
$mcpUrl = defined('SEEKMODO_AI_CHATBOT_MCP_URL') ? SEEKMODO_AI_CHATBOT_MCP_URL : '';
$mcpToken = defined('SEEKMODO_AI_CHATBOT_MCP_TOKEN') ? SEEKMODO_AI_CHATBOT_MCP_TOKEN : '';
$systemPrompt = defined('SEEKMODO_AI_CHATBOT_SYSTEM_PROMPT') ? SEEKMODO_AI_CHATBOT_SYSTEM_PROMPT : '';
$additionalRules = defined('SEEKMODO_AI_CHATBOT_ADDITIONAL_RULES') ? SEEKMODO_AI_CHATBOT_ADDITIONAL_RULES : '';
$maxToolRounds = defined('SEEKMODO_AI_CHATBOT_MAX_TOOL_ROUNDS') ? (int) SEEKMODO_AI_CHATBOT_MAX_TOOL_ROUNDS : 10;
$kbPath = defined('SEEKMODO_AI_CHATBOT_KB_PATH') ? SEEKMODO_AI_CHATBOT_KB_PATH : '';

$mcpTimeout = defined('SEEKMODO_AI_CHATBOT_MCP_TIMEOUT')
    ? (int) SEEKMODO_AI_CHATBOT_MCP_TIMEOUT
    : 120;
$mcpClient = new \SeekmodoAiChatbot\McpClient($mcpUrl, $mcpToken, $mcpTimeout);
$kbStore = new \SeekmodoAiChatbot\KbStore($kbPath !== '' ? $kbPath : null);
$sitemapIndex = new \SeekmodoAiChatbot\SitemapProductIndex($kbPath !== '' ? $kbPath : null);
$agent = new \SeekmodoAiChatbot\ChatAgent(
    $apiKey !== '' ? $apiKey : 'unavailable',
    $model,
    $systemPrompt,
    $mcpClient,
    $kbStore,
    $sitemapIndex,
    $maxToolRounds,
    $kbPath,
    null,
    $shopper,
    $additionalRules
);

// Contact capture while AI is unavailable (OTP via MCP — does not call OpenAI).
if ($action === 'leave_contact_send') {
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $result = $agent->leaveContactSend($sessionId, $email);
    if (empty($result['ok'])) {
        http_response_code(400);
    }
    echo json_encode(array_merge($result, [
        'session_id' => $sessionId,
        'session_reset' => $sessionForcedReset,
    ]), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'leave_contact_verify') {
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $code = trim((string) ($body['code'] ?? ''));
    $result = $agent->leaveContactVerify($sessionId, $email, $code);
    if (empty($result['ok'])) {
        http_response_code(400);
    }
    echo json_encode(array_merge($result, [
        'session_id' => $sessionId,
        'assistant_unavailable' => true,
        'session_reset' => $sessionForcedReset,
    ]), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'confirm_offline_ticket') {
    $result = $agent->confirmOfflineTicket($sessionId, trim((string) ($body['message'] ?? '')));
    if (empty($result['ok'])) {
        http_response_code(400);
    }
    echo json_encode(array_merge($result, [
        'session_id' => $sessionId,
        'session_reset' => $sessionForcedReset,
    ]), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'decline_offline_ticket') {
    $result = $agent->declineOfflineTicket($sessionId);
    if (empty($result['ok'])) {
        http_response_code(400);
    }
    echo json_encode(array_merge($result, [
        'session_id' => $sessionId,
        'session_reset' => $sessionForcedReset,
    ]), JSON_UNESCAPED_UNICODE);
    exit;
}

$message = trim((string) ($body['message'] ?? ''));
if ($message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'message_required']);
    exit;
}

// Opportunistic cleanup of abandoned session files (approx 1 in 40 requests).
if (mt_rand(1, 40) === 1) {
    try {
        \SeekmodoAiChatbot\ChatAgent::gcExpiredSessions();
    } catch (\Throwable $e) {
        // ignore GC failures
    }
}

try {
    // Engraving updates re-export Customily PDFs and can exceed 15–30s.
    @set_time_limit(180);

    if ($apiKey === '') {
        throw new \SeekmodoAiChatbot\ChatbotException('api_key_not_configured');
    }

    $result = $agent->processMessage($sessionId, $message);

    $payload = [
        'reply' => $result['reply'],
        'session_id' => $sessionId,
        'tools_called' => $result['tools_called'] ?? [],
        'session_reset' => !empty($result['session_reset']) || $sessionForcedReset,
    ];
    if (!empty($result['live_agent']) || (($result['status'] ?? '') === 'live')) {
        $payload['live_agent'] = true;
        $payload['status'] = 'live';
    } elseif (!empty($result['status'])) {
        $payload['status'] = (string) $result['status'];
    }
    if (!empty($result['suppressed'])) {
        $payload['suppressed'] = true;
    }
    if (!empty($result['assistant_unavailable'])) {
        $payload['assistant_unavailable'] = true;
        $payload['needs_email'] = !empty($result['needs_email']);
        $payload['needs_ticket_confirm'] = !empty($result['needs_ticket_confirm']);
        $payload['ticket_opened'] = !empty($result['ticket_opened']);
        $payload['has_contact'] = !empty($result['has_contact']);
        $payload['contact_email'] = (string) ($result['contact_email'] ?? '');
    }
    if (!empty($result['escalation_timed_out']) || !empty($result['recovered'])) {
        $payload['escalation_timed_out'] = true;
        $payload['needs_ticket_confirm'] = !empty($result['needs_ticket_confirm']);
        $payload['needs_email'] = !empty($result['needs_email']);
        if (!empty($result['recovery_message'])) {
            $payload['recovery_message'] = (string) $result['recovery_message'];
        }
        if (!empty($result['contact_email'])) {
            $payload['contact_email'] = (string) $result['contact_email'];
        }
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
} catch (\SeekmodoAiChatbot\ChatbotException $e) {
    try {
        $fallback = $agent->handleAssistantUnavailable($sessionId, $message);
        echo json_encode([
            'assistant_unavailable' => true,
            'reply' => $fallback['reply'],
            'needs_email' => !empty($fallback['needs_email']),
            'needs_ticket_confirm' => !empty($fallback['needs_ticket_confirm']),
            'ticket_opened' => !empty($fallback['ticket_opened']),
            'has_contact' => !empty($fallback['has_contact']),
            'contact_email' => (string) ($fallback['contact_email'] ?? ''),
            'session_id' => $sessionId,
            'session_reset' => !empty($fallback['session_reset']) || $sessionForcedReset,
            'error_code' => $e->getMessage(),
            'context' => $e->context(),
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $fallbackError) {
        http_response_code(500);
        echo json_encode([
            'error' => $e->getMessage(),
            'context' => $e->context(),
        ]);
    }
} catch (\Throwable $e) {
    try {
        $fallback = $agent->handleAssistantUnavailable($sessionId, $message);
        echo json_encode([
            'assistant_unavailable' => true,
            'reply' => $fallback['reply'],
            'needs_email' => !empty($fallback['needs_email']),
            'needs_ticket_confirm' => !empty($fallback['needs_ticket_confirm']),
            'ticket_opened' => !empty($fallback['ticket_opened']),
            'has_contact' => !empty($fallback['has_contact']),
            'contact_email' => (string) ($fallback['contact_email'] ?? ''),
            'session_id' => $sessionId,
            'session_reset' => !empty($fallback['session_reset']) || $sessionForcedReset,
            'error_code' => 'internal_error',
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $fallbackError) {
        http_response_code(500);
        echo json_encode([
            'error' => 'internal_error',
            'message' => $e->getMessage(),
        ]);
    }
}

/**
 * Allow CORS only for same-host origins so session cookies can be used safely.
 */
function seekmodo_ai_chat_send_cors_headers(): void
{
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') {
        return;
    }
    $originHost = parse_url($origin, PHP_URL_HOST);
    $requestHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $requestHost = preg_replace('/:\d+$/', '', $requestHost) ?: $requestHost;
    if (!is_string($originHost) || strcasecmp($originHost, $requestHost) !== 0) {
        return;
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Vary: Origin');
}

/**
 * Resolve the storefront shopper from the Zen Cart session (never from request body).
 *
 * @return array{logged_in: bool, customers_id: int, email: string, first_name: string, last_name: string}
 */
function seekmodo_ai_chat_resolve_shopper(): array
{
    $guest = [
        'logged_in' => false,
        'customers_id' => 0,
        'email' => '',
        'first_name' => '',
        'last_name' => '',
    ];
    $customersId = (int) ($_SESSION['customer_id'] ?? 0);
    if ($customersId <= 0) {
        return $guest;
    }

    global $db;
    $email = '';
    $firstName = '';
    $lastName = '';
    if (isset($db) && is_object($db) && defined('TABLE_CUSTOMERS')) {
        $row = $db->Execute(
            'SELECT customers_email_address, customers_firstname, customers_lastname
               FROM ' . TABLE_CUSTOMERS . '
              WHERE customers_id = ' . $customersId . '
              LIMIT 1'
        );
        if ($row && !$row->EOF) {
            $email = strtolower(trim((string) $row->fields['customers_email_address']));
            $firstName = trim((string) $row->fields['customers_firstname']);
            $lastName = trim((string) ($row->fields['customers_lastname'] ?? ''));
        }
    }

    // Session claims login but email is missing — treat as guest (no private lookups).
    if ($email === '') {
        return $guest;
    }

    return [
        'logged_in' => true,
        'customers_id' => $customersId,
        'email' => $email,
        'first_name' => $firstName,
        'last_name' => $lastName,
    ];
}

/**
 * Locate the newest SeekmodoAiChatbot catalog directory on disk.
 */
function seekmodo_ai_chat_find_plugin_catalog(string $catalogRoot): ?string
{
    $base = rtrim($catalogRoot, '/\\') . '/zc_plugins/SeekmodoAiChatbot';
    if (!is_dir($base)) {
        return null;
    }
    $versions = glob($base . '/v*', GLOB_ONLYDIR) ?: [];
    rsort($versions, SORT_NATURAL);
    foreach ($versions as $versionDir) {
        $candidate = $versionDir . '/catalog';
        if (is_dir($candidate . '/includes/library/SeekmodoAiChatbot')) {
            return $candidate;
        }
    }
    return null;
}

/**
 * Load library classes from a plugin catalog path before Plugin Manager autoload
 * can bind an older version (e.g. confirmOfflineTicket missing → HTTP 500).
 */
function seekmodo_ai_chat_preload_plugin_library(string $pluginCatalog): void
{
    $lib = rtrim($pluginCatalog, '/\\') . '/includes/library/SeekmodoAiChatbot';
    if (!is_dir($lib)) {
        return;
    }
    $order = [
        'ChatbotException.php',
        'McpClient.php',
        'KbStore.php',
        'SitemapProductIndex.php',
        'SeekmodoProductSearch.php',
        'SeekmodoTenantChatTools.php',
        'SeekmodoEntitlementGate.php',
        'ModelCatalog.php',
        'StoreContext.php',
        'ChatArchive.php',
        'ChatAgent.php',
    ];
    foreach ($order as $file) {
        $path = $lib . '/' . $file;
        if (is_file($path)) {
            require_once $path;
        }
    }
    $llmDir = $lib . '/Llm';
    if (is_dir($llmDir)) {
        foreach ([
            'LlmResult.php',
            'ProviderAdapter.php',
            'OpenAiCompatibleAdapter.php',
            'AnthropicAdapter.php',
            'LlmClient.php',
        ] as $file) {
            $path = $llmDir . '/' . $file;
            if (is_file($path)) {
                require_once $path;
            }
        }
    }
    foreach (glob($lib . '/*.php') ?: [] as $path) {
        require_once $path;
    }
}

function seekmodo_ai_chat_find_plugin_init(string $catalogRoot): ?string
{
    $catalog = seekmodo_ai_chat_find_plugin_catalog($catalogRoot);
    if ($catalog === null) {
        return null;
    }
    $init = $catalog . '/includes/init_includes/init_seekmodo_ai_chatbot.php';
    return is_file($init) ? $init : null;
}

/**
 * Simple file-based sliding-window rate limiter.
 */
function seekmodo_ai_chat_check_rate_limit(string $ip, int $limitPerMinute): bool
{
    $cacheDir = defined('DIR_FS_CATALOG') ? DIR_FS_CATALOG . 'cache' : sys_get_temp_dir();
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $rateFile = $cacheDir . '/seekmodo_ai_chatbot_ratelimit.dat';
    $window = 60;

    $records = [];
    if (is_file($rateFile)) {
        $data = @file_get_contents($rateFile);
        if ($data !== false) {
            $records = json_decode($data, true) ?? [];
        }
    }

    $now = time();
    $ipKey = str_replace(':', '_', $ip);

    if (isset($records[$ipKey]) && is_array($records[$ipKey])) {
        $records[$ipKey] = array_values(array_filter(
            $records[$ipKey],
            static function ($ts) use ($now, $window): bool {
                return is_int($ts) && ($now - $ts) < $window;
            }
        ));
    } else {
        $records[$ipKey] = [];
    }

    if (count($records[$ipKey]) >= $limitPerMinute) {
        return false;
    }

    $records[$ipKey][] = $now;
    @file_put_contents($rateFile, json_encode($records), LOCK_EX);
    return true;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function seekmodo_ai_chat_public_chat_row(array $row): array
{
    $messages = [];
    foreach (($row['messages'] ?? []) as $m) {
        if (!is_array($m)) {
            continue;
        }
        $role = (string) ($m['role'] ?? '');
        $content = trim((string) ($m['content'] ?? ''));
        if ($content === '' || !in_array($role, ['user', 'assistant', 'admin'], true)) {
            continue;
        }
        $entry = ['role' => $role, 'content' => $content];
        if ($role === 'admin' && !empty($m['date'])) {
            $entry['date'] = (string) $m['date'];
        }
        $messages[] = $entry;
    }

    $status = (string) ($row['status'] ?? 'open');
    $agentName = '';
    if ($status === 'live') {
        $agentName = \SeekmodoAiChatbot\ChatArchive::assigneeDisplayName((int) ($row['assigned_admin_id'] ?? 0));
    }

    return [
        'chat_id' => (int) ($row['chat_id'] ?? 0),
        'session_id' => (string) ($row['session_id'] ?? ''),
        'status' => $status,
        'preview' => (string) ($row['preview'] ?? ''),
        'updated_at' => (string) ($row['date_updated'] ?? ''),
        'agent_name' => $agentName,
        'awaiting_human' => !empty($row['awaiting_human'])
            || \SeekmodoAiChatbot\ChatArchive::isAwaitingHuman($row),
        'messages' => $messages,
    ];
}

function seekmodo_ai_chat_session_claim(string $sessionId): void
{
    if ($sessionId === '') {
        return;
    }
    if (!isset($_SESSION['seekmodo_ai_owned_sessions']) || !is_array($_SESSION['seekmodo_ai_owned_sessions'])) {
        $_SESSION['seekmodo_ai_owned_sessions'] = [];
    }
    $_SESSION['seekmodo_ai_owned_sessions'][$sessionId] = time();
}

function seekmodo_ai_chat_session_claimed(string $sessionId): bool
{
    return $sessionId !== '' && !empty($_SESSION['seekmodo_ai_owned_sessions'][$sessionId]);
}

function seekmodo_ai_chat_session_file_exists(string $sessionId): bool
{
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId) ?: '';
    if ($safe === '' || !class_exists('\\SeekmodoAiChatbot\\ChatAgent')) {
        return false;
    }
    try {
        $file = \SeekmodoAiChatbot\ChatAgent::sessionsDirectory() . '/' . $safe . '.json';
    } catch (\Throwable $e) {
        return false;
    }
    return is_file($file);
}

function seekmodo_ai_chat_new_session_id(): string
{
    try {
        return 'sess_' . bin2hex(random_bytes(16));
    } catch (\Throwable $e) {
        return 'sess_' . md5(uniqid((string) mt_rand(), true));
    }
}

/**
 * @param array{logged_in?: bool, customers_id?: int, email?: string} $shopper
 * @return array<string, mixed>|null
 */
function seekmodo_ai_chat_verify_session_owner(string $sessionId, array $shopper): ?array
{
    try {
        $chat = \SeekmodoAiChatbot\ChatArchive::getBySessionId($sessionId);
    } catch (\Throwable $e) {
        return null;
    }
    if ($chat === null) {
        return null;
    }

    $shopperId = (int) ($shopper['customers_id'] ?? 0);
    $shopperEmail = strtolower(trim((string) ($shopper['email'] ?? '')));
    $chatId = (int) ($chat['customers_id'] ?? 0);
    $chatEmail = strtolower(trim((string) ($chat['customer_email'] ?? '')));

    if ($shopperId > 0) {
        if ($chatId === $shopperId) {
            return $chat;
        }
        if ($chatId < 1 && $chatEmail !== '' && $chatEmail === $shopperEmail) {
            return $chat;
        }
        // Guest started this chat in the same PHP session, then logged in.
        // Only bridge when the archive has no contact email yet, or it matches.
        if ($chatId < 1 && seekmodo_ai_chat_session_claimed($sessionId)
            && ($chatEmail === '' || $chatEmail === $shopperEmail)
        ) {
            return $chat;
        }
        return null;
    }

    // Anonymous: never adopt a logged-in customer's archive.
    if ($chatId > 0) {
        return null;
    }
    // Verified guest email match, or this PHP session already claimed the id.
    if ($chatEmail !== '' && $shopperEmail !== '' && $chatEmail === $shopperEmail) {
        return $chat;
    }
    if (seekmodo_ai_chat_session_claimed($sessionId)) {
        return $chat;
    }

    return null;
}

/**
 * Keep message/handoff flows on a session the current shopper may use.
 * Unknown sessions are allowed (first message creates history); foreign
 * archive rows or unclaimed history files are rotated so localStorage
 * leftovers cannot leak transcripts between shoppers.
 *
 * @param array{logged_in?: bool, customers_id?: int, email?: string} $shopper
 * @return array{session_id: string, session_reset: bool}
 */
function seekmodo_ai_chat_ensure_owned_session(string $sessionId, array $shopper): array
{
    $archiveLookupFailed = false;
    try {
        $chat = \SeekmodoAiChatbot\ChatArchive::getBySessionId($sessionId);
    } catch (\Throwable $e) {
        // Do not claim the caller-supplied id when ownership cannot be verified.
        $chat = null;
        $archiveLookupFailed = true;
    }

    if ($archiveLookupFailed) {
        if (seekmodo_ai_chat_session_claimed($sessionId)) {
            return ['session_id' => $sessionId, 'session_reset' => false];
        }
        $newId = seekmodo_ai_chat_new_session_id();
        seekmodo_ai_chat_session_claim($newId);
        return ['session_id' => $newId, 'session_reset' => true];
    }

    if ($chat !== null) {
        if (seekmodo_ai_chat_verify_session_owner($sessionId, $shopper) !== null) {
            seekmodo_ai_chat_session_claim($sessionId);
            return ['session_id' => $sessionId, 'session_reset' => false];
        }
        $newId = seekmodo_ai_chat_new_session_id();
        seekmodo_ai_chat_session_claim($newId);
        return ['session_id' => $newId, 'session_reset' => true];
    }

    // No archive row: allow only if unused, or already claimed by this PHP session.
    if (seekmodo_ai_chat_session_file_exists($sessionId)) {
        if (seekmodo_ai_chat_session_claimed($sessionId)) {
            return ['session_id' => $sessionId, 'session_reset' => false];
        }
        $newId = seekmodo_ai_chat_new_session_id();
        seekmodo_ai_chat_session_claim($newId);
        return ['session_id' => $newId, 'session_reset' => true];
    }

    seekmodo_ai_chat_session_claim($sessionId);
    return ['session_id' => $sessionId, 'session_reset' => false];
}
