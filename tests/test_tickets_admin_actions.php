<?php
declare(strict_types=1);

// SM-154: the Zen Cart Tickets proxy allows the store-admin ticket actions
// the shared UI needs, keeps tenant-config / portal actions out, and the page
// glue posts the action that SeekmodoTicketsAdmin passes as request(action, body).

$root = __DIR__ . '/../zc_plugins/SeekmodoAiChatbot/v1.1.1';
require $root . '/catalog/includes/library/SeekmodoAiChatbot/SeekmodoTickets.php';
use SeekmodoAiChatbot\SeekmodoTickets;

$allowed = SeekmodoTickets::ADMIN_ACTIONS;
$mustAllow = [
    'list', 'get', 'reply', 'note', 'forward_create', 'forward_reply',
    'attachment_get', 'participants_add', 'participants_remove', 'macros_list',
];
$mustBlock = [
    'inbound_email', 'shopper_list', 'shopper_get', 'shopper_reply', 'csat_submit',
    'settings_set', 'macros_set', 'macros_remove', 'suppressions_list', 'suppressions_add',
    'suppressions_remove', 'quarantine_promote', 'quarantine_reject', 'merge', 'bulk_update',
];
foreach ($mustAllow as $action) {
    if (!in_array($action, $allowed, true)) {
        fwrite(STDERR, "FAIL: {$action} must be allowed\n");
        exit(1);
    }
}
foreach ($mustBlock as $action) {
    if (in_array($action, $allowed, true)) {
        fwrite(STDERR, "FAIL: {$action} must stay blocked\n");
        exit(1);
    }
}

$page = (string) file_get_contents($root . '/admin/seekmodo_ai_chatbot_tickets.php');
if (strpos($page, 'function request(action, body)') === false
    || strpos($page, '{ action: action }') === false) {
    fwrite(STDERR, "FAIL: Tickets page glue must merge request(action, body) into the payload\n");
    exit(1);
}

echo "OK tickets admin actions\n";
