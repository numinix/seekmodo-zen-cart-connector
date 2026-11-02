<?php
/**
 * Seekmodo AI Chatbot - Support Tickets admin browser.
 */
require 'includes/application_top.php';

if (!defined('IS_ADMIN_FLAG') || !IS_ADMIN_FLAG) {
    die('Illegal Access');
}

// Prefer copied admin includes; fall back to zc_plugins after deploy wipe.
$ogcBoot = __DIR__ . '/seekmodo_ai_chatbot_bootstrap.php';
if (!is_file($ogcBoot) && defined('DIR_FS_CATALOG')) {
    $ogcBoots = glob(rtrim((string) DIR_FS_CATALOG, '/\\') . '/zc_plugins/SeekmodoAiChatbot/v*/admin/seekmodo_ai_chatbot_bootstrap.php') ?: [];
    usort($ogcBoots, static function ($a, $b) {
        preg_match('#/SeekmodoAiChatbot/v([^/]+)/#', str_replace('\\', '/', $a), $ma);
        preg_match('#/SeekmodoAiChatbot/v([^/]+)/#', str_replace('\\', '/', $b), $mb);
        return version_compare($mb[1] ?? '0', $ma[1] ?? '0');
    });
    if (!empty($ogcBoots[0])) {
        $ogcBoot = $ogcBoots[0];
    }
}
require_once $ogcBoot;
seekmodo_ai_chatbot_require_admin_include('languages/english/seekmodo_ai_chatbot_chats.php');
seekmodo_ai_chatbot_require_admin_include('languages/english/seekmodo_ai_chatbot_tickets.php');

seekmodo_ai_chatbot_require_library([
    'SeekmodoTickets.php',
    'MailConfig.php',
    'ChatbotSmtpMailer.php',
    'TicketMail.php',
    'ChatbotException.php',
    'McpClient.php',
    'SeekmodoProductSearch.php',
    'SeekmodoTenantChatTools.php',
    'ChatAgent.php',
    'ChatArchive.php',
    'AdminPresence.php',
]);

use SeekmodoAiChatbot\AdminPresence;
use SeekmodoAiChatbot\ChatArchive;
use SeekmodoAiChatbot\ChatAgent;
use SeekmodoAiChatbot\MailConfig;
use SeekmodoAiChatbot\SeekmodoTickets;
use SeekmodoAiChatbot\TicketMail;

AdminPresence::touch(function_exists('seekmodo_ai_chatbot_admin_id') ? seekmodo_ai_chatbot_admin_id() : 0);

ChatArchive::ensureTable();

function seekmodo_ai_chatbot_wants_json(): bool
{
    if ((string) ($_POST['ajax'] ?? '') === '1') {
        return true;
    }
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    if (stripos($accept, 'application/json') !== false) {
        return true;
    }
    return strcasecmp((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0;
}

/** @param array<string, mixed> $row */
function seekmodo_ai_chatbot_initials(array $row): string
{
    $name = trim((string) ($row['customer_name'] ?? ''));
    if ($name !== '') {
        $parts = preg_split('/\s+/', $name) ?: [];
        if (count($parts) >= 2) {
            return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
        }
        return strtoupper(substr($name, 0, 2));
    }
    $email = trim((string) ($row['customer_email'] ?? ''));
    if ($email !== '') {
        return strtoupper(substr($email, 0, 2));
    }
    return '?';
}

/** @param array<string, mixed> $row @return array<string, mixed> */
function seekmodo_ai_chatbot_serialize_list_row(array $row): array
{
    return [
        'chat_id' => (int) ($row['chat_id'] ?? 0),
        'customer_name' => (string) ($row['customer_name'] ?? ''),
        'customer_email' => (string) ($row['customer_email'] ?? ''),
        'customers_id' => (int) ($row['customers_id'] ?? 0),
        'is_guest' => (int) ($row['is_guest'] ?? 0),
        'preview' => (string) ($row['preview'] ?? ''),
        'message_count' => (int) ($row['message_count'] ?? 0),
        'status' => (string) ($row['status'] ?? 'open'),
        'awaiting_human' => !empty($row['awaiting_human']) || ChatArchive::isAwaitingHuman($row),
        'present' => ChatArchive::isShopperPresent($row),
        'date_updated' => (string) ($row['date_updated'] ?? ''),
        'date_created' => (string) ($row['date_created'] ?? ''),
        'archived_at' => (string) ($row['archived_at'] ?? ''),
        'assigned_admin_id' => (int) ($row['assigned_admin_id'] ?? 0),
        'assigned_admin_name' => ChatArchive::assigneeDisplayName((int) ($row['assigned_admin_id'] ?? 0)),
        'assigned_seekmodo_user_id' => (string) ($row['assigned_seekmodo_user_id'] ?? ''),
        'assigned_seekmodo_name' => (string) ($row['assigned_seekmodo_name'] ?? ''),
        'initials' => seekmodo_ai_chatbot_initials($row),
    ];
}

/** @param array<string, mixed> $chat @return array<string, mixed> */
function seekmodo_ai_chatbot_serialize_conversation(array $chat): array
{
    $status = (string) ($chat['status'] ?? 'open');
    $isResolved = $status === 'resolved';
    $isArchived = ChatArchive::isArchived($chat);
    $assigned = (int) ($chat['assigned_admin_id'] ?? 0);
    $cid = (int) ($chat['customers_id'] ?? 0);
    $accountUrl = '';
    if ($cid > 0) {
        $customersPage = defined('FILENAME_CUSTOMERS') ? FILENAME_CUSTOMERS : 'customers';
        $accountUrl = zen_href_link($customersPage, 'cID=' . $cid . '&action=edit');
    }
    $canEmail = ChatArchive::canAdminReply($chat) && !$isResolved;
    $who = trim((string) ($chat['customer_name'] ?? ''));
    $email = trim((string) ($chat['customer_email'] ?? ''));
    $shopperLabel = $who !== '' ? $who : ($email !== '' ? $email : (defined('TEXT_SEEKMODO_AI_CHATBOT_CHATS_GUEST') ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_GUEST : 'Guest'));
    $messages = [];
    foreach (($chat['messages'] ?? []) as $m) {
        if (!is_array($m)) {
            continue;
        }
        $role = (string) ($m['role'] ?? 'user');
        if (!in_array($role, ['user', 'assistant', 'admin', 'agent_note'], true)) {
            continue;
        }
        $messages[] = [
            'role' => $role,
            'content' => (string) ($m['content'] ?? ''),
            'date' => (string) ($m['date'] ?? ''),
            'admin_name' => (string) ($m['admin_name'] ?? ''),
            'admin_id' => (int) ($m['admin_id'] ?? 0),
            'seq' => (int) ($m['seq'] ?? 0),
            'channel' => (string) ($m['channel'] ?? ''),
            'author_label' => seekmodo_ai_chatbot_message_author_label($role, $shopperLabel, $m),
        ];
    }
    return [
        'chat_id' => (int) ($chat['chat_id'] ?? 0),
        'session_id' => (string) ($chat['session_id'] ?? ''),
        'customer_name' => (string) ($chat['customer_name'] ?? ''),
        'customer_email' => (string) ($chat['customer_email'] ?? ''),
        'shopper_label' => $shopperLabel,
        'customers_id' => $cid,
        'is_guest' => (int) ($chat['is_guest'] ?? 0),
        'email_verified' => (int) ($chat['email_verified'] ?? 0),
        'status' => $status,
        'message_count' => (int) ($chat['message_count'] ?? 0),
        'date_created' => (string) ($chat['date_created'] ?? ''),
        'date_updated' => (string) ($chat['date_updated'] ?? ''),
        'assigned_admin_id' => $assigned,
        'archived_at' => (string) ($chat['archived_at'] ?? ''),
        'awaiting_human' => !empty($chat['awaiting_human']) || ChatArchive::isAwaitingHuman($chat),
        'present' => ChatArchive::isShopperPresent($chat),
        'can_email_reply' => $canEmail,
        'account_url' => $accountUrl,
        'messages' => $messages,
        'actions' => [
            'can_claim' => !$isResolved && $assigned === 0,
            'can_live_reply' => !$isResolved,
            'can_email_reply' => $canEmail,
            'can_resume_ai' => in_array($status, ['live', 'handed_off'], true),
            'can_resolve' => !$isResolved,
            'can_reopen' => $isResolved,
            'can_archive' => !$isArchived,
            'can_unarchive' => $isArchived,
            'can_delete' => true,
        ],
    ];
}

// Lightweight JSON endpoints (dashboard poll, console list, conversation).
$ajaxMode = (string) ($_GET['ajax'] ?? '');
if ($ajaxMode === 'inbox_poll') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $awaitingIds = ChatArchive::awaitingHumanIds();
    $items = [];
    foreach (ChatArchive::listOpenForDashboard(12) as $ticket) {
        $id = (int) ($ticket['chat_id'] ?? 0);
        $name = trim((string) ($ticket['customer_name'] ?? ''));
        $email = trim((string) ($ticket['customer_email'] ?? ''));
        $label = $name !== '' ? $name : ($email !== '' ? $email : ('Chat #' . $id));
        $items[] = [
            'chat_id' => $id,
            'label' => $label,
            'preview' => (string) ($ticket['preview'] ?? ''),
            'status' => (string) ($ticket['status'] ?? 'open'),
            'awaiting' => !empty($ticket['awaiting_human']),
            'present' => ChatArchive::isShopperPresent($ticket),
            'updated' => (string) ($ticket['date_updated'] ?? ''),
            'view_url' => zen_href_link(FILENAME_SEEKMODO_AI_CHATBOT_CHATS, 'chat_id=' . $id),
        ];
    }
    echo json_encode([
        'ok' => true,
        'awaiting_count' => count($awaitingIds),
        'awaiting_ids' => $awaitingIds,
        'open_count' => ChatArchive::countOpenTickets(),
        'items' => $items,
        'list_url' => zen_href_link(FILENAME_SEEKMODO_AI_CHATBOT_CHATS, 'awaiting=1'),
        'ts' => time(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($ajaxMode === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $archived = ((string) ($_GET['archived'] ?? '') === '1') ? '1' : '0';
    $result = ChatArchive::listAdmin([
        'q' => (string) ($_GET['q'] ?? ''),
        'date_from' => (string) ($_GET['date_from'] ?? ''),
        'date_to' => (string) ($_GET['date_to'] ?? ''),
        'guest' => (string) ($_GET['guest'] ?? ''),
        'status' => (string) ($_GET['status'] ?? ''),
        'awaiting' => (string) ($_GET['awaiting'] ?? ''),
        'assigned_admin_id' => (string) ($_GET['assigned_admin_id'] ?? ''),
        'current_admin_id' => (int) ($_SESSION['admin_id'] ?? 0),
        'archived' => $archived,
        'sort' => (string) ($_GET['sort'] ?? 'updated'),
        'dir' => (string) ($_GET['dir'] ?? 'desc'),
        'page' => (int) ($_GET['page'] ?? 1),
        'per_page' => max(1, min(100, (int) ($_GET['per_page'] ?? 25))),
    ]);
    $rows = [];
    foreach ($result['rows'] as $row) {
        $rows[] = seekmodo_ai_chatbot_serialize_list_row($row);
    }
    echo json_encode([
        'ok' => true,
        'total' => (int) ($result['total'] ?? 0),
        'page' => (int) ($result['page'] ?? 1),
        'pages' => (int) ($result['pages'] ?? 1),
        'per_page' => (int) ($result['per_page'] ?? 25),
        'awaiting_count' => $archived === '1' ? 0 : ChatArchive::countAwaitingHuman(),
        'rows' => $rows,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($ajaxMode === 'conversation') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $chatRow = ChatArchive::getById((int) ($_GET['chat_id'] ?? 0));
    if ($chatRow === null) {
        echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'chat' => seekmodo_ai_chatbot_serialize_conversation($chatRow),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function seekmodo_ai_chatbot_admin_id(): int
{
    return (int) ($_SESSION['admin_id'] ?? 0);
}

function seekmodo_ai_chatbot_admin_name(): string
{
    $adminId = seekmodo_ai_chatbot_admin_id();
    if ($adminId < 1) {
        return '';
    }
    return ChatArchive::assigneeDisplayName($adminId);
}

function seekmodo_ai_chatbot_support_reply_email(): string
{
    $mail = MailConfig::address();
    if ($mail !== '') {
        return $mail;
    }
    if (defined('SEEKMODO_AI_CHATBOT_SUPPORT_EMAIL') && trim((string) SEEKMODO_AI_CHATBOT_SUPPORT_EMAIL) !== '') {
        return trim((string) SEEKMODO_AI_CHATBOT_SUPPORT_EMAIL);
    }
    if (defined('STORE_OWNER_EMAIL_ADDRESS') && trim((string) STORE_OWNER_EMAIL_ADDRESS) !== '') {
        return trim((string) STORE_OWNER_EMAIL_ADDRESS);
    }
    return defined('EMAIL_FROM') ? trim((string) EMAIL_FROM) : '';
}

function seekmodo_ai_chatbot_role_label(string $role): string
{
    if ($role === 'assistant') {
        return TEXT_SEEKMODO_AI_CHATBOT_CHATS_ASSISTANT;
    }
    if ($role === 'admin') {
        return TEXT_SEEKMODO_AI_CHATBOT_CHATS_ADMIN;
    }
    if ($role === 'agent_note') {
        return TEXT_SEEKMODO_AI_CHATBOT_CHATS_AGENT_NOTE;
    }
    return TEXT_SEEKMODO_AI_CHATBOT_CHATS_USER;
}

/**
 * @param array<string, mixed> $message
 */
function seekmodo_ai_chatbot_message_author_label(string $role, string $shopperLabel, array $message): string
{
    if ($role === 'user') {
        return $shopperLabel !== '' ? $shopperLabel : seekmodo_ai_chatbot_role_label('user');
    }
    if ($role === 'assistant') {
        return seekmodo_ai_chatbot_role_label('assistant');
    }
    $label = seekmodo_ai_chatbot_role_label($role);
    $adminName = trim((string) ($message['admin_name'] ?? ''));
    if ($adminName !== '') {
        $label .= ' — ' . $adminName;
    }
    $channel = trim((string) ($message['channel'] ?? ''));
    if ($role === 'admin' && $channel !== '') {
        $channelLabels = [
            'live' => defined('TEXT_SEEKMODO_AI_CHATBOT_CHATS_CHANNEL_LIVE') ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_CHANNEL_LIVE : 'Live',
            'email' => defined('TEXT_SEEKMODO_AI_CHATBOT_CHATS_CHANNEL_EMAIL') ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_CHANNEL_EMAIL : 'Email',
            'note' => defined('TEXT_SEEKMODO_AI_CHATBOT_CHATS_CHANNEL_NOTE') ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_CHANNEL_NOTE : 'Note',
        ];
        if (isset($channelLabels[$channel])) {
            $label .= ' (' . $channelLabels[$channel] . ')';
        }
    }
    return $label;
}

/**
 * Render shopper/assistant markdown for the admin transcript (parity with the
 * storefront widget: bold, links, product images).
 */
function seekmodo_ai_chatbot_format_message_html(string $text): string
{
    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    $safeUrl = static function (string $url): string {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $built = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $built .= ':' . $parts['port'];
        }
        $path = (string) ($parts['path'] ?? '');
        if ($path !== '') {
            $segs = explode('/', $path);
            $built .= implode('/', array_map(static function ($seg) {
                if ($seg === '') {
                    return '';
                }
                return rawurlencode(rawurldecode($seg));
            }, $segs));
        }
        if (!empty($parts['query'])) {
            $built .= '?' . $parts['query'];
        }
        return str_replace('"', '%22', $built);
    };

    $escaped = preg_replace_callback(
        '/!\[([^\]]*)\]\((https?:\/\/[^)\s]+)\)/',
        static function (array $m) use ($safeUrl): string {
            $href = $safeUrl($m[2]);
            if ($href === '') {
                return $m[0];
            }
            $alt = $m[1];
            return '<a class="ogc-msg-image-link" href="' . $href . '" target="_blank" rel="noopener noreferrer">'
                . '<img class="ogc-msg-product-image" src="' . $href . '" alt="' . $alt . '" loading="lazy" '
                . 'onerror="this.onerror=null;var a=this.closest(\'a\');if(a){a.remove();}else{this.remove();}">'
                . '</a>';
        },
        $escaped
    ) ?? $escaped;

    $escaped = preg_replace_callback(
        '/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/',
        static function (array $m) use ($safeUrl): string {
            $href = $safeUrl($m[2]);
            if ($href === '') {
                return $m[0];
            }
            return '<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
        },
        $escaped
    ) ?? $escaped;

    $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
    $escaped = preg_replace('/\n{2,}/', '</p><p class="ogc-msg-gap">', $escaped) ?? $escaped;
    $escaped = str_replace("\n", '<br>', $escaped);

    return '<p class="ogc-msg-para">' . $escaped . '</p>';
}

/** @param list<array<string, mixed>> $messages */
function seekmodo_ai_chatbot_build_reply_email_html(array $messages, string $adminReply): string
{
    $ordered = [];
    $ordered[] = [
        'role' => 'admin',
        'content' => $adminReply,
        'label' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_ADMIN,
    ];
    $history = array_reverse($messages);
    foreach ($history as $m) {
        if (!is_array($m)) {
            continue;
        }
        $role = (string) ($m['role'] ?? '');
        $content = trim((string) ($m['content'] ?? ''));
        if ($content === '' || !in_array($role, ['user', 'assistant', 'admin'], true)) {
            continue;
        }
        if ($role === 'admin' && $content === $adminReply) {
            continue;
        }
        $ordered[] = [
            'role' => $role,
            'content' => $content,
            'label' => seekmodo_ai_chatbot_role_label($role),
        ];
    }

    // Match KIP email_template_default.html header inset (margin 0 21px) so cards
    // are not wider than the nav links and do not touch the .holder border.
    $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;max-width:100%;">'
        . '<tr><td style="padding:8px 21px 16px 21px;font-family:Verdana,Arial,Helvetica,sans-serif;font-size:14px;color:#333333;">';
    foreach ($ordered as $entry) {
        $bg = ($entry['role'] ?? '') === 'user' ? '#e8f4f8' : '#f7f8fa';
        if (($entry['role'] ?? '') === 'admin') {
            $bg = '#fff4e5';
        }
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;margin:0 0 14px 0;">'
            . '<tr><td style="padding:12px 14px;border:1px solid #dde3ea;border-radius:8px;background-color:'
            . $bg . ';">';
        $html .= '<div style="font-size:11px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:#667085;margin:0 0 6px 0;">'
            . htmlspecialchars((string) $entry['label'], ENT_QUOTES, 'UTF-8') . '</div>';
        $html .= '<div style="line-height:1.45;word-wrap:break-word;">'
            . nl2br(htmlspecialchars((string) $entry['content'], ENT_QUOTES, 'UTF-8')) . '</div>';
        $html .= '</td></tr></table>';
    }
    $html .= '</td></tr></table>';
    return $html;
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'list');
$messages = [];
$isArchiveView = ($action === 'archive') || ((string) ($_GET['archived'] ?? '') === '1');
if ($action === 'archive') {
    $action = 'list';
    $_GET['archived'] = '1';
    $isArchiveView = true;
}

function seekmodo_ai_chatbot_chats_list_url(array $overrides = []): string
{
    $params = array_merge([
        'q' => (string) ($_GET['q'] ?? ''),
        'date_from' => (string) ($_GET['date_from'] ?? ''),
        'date_to' => (string) ($_GET['date_to'] ?? ''),
        'guest' => (string) ($_GET['guest'] ?? ''),
        'status' => (string) ($_GET['status'] ?? ''),
        'awaiting' => (string) ($_GET['awaiting'] ?? ''),
        'assigned_admin_id' => (string) ($_GET['assigned_admin_id'] ?? ''),
        'archived' => (string) ($_GET['archived'] ?? ''),
        'sort' => (string) ($_GET['sort'] ?? 'updated'),
        'dir' => (string) ($_GET['dir'] ?? 'desc'),
        'page' => (string) ($_GET['page'] ?? '1'),
    ], $overrides);
    $parts = [];
    foreach ($params as $k => $v) {
        $v = trim((string) $v);
        if ($v === '' || ($k === 'page' && $v === '1') || ($k === 'sort' && $v === 'updated') || ($k === 'dir' && $v === 'desc')) {
            if (!in_array($k, ['q', 'date_from', 'date_to', 'guest', 'status', 'awaiting', 'assigned_admin_id', 'archived'], true) || $v === '') {
                if ($k === 'page' && $v === '1') {
                    continue;
                }
                if (($k === 'sort' && $v === 'updated') || ($k === 'dir' && $v === 'desc')) {
                    continue;
                }
                if ($v === '') {
                    continue;
                }
            }
        }
        if ($v === '') {
            continue;
        }
        $parts[] = rawurlencode($k) . '=' . rawurlencode($v);
    }
    $qs = implode('&', $parts);
    return zen_href_link(FILENAME_SEEKMODO_AI_CHATBOT_CHATS, $qs);
}

/** zen_href_link already HTML-escapes & as &amp; — use as-is in href; decode for JS/JSON. */
function seekmodo_ai_chatbot_href_attr(string $zenHref): string
{
    return $zenHref;
}

function seekmodo_ai_chatbot_href_raw(string $zenHref): string
{
    return str_replace('&amp;', '&', $zenHref);
}

function seekmodo_ai_chatbot_sort_link(string $column, string $label): string
{
    $current = (string) ($_GET['sort'] ?? 'updated');
    $dir = strtolower((string) ($_GET['dir'] ?? 'desc'));
    $nextDir = ($current === $column && $dir === 'desc') ? 'asc' : 'desc';
    $arrow = '';
    if ($current === $column) {
        $arrow = $dir === 'asc' ? ' â†‘' : ' â†“';
    }
    $url = seekmodo_ai_chatbot_chats_list_url(['sort' => $column, 'dir' => $nextDir, 'page' => '1']);
    // zen_href_link already emits &amp; for HTML hrefs - do not htmlspecialchars again.
    return '<a href="' . $url . '">' . htmlspecialchars($label . $arrow, ENT_QUOTES) . '</a>';
}

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenOk = isset($_POST['securityToken'], $_SESSION['securityToken'])
        && hash_equals((string) $_SESSION['securityToken'], (string) $_POST['securityToken']);
    if (!$tokenOk) {
        $messages[] = ['type' => 'danger', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_TOKEN_ERROR];
    } else {
        $postAction = (string) ($_POST['action'] ?? '');
        $adminId = seekmodo_ai_chatbot_admin_id();
        $returnTo = ((string) ($_POST['return_to'] ?? '') === 'list') ? 'list' : 'view';
        if ($postAction === 'admin_reply' && !empty($_POST['chat_id'])) {
            $chatId = (int) $_POST['chat_id'];
            $replyText = trim((string) ($_POST['reply_text'] ?? ''));
            $chatRow = ChatArchive::getById($chatId);
            if ($chatRow === null) {
                $messages[] = ['type' => 'warning', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_NOT_FOUND];
            } elseif (!\SeekmodoAiChatbot\ChatArchive::canAdminReply($chatRow)) {
                $messages[] = ['type' => 'warning', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_REPLY_EMAIL_VERIFIED_REQUIRED];
            } elseif ($replyText === '') {
                $messages[] = ['type' => 'warning', 'text' => defined('TEXT_SEEKMODO_AI_CHATBOT_CHATS_REPLY_REQUIRED') ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_REPLY_REQUIRED : 'Reply text is required.'];
            } else {
                if (ChatArchive::appendAdminMessage($chatId, $replyText, $adminId)) {
                    $chatRow = ChatArchive::getById($chatId) ?? $chatRow;
                    $notify = TicketMail::notifyOtherParty($chatRow, 'admin', $replyText);
                    if (!empty($notify['ok'])) {
                        ChatAgent::stampSessionHandedOff((string) ($chatRow['session_id'] ?? ''));
                        $messages[] = ['type' => 'success', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_REPLY_SENT];
                    } else {
                        $messages[] = ['type' => 'danger', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_REPLY_FAILED];
                    }
                } else {
                    $messages[] = ['type' => 'danger', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_REPLY_FAILED];
                }
            }
            $action = 'view';
        } elseif ($postAction === 'claim_chat' && !empty($_POST['chat_id'])) {
            $chatId = (int) $_POST['chat_id'];
            $chatRow = ChatArchive::getById($chatId);
            if ($chatRow === null) {
                $messages[] = ['type' => 'warning', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_NOT_FOUND];
            } elseif (ChatArchive::claimChat($chatId, $adminId)) {
                ChatAgent::stampSessionLive((string) ($chatRow['session_id'] ?? ''), $adminId);
                $messages[] = ['type' => 'success', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_CLAIMED];
            } else {
                $messages[] = ['type' => 'danger', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_CLAIM_FAILED];
            }
            $action = 'view';
        } elseif ($postAction === 'assign_chat' && !empty($_POST['chat_id'])) {
            $chatId = (int) $_POST['chat_id'];
            $assignTo = (int) ($_POST['assigned_admin_id'] ?? 0);
            $chatRow = ChatArchive::getById($chatId);
            if ($chatRow === null) {
                $messages[] = ['type' => 'warning', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_NOT_FOUND];
            } elseif ($assignTo < 1) {
                $messages[] = ['type' => 'warning', 'text' => 'Select an admin to assign.'];
            } elseif (ChatArchive::assignToAdmin($chatId, $assignTo)) {
                $fresh = ChatArchive::getById($chatId) ?? $chatRow;
                if ((string) ($fresh['status'] ?? '') === 'live') {
                    ChatAgent::stampSessionLive((string) ($chatRow['session_id'] ?? ''), $assignTo);
                }
                $messages[] = [
                    'type' => 'success',
                    'text' => 'Assigned to ' . ChatArchive::assigneeDisplayName($assignTo) . '.',
                ];
            } else {
                $messages[] = ['type' => 'danger', 'text' => 'Could not assign this chat.'];
            }
            $action = 'view';
        } elseif ($postAction === 'live_reply' && !empty($_POST['chat_id'])) {
            $chatId = (int) $_POST['chat_id'];
            $replyText = trim((string) ($_POST['reply_text'] ?? ''));
            $chatRow = ChatArchive::getById($chatId);
            if ($chatRow === null) {
                $messages[] = ['type' => 'warning', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_NOT_FOUND];
            } elseif ($replyText === '') {
                $messages[] = ['type' => 'warning', 'text' => defined('TEXT_SEEKMODO_AI_CHATBOT_CHATS_REPLY_REQUIRED') ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_REPLY_REQUIRED : 'Reply text is required.'];
            } elseif ((string) ($chatRow['status'] ?? '') === 'resolved') {
                $messages[] = ['type' => 'warning', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_LIVE_REPLY_FAILED];
            } elseif (ChatArchive::appendLiveAdminMessage($chatId, $replyText, $adminId, seekmodo_ai_chatbot_admin_name())) {
                $sid = (string) ($chatRow['session_id'] ?? '');
                ChatAgent::appendLiveAdminToSession($sid, $replyText, seekmodo_ai_chatbot_admin_name());
                ChatAgent::stampSessionLive($sid, $adminId);
                $messages[] = ['type' => 'success', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_LIVE_REPLY_SENT];
            } else {
                $messages[] = ['type' => 'danger', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_LIVE_REPLY_FAILED];
            }
            $action = 'view';
        } elseif ($postAction === 'agent_note' && !empty($_POST['chat_id'])) {
            $chatId = (int) $_POST['chat_id'];
            $noteText = trim((string) ($_POST['note_text'] ?? ''));
            $chatRow = ChatArchive::getById($chatId);
            if ($chatRow === null) {
                $messages[] = ['type' => 'warning', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_NOT_FOUND];
            } elseif ($noteText === '') {
                $messages[] = ['type' => 'warning', 'text' => defined('TEXT_SEEKMODO_AI_CHATBOT_CHATS_NOTE_REQUIRED') ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_NOTE_REQUIRED : 'Note text is required.'];
            } elseif (ChatArchive::appendAgentNote($chatId, $noteText, $adminId, seekmodo_ai_chatbot_admin_name())) {
                $messages[] = ['type' => 'success', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_NOTE_SAVED];
            } else {
                $messages[] = ['type' => 'danger', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_NOTE_FAILED];
            }
            $action = 'view';
        } elseif ($postAction === 'resume_ai' && !empty($_POST['chat_id'])) {
            $chatId = (int) $_POST['chat_id'];
            $chatRow = ChatArchive::getById($chatId);
            if ($chatRow === null) {
                $messages[] = ['type' => 'warning', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_NOT_FOUND];
            } elseif (ChatArchive::resumeAi($chatId, $adminId)) {
                $sid = (string) ($chatRow['session_id'] ?? '');
                ChatAgent::syncSessionFromArchive($sid);
                ChatAgent::stampSessionOpen($sid);
                $name = trim((string) ($chatRow['customer_name'] ?? ''));
                $parts = $name !== '' ? (preg_split('/\s+/', $name, 2) ?: []) : [];
                $shopper = [
                    'logged_in' => (int) ($chatRow['customers_id'] ?? 0) > 0 && empty($chatRow['is_guest']),
                    'customers_id' => (int) ($chatRow['customers_id'] ?? 0),
                    'email' => strtolower(trim((string) ($chatRow['customer_email'] ?? ''))),
                    'first_name' => trim((string) ($parts[0] ?? '')),
                    'last_name' => trim((string) ($parts[1] ?? '')),
                ];
                // Zen Cart admin can wait for the reply; run synchronously.
                try {
                    ChatAgent::continueAfterResume($sid, $shopper);
                } catch (\Throwable $e) {
                    // Status is already open; shopper can still continue the chat.
                }
                $messages[] = ['type' => 'success', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_AI_RESUMED];
            } else {
                $messages[] = ['type' => 'danger', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_RESUME_FAILED];
            }
            $action = 'view';
        } elseif ($postAction === 'mark_resolved' && !empty($_POST['chat_id'])) {
            $chatId = (int) $_POST['chat_id'];
            $chatRow = ChatArchive::getById($chatId);
            if ($chatRow === null) {
                $messages[] = ['type' => 'warning', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_NOT_FOUND];
            } elseif (ChatArchive::setStatus($chatId, 'resolved', $adminId)) {
                ChatAgent::stampSessionResolved((string) ($chatRow['session_id'] ?? ''));
                ChatAgent::enqueuePostChatAnalysis($chatId, 'resolve');
                $messages[] = ['type' => 'success', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_RESOLVED];
            }
            $action = $returnTo;
            if ($returnTo === 'view') {
                $_GET['chat_id'] = (string) $chatId;
            }
        } elseif ($postAction === 'reopen_chat' && !empty($_POST['chat_id'])) {
            $chatId = (int) $_POST['chat_id'];
            $chatRow = ChatArchive::getById($chatId);
            if ($chatRow === null) {
                $messages[] = ['type' => 'warning', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_NOT_FOUND];
            } else {
                $newStatus = ChatArchive::reopenChat($chatId, $adminId);
                if ($newStatus === false) {
                    $messages[] = ['type' => 'warning', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_NOT_FOUND];
                } else {
                    $sid = (string) ($chatRow['session_id'] ?? '');
                    if ($newStatus === 'handed_off') {
                        ChatAgent::stampSessionHandedOff($sid);
                    } else {
                        ChatAgent::stampSessionOpen($sid);
                    }
                    $messages[] = ['type' => 'success', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_REOPENED];
                }
            }
            $action = $returnTo;
            if ($returnTo === 'view') {
                $_GET['chat_id'] = (string) $chatId;
            }
        } elseif ($postAction === 'delete' && !empty($_POST['chat_id'])) {
            ChatArchive::delete((int) $_POST['chat_id']);
            $messages[] = ['type' => 'success', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_DELETED];
            $action = 'list';
        } elseif ($postAction === 'archive_chat' && !empty($_POST['chat_id'])) {
            $chatId = (int) $_POST['chat_id'];
            if (ChatArchive::archiveChat($chatId)) {
                $messages[] = ['type' => 'success', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_ARCHIVED];
            } else {
                $messages[] = ['type' => 'warning', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_NOT_FOUND];
            }
            // Always return to inbox list after archiving from view/list.
            $action = 'list';
            $isArchiveView = false;
            unset($_GET['archived']);
        } elseif ($postAction === 'unarchive_chat' && !empty($_POST['chat_id'])) {
            $chatId = (int) $_POST['chat_id'];
            if (ChatArchive::unarchiveChat($chatId)) {
                $messages[] = ['type' => 'success', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_UNARCHIVED];
            } else {
                $messages[] = ['type' => 'warning', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_NOT_FOUND];
            }
            if ($returnTo === 'view') {
                $_GET['chat_id'] = (string) $chatId;
                $action = 'view';
            } else {
                unset($_GET['archived']);
                $isArchiveView = false;
                $action = 'list';
            }
        } elseif ($postAction === 'archive_selected' && !empty($_POST['chat_ids']) && is_array($_POST['chat_ids'])) {
            $n = ChatArchive::archiveMany($_POST['chat_ids']);
            $messages[] = ['type' => 'success', 'text' => sprintf(TEXT_SEEKMODO_AI_CHATBOT_CHATS_ARCHIVED_N, $n)];
            $action = 'list';
            $isArchiveView = false;
            unset($_GET['archived']);
        } elseif ($postAction === 'unarchive_selected' && !empty($_POST['chat_ids']) && is_array($_POST['chat_ids'])) {
            $n = ChatArchive::unarchiveMany($_POST['chat_ids']);
            $messages[] = ['type' => 'success', 'text' => sprintf(TEXT_SEEKMODO_AI_CHATBOT_CHATS_UNARCHIVED_N, $n)];
            unset($_GET['archived']);
            $isArchiveView = false;
            $action = 'list';
        } elseif ($postAction === 'delete_selected' && !empty($_POST['chat_ids']) && is_array($_POST['chat_ids'])) {
            $n = ChatArchive::deleteMany($_POST['chat_ids']);
            $messages[] = ['type' => 'success', 'text' => sprintf(TEXT_SEEKMODO_AI_CHATBOT_CHATS_DELETED_N, $n)];
            $action = 'list';
            if (!empty($_POST['was_archive'])) {
                $_GET['archived'] = '1';
                $isArchiveView = true;
            }
        } elseif ($postAction === 'import_files') {
            $n = ChatArchive::importSessionFiles();
            $messages[] = ['type' => 'success', 'text' => sprintf(TEXT_SEEKMODO_AI_CHATBOT_CHATS_IMPORTED, $n)];
            $action = 'list';
        } elseif ($postAction === 'convert_to_ticket' && !empty($_POST['chat_id'])) {
            $chatId = (int) $_POST['chat_id'];
            $chatRow = ChatArchive::getById($chatId);
            $agent = SeekmodoTickets::actingAgent($adminId);
            if ($chatRow === null) {
                $messages[] = ['type' => 'warning', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_NOT_FOUND];
            } elseif ($agent === null || !SeekmodoTickets::isPaired()) {
                $messages[] = ['type' => 'danger', 'text' => TEXT_SEEKMODO_TICKETS_CONVERT_FAILED];
            } else {
                $converted = SeekmodoTickets::convertChat($chatRow, $agent);
                $number = (int) ($converted['number'] ?? ($converted['ticket']['number'] ?? 0));
                if (!empty($converted['ok']) && $number > 0) {
                    ChatArchive::appendAgentNote($chatId, 'Converted to Seekmodo ticket #' . $number . '.', $adminId);
                    $ticketRedirect = str_replace('&amp;', '&', zen_href_link(FILENAME_SEEKMODO_AI_CHATBOT_TICKETS, 'ticket=' . $number));
                    if (!seekmodo_ai_chatbot_wants_json()) {
                        zen_redirect($ticketRedirect);
                    }
                    $messages[] = ['type' => 'success', 'text' => 'Converted to ticket #' . $number . '.'];
                } else {
                    $messages[] = ['type' => 'danger', 'text' => TEXT_SEEKMODO_TICKETS_CONVERT_FAILED];
                }
            }
            $action = 'view';
        }
    }

    if (seekmodo_ai_chatbot_wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $postAction = (string) ($_POST['action'] ?? '');
        $payload = [
            'ok' => true,
            'messages' => $messages,
        ];
        foreach ($messages as $m) {
            if (($m['type'] ?? '') === 'danger' && strpos((string) ($m['text'] ?? ''), 'token') !== false) {
                $payload['ok'] = false;
            }
        }
        if (!$tokenOk) {
            $payload['ok'] = false;
        }
        $cid = (int) ($_POST['chat_id'] ?? 0);
        if ($cid > 0) {
            $payload['chat_id'] = $cid;
        }
        if ($postAction === 'delete' || $postAction === 'delete_selected') {
            $payload['deleted'] = true;
        }
        if ($postAction === 'archive_chat' || $postAction === 'archive_selected') {
            $payload['archived'] = true;
        }
        if (isset($ticketRedirect)) {
            $payload['redirect'] = $ticketRedirect;
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$chat = null;
$selectedChatId = (int) ($_GET['chat_id'] ?? 0);
if ($selectedChatId < 1 && $action === 'view') {
    $selectedChatId = (int) ($_POST['chat_id'] ?? 0);
}
if ($action === 'view' || $selectedChatId > 0) {
    $chat = ChatArchive::getById($selectedChatId);
    if ($chat === null) {
        $messages[] = ['type' => 'warning', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_NOT_FOUND];
        $selectedChatId = 0;
        $action = 'list';
    } else {
        $action = 'console';
        $_GET['chat_id'] = (string) $selectedChatId;
    }
}

$result = [
    'rows' => [],
    'total' => 0,
    'page' => 1,
    'per_page' => 25,
    'pages' => 1,
];
$isArchiveView = $isArchiveView || ((string) ($_GET['archived'] ?? '') === '1');
$result = ChatArchive::listAdmin([
    'q' => (string) ($_GET['q'] ?? ''),
    'date_from' => (string) ($_GET['date_from'] ?? ''),
    'date_to' => (string) ($_GET['date_to'] ?? ''),
    'guest' => (string) ($_GET['guest'] ?? ''),
    'status' => (string) ($_GET['status'] ?? ''),
    'awaiting' => (string) ($_GET['awaiting'] ?? ''),
    'assigned_admin_id' => (string) ($_GET['assigned_admin_id'] ?? ''),
    'current_admin_id' => (int) ($_SESSION['admin_id'] ?? 0),
    'archived' => $isArchiveView ? '1' : '0',
    'sort' => (string) ($_GET['sort'] ?? 'updated'),
    'dir' => (string) ($_GET['dir'] ?? 'desc'),
    'page' => (int) ($_GET['page'] ?? 1),
    'per_page' => 25,
]);

$settingsHref = zen_href_link(FILENAME_SEEKMODO_AI_CHATBOT_SETTINGS);
$mailHref = zen_href_link(defined('FILENAME_SEEKMODO_AI_CHATBOT_MAIL') ? FILENAME_SEEKMODO_AI_CHATBOT_MAIL : 'seekmodo_ai_chatbot_mail');
$listHref = zen_href_link(FILENAME_SEEKMODO_AI_CHATBOT_CHATS);
$archiveHref = zen_href_link(FILENAME_SEEKMODO_AI_CHATBOT_CHATS, 'archived=1');
$statusLabels = [
    'open' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_STATUS_OPEN,
    'live' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_STATUS_LIVE,
    'handed_off' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_STATUS_HANDED_OFF,
    'resolved' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_STATUS_RESOLVED,
];
$awaitingCount = $isArchiveView ? 0 : ChatArchive::countAwaitingHuman();
$consoleHeading = defined('TEXT_SEEKMODO_AI_CHATBOT_CHATS_CONSOLE_HEADING')
    ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_CONSOLE_HEADING
    : TEXT_SEEKMODO_AI_CHATBOT_CHATS_HEADING;
$consoleSubtitle = defined('TEXT_SEEKMODO_AI_CHATBOT_CHATS_CONSOLE_SUBTITLE')
    ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_CONSOLE_SUBTITLE
    : TEXT_SEEKMODO_AI_CHATBOT_CHATS_SUBTITLE;
$pageTitle = $isArchiveView ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_ARCHIVE_HEADING : $consoleHeading;

$tabAll = defined('TEXT_SEEKMODO_AI_CHATBOT_CHATS_TAB_ALL') ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_TAB_ALL : 'All';
$tabAwaiting = defined('TEXT_SEEKMODO_AI_CHATBOT_CHATS_TAB_AWAITING') ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_TAB_AWAITING : 'Awaiting';
$tabLive = defined('TEXT_SEEKMODO_AI_CHATBOT_CHATS_TAB_LIVE') ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_TAB_LIVE : 'Live';
$tabOpen = defined('TEXT_SEEKMODO_AI_CHATBOT_CHATS_TAB_OPEN') ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_TAB_OPEN : 'Open';
$tabResolved = defined('TEXT_SEEKMODO_AI_CHATBOT_CHATS_TAB_RESOLVED') ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_TAB_RESOLVED : 'Resolved';
$tabArchived = defined('TEXT_SEEKMODO_AI_CHATBOT_CHATS_TAB_ARCHIVED') ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_TAB_ARCHIVED : 'Archived';
$emptySelect = defined('TEXT_SEEKMODO_AI_CHATBOT_CHATS_EMPTY_SELECT') ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_EMPTY_SELECT : 'Select a conversation';
$emptySelectHelp = defined('TEXT_SEEKMODO_AI_CHATBOT_CHATS_EMPTY_SELECT_HELP') ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_EMPTY_SELECT_HELP : 'Choose a chat from the list to read the transcript and reply.';
$composerLive = defined('TEXT_SEEKMODO_AI_CHATBOT_CHATS_COMPOSER_LIVE') ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_COMPOSER_LIVE : 'Live';
$composerEmail = defined('TEXT_SEEKMODO_AI_CHATBOT_CHATS_COMPOSER_EMAIL') ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_COMPOSER_EMAIL : 'Email';
$composerNote = defined('TEXT_SEEKMODO_AI_CHATBOT_CHATS_COMPOSER_NOTE') ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_COMPOSER_NOTE : 'Note';
$pagerPrev = defined('TEXT_SEEKMODO_AI_CHATBOT_CHATS_PAGER_PREV') ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_PAGER_PREV : '‹ Prev';
$pagerNext = defined('TEXT_SEEKMODO_AI_CHATBOT_CHATS_PAGER_NEXT') ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_PAGER_NEXT : 'Next ›';
$pagerPage = defined('TEXT_SEEKMODO_AI_CHATBOT_CHATS_PAGER_PAGE') ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_PAGER_PAGE : 'Page %d / %d';

$filterStatus = (string) ($_GET['status'] ?? '');
$filterAwaiting = (string) ($_GET['awaiting'] ?? '');
$filterAssigned = (string) ($_GET['assigned_admin_id'] ?? '');
$storeAssignees = ChatArchive::listStoreAssignees();
$currentAdminId = (int) ($_SESSION['admin_id'] ?? 0);
$activeTab = 'all';
if ($isArchiveView) {
    $activeTab = 'archived';
} elseif ($filterAwaiting === '1') {
    $activeTab = 'awaiting';
} elseif ($filterStatus === 'live') {
    $activeTab = 'live';
} elseif ($filterStatus === 'open') {
    $activeTab = 'open';
} elseif ($filterStatus === 'resolved') {
    $activeTab = 'resolved';
}

$chatSerialized = is_array($chat) ? seekmodo_ai_chatbot_serialize_conversation($chat) : null;
$token = (string) ($_SESSION['securityToken'] ?? '');
$postUrl = seekmodo_ai_chatbot_href_raw(zen_href_link(FILENAME_SEEKMODO_AI_CHATBOT_CHATS));
$listAjaxUrl = seekmodo_ai_chatbot_href_raw(zen_href_link(FILENAME_SEEKMODO_AI_CHATBOT_CHATS, 'ajax=list'));
$convAjaxUrl = seekmodo_ai_chatbot_href_raw(zen_href_link(FILENAME_SEEKMODO_AI_CHATBOT_CHATS, 'ajax=conversation'));
$pollAjaxUrl = seekmodo_ai_chatbot_href_raw(zen_href_link(FILENAME_SEEKMODO_AI_CHATBOT_CHATS, 'ajax=inbox_poll'));
?>
<!doctype html>
<html <?php echo HTML_PARAMS; ?>>
<head>
<meta charset="<?php echo CHARSET; ?>">
<title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES); ?></title>
<link rel="stylesheet" href="includes/stylesheet.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars(seekmodo_ai_chatbot_admin_asset_url('includes/css/seekmodo_ai_chatbot_admin.css'), ENT_QUOTES); ?>">
</head>
<body>
<?php require DIR_WS_INCLUDES . 'header.php'; ?>

<div class="nmx-module" id="openai-gpt-chats-app">
  <div class="nmx-page-header">
    <div>
      <div class="nmx-breadcrumb">
        <a href="<?php echo $settingsHref; ?>">Seekmodo AI Chatbot</a>
        <span class="sep"></span>
        <?php if ($isArchiveView): ?>
          <a href="<?php echo $listHref; ?>"><?php echo htmlspecialchars($consoleHeading, ENT_QUOTES); ?></a>
          <span class="sep"></span>
          <?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_ARCHIVE_HEADING, ENT_QUOTES); ?>
        <?php else: ?>
          <?php echo htmlspecialchars($consoleHeading, ENT_QUOTES); ?>
        <?php endif; ?>
      </div>
      <h2><?php echo htmlspecialchars($isArchiveView ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_ARCHIVE_HEADING : $consoleHeading, ENT_QUOTES); ?></h2>
      <p class="nmx-page-header-subtitle"><?php echo htmlspecialchars($isArchiveView ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_ARCHIVE_SUBTITLE : $consoleSubtitle, ENT_QUOTES); ?></p>
    </div>
    <div class="nmx-page-header-actions">
      <?php if ($isArchiveView): ?>
      <a href="<?php echo $listHref; ?>"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_BACK_INBOX, ENT_QUOTES); ?></a>
      <?php else: ?>
      <a href="<?php echo $archiveHref; ?>"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_ARCHIVE_LINK, ENT_QUOTES); ?></a>
      <?php endif; ?>
      <a href="<?php echo $settingsHref; ?>"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_SETTINGS_LINK, ENT_QUOTES); ?></a>
      <a href="<?php echo $mailHref; ?>"><?php echo htmlspecialchars(defined('BOX_CONFIGURATION_SEEKMODO_AI_CHATBOT_MAIL') ? BOX_CONFIGURATION_SEEKMODO_AI_CHATBOT_MAIL : 'Support Ticket Mail', ENT_QUOTES); ?></a>
    </div>
  </div>

  <div id="ogc-console-flash">
  <?php foreach ($messages as $msg): ?>
  <div class="nmx-alert nmx-alert-<?php echo htmlspecialchars($msg['type'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($msg['text'], ENT_QUOTES); ?></div>
  <?php endforeach; ?>
  </div>

  <?php if (!$isArchiveView): ?>
  <div id="ogc-awaiting-banner" class="ogc-awaiting-banner<?php echo $awaitingCount > 0 ? ' is-active' : ''; ?>"<?php echo $awaitingCount > 0 ? '' : ' hidden'; ?>>
    <strong id="ogc-awaiting-banner-text"><?php
      echo $awaitingCount > 0
        ? htmlspecialchars(sprintf(TEXT_SEEKMODO_AI_CHATBOT_CHATS_AWAITING_BANNER, $awaitingCount), ENT_QUOTES)
        : '';
    ?></strong>
    <?php if ($awaitingCount > 0): ?>
      <a class="nmx-btn nmx-btn-primary nmx-btn-sm" href="<?php echo seekmodo_ai_chatbot_chats_list_url(['awaiting' => '1', 'page' => '1', 'archived' => '']); ?>"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_AWAITING_BANNER_LINK, ENT_QUOTES); ?></a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="ogc-console" id="ogc-console" style="--ogc-list-width: 420px">
    <aside class="ogc-console-list-pane" aria-label="Conversations">
      <div class="ogc-console-list-header">
        <div class="ogc-console-list-title"><?php echo htmlspecialchars($isArchiveView ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_ARCHIVE_HEADING : $consoleHeading, ENT_QUOTES); ?></div>
        <div class="ogc-console-list-count" id="ogc-console-list-count"><?php echo (int) $result['total']; ?> <?php echo htmlspecialchars($isArchiveView ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_ARCHIVE_RESULTS : TEXT_SEEKMODO_AI_CHATBOT_CHATS_RESULTS, ENT_QUOTES); ?></div>
      </div>

      <div class="nmx-tabs ogc-console-tabs" role="tablist">
        <a role="tab" class="nmx-tab<?php echo $activeTab === 'all' ? ' is-active' : ''; ?>" aria-selected="<?php echo $activeTab === 'all' ? 'true' : 'false'; ?>" href="<?php echo $listHref; ?>"><?php echo htmlspecialchars($tabAll, ENT_QUOTES); ?></a>
        <a role="tab" class="nmx-tab<?php echo $activeTab === 'awaiting' ? ' is-active' : ''; ?>" aria-selected="<?php echo $activeTab === 'awaiting' ? 'true' : 'false'; ?>" href="<?php echo seekmodo_ai_chatbot_chats_list_url(['awaiting' => '1', 'status' => '', 'archived' => '', 'page' => '1']); ?>"><?php echo htmlspecialchars($tabAwaiting, ENT_QUOTES); ?></a>
        <a role="tab" class="nmx-tab<?php echo $activeTab === 'live' ? ' is-active' : ''; ?>" aria-selected="<?php echo $activeTab === 'live' ? 'true' : 'false'; ?>" href="<?php echo seekmodo_ai_chatbot_chats_list_url(['status' => 'live', 'awaiting' => '', 'archived' => '', 'page' => '1']); ?>"><?php echo htmlspecialchars($tabLive, ENT_QUOTES); ?></a>
        <a role="tab" class="nmx-tab<?php echo $activeTab === 'open' ? ' is-active' : ''; ?>" aria-selected="<?php echo $activeTab === 'open' ? 'true' : 'false'; ?>" href="<?php echo seekmodo_ai_chatbot_chats_list_url(['status' => 'open', 'awaiting' => '', 'archived' => '', 'page' => '1']); ?>"><?php echo htmlspecialchars($tabOpen, ENT_QUOTES); ?></a>
        <a role="tab" class="nmx-tab<?php echo $activeTab === 'resolved' ? ' is-active' : ''; ?>" aria-selected="<?php echo $activeTab === 'resolved' ? 'true' : 'false'; ?>" href="<?php echo seekmodo_ai_chatbot_chats_list_url(['status' => 'resolved', 'awaiting' => '', 'archived' => '', 'page' => '1']); ?>"><?php echo htmlspecialchars($tabResolved, ENT_QUOTES); ?></a>
        <a role="tab" class="nmx-tab<?php echo $activeTab === 'archived' ? ' is-active' : ''; ?>" aria-selected="<?php echo $activeTab === 'archived' ? 'true' : 'false'; ?>" href="<?php echo $archiveHref; ?>"><?php echo htmlspecialchars($tabArchived, ENT_QUOTES); ?></a>
      </div>

      <form method="get" class="ogc-console-filter" id="ogc-console-filter-form" action="<?php echo $isArchiveView ? $archiveHref : $listHref; ?>">
        <?php if ($isArchiveView): ?><input type="hidden" name="archived" value="1"><?php endif; ?>
        <?php if ($filterStatus !== ''): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($filterStatus, ENT_QUOTES); ?>"><?php endif; ?>
        <?php if ($filterAwaiting !== ''): ?><input type="hidden" name="awaiting" value="<?php echo htmlspecialchars($filterAwaiting, ENT_QUOTES); ?>"><?php endif; ?>
        <input class="nmx-form-control" type="search" name="q" value="<?php echo htmlspecialchars((string) ($_GET['q'] ?? ''), ENT_QUOTES); ?>" placeholder="<?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_SEARCH, ENT_QUOTES); ?>…" aria-label="<?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_SEARCH, ENT_QUOTES); ?>">
        <div class="ogc-console-filter-row">
          <select class="nmx-form-control" name="guest" aria-label="<?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_TYPE, ENT_QUOTES); ?>">
            <option value=""><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_TYPE_ALL, ENT_QUOTES); ?></option>
            <option value="0"<?php echo ((string) ($_GET['guest'] ?? '') === '0') ? ' selected' : ''; ?>><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_TYPE_CUSTOMER, ENT_QUOTES); ?></option>
            <option value="1"<?php echo ((string) ($_GET['guest'] ?? '') === '1') ? ' selected' : ''; ?>><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_GUEST, ENT_QUOTES); ?></option>
          </select>
          <select class="nmx-form-control" name="assigned_admin_id" aria-label="Assignee">
            <option value="">All assignees</option>
            <option value="me"<?php echo ($filterAssigned === 'me' || $filterAssigned === 'self') ? ' selected' : ''; ?>>Assigned to me</option>
            <option value="unassigned"<?php echo ($filterAssigned === '0' || $filterAssigned === 'unassigned') ? ' selected' : ''; ?>>Unassigned</option>
            <?php foreach ($storeAssignees as $assignee): ?>
              <?php
                $aid = (int) ($assignee['id'] ?? 0);
                if ($aid < 1) {
                    continue;
                }
              ?>
              <option value="<?php echo $aid; ?>"<?php echo ($filterAssigned === (string) $aid) ? ' selected' : ''; ?>>
                <?php echo htmlspecialchars((string) ($assignee['name'] ?? ('Admin #' . $aid)), ENT_QUOTES); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="nmx-btn nmx-btn-primary nmx-btn-sm"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_FILTER, ENT_QUOTES); ?></button>
        </div>
      </form>

      <div class="ogc-console-list-body" id="ogc-console-list-body" role="listbox" aria-label="Conversation list">
        <?php if ($result['rows'] === []): ?>
          <div class="nmx-empty-state">
            <div class="nmx-empty-state-title"><?php echo htmlspecialchars($isArchiveView ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_ARCHIVE_EMPTY : TEXT_SEEKMODO_AI_CHATBOT_CHATS_EMPTY, ENT_QUOTES); ?></div>
          </div>
        <?php else: ?>
          <?php foreach ($result['rows'] as $row): ?>
            <?php
              $rowId = (int) $row['chat_id'];
              $rowName = trim((string) $row['customer_name']);
              $rowEmail = trim((string) $row['customer_email']);
              $rowLabel = $rowName !== '' ? $rowName : ($rowEmail !== '' ? $rowEmail : TEXT_SEEKMODO_AI_CHATBOT_CHATS_GUEST);
              $rowStatus = (string) ($row['status'] ?? 'open');
              $rowAwaiting = !$isArchiveView && (!empty($row['awaiting_human']) || ChatArchive::isAwaitingHuman($row));
              $rowPresent = ChatArchive::isShopperPresent($row);
              $rowHref = seekmodo_ai_chatbot_chats_list_url(array_filter([
                  'chat_id' => (string) $rowId,
                  'q' => (string) ($_GET['q'] ?? ''),
                  'status' => $filterStatus,
                  'awaiting' => $filterAwaiting,
                  'archived' => $isArchiveView ? '1' : '',
                  'guest' => (string) ($_GET['guest'] ?? ''),
                  'page' => (string) ($_GET['page'] ?? '1'),
              ], static function ($v) { return $v !== '' && $v !== null; }));
              // Prefer simple chat_id deep link
              $rowHref = zen_href_link(FILENAME_SEEKMODO_AI_CHATBOT_CHATS, ($isArchiveView ? 'archived=1&' : '') . 'chat_id=' . $rowId);
              $isSelected = $selectedChatId === $rowId;
              $initials = seekmodo_ai_chatbot_initials($row);
            ?>
            <a href="<?php echo $rowHref; ?>"
               class="ogc-conv-item<?php echo $isSelected ? ' is-selected' : ''; ?><?php echo $rowAwaiting ? ' is-awaiting' : ''; ?>"
               role="option"
               data-chat-id="<?php echo $rowId; ?>"
               aria-selected="<?php echo $isSelected ? 'true' : 'false'; ?>"
               tabindex="<?php echo $isSelected ? '0' : '-1'; ?>">
              <span class="nmx-avatar" aria-hidden="true"><?php echo htmlspecialchars($initials, ENT_QUOTES); ?></span>
              <span class="ogc-conv-body">
                <span class="ogc-conv-top">
                  <span class="ogc-conv-name"><?php echo htmlspecialchars($rowLabel, ENT_QUOTES); ?></span>
                  <span class="ogc-conv-time"><?php echo htmlspecialchars(substr((string) $row['date_updated'], 0, 16), ENT_QUOTES); ?></span>
                </span>
                <span class="ogc-conv-preview"><?php echo htmlspecialchars((string) $row['preview'], ENT_QUOTES); ?></span>
                <span class="ogc-conv-footer">
                  <span class="nmx-pill ogc-status-<?php echo htmlspecialchars($rowStatus, ENT_QUOTES); ?>"><?php echo htmlspecialchars($statusLabels[$rowStatus] ?? $rowStatus, ENT_QUOTES); ?></span>
                  <span class="ogc-presence-dot<?php echo $rowPresent ? ' is-online' : ''; ?>" title="<?php echo htmlspecialchars($rowPresent ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_PRESENCE_ONLINE : TEXT_SEEKMODO_AI_CHATBOT_CHATS_PRESENCE_OFFLINE, ENT_QUOTES); ?>" aria-hidden="true"></span>
                  <?php if ($rowAwaiting): ?>
                    <span class="nmx-pill ogc-awaiting"><?php echo htmlspecialchars(
                      ($rowStatus === 'live' && (int) ($row['assigned_admin_id'] ?? 0) === 0)
                        ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_NEEDS_CLAIM
                        : TEXT_SEEKMODO_AI_CHATBOT_CHATS_AWAITING,
                      ENT_QUOTES
                    ); ?></span>
                  <?php endif; ?>
                </span>
              </span>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="ogc-console-list-footer">
        <div class="ogc-pager" id="ogc-console-pager" data-page="<?php echo (int) $result['page']; ?>" data-pages="<?php echo (int) $result['pages']; ?>">
          <?php if ($result['page'] > 1): ?>
            <button type="button" class="nmx-btn nmx-btn-default nmx-btn-sm" data-ogc-page="<?php echo (int) ($result['page'] - 1); ?>"><?php echo htmlspecialchars($pagerPrev, ENT_QUOTES); ?></button>
          <?php endif; ?>
          <span id="ogc-console-pager-label"><?php echo htmlspecialchars(sprintf($pagerPage, (int) $result['page'], (int) $result['pages']), ENT_QUOTES); ?></span>
          <?php if ($result['page'] < $result['pages']): ?>
            <button type="button" class="nmx-btn nmx-btn-default nmx-btn-sm" data-ogc-page="<?php echo (int) ($result['page'] + 1); ?>"><?php echo htmlspecialchars($pagerNext, ENT_QUOTES); ?></button>
          <?php endif; ?>
        </div>
        <?php if (!$isArchiveView): ?>
        <form method="post" class="ogc-toolbar-right">
          <input type="hidden" name="securityToken" value="<?php echo htmlspecialchars($token, ENT_QUOTES); ?>">
          <input type="hidden" name="action" value="import_files">
          <button type="submit" class="nmx-btn nmx-btn-ghost nmx-btn-sm"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_IMPORT, ENT_QUOTES); ?></button>
        </form>
        <?php endif; ?>
      </div>
    </aside>

    <div class="ogc-console-splitter" id="ogc-console-splitter" role="separator" aria-orientation="vertical" aria-label="Resize conversation list" tabindex="0"></div>

    <section class="ogc-console-main">
      <div class="ogc-console-empty" id="ogc-console-empty"<?php echo is_array($chat) ? ' hidden' : ''; ?>>
        <div class="nmx-empty-state">
          <div class="nmx-empty-state-icon" aria-hidden="true">💬</div>
          <div class="nmx-empty-state-title"><?php echo htmlspecialchars($emptySelect, ENT_QUOTES); ?></div>
          <div class="nmx-empty-state-body"><?php echo htmlspecialchars($emptySelectHelp, ENT_QUOTES); ?></div>
        </div>
      </div>

      <?php
        // Always render the chat shell so Ajax selection has DOM targets even when
        // no chat_id is in the initial request (otherwise clicks paint nowhere).
        $cs = is_array($chatSerialized) ? $chatSerialized : [
            'chat_id' => 0,
            'status' => 'open',
            'actions' => [],
            'present' => false,
            'awaiting_human' => false,
            'assigned_admin_id' => 0,
            'customer_name' => '',
            'customer_email' => '',
            'session_id' => '',
            'account_url' => '',
            'can_email_reply' => false,
            'messages' => [],
            'shopper_label' => '',
        ];
        $chatStatus = (string) ($cs['status'] ?? 'open');
        $isResolved = $chatStatus === 'resolved';
        $isLive = $chatStatus === 'live';
        $actions = is_array($cs['actions'] ?? null) ? $cs['actions'] : [];
        $shopperPresent = !empty($cs['present']);
        $viewAwaiting = !empty($cs['awaiting_human']);
        $viewUnclaimed = $isLive && (int) ($cs['assigned_admin_id'] ?? 0) === 0;
        $who = trim((string) ($cs['customer_name'] ?? ''));
        $email = trim((string) ($cs['customer_email'] ?? ''));
        $displayName = $who !== '' ? $who : ($email !== '' ? $email : TEXT_SEEKMODO_AI_CHATBOT_CHATS_GUEST);
        $shellChatId = (int) ($cs['chat_id'] ?? 0);
      ?>
      <div id="ogc-console-chat"<?php echo is_array($chat) ? '' : ' hidden'; ?>>
          <div class="ogc-console-header">
            <div class="ogc-console-header-info" id="ogc-console-header-info">
              <?php if (is_array($chat)): ?>
              <span class="nmx-avatar nmx-avatar-lg" aria-hidden="true"><?php echo htmlspecialchars(seekmodo_ai_chatbot_initials($chat), ENT_QUOTES); ?></span>
              <span class="ogc-console-header-text">
                <span class="ogc-console-header-name">
                  <?php echo htmlspecialchars($displayName, ENT_QUOTES); ?>
                  <span class="nmx-pill ogc-status-<?php echo htmlspecialchars($chatStatus, ENT_QUOTES); ?>"><?php echo htmlspecialchars($statusLabels[$chatStatus] ?? $chatStatus, ENT_QUOTES); ?></span>
                  <span class="nmx-pill <?php echo $shopperPresent ? 'ogc-presence-online' : 'ogc-presence-offline'; ?>"><?php echo htmlspecialchars($shopperPresent ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_PRESENCE_ONLINE : TEXT_SEEKMODO_AI_CHATBOT_CHATS_PRESENCE_OFFLINE, ENT_QUOTES); ?></span>
                  <?php if ($viewAwaiting): ?>
                    <span class="nmx-pill ogc-awaiting"><?php echo htmlspecialchars($viewUnclaimed ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_NEEDS_CLAIM : TEXT_SEEKMODO_AI_CHATBOT_CHATS_AWAITING, ENT_QUOTES); ?></span>
                  <?php endif; ?>
                </span>
                <span class="ogc-console-header-sub">
                  #<?php echo $shellChatId; ?>
                  <?php if ($email !== ''): ?>
                    · <a href="mailto:<?php echo htmlspecialchars($email, ENT_QUOTES); ?>"><?php echo htmlspecialchars($email, ENT_QUOTES); ?></a>
                    <?php if (!empty($cs['can_email_reply']) || (is_array($chat) && ChatArchive::canAdminReply($chat))): ?>
                      <span class="nmx-pill"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_EMAIL_VERIFIED, ENT_QUOTES); ?></span>
                    <?php else: ?>
                      <span class="nmx-pill"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_EMAIL_UNVERIFIED, ENT_QUOTES); ?></span>
                    <?php endif; ?>
                  <?php endif; ?>
                  <?php if (!empty($cs['account_url'])): ?>
                    · <a href="<?php echo htmlspecialchars((string) $cs['account_url'], ENT_QUOTES); ?>"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_ACCOUNT, ENT_QUOTES); ?></a>
                  <?php endif; ?>
                  · <span class="nmx-mono"><?php echo htmlspecialchars((string) ($cs['session_id'] ?? ''), ENT_QUOTES); ?></span>
                </span>
              </span>
              <?php endif; ?>
            </div>
            <div class="ogc-context-bar" id="ogc-console-context-bar">
              <?php if (is_array($chat)): ?>
              <?php if (!empty($actions['can_claim'])): ?>
              <form method="post" class="ogc-inline-form">
                <input type="hidden" name="securityToken" value="<?php echo htmlspecialchars($token, ENT_QUOTES); ?>">
                <input type="hidden" name="action" value="claim_chat">
                <input type="hidden" name="chat_id" value="<?php echo $shellChatId; ?>">
                <button type="submit" class="nmx-btn nmx-btn-primary nmx-btn-sm"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_CLAIM, ENT_QUOTES); ?></button>
              </form>
              <?php endif; ?>
              <?php if (is_array($chat) && (string) ($cs['status'] ?? '') !== 'resolved'): ?>
              <form method="post" class="ogc-inline-form" style="display:inline-flex;gap:4px;align-items:center">
                <input type="hidden" name="securityToken" value="<?php echo htmlspecialchars($token, ENT_QUOTES); ?>">
                <input type="hidden" name="action" value="assign_chat">
                <input type="hidden" name="chat_id" value="<?php echo $shellChatId; ?>">
                <select name="assigned_admin_id" class="nmx-form-control" style="min-width:8rem;height:auto;padding:2px 6px" aria-label="Assign to">
                  <option value="">Assign…</option>
                  <?php foreach ($storeAssignees as $assignee): ?>
                    <?php $aid = (int) ($assignee['id'] ?? 0); if ($aid < 1) { continue; } ?>
                    <option value="<?php echo $aid; ?>"<?php echo ((int) ($cs['assigned_admin_id'] ?? 0) === $aid) ? ' selected' : ''; ?>>
                      <?php echo htmlspecialchars((string) ($assignee['name'] ?? ('Admin #' . $aid)), ENT_QUOTES); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="nmx-btn nmx-btn-secondary nmx-btn-sm">Assign</button>
              </form>
              <?php endif; ?>
              <?php if (!empty($actions['can_resume_ai'])): ?>
              <form method="post" class="ogc-inline-form">
                <input type="hidden" name="securityToken" value="<?php echo htmlspecialchars($token, ENT_QUOTES); ?>">
                <input type="hidden" name="action" value="resume_ai">
                <input type="hidden" name="chat_id" value="<?php echo $shellChatId; ?>">
                <button type="submit" class="nmx-btn nmx-btn-secondary nmx-btn-sm"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_RESUME_AI, ENT_QUOTES); ?></button>
              </form>
              <?php endif; ?>
              <?php if (!empty($actions['can_resolve'])): ?>
              <form method="post" class="ogc-inline-form">
                <input type="hidden" name="securityToken" value="<?php echo htmlspecialchars($token, ENT_QUOTES); ?>">
                <input type="hidden" name="action" value="mark_resolved">
                <input type="hidden" name="chat_id" value="<?php echo $shellChatId; ?>">
                <button type="submit" class="nmx-btn nmx-btn-default nmx-btn-sm"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_MARK_RESOLVED, ENT_QUOTES); ?></button>
              </form>
              <?php endif; ?>
              <?php if (!empty($actions['can_reopen'])): ?>
              <form method="post" class="ogc-inline-form">
                <input type="hidden" name="securityToken" value="<?php echo htmlspecialchars($token, ENT_QUOTES); ?>">
                <input type="hidden" name="action" value="reopen_chat">
                <input type="hidden" name="chat_id" value="<?php echo $shellChatId; ?>">
                <button type="submit" class="nmx-btn nmx-btn-default nmx-btn-sm"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_REOPEN, ENT_QUOTES); ?></button>
              </form>
              <?php endif; ?>
              <?php if (!empty($actions['can_archive'])): ?>
              <form method="post" class="ogc-inline-form">
                <input type="hidden" name="securityToken" value="<?php echo htmlspecialchars($token, ENT_QUOTES); ?>">
                <input type="hidden" name="action" value="archive_chat">
                <input type="hidden" name="chat_id" value="<?php echo $shellChatId; ?>">
                <button type="submit" class="nmx-btn nmx-btn-ghost nmx-btn-sm"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_ARCHIVE, ENT_QUOTES); ?></button>
              </form>
              <?php endif; ?>
              <?php if (!empty($actions['can_unarchive'])): ?>
              <form method="post" class="ogc-inline-form">
                <input type="hidden" name="securityToken" value="<?php echo htmlspecialchars($token, ENT_QUOTES); ?>">
                <input type="hidden" name="action" value="unarchive_chat">
                <input type="hidden" name="return_to" value="view">
                <input type="hidden" name="chat_id" value="<?php echo $shellChatId; ?>">
                <button type="submit" class="nmx-btn nmx-btn-ghost nmx-btn-sm"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_UNARCHIVE, ENT_QUOTES); ?></button>
              </form>
              <?php endif; ?>
              <?php if (SeekmodoTickets::isPaired()): ?>
              <form method="post" class="ogc-inline-form">
                <input type="hidden" name="securityToken" value="<?php echo htmlspecialchars($token, ENT_QUOTES); ?>">
                <input type="hidden" name="action" value="convert_to_ticket">
                <input type="hidden" name="chat_id" value="<?php echo $shellChatId; ?>">
                <button type="submit" class="nmx-btn nmx-btn-default nmx-btn-sm"><?php echo htmlspecialchars(TEXT_SEEKMODO_TICKETS_CONVERT, ENT_QUOTES); ?></button>
              </form>
              <?php endif; ?>
              <form method="post" class="ogc-inline-form" onsubmit="return confirm('<?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_DELETE_CONFIRM, ENT_QUOTES); ?>');">
                <input type="hidden" name="securityToken" value="<?php echo htmlspecialchars($token, ENT_QUOTES); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="chat_id" value="<?php echo $shellChatId; ?>">
                <button type="submit" class="nmx-btn nmx-btn-danger nmx-btn-sm"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_DELETE, ENT_QUOTES); ?></button>
              </form>
              <?php endif; ?>
            </div>
          </div>

          <div class="ogc-console-transcript" id="ogc-console-transcript">
            <div class="ogc-transcript">
              <?php if (is_array($chat)): ?>
              <?php foreach (($cs['messages'] ?? []) as $m): ?>
                <?php
                  if (!is_array($m)) {
                      continue;
                  }
                  $role = (string) ($m['role'] ?? 'user');
                  if ($role === 'user') {
                      $label = (string) ($cs['shopper_label'] ?? seekmodo_ai_chatbot_role_label($role));
                  } elseif ($role === 'assistant') {
                      $label = seekmodo_ai_chatbot_role_label($role);
                  } else {
                      $label = seekmodo_ai_chatbot_role_label($role);
                      if (!empty($m['admin_name'])) {
                          $label .= ' — ' . (string) $m['admin_name'];
                      }
                  }
                  $msgDate = trim((string) ($m['date'] ?? ''));
                ?>
                <div class="ogc-msg-row<?php echo in_array($role, ['admin', 'assistant'], true) ? ' is-outgoing' : ''; ?>">
                  <span class="nmx-avatar nmx-avatar-sm" aria-hidden="true"><?php
                    $iniSrc = $role === 'user' ? (string) ($cs['shopper_label'] ?? 'S') : $label;
                    echo htmlspecialchars(strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/', '', $iniSrc) ?: '?', 0, 2)), ENT_QUOTES);
                  ?></span>
                  <div class="ogc-msg ogc-msg-<?php echo htmlspecialchars($role, ENT_QUOTES); ?>">
                    <div class="ogc-msg-role">
                      <?php echo htmlspecialchars($label, ENT_QUOTES); ?>
                      <?php if ($msgDate !== ''): ?>
                        <span class="ogc-msg-time"><?php echo htmlspecialchars($msgDate, ENT_QUOTES); ?></span>
                      <?php endif; ?>
                    </div>
                    <div class="ogc-msg-body ogc-msg-body-md"><?php echo seekmodo_ai_chatbot_format_message_html((string) ($m['content'] ?? '')); ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>

          <div class="ogc-composer" id="ogc-console-composer">
            <div class="nmx-segmented ogc-composer-segmented" role="tablist" aria-label="Composer mode">
              <button type="button" class="nmx-segmented-btn is-active" role="tab" data-composer-mode="live" aria-selected="true"<?php echo empty($actions['can_live_reply']) ? ' disabled' : ''; ?>><?php echo htmlspecialchars($composerLive, ENT_QUOTES); ?></button>
              <button type="button" class="nmx-segmented-btn" role="tab" data-composer-mode="note" aria-selected="false"><?php echo htmlspecialchars($composerNote, ENT_QUOTES); ?></button>
            </div>

            <div class="ogc-composer-panel is-active" data-composer-panel="live" role="tabpanel">
              <p class="ogc-composer-hint ogc-sub"><?php echo htmlspecialchars($shopperPresent || $isLive ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_LIVE_REPLY_HELP : TEXT_SEEKMODO_AI_CHATBOT_CHATS_LIVE_REPLY_OFFLINE, ENT_QUOTES); ?></p>
              <form method="post" class="ogc-reply-form">
                <input type="hidden" name="securityToken" value="<?php echo htmlspecialchars($token, ENT_QUOTES); ?>">
                <input type="hidden" name="action" value="live_reply">
                <input type="hidden" name="chat_id" value="<?php echo $shellChatId; ?>">
                <textarea class="nmx-form-control ogc-composer-textarea" name="reply_text" rows="3" data-role="composer-textarea" placeholder="Write a live reply…"<?php echo empty($actions['can_live_reply']) ? ' disabled' : (is_array($chat) ? ' autofocus' : ''); ?>></textarea>
                <div class="ogc-composer-footer ogc-reply-actions">
                  <button type="submit" class="nmx-btn nmx-btn-primary" data-role="composer-submit"<?php echo empty($actions['can_live_reply']) ? ' disabled' : ''; ?>><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_SEND_LIVE_REPLY, ENT_QUOTES); ?></button>
                </div>
              </form>
            </div>

            <div class="ogc-composer-panel" data-composer-panel="note" role="tabpanel" hidden>
              <p class="ogc-composer-hint ogc-sub"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_INTERNAL_NOTE_HELP, ENT_QUOTES); ?></p>
              <form method="post" class="ogc-reply-form">
                <input type="hidden" name="securityToken" value="<?php echo htmlspecialchars($token, ENT_QUOTES); ?>">
                <input type="hidden" name="action" value="agent_note">
                <input type="hidden" name="chat_id" value="<?php echo $shellChatId; ?>">
                <textarea class="nmx-form-control ogc-composer-textarea" name="note_text" rows="3" data-role="composer-textarea" placeholder="Add an internal note…"></textarea>
                <div class="ogc-composer-footer ogc-reply-actions">
                  <button type="submit" class="nmx-btn nmx-btn-default" data-role="composer-submit"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_CHATS_SAVE_NOTE, ENT_QUOTES); ?></button>
                </div>
              </form>
            </div>
          </div>
      </div>
    </section>
  </div>
</div>

<script>
window.SeekmodoChatConsoleConfig = <?php echo json_encode([
    'endpoints' => [
        'list' => $listAjaxUrl,
        'conversation' => $convAjaxUrl,
        'poll' => $pollAjaxUrl,
        'post' => $postUrl,
    ],
    'securityToken' => $token,
    'selectedChatId' => $selectedChatId > 0 ? $selectedChatId : null,
    'filters' => [
        'q' => (string) ($_GET['q'] ?? ''),
        'status' => $filterStatus,
        'awaiting' => $filterAwaiting,
        'assigned_admin_id' => $filterAssigned,
        'archived' => $isArchiveView ? '1' : '',
        'guest' => (string) ($_GET['guest'] ?? ''),
        'date_from' => (string) ($_GET['date_from'] ?? ''),
        'date_to' => (string) ($_GET['date_to'] ?? ''),
        'sort' => (string) ($_GET['sort'] ?? 'updated'),
        'dir' => (string) ($_GET['dir'] ?? 'desc'),
        'page' => (string) ($_GET['page'] ?? '1'),
    ],
    'assignees' => $storeAssignees,
    'currentAdminId' => $currentAdminId,
    'ticketsEnabled' => SeekmodoTickets::isPaired(),
    'i18n' => [
        'guest' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_GUEST,
        'noEmail' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_NO_EMAIL,
        'verified' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_EMAIL_VERIFIED,
        'unverified' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_EMAIL_UNVERIFIED,
        'accountLink' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_ACCOUNT,
        'presenceOnline' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_PRESENCE_ONLINE,
        'presenceOffline' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_PRESENCE_OFFLINE,
        'awaitingPill' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_AWAITING,
        'needsClaimPill' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_NEEDS_CLAIM,
        'statusLabels' => $statusLabels,
        'roleLabels' => [
            'user' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_USER,
            'assistant' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_ASSISTANT,
            'admin' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_ADMIN,
            'agent_note' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_AGENT_NOTE,
        ],
        'emptyChatTitle' => $emptySelect,
        'emptyChatBody' => $emptySelectHelp,
        'composerLive' => $composerLive,
        'composerEmail' => $composerEmail,
        'composerNote' => $composerNote,
        'sendLiveReply' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_SEND_LIVE_REPLY,
        'sendEmailReply' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_SEND_REPLY,
        'saveNote' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_SAVE_NOTE,
        'claim' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_CLAIM,
        'resumeAi' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_RESUME_AI,
        'resolve' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_MARK_RESOLVED,
        'reopen' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_REOPEN,
        'archive' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_ARCHIVE,
        'unarchive' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_UNARCHIVE,
        'delete' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_DELETE,
        'deleteConfirm' => TEXT_SEEKMODO_AI_CHATBOT_CHATS_DELETE_CONFIRM,
        'convertToTicket' => TEXT_SEEKMODO_TICKETS_CONVERT,
        'awaitingBannerOne' => defined('TEXT_SEEKMODO_AI_CHATBOT_CHATS_TITLE_AWAITING_ONE') ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_TITLE_AWAITING_ONE : '1 chat needs a human — claim live or reply now.',
        'awaitingBannerMany' => defined('TEXT_SEEKMODO_AI_CHATBOT_CHATS_TITLE_AWAITING_MANY') ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_TITLE_AWAITING_MANY : '%d chats need a human — claim live or reply now.',
        'pagerPrev' => $pagerPrev,
        'pagerNext' => $pagerNext,
        'pagerPage' => $pagerPage,
        'resultsLabel' => $isArchiveView ? TEXT_SEEKMODO_AI_CHATBOT_CHATS_ARCHIVE_RESULTS : TEXT_SEEKMODO_AI_CHATBOT_CHATS_RESULTS,
    ],
    'pollMs' => 3000,
    'awaitingListUrl' => seekmodo_ai_chatbot_href_raw(seekmodo_ai_chatbot_chats_list_url(['awaiting' => '1', 'page' => '1', 'archived' => ''])),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="<?php echo htmlspecialchars(seekmodo_ai_chatbot_admin_asset_url('includes/js/seekmodo_ai_chatbot_console.js'), ENT_QUOTES); ?>"></script>

<?php require DIR_WS_INCLUDES . 'footer.php'; ?>
</body>
</html>
<?php require DIR_WS_INCLUDES . 'application_bottom.php'; ?>

