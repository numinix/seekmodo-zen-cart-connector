<?php
/**
 * Seekmodo AI Chatbot ? admin settings page.
 */
require 'includes/application_top.php';

if (!defined('IS_ADMIN_FLAG') || !IS_ADMIN_FLAG) {
    die('Illegal Access');
}

// Prefer copied admin includes; fall back to zc_plugins after deploy wipe.
// The seed/ensure calls below use seekmodo_ai_chatbot_require_library(),
// which is defined by seekmodo_ai_chatbot_load.php via this bootstrap.
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

/**
 * Insert missing configuration keys (for stores that already installed an older v1.0.0).
 *
 * @param object $db
 * @param array<string, array{title:string,value:string,description:string,sort:int}> $defs
 */
function seekmodo_ai_chatbot_ensure_config_keys(object $db, array $defs): void
{
    $groupId = 0;
    $group = $db->Execute(
        "SELECT configuration_group_id FROM " . TABLE_CONFIGURATION_GROUP
        . " WHERE configuration_group_title = 'Seekmodo AI Chatbot' LIMIT 1"
    );
    if ($group && !$group->EOF) {
        $groupId = (int) $group->fields['configuration_group_id'];
    }
    if ($groupId < 1) {
        $probe = $db->Execute(
            "SELECT configuration_group_id FROM " . TABLE_CONFIGURATION
            . " WHERE configuration_key = 'SEEKMODO_AI_CHATBOT_ENABLED' LIMIT 1"
        );
        if ($probe && !$probe->EOF) {
            $groupId = (int) $probe->fields['configuration_group_id'];
        }
    }
    if ($groupId < 1) {
        return;
    }

    foreach ($defs as $key => $meta) {
        $check = $db->Execute(
            "SELECT configuration_key FROM " . TABLE_CONFIGURATION
            . " WHERE configuration_key = '" . $db->prepareInput($key) . "' LIMIT 1"
        );
        if ($check && !$check->EOF) {
            continue;
        }
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " (
                configuration_title,
                configuration_key,
                configuration_value,
                configuration_description,
                configuration_group_id,
                sort_order,
                date_added,
                use_function,
                set_function
             ) VALUES (
                '" . $db->prepareInput($meta['title']) . "',
                '" . $db->prepareInput($key) . "',
                '" . $db->prepareInput($meta['value']) . "',
                '" . $db->prepareInput($meta['description']) . "',
                " . (int) $groupId . ",
                " . (int) $meta['sort'] . ",
                NOW(),
                NULL,
                NULL
             )"
        );
    }
}

/**
 * Zen Cart configuration_value is commonly latin1_swedish_ci. Non-latin1 bytes
 * (e.g. UTF-8 em dashes) get corrupted and break OpenAI json_encode ? openai_api_error.
 */
function seekmodo_ai_chatbot_latin1_safe_text(string $text): string
{
    $map = [
        "\xE2\x80\x94" => ' - ', // em dash
        "\xE2\x80\x93" => ' - ', // en dash
        "\xE2\x80\x9C" => '"',
        "\xE2\x80\x9D" => '"',
        "\xE2\x80\x98" => "'",
        "\xE2\x80\x99" => "'",
        "\xC2\xA0" => ' ',
        "\x97" => ' - ',
        "\x96" => '-',
        "\x93" => '"',
        "\x94" => '"',
        "\x9d" => ' - ',
        "\x91" => "'",
        "\x92" => "'",
    ];
    $text = strtr($text, $map);
    // Drop any remaining non-ASCII so latin1 storage cannot corrupt prompts.
    return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $text) ?? $text;
}

function seekmodo_ai_chatbot_sanitize_hex_color(string $value, string $fallback): string
{
    $value = trim($value);
    if (preg_match('/^#([A-Fa-f0-9]{6})$/', $value)) {
        return strtoupper($value);
    }
    if (preg_match('/^#([A-Fa-f0-9]{3})$/', $value, $m)) {
        $h = $m[1];
        return strtoupper('#' . $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2]);
    }
    return $fallback;
}

/**
 * Process the Widget Agent Icon upload / removal from the settings form.
 *
 * Stores files under catalog images/seekmodo_ai_chatbot/ so the storefront can
 * serve them without extra routing. Returns a $messages entry when something
 * happened, or null when no icon action was requested.
 *
 * @return array{type:string,text:string}|null
 */
function seekmodo_ai_chatbot_handle_widget_icon(): ?array
{
    $dir = rtrim((string) DIR_FS_CATALOG, '/\\') . '/images/seekmodo_ai_chatbot';
    $key = 'SEEKMODO_AI_CHATBOT_WIDGET_ICON';

    if (!empty($_POST['widget_icon_remove'])) {
        foreach (glob($dir . '/agent-icon.*') ?: [] as $old) {
            @unlink($old);
        }
        seekmodo_ai_chatbot_save_config_value($key, '');
        return ['type' => 'success', 'text' => TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON_REMOVED];
    }

    $file = $_FILES['widget_icon'] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
        return ['type' => 'error', 'text' => TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON_UPLOAD_FAIL];
    }
    if ((int) $file['size'] > 512 * 1024) {
        return ['type' => 'error', 'text' => TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON_TOO_LARGE];
    }
    $info = @getimagesize((string) $file['tmp_name']);
    $exts = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!is_array($info) || !isset($exts[$info[2]])) {
        return ['type' => 'error', 'text' => TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON_BAD_TYPE];
    }
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['type' => 'error', 'text' => TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON_DIR_FAIL];
    }
    // Replace any previous icon regardless of extension.
    foreach (glob($dir . '/agent-icon.*') ?: [] as $old) {
        @unlink($old);
    }
    $dest = $dir . '/agent-icon.' . $exts[$info[2]];
    if (!@move_uploaded_file((string) $file['tmp_name'], $dest)) {
        return ['type' => 'error', 'text' => TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON_UPLOAD_FAIL];
    }
    @chmod($dest, 0644);
    seekmodo_ai_chatbot_save_config_value($key, 'images/seekmodo_ai_chatbot/agent-icon.' . $exts[$info[2]]);
    return ['type' => 'success', 'text' => TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON_SAVED];
}

function seekmodo_ai_chatbot_save_config_value(string $key, string $value): void
{
    global $db;
    $db->Execute(
        "UPDATE " . TABLE_CONFIGURATION . "
            SET configuration_value = '" . $db->prepareInput($value) . "'
          WHERE configuration_key = '" . $db->prepareInput($key) . "'"
    );
}

/**
 * Storefront/admin preview URL for the configured agent icon.
 */
function seekmodo_ai_chatbot_widget_icon_url(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $value)) {
        return $value;
    }
    $rel = ltrim(str_replace('\\', '/', $value), '/');
    if ($rel === '' || strpos($rel, '..') !== false) {
        return '';
    }
    if (defined('DIR_FS_CATALOG') && !is_file(rtrim((string) DIR_FS_CATALOG, '/\\') . '/' . $rel)) {
        return '';
    }
    $server = defined('HTTPS_CATALOG_SERVER') ? HTTPS_CATALOG_SERVER : (defined('HTTP_CATALOG_SERVER') ? HTTP_CATALOG_SERVER : HTTP_SERVER);
    $catalog = defined('DIR_WS_CATALOG') ? DIR_WS_CATALOG : '/';
    return rtrim($server, '/') . rtrim($catalog, '/') . '/' . $rel;
}

function seekmodo_ai_chatbot_default_additional_rules(): string
{
    return <<<'TXT'
- Answer inquiries using only this business's provided knowledge and tool results.
- Stay inside this store's catalog: never invent product types or gift ideas (blankets, gardening tools, jewellery, photo albums, tea sets, etc.) when search is empty — ask one clarifying question and search again.
- If you are unsure, say your knowledge is limited to the information provided by this business.
- If a message contains multiple questions, answer them one by one.
- Refuse to tell jokes.
- When discussing a product from knowledge or tools, include its storefront URL in plain text.
- Only ask the shopper to email engraving/message text if it cannot be entered on the product page.
- Never say the store is closed; give opening hours instead.
- Prefer not to share the telephone number; offer a support ticket (or the configured support email) for human help.
- For shipping / delivery ETA / destination questions: call mcp_kb_topic_get (shipping or international) first when those topics exist; use kb_search for long-form pages. Never invent carrier SLAs or claim the store does not ship to a country - offer a support ticket for unclear destinations.
- For returns / exchanges: call mcp_kb_topic_get (returns) when available, then mcp_return_request for logged-in shoppers. Never invent refunds or RMA numbers.
- For account / login / newsletter: call mcp_kb_topic_get (account). Forgot password: collect email and call mcp_password_reset (never reveal whether an account exists). Newsletter (logged-in): mcp_newsletter_set. Never invent account state.
- For photo upload / image personalisation / "can I upload a photo" questions: call mcp_kb_topic_get (personalisation) first, every time. Many products support photo upload on the product page — never claim sitewide that uploads are unsupported. No product named: ask one clarifying question before searching. Named product: seekmodo_product_search + mcp_product_get, answer from personalisation_capabilities.supports_photo_upload. Broad "which products allow photo uploads": seekmodo_product_search with photo_upload_only=true and list results — never guess from descriptions.
- For product care / cleaning: call mcp_kb_topic_get (care); use kb_search for long-form care pages; never invent care steps.
- For gift wrap / gift bag / sending direct to a recipient: call mcp_kb_topic_get (gift-packaging) first — this is a per-product option, confirm via seekmodo_product_search + mcp_product_get for a named item.
- For payment methods: call mcp_kb_topic_get (payments) first; never invent a gateway; never collect card details in chat.
- For gift voucher / store credit balance, purchase, or redemption: call mcp_kb_topic_get (gift-vouchers) first; balance requires login + mcp_store_credit_balance; never invent amounts.
- After a genuinely useful tool-backed product-capability answer, you may silently call mcp_kb_fact_propose with a short claim + the evidence the tool returned (never tell the shopper). Never propose prices, refunds, shipping promises, or medical/safety claims. Nothing is auto-published; an operator reviews every draft.

- For warranty policy or damaged-item questions: call mcp_kb_topic_get with topic_id warranty BEFORE answering; then mcp_warranty_request for claims. Never invent approvals or refunds.
- For missing order confirmation emails: logged-in shoppers use mcp_order_confirmation_resend.
- For address changes: call mcp_kb_topic_get (order-address-change). Prefer My Account self-service when in-chat address tools are unavailable; escalate with a support ticket when the order is not editable/shipped.
- For order cancel: call mcp_kb_topic_get (order-cancel). Use cancel tools only when available; otherwise offer a support ticket - do not invent refunds.
- For engraving/personalisation text changes on a logged-in shopper's order: call mcp_order_lookup (and mcp_kb_topic_get order-edit). Follow verify.engraving_editable. If true, share lines[].personalise_url as a Markdown link to the My Account editor. Use mcp_order_engraving_update only when that tool is available. Do not invent URLs. If false, explain the order can no longer be changed and offer a support ticket.
- If the shopper asks for a phone number or human/customer support, offer to open a support ticket in chat (collect their email) or direct them to the configured support email.
- Product design-change requests go to customer support via a support ticket or email; do not promise design changes yourself.
- Bulk-order and logo requests: call mcp_kb_topic_get (bulk-orders), then mcp_bulk_request; never quote a price.
- Order status and tracking: logged-in shoppers can look up their own order with the order number. Ask guests to log in first. When tracking exists, share one tracking link.
TXT;
}

/**
 * Fill ADDITIONAL_RULES when the row exists but the value was never seeded.
 */
function seekmodo_ai_chatbot_seed_empty_additional_rules(object $db): void
{
    $key = 'SEEKMODO_AI_CHATBOT_ADDITIONAL_RULES';
    $row = $db->Execute(
        "SELECT configuration_value FROM " . TABLE_CONFIGURATION
        . " WHERE configuration_key = '" . $db->prepareInput($key) . "' LIMIT 1"
    );
    if (!$row || $row->EOF) {
        return;
    }
    if (trim((string) $row->fields['configuration_value']) !== '') {
        return;
    }
    $rules = seekmodo_ai_chatbot_latin1_safe_text(seekmodo_ai_chatbot_default_additional_rules());
    $db->Execute(
        "UPDATE " . TABLE_CONFIGURATION
        . " SET configuration_value = '" . $db->prepareInput($rules) . "'"
        . " WHERE configuration_key = '" . $db->prepareInput($key) . "' LIMIT 1"
    );
}

/**
 * Seed STORE_CONTEXT from the live catalog when empty so dig gets a profile
 * without an admin visit. Merchants can edit afterwards.
 */
function seekmodo_ai_chatbot_seed_empty_store_context(object $db): void
{
    $key = 'SEEKMODO_AI_CHATBOT_STORE_CONTEXT';
    $row = $db->Execute(
        "SELECT configuration_value FROM " . TABLE_CONFIGURATION
        . " WHERE configuration_key = '" . $db->prepareInput($key) . "' LIMIT 1"
    );
    if (!$row || $row->EOF) {
        return;
    }
    if (trim((string) $row->fields['configuration_value']) !== '') {
        return;
    }
    seekmodo_ai_chatbot_require_library(['StoreContext.php'], false);
    if (!class_exists('\\SeekmodoAiChatbot\\StoreContext')) {
        return;
    }
    $draft = seekmodo_ai_chatbot_latin1_safe_text(
        \SeekmodoAiChatbot\StoreContext::buildFromCatalog()
    );
    if ($draft === '') {
        return;
    }
    $db->Execute(
        "UPDATE " . TABLE_CONFIGURATION
        . " SET configuration_value = '" . $db->prepareInput($draft) . "'"
        . " WHERE configuration_key = '" . $db->prepareInput($key) . "' LIMIT 1"
    );
}

/**
 * Ensure chat history admin page + DB table exist for upgraded installs.
 */
function seekmodo_ai_chatbot_migrate_mail_admin_page(): void
{
    global $db;
    if (!isset($db) || !defined('TABLE_ADMIN_PAGES')) {
        return;
    }
    $db->Execute(
        "UPDATE " . TABLE_ADMIN_PAGES . "
         SET menu_key = 'configuration',
             language_key = 'BOX_CONFIGURATION_SEEKMODO_AI_CHATBOT_MAIL'
         WHERE page_key = 'seekmodoAiChatbotMail'
           AND (menu_key <> 'configuration'
                OR language_key <> 'BOX_CONFIGURATION_SEEKMODO_AI_CHATBOT_MAIL')"
    );
}

function seekmodo_ai_chatbot_ensure_chat_history_page(object $db): void
{
    $prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
    $table = $prefix . 'seekmodo_ai_chatbot_chats';
    $check = $db->Execute("SHOW TABLES LIKE '" . $db->prepareInput($table) . "'");
    if (!$check || $check->EOF) {
        $db->Execute(
            'CREATE TABLE IF NOT EXISTS `' . str_replace('`', '``', $table) . '` (
                `chat_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `session_id` VARCHAR(64) NOT NULL,
                `customers_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `customer_email` VARCHAR(96) NOT NULL DEFAULT \'\',
                `email_verified` TINYINT(1) NOT NULL DEFAULT 0,
                `customer_name` VARCHAR(128) NOT NULL DEFAULT \'\',
                `is_guest` TINYINT(1) NOT NULL DEFAULT 1,
                `preview` VARCHAR(255) NOT NULL DEFAULT \'\',
                `message_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `tickets_sent` INT UNSIGNED NOT NULL DEFAULT 0,
                `messages_json` MEDIUMTEXT NOT NULL,
                `status` VARCHAR(16) NOT NULL DEFAULT \'open\',
                `resolved_at` DATETIME NULL DEFAULT NULL,
                `resolved_by_admin_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `handed_off_at` DATETIME NULL DEFAULT NULL,
                `last_admin_reply_at` DATETIME NULL DEFAULT NULL,
                `date_created` DATETIME NOT NULL,
                `date_updated` DATETIME NOT NULL,
                PRIMARY KEY (`chat_id`),
                UNIQUE KEY `idx_seekmodo_ai_chat_session` (`session_id`),
                KEY `idx_seekmodo_ai_chat_email` (`customer_email`),
                KEY `idx_seekmodo_ai_chat_customers` (`customers_id`),
                KEY `idx_seekmodo_ai_chat_status` (`status`),
                KEY `idx_seekmodo_ai_chat_updated` (`date_updated`),
                KEY `idx_seekmodo_ai_chat_created` (`date_created`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

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
    seekmodo_ai_chatbot_require_library(['ChatArchive.php']);
    \SeekmodoAiChatbot\ChatArchive::ensureTable();

    if (function_exists('zen_register_admin_pages')) {
        zen_register_admin_pages(
            'seekmodoAiChatbotChats',
            'BOX_TOOLS_SEEKMODO_AI_CHATBOT_CHATS',
            'FILENAME_SEEKMODO_AI_CHATBOT_CHATS',
            '',
            'tools',
            'Y',
            521
        );
        zen_register_admin_pages(
            'seekmodoAiChatbotMail',
            'BOX_CONFIGURATION_SEEKMODO_AI_CHATBOT_MAIL',
            'FILENAME_SEEKMODO_AI_CHATBOT_MAIL',
            '',
            'configuration',
            'Y',
            522
        );
        seekmodo_ai_chatbot_migrate_mail_admin_page();
        return;
    }
    if (function_exists('zen_register_admin_page') && function_exists('zen_page_key_exists')) {
        if (!zen_page_key_exists('seekmodoAiChatbotChats')) {
            zen_register_admin_page(
                'seekmodoAiChatbotChats',
                'BOX_TOOLS_SEEKMODO_AI_CHATBOT_CHATS',
                'FILENAME_SEEKMODO_AI_CHATBOT_CHATS',
                '',
                'tools',
                'Y',
                521
            );
        }
        if (!zen_page_key_exists('seekmodoAiChatbotMail')) {
            zen_register_admin_page(
                'seekmodoAiChatbotMail',
                'BOX_CONFIGURATION_SEEKMODO_AI_CHATBOT_MAIL',
                'FILENAME_SEEKMODO_AI_CHATBOT_MAIL',
                '',
                'configuration',
                'Y',
                522
            );
        }
    }
    seekmodo_ai_chatbot_migrate_mail_admin_page();
}

seekmodo_ai_chatbot_ensure_config_keys($db, [
    'SEEKMODO_AI_CHATBOT_SUPPORT_EMAIL' => [
        'title' => 'Chatbot: Support Email',
        'value' => '',
        'description' => 'Inbox that receives chatbot support tickets (chat history emailed from the shopper address). Leave empty to disable tickets.',
        'sort' => 105,
    ],
    'SEEKMODO_AI_CHATBOT_ALLOW_ORDER_MUTATIONS' => [
        'title' => 'Chatbot: Allow In-Chat Order Mutations',
        'value' => 'false',
        'description' => 'When true, the chatbot may change editable orders in chat (engraving text, address, shipping upgrade, cancel). When false (default), it only looks up orders and shares My Account self-service edit links.',
        'sort' => 55,
    ],
    'SEEKMODO_AI_CHATBOT_SESSION_IDLE_MINUTES' => [
        'title' => 'Chatbot: Session Idle Minutes',
        'value' => '30',
        'description' => 'End a chat session after this many minutes without activity (default 30).',
        'sort' => 106,
    ],
    'SEEKMODO_AI_CHATBOT_WIDGET_PRIMARY_COLOR' => [
        'title' => 'Chatbot: Widget Primary Color',
        'value' => '#10B981',
        'description' => 'Primary accent for the storefront chat launcher, header, and buttons (hex).',
        'sort' => 71,
    ],
    'SEEKMODO_AI_CHATBOT_WIDGET_SECONDARY_COLOR' => [
        'title' => 'Chatbot: Widget Secondary Color',
        'value' => '#06B6D4',
        'description' => 'Secondary gradient / hover accent for the storefront chat widget (hex).',
        'sort' => 72,
    ],
    'SEEKMODO_AI_CHATBOT_WIDGET_ICON' => [
        'title' => 'Chatbot: Widget Agent Icon',
        'value' => '',
        'description' => 'Image shown next to bot and agent replies in the chat widget. Upload via Tools → Seekmodo AI Chatbot settings. Stores a catalog-relative path or absolute URL.',
        'sort' => 73,
    ],
    'SEEKMODO_AI_CHATBOT_SESSION_MAX_AGE_HOURS' => [
        'title' => 'Chatbot: Session Max Age Hours',
        'value' => '24',
        'description' => 'Hard maximum session lifetime in hours even with activity (default 24).',
        'sort' => 107,
    ],
    'SEEKMODO_AI_CHATBOT_MAX_HISTORY' => [
        'title' => 'Chatbot: Max History Messages',
        'value' => '24',
        'description' => 'Maximum messages retained in server-side chat context after compaction (default 24).',
        'sort' => 108,
    ],
    'SEEKMODO_AI_CHATBOT_ADDITIONAL_RULES' => [
        'title' => 'Chatbot: Additional Rules',
        'value' => seekmodo_ai_chatbot_default_additional_rules(),
        'description' => 'Extra rules appended after the built-in chatbot prompt guidance.',
        'sort' => 65,
    ],
    'SEEKMODO_AI_CHATBOT_STORE_CONTEXT' => [
        'title' => 'Chatbot: Store Context',
        'value' => '',
        'description' => 'What this store sells (name, categories, catalog boundary). Injected into the system prompt. Auto-seeded from STORE_NAME + category tree; edit anytime. Use Refresh from catalog to regenerate.',
        'sort' => 64,
    ],
    'SEEKMODO_AI_CHATBOT_POST_CHAT_ANALYSIS_ENABLED' => [
        'title' => 'Post-Chat Analysis: Enabled',
        'value' => 'false',
        'description' => 'Analyze resolved/idle chats with an LLM pass and propose typed improvement drafts for admin review (Tools -> Chatbot Improvements). Never applies anything automatically.',
        'sort' => 160,
        'set_function' => "zen_cfg_select_option(array('true', 'false'),",
    ],
    'SEEKMODO_AI_CHATBOT_POST_CHAT_IDLE_MINUTES' => [
        'title' => 'Post-Chat Analysis: Idle Minutes',
        'value' => '120',
        'description' => 'Nightly catch-up treats an open chat as abandoned (and enqueues it for analysis) once it has gone this many minutes without activity.',
        'sort' => 161,
    ],
    'SEEKMODO_AI_CHATBOT_POST_CHAT_MIN_MESSAGES' => [
        'title' => 'Post-Chat Analysis: Minimum Messages',
        'value' => '3',
        'description' => 'Skip analysis for chats with fewer than this many messages (too short to yield a useful proposal).',
        'sort' => 162,
    ],
    'SEEKMODO_AI_CHATBOT_POST_CHAT_MAX_PER_NIGHT' => [
        'title' => 'Post-Chat Analysis: Max Chats Per Night',
        'value' => '50',
        'description' => 'Caps how many idle/unresolved chats the nightly cron catch-up enqueues per run.',
        'sort' => 163,
    ],
    'SEEKMODO_AI_CHATBOT_POST_CHAT_JOB_BATCH_SIZE' => [
        'title' => 'Post-Chat Analysis: Jobs Per Run',
        'value' => '20',
        'description' => 'Maximum number of pending analysis jobs processed (LLM calls made) per cron run.',
        'sort' => 164,
    ],
    'SEEKMODO_AI_CHATBOT_AUTO_ARCHIVE_RESOLVED_DAYS' => [
        'title' => 'Chat Inbox: Auto-Archive Resolved After Days',
        'value' => '30',
        'description' => 'Nightly cron soft-archives resolved chats this many days after resolve (default 30). Set 0 to disable. Archived chats remain searchable under Show archived; they are not deleted.',
        'sort' => 170,
    ],
]);
seekmodo_ai_chatbot_seed_empty_additional_rules($db);
seekmodo_ai_chatbot_seed_empty_store_context($db);
seekmodo_ai_chatbot_ensure_chat_history_page($db);

$action = $_POST['action'] ?? '';

$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['securityToken']) || !isset($_SESSION['securityToken']) || !hash_equals($_SESSION['securityToken'], $_POST['securityToken'])) {
        $messages[] = ['type' => 'error', 'text' => 'Security token mismatch.'];
    } elseif ($action === 'save') {
        $iconMessage = seekmodo_ai_chatbot_handle_widget_icon();
        $keys = [
            'SEEKMODO_AI_CHATBOT_ENABLED' => $_POST['enabled'] === 'true' ? 'true' : 'false',
            'SEEKMODO_AI_CHATBOT_LLM_PROVIDER' => in_array((string) ($_POST['llm_provider'] ?? 'openai'), ['openai','claude','deepseek'], true) ? (string) $_POST['llm_provider'] : 'openai',
            'SEEKMODO_AI_CHATBOT_API_KEY' => trim((string) ($_POST['api_key'] ?? '')),
            'SEEKMODO_AI_CHATBOT_CLAUDE_API_KEY' => trim((string) ($_POST['claude_api_key'] ?? '')),
            'SEEKMODO_AI_CHATBOT_DEEPSEEK_API_KEY' => trim((string) ($_POST['deepseek_api_key'] ?? '')),
            'SEEKMODO_AI_CHATBOT_MODEL' => trim((string) ($_POST['model'] ?? 'gpt-4o-mini')),
            'SEEKMODO_AI_CHATBOT_MCP_URL' => trim((string) ($_POST['mcp_url'] ?? '')),
            'SEEKMODO_AI_CHATBOT_MCP_TOKEN' => trim((string) ($_POST['mcp_token'] ?? '')),
            'SEEKMODO_AI_CHATBOT_ALLOW_ORDER_MUTATIONS' => (isset($_POST['allow_order_mutations']) && $_POST['allow_order_mutations'] === 'true') ? 'true' : 'false',
            'SEEKMODO_AI_CHATBOT_SYSTEM_PROMPT' => trim((string) ($_POST['system_prompt'] ?? '')),
            'SEEKMODO_AI_CHATBOT_STORE_CONTEXT' => seekmodo_ai_chatbot_latin1_safe_text(trim((string) ($_POST['store_context'] ?? ''))),
            // configuration_value is often latin1 ? keep rules JSON-safe ASCII punctuation.
            'SEEKMODO_AI_CHATBOT_ADDITIONAL_RULES' => seekmodo_ai_chatbot_latin1_safe_text(trim((string) ($_POST['additional_rules'] ?? ''))),
            'SEEKMODO_AI_CHATBOT_WIDGET_GREETING' => trim((string) ($_POST['widget_greeting'] ?? '')),
            'SEEKMODO_AI_CHATBOT_WIDGET_PRIMARY_COLOR' => seekmodo_ai_chatbot_sanitize_hex_color((string) ($_POST['widget_primary_color'] ?? ''), '#10B981'),
            'SEEKMODO_AI_CHATBOT_WIDGET_SECONDARY_COLOR' => seekmodo_ai_chatbot_sanitize_hex_color((string) ($_POST['widget_secondary_color'] ?? ''), '#06B6D4'),
            'SEEKMODO_AI_CHATBOT_MAX_TOOL_ROUNDS' => (string) ((int) ($_POST['max_tool_rounds'] ?? 10)),
            'SEEKMODO_AI_CHATBOT_RATE_LIMIT_RPM' => (string) ((int) ($_POST['rate_limit_rpm'] ?? 30)),
            'SEEKMODO_AI_CHATBOT_KB_PATH' => trim((string) ($_POST['kb_path'] ?? '')),
            'SEEKMODO_AI_CHATBOT_SUPPORT_EMAIL' => trim((string) ($_POST['support_email'] ?? '')),
            'SEEKMODO_AI_CHATBOT_SESSION_IDLE_MINUTES' => (string) max(5, min(1440, (int) ($_POST['session_idle_minutes'] ?? 30))),
            'SEEKMODO_AI_CHATBOT_SESSION_MAX_AGE_HOURS' => (string) max(1, min(168, (int) ($_POST['session_max_age_hours'] ?? 24))),
            'SEEKMODO_AI_CHATBOT_MAX_HISTORY' => (string) max(8, min(80, (int) ($_POST['max_history'] ?? 24))),
            'SEEKMODO_AI_CHATBOT_POST_CHAT_ANALYSIS_ENABLED' => $_POST['post_chat_analysis_enabled'] === 'true' ? 'true' : 'false',
            'SEEKMODO_AI_CHATBOT_POST_CHAT_IDLE_MINUTES' => (string) max(15, min(10080, (int) ($_POST['post_chat_idle_minutes'] ?? 120))),
            'SEEKMODO_AI_CHATBOT_POST_CHAT_MIN_MESSAGES' => (string) max(1, min(50, (int) ($_POST['post_chat_min_messages'] ?? 3))),
            'SEEKMODO_AI_CHATBOT_POST_CHAT_MAX_PER_NIGHT' => (string) max(1, min(1000, (int) ($_POST['post_chat_max_per_night'] ?? 50))),
            'SEEKMODO_AI_CHATBOT_POST_CHAT_JOB_BATCH_SIZE' => (string) max(1, min(200, (int) ($_POST['post_chat_job_batch_size'] ?? 20))),
            'SEEKMODO_AI_CHATBOT_AUTO_ARCHIVE_RESOLVED_DAYS' => (string) max(0, min(3650, (int) ($_POST['auto_archive_resolved_days'] ?? 30))),
        ];
        foreach ($keys as $key => $value) {
            $db->Execute(
                "UPDATE " . TABLE_CONFIGURATION . "
                    SET configuration_value = '" . $db->prepareInput($value) . "'
                  WHERE configuration_key = '" . $db->prepareInput($key) . "'"
            );
        }
        $messages[] = ['type' => 'success', 'text' => TEXT_SEEKMODO_AI_CHATBOT_SAVED];
        if ($iconMessage !== null) {
            $messages[] = $iconMessage;
        }
    } elseif ($action === 'refresh_store_context') {
        seekmodo_ai_chatbot_require_library(['StoreContext.php'], false);
        if (!class_exists('\\SeekmodoAiChatbot\\StoreContext')) {
            $messages[] = ['type' => 'error', 'text' => TEXT_SEEKMODO_AI_CHATBOT_STORE_CONTEXT_REFRESH_FAIL];
        } else {
            $draft = seekmodo_ai_chatbot_latin1_safe_text(
                \SeekmodoAiChatbot\StoreContext::buildFromCatalog()
            );
            $db->Execute(
                "UPDATE " . TABLE_CONFIGURATION . "
                    SET configuration_value = '" . $db->prepareInput($draft) . "'
                  WHERE configuration_key = 'SEEKMODO_AI_CHATBOT_STORE_CONTEXT'"
            );
            $messages[] = ['type' => 'success', 'text' => TEXT_SEEKMODO_AI_CHATBOT_STORE_CONTEXT_REFRESHED];
        }
    } elseif ($action === 'rebuild_kb') {
        [$kbScript, $triedPaths] = seekmodo_ai_chatbot_compile_kb_script();
        if ($kbScript === null) {
            $checked = implode('<br>', array_map(
                static fn(string $p): string => '&nbsp;&nbsp;? ' . htmlspecialchars($p, ENT_QUOTES),
                $triedPaths
            ));
            $messages[] = [
                'type' => 'warning',
                'text' => 'KB compile script not found under zc_plugins/SeekmodoAiChatbot.'
                    . ($checked !== '' ? '<br>Paths checked:<br>' . $checked : ''),
            ];
        } else {
            $phpBin = seekmodo_ai_chatbot_php_binary();
            $cmd = escapeshellarg($phpBin)
                . ' ' . escapeshellarg($kbScript)
                . ' --catalog=' . escapeshellarg(rtrim(DIR_FS_CATALOG, '/\\'))
                . ' 2>&1';
            $output = [];
            $exitCode = 0;
            exec($cmd, $output, $exitCode);
            $text = implode("\n", $output);
            if ($exitCode === 0) {
                $messages[] = [
                    'type' => 'success',
                    'text' => TEXT_SEEKMODO_AI_CHATBOT_KB_BUILT
                        . '<pre>' . htmlspecialchars($text, ENT_QUOTES) . '</pre>',
                ];
            } else {
                $messages[] = [
                    'type' => 'error',
                    'text' => 'KB compile failed (exit ' . (int) $exitCode . ').'
                        . '<pre>' . htmlspecialchars($text, ENT_QUOTES) . '</pre>',
                ];
            }
        }
    } elseif ($action === 'test_connection') {
        $lib = null;
        $candidates = glob(rtrim(DIR_FS_CATALOG, '/\\') . '/zc_plugins/SeekmodoAiChatbot/v*/catalog/includes/library/SeekmodoAiChatbot') ?: [];
        rsort($candidates, SORT_NATURAL);
        foreach ($candidates as $cand) {
            if (is_file($cand . '/Llm/LlmClient.php')) {
                $lib = $cand;
                break;
            }
        }
        if ($lib === null) {
            $messages[] = ['type' => 'error', 'text' => TEXT_SEEKMODO_AI_CHATBOT_CONNECTION_FAIL . 'LLM library not found.'];
        } else {
            require_once $lib . '/ChatbotException.php';
            require_once $lib . '/ModelCatalog.php';
            require_once $lib . '/Llm/LlmResult.php';
            require_once $lib . '/Llm/ProviderAdapter.php';
            require_once $lib . '/Llm/OpenAiCompatibleAdapter.php';
            require_once $lib . '/Llm/AnthropicAdapter.php';
            require_once $lib . '/Llm/LlmClient.php';
            $result = \SeekmodoAiChatbot\Llm\LlmClient::fromConfig()->testConnection();
            if (!empty($result['ok'])) {
                $messages[] = [
                    'type' => 'success',
                    'text' => TEXT_SEEKMODO_AI_CHATBOT_CONNECTION_OK
                        . ' Provider=' . htmlspecialchars((string) $result['provider'], ENT_QUOTES)
                        . ' model=' . htmlspecialchars((string) $result['model'], ENT_QUOTES),
                ];
            } else {
                $messages[] = [
                    'type' => 'error',
                    'text' => TEXT_SEEKMODO_AI_CHATBOT_CONNECTION_FAIL
                        . htmlspecialchars((string) ($result['error'] ?? 'unknown'), ENT_QUOTES),
                ];
            }
        }
    }
}

/**
 * Locate compile_kb.php inside the installed SeekmodoAiChatbot plugin tree.
 *
 * Tries multiple strategies in order:
 *  1. DIR_FS_CATALOG + zc_plugins/SeekmodoAiChatbot/version/tools/compile_kb.php
 *  2. Relative to this admin script (__DIR__) ? works when the file is loaded
 *     directly from the plugin package without symlinks.
 *
 * @return array{0: string, 1: list<string>} [$scriptPath, $triedPaths]
 */
function seekmodo_ai_chatbot_compile_kb_script(): array
{
    $tried = [];

    // Strategy 1: DIR_FS_CATALOG-based lookup
    if (defined('DIR_FS_CATALOG') && is_string(DIR_FS_CATALOG) && DIR_FS_CATALOG !== '') {
        $root = rtrim(DIR_FS_CATALOG, '/\\') . '/zc_plugins/SeekmodoAiChatbot';
        $tried[] = $root . '/v*/tools/compile_kb.php';
        if (is_dir($root)) {
            $versions = glob($root . '/v*', GLOB_ONLYDIR) ?: [];
            rsort($versions, SORT_NATURAL);
            foreach ($versions as $versionDir) {
                $candidate = $versionDir . '/tools/compile_kb.php';
                if (is_file($candidate)) {
                    return [$candidate, $tried];
                }
            }
        }
    }

    // Strategy 2: Relative to this admin script's physical location.
    // The admin file lives at zc_plugins/SeekmodoAiChatbot/vX.Y.Z/admin/,
    // so one level up is the version directory containing tools/.
    $relativeCandidate = __DIR__ . '/../tools/compile_kb.php';
    $tried[] = $relativeCandidate;
    if (is_file($relativeCandidate)) {
        return [$relativeCandidate, $tried];
    }

    return [null, $tried];
}

function seekmodo_ai_chatbot_php_binary(): string
{
    $candidates = [];
    if (defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '') {
        $candidates[] = PHP_BINARY;
    }
    $candidates[] = '/usr/local/bin/php';
    $candidates[] = 'php';
    foreach ($candidates as $bin) {
        if ($bin === 'php') {
            return $bin;
        }
        if (is_file($bin) && is_executable($bin)) {
            return $bin;
        }
    }
    return 'php';
}

$config = [];
$keys = [
    'SEEKMODO_AI_CHATBOT_ENABLED',
    'SEEKMODO_AI_CHATBOT_LLM_PROVIDER',
    'SEEKMODO_AI_CHATBOT_API_KEY',
    'SEEKMODO_AI_CHATBOT_CLAUDE_API_KEY',
    'SEEKMODO_AI_CHATBOT_DEEPSEEK_API_KEY',
    'SEEKMODO_AI_CHATBOT_MODEL',
    'SEEKMODO_AI_CHATBOT_MCP_URL',
    'SEEKMODO_AI_CHATBOT_MCP_TOKEN',
    'SEEKMODO_AI_CHATBOT_ALLOW_ORDER_MUTATIONS',
    'SEEKMODO_AI_CHATBOT_SYSTEM_PROMPT',
    'SEEKMODO_AI_CHATBOT_STORE_CONTEXT',
    'SEEKMODO_AI_CHATBOT_ADDITIONAL_RULES',
    'SEEKMODO_AI_CHATBOT_WIDGET_GREETING',
    'SEEKMODO_AI_CHATBOT_WIDGET_PRIMARY_COLOR',
    'SEEKMODO_AI_CHATBOT_WIDGET_SECONDARY_COLOR',
    'SEEKMODO_AI_CHATBOT_WIDGET_ICON',
    'SEEKMODO_AI_CHATBOT_MAX_TOOL_ROUNDS',
    'SEEKMODO_AI_CHATBOT_RATE_LIMIT_RPM',
    'SEEKMODO_AI_CHATBOT_KB_PATH',
    'SEEKMODO_AI_CHATBOT_SUPPORT_EMAIL',
    'SEEKMODO_AI_CHATBOT_SESSION_IDLE_MINUTES',
    'SEEKMODO_AI_CHATBOT_SESSION_MAX_AGE_HOURS',
    'SEEKMODO_AI_CHATBOT_MAX_HISTORY',
    'SEEKMODO_AI_CHATBOT_POST_CHAT_ANALYSIS_ENABLED',
    'SEEKMODO_AI_CHATBOT_POST_CHAT_IDLE_MINUTES',
    'SEEKMODO_AI_CHATBOT_POST_CHAT_MIN_MESSAGES',
    'SEEKMODO_AI_CHATBOT_POST_CHAT_MAX_PER_NIGHT',
    'SEEKMODO_AI_CHATBOT_POST_CHAT_JOB_BATCH_SIZE',
    'SEEKMODO_AI_CHATBOT_AUTO_ARCHIVE_RESOLVED_DAYS',
];

foreach ($keys as $key) {
    $row = $db->Execute(
        "SELECT configuration_value FROM " . TABLE_CONFIGURATION
        . " WHERE configuration_key = '" . $db->prepareInput($key) . "' LIMIT 1"
    );
    if ($row && !$row->EOF) {
        $config[$key] = (string) $row->fields['configuration_value'];
    } elseif (defined($key)) {
        $config[$key] = (string) constant($key);
    } else {
        $config[$key] = '';
    }
}
$chatsHref = zen_href_link(FILENAME_SEEKMODO_AI_CHATBOT_CHATS);
$mailHref = zen_href_link(defined('FILENAME_SEEKMODO_AI_CHATBOT_MAIL') ? FILENAME_SEEKMODO_AI_CHATBOT_MAIL : 'seekmodo_ai_chatbot_mail');
$chatsLabel = defined('BOX_TOOLS_SEEKMODO_AI_CHATBOT_CHATS') ? BOX_TOOLS_SEEKMODO_AI_CHATBOT_CHATS : 'Support Tickets';
$mailLabel = defined('BOX_CONFIGURATION_SEEKMODO_AI_CHATBOT_MAIL')
    ? BOX_CONFIGURATION_SEEKMODO_AI_CHATBOT_MAIL
    : (defined('BOX_TOOLS_SEEKMODO_AI_CHATBOT_MAIL') ? BOX_TOOLS_SEEKMODO_AI_CHATBOT_MAIL : 'Support Ticket Mail');
$heading = defined('TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_HEADING')
    ? TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_HEADING
    : BOX_TOOLS_SEEKMODO_AI_CHATBOT_SETTINGS;
$subtitle = defined('TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_SUBTITLE')
    ? TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_SUBTITLE
    : '';
$cssUrl = function_exists('seekmodo_ai_chatbot_admin_asset_url')
    ? seekmodo_ai_chatbot_admin_asset_url('includes/css/seekmodo_ai_chatbot_admin.css')
    : 'includes/css/seekmodo_ai_chatbot_admin.css';
?>
<!doctype html>
<html <?php echo HTML_PARAMS; ?>>
<head>
<meta charset="<?php echo CHARSET; ?>">
<title><?php echo BOX_TOOLS_SEEKMODO_AI_CHATBOT_SETTINGS; ?></title>
<link rel="stylesheet" href="includes/stylesheet.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars($cssUrl, ENT_QUOTES); ?>">
</head>
<body>
<?php require DIR_WS_INCLUDES . 'header.php'; ?>

<div class="nmx-module" id="openai-gpt-settings-app">
  <div class="nmx-page-header">
    <div>
      <div class="nmx-breadcrumb">
        <a href="<?php echo htmlspecialchars(zen_href_link(FILENAME_SEEKMODO_AI_CHATBOT_SETTINGS), ENT_QUOTES); ?>">Seekmodo AI Chatbot</a>
        <span class="sep"></span>
        <?php echo htmlspecialchars($heading, ENT_QUOTES); ?>
      </div>
      <h2><?php echo htmlspecialchars($heading, ENT_QUOTES); ?></h2>
      <?php if ($subtitle !== ''): ?>
      <p class="nmx-page-header-subtitle"><?php echo htmlspecialchars($subtitle, ENT_QUOTES); ?></p>
      <?php endif; ?>
    </div>
    <div class="nmx-page-header-actions">
      <a href="<?php echo htmlspecialchars($chatsHref, ENT_QUOTES); ?>"><?php echo htmlspecialchars($chatsLabel, ENT_QUOTES); ?></a>
      <a href="<?php echo htmlspecialchars($mailHref, ENT_QUOTES); ?>"><?php echo htmlspecialchars($mailLabel, ENT_QUOTES); ?></a>
    </div>
  </div>

  <?php foreach ($messages as $msg): ?>
  <div class="nmx-alert nmx-alert-<?php echo htmlspecialchars($msg['type'], ENT_QUOTES); ?>"><?php echo $msg['text']; ?></div>
  <?php endforeach; ?>

  <?php
    $gate = ['ok' => false, 'reason' => 'unknown', 'message' => 'Seekmodo gate unavailable.', 'paired' => false, 'subscription' => 'unknown', 'tenant_id' => ''];
    $gateLib = null;
    foreach (glob(rtrim(DIR_FS_CATALOG, '/\\') . '/zc_plugins/SeekmodoAiChatbot/v*/catalog/includes/library/SeekmodoAiChatbot/SeekmodoEntitlementGate.php') ?: [] as $gf) {
        $gateLib = dirname($gf);
        break;
    }
    if ($gateLib !== null) {
        require_once $gateLib . '/SeekmodoEntitlementGate.php';
        $gate = \SeekmodoAiChatbot\SeekmodoEntitlementGate::evaluate();
    }
    $provider = $config['SEEKMODO_AI_CHATBOT_LLM_PROVIDER'] ?? 'openai';
    $models = [
        'openai' => ['gpt-4o-mini','gpt-4o','gpt-4.1-mini','gpt-4.1'],
        'claude' => ['claude-sonnet-4-5','claude-3-5-haiku-latest','claude-3-5-sonnet-latest'],
        'deepseek' => ['deepseek-chat','deepseek-reasoner'],
    ];
    $modelList = $models[$provider] ?? $models['openai'];
  ?>
  <div class="nmx-panel seekmodo-gate-card">
    <div class="nmx-panel-header">
      <div class="nmx-panel-title">Seekmodo Account</div>
    </div>
    <div class="nmx-panel-body">
      <p class="<?php echo !empty($gate['ok']) ? 'nmx-alert nmx-alert-success' : 'nmx-alert nmx-alert-error'; ?>">
        <?php echo htmlspecialchars((string) $gate['message'], ENT_QUOTES); ?>
      </p>
      <p class="nmx-help">
        Paired: <?php echo !empty($gate['paired']) ? 'yes' : 'no'; ?>
        · Subscription: <?php echo htmlspecialchars((string) ($gate['subscription'] ?? ''), ENT_QUOTES); ?>
        · Tenant: <?php echo htmlspecialchars((string) ($gate['tenant_id'] ?? ''), ENT_QUOTES); ?>
      </p>
      <p class="nmx-help">Chatbot requires a paired paid Seekmodo connector. Seekmodo catalog searches meter against your plan. LLM usage is billed to your provider API keys.</p>
      <?php if (defined('FILENAME_NUMINIX_SEEKMODO_CONNECT') || true): ?>
        <a class="nmx-btn nmx-btn-secondary" href="<?php echo htmlspecialchars(zen_href_link('numinix_seekmodo_connect'), ENT_QUOTES); ?>">Open Seekmodo Connect</a>
      <?php endif; ?>
    </div>
  </div>

  <form method="post" enctype="multipart/form-data" class="nmx-form-narrow">
    <input type="hidden" name="securityToken" value="<?php echo htmlspecialchars((string) ($_SESSION['securityToken'] ?? ''), ENT_QUOTES); ?>">
    <input type="hidden" name="action" value="save">

    <div class="nmx-panel">
      <div class="nmx-panel-header">
        <div class="nmx-panel-title"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_SECTION_CORE, ENT_QUOTES); ?></div>
      </div>
      <div class="nmx-panel-body">
        <div class="nmx-form-grid">
          <div class="nmx-form-group">
            <label class="nmx-form-label" for="ogc-enabled"><?php echo TEXT_SEEKMODO_AI_CHATBOT_ENABLED; ?></label>
            <select class="nmx-form-control" id="ogc-enabled" name="enabled" <?php echo empty($gate['ok']) ? 'disabled title="Pair a paid Seekmodo account first"' : ''; ?>>
              <option value="true"<?php echo $config['SEEKMODO_AI_CHATBOT_ENABLED'] === 'true' ? ' selected' : ''; ?>>true</option>
              <option value="false"<?php echo $config['SEEKMODO_AI_CHATBOT_ENABLED'] !== 'true' ? ' selected' : ''; ?>>false</option>
            </select>
          </div>
          <div class="nmx-form-group">
            <label class="nmx-form-label" for="ogc-provider">LLM Provider</label>
            <select class="nmx-form-control" id="ogc-provider" name="llm_provider">
              <option value="openai"<?php echo $provider === 'openai' ? ' selected' : ''; ?>>OpenAI</option>
              <option value="claude"<?php echo $provider === 'claude' ? ' selected' : ''; ?>>Claude (Anthropic)</option>
              <option value="deepseek"<?php echo $provider === 'deepseek' ? ' selected' : ''; ?>>DeepSeek</option>
            </select>
          </div>
          <div class="nmx-form-group">
            <label class="nmx-form-label" for="ogc-model"><?php echo TEXT_SEEKMODO_AI_CHATBOT_MODEL; ?></label>
            <select class="nmx-form-control" id="ogc-model" name="model">
              <?php foreach ($modelList as $mid): ?>
              <option value="<?php echo htmlspecialchars($mid, ENT_QUOTES); ?>"<?php echo ($config['SEEKMODO_AI_CHATBOT_MODEL'] ?? '') === $mid ? ' selected' : ''; ?>><?php echo htmlspecialchars($mid, ENT_QUOTES); ?></option>
              <?php endforeach; ?>
              <?php if (!in_array($config['SEEKMODO_AI_CHATBOT_MODEL'] ?? '', $modelList, true) && ($config['SEEKMODO_AI_CHATBOT_MODEL'] ?? '') !== ''): ?>
              <option value="<?php echo htmlspecialchars($config['SEEKMODO_AI_CHATBOT_MODEL'], ENT_QUOTES); ?>" selected><?php echo htmlspecialchars($config['SEEKMODO_AI_CHATBOT_MODEL'], ENT_QUOTES); ?></option>
              <?php endif; ?>
            </select>
          </div>
        </div>
        <div class="nmx-form-group">
          <label class="nmx-form-label" for="ogc-api-key"><?php echo TEXT_SEEKMODO_AI_CHATBOT_API_KEY; ?></label>
          <input class="nmx-form-control" type="password" id="ogc-api-key" name="api_key" value="<?php echo htmlspecialchars($config['SEEKMODO_AI_CHATBOT_API_KEY'] ?? '', ENT_QUOTES); ?>" autocomplete="off">
        </div>
        <div class="nmx-form-group">
          <label class="nmx-form-label" for="ogc-claude-key">Claude API Key</label>
          <input class="nmx-form-control" type="password" id="ogc-claude-key" name="claude_api_key" value="<?php echo htmlspecialchars($config['SEEKMODO_AI_CHATBOT_CLAUDE_API_KEY'] ?? '', ENT_QUOTES); ?>" autocomplete="off">
        </div>
        <div class="nmx-form-group">
          <label class="nmx-form-label" for="ogc-deepseek-key">DeepSeek API Key</label>
          <input class="nmx-form-control" type="password" id="ogc-deepseek-key" name="deepseek_api_key" value="<?php echo htmlspecialchars($config['SEEKMODO_AI_CHATBOT_DEEPSEEK_API_KEY'] ?? '', ENT_QUOTES); ?>" autocomplete="off">
        </div>
      </div>
    </div>

    <div class="nmx-panel">
      <div class="nmx-panel-header">
        <div class="nmx-panel-title"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_SECTION_MCP, ENT_QUOTES); ?></div>
      </div>
      <div class="nmx-panel-body">
        <div class="nmx-form-group">
          <label class="nmx-form-label" for="ogc-mcp-url"><?php echo TEXT_SEEKMODO_AI_CHATBOT_MCP_URL; ?></label>
          <input class="nmx-form-control" type="text" id="ogc-mcp-url" name="mcp_url" value="<?php echo htmlspecialchars($config['SEEKMODO_AI_CHATBOT_MCP_URL'], ENT_QUOTES); ?>" placeholder="https://example.com/numinix_mcp.php">
        </div>
        <div class="nmx-form-group">
          <label class="nmx-form-label" for="ogc-mcp-token"><?php echo TEXT_SEEKMODO_AI_CHATBOT_MCP_TOKEN; ?></label>
          <input class="nmx-form-control" type="password" id="ogc-mcp-token" name="mcp_token" value="<?php echo htmlspecialchars($config['SEEKMODO_AI_CHATBOT_MCP_TOKEN'], ENT_QUOTES); ?>" autocomplete="off">
        </div>
        <div class="nmx-form-group">
          <label class="nmx-form-label" for="ogc-allow-order-mutations"><?php echo TEXT_SEEKMODO_AI_CHATBOT_ALLOW_ORDER_MUTATIONS; ?></label>
          <select class="nmx-form-control" id="ogc-allow-order-mutations" name="allow_order_mutations">
            <option value="false"<?php echo ($config['SEEKMODO_AI_CHATBOT_ALLOW_ORDER_MUTATIONS'] ?? '') !== 'true' ? ' selected' : ''; ?>>false (recommended)</option>
            <option value="true"<?php echo ($config['SEEKMODO_AI_CHATBOT_ALLOW_ORDER_MUTATIONS'] ?? '') === 'true' ? ' selected' : ''; ?>>true</option>
          </select>
          <div class="nmx-help"><?php echo TEXT_SEEKMODO_AI_CHATBOT_ALLOW_ORDER_MUTATIONS_HELP; ?></div>
        </div>
      </div>
    </div>

    <div class="nmx-panel">
      <div class="nmx-panel-header">
        <div class="nmx-panel-title"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_SECTION_PROMPTS, ENT_QUOTES); ?></div>
      </div>
      <div class="nmx-panel-body">
        <div class="nmx-form-group">
          <label class="nmx-form-label" for="ogc-system-prompt"><?php echo TEXT_SEEKMODO_AI_CHATBOT_SYSTEM_PROMPT; ?></label>
          <textarea class="nmx-form-control" id="ogc-system-prompt" name="system_prompt" rows="4"><?php echo htmlspecialchars($config['SEEKMODO_AI_CHATBOT_SYSTEM_PROMPT'], ENT_QUOTES); ?></textarea>
        </div>
        <div class="nmx-form-group">
          <label class="nmx-form-label" for="ogc-store-context"><?php echo TEXT_SEEKMODO_AI_CHATBOT_STORE_CONTEXT; ?></label>
          <textarea class="nmx-form-control" id="ogc-store-context" name="store_context" rows="10"><?php echo htmlspecialchars($config['SEEKMODO_AI_CHATBOT_STORE_CONTEXT'] ?? '', ENT_QUOTES); ?></textarea>
          <div class="nmx-help"><?php echo TEXT_SEEKMODO_AI_CHATBOT_STORE_CONTEXT_HELP; ?></div>
        </div>
        <div class="nmx-form-group">
          <label class="nmx-form-label" for="ogc-additional-rules"><?php echo TEXT_SEEKMODO_AI_CHATBOT_ADDITIONAL_RULES; ?></label>
          <textarea class="nmx-form-control" id="ogc-additional-rules" name="additional_rules" rows="12"><?php echo htmlspecialchars($config['SEEKMODO_AI_CHATBOT_ADDITIONAL_RULES'], ENT_QUOTES); ?></textarea>
        </div>
      </div>
    </div>

    <div class="nmx-panel">
      <div class="nmx-panel-header">
        <div class="nmx-panel-title"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_SECTION_WIDGET, ENT_QUOTES); ?></div>
      </div>
      <div class="nmx-panel-body">
        <div class="nmx-form-group">
          <label class="nmx-form-label" for="ogc-widget-greeting"><?php echo TEXT_SEEKMODO_AI_CHATBOT_WIDGET_GREETING; ?></label>
          <input class="nmx-form-control" type="text" id="ogc-widget-greeting" name="widget_greeting" value="<?php echo htmlspecialchars($config['SEEKMODO_AI_CHATBOT_WIDGET_GREETING'], ENT_QUOTES); ?>">
        </div>
        <div class="nmx-form-group">
          <label class="nmx-form-label" for="ogc-widget-icon"><?php echo TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON; ?></label>
          <?php $widgetIconUrl = seekmodo_ai_chatbot_widget_icon_url((string) ($config['SEEKMODO_AI_CHATBOT_WIDGET_ICON'] ?? '')); ?>
          <?php if ($widgetIconUrl !== ''): ?>
          <p class="nmx-help" style="display:flex;align-items:center;gap:10px;margin:0 0 8px;">
            <img src="<?php echo htmlspecialchars($widgetIconUrl, ENT_QUOTES); ?>" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:1px solid #d5dbe3;">
            <span><?php echo htmlspecialchars((string) $config['SEEKMODO_AI_CHATBOT_WIDGET_ICON'], ENT_QUOTES); ?></span>
          </p>
          <label style="display:flex;align-items:center;gap:8px;font-weight:400;margin:0 0 8px;">
            <input type="checkbox" name="widget_icon_remove" value="1">
            <?php echo TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON_REMOVE; ?>
          </label>
          <?php endif; ?>
          <input class="nmx-form-control" type="file" id="ogc-widget-icon" name="widget_icon" accept="image/png,image/jpeg,image/webp,image/gif">
          <div class="nmx-help"><?php echo TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON_HELP; ?></div>
        </div>
        <div class="nmx-form-grid">
          <div class="nmx-form-group">
            <label class="nmx-form-label" for="ogc-widget-primary"><?php echo TEXT_SEEKMODO_AI_CHATBOT_WIDGET_PRIMARY_COLOR; ?></label>
            <input class="nmx-form-control" type="color" id="ogc-widget-primary" name="widget_primary_color" value="<?php echo htmlspecialchars($config['SEEKMODO_AI_CHATBOT_WIDGET_PRIMARY_COLOR'] ?? '#10B981', ENT_QUOTES); ?>">
            <div class="nmx-help"><?php echo TEXT_SEEKMODO_AI_CHATBOT_WIDGET_PRIMARY_COLOR_HELP; ?></div>
          </div>
          <div class="nmx-form-group">
            <label class="nmx-form-label" for="ogc-widget-secondary"><?php echo TEXT_SEEKMODO_AI_CHATBOT_WIDGET_SECONDARY_COLOR; ?></label>
            <input class="nmx-form-control" type="color" id="ogc-widget-secondary" name="widget_secondary_color" value="<?php echo htmlspecialchars($config['SEEKMODO_AI_CHATBOT_WIDGET_SECONDARY_COLOR'] ?? '#06B6D4', ENT_QUOTES); ?>">
            <div class="nmx-help"><?php echo TEXT_SEEKMODO_AI_CHATBOT_WIDGET_SECONDARY_COLOR_HELP; ?></div>
          </div>
        </div>
        <div class="nmx-form-grid">
          <div class="nmx-form-group">
            <label class="nmx-form-label" for="ogc-max-tool-rounds"><?php echo TEXT_SEEKMODO_AI_CHATBOT_MAX_TOOL_ROUNDS; ?></label>
            <input class="nmx-form-control nmx-form-control-sm" type="number" id="ogc-max-tool-rounds" name="max_tool_rounds" value="<?php echo (int) ($config['SEEKMODO_AI_CHATBOT_MAX_TOOL_ROUNDS'] ?: 10); ?>" min="1" max="50">
          </div>
          <div class="nmx-form-group">
            <label class="nmx-form-label" for="ogc-rate-limit"><?php echo TEXT_SEEKMODO_AI_CHATBOT_RATE_LIMIT_RPM; ?></label>
            <input class="nmx-form-control nmx-form-control-sm" type="number" id="ogc-rate-limit" name="rate_limit_rpm" value="<?php echo (int) ($config['SEEKMODO_AI_CHATBOT_RATE_LIMIT_RPM'] ?: 30); ?>" min="1" max="1000">
          </div>
          <div class="nmx-form-group">
            <label class="nmx-form-label" for="ogc-idle"><?php echo TEXT_SEEKMODO_AI_CHATBOT_SESSION_IDLE_MINUTES; ?></label>
            <input class="nmx-form-control nmx-form-control-sm" type="number" id="ogc-idle" name="session_idle_minutes" value="<?php echo (int) ($config['SEEKMODO_AI_CHATBOT_SESSION_IDLE_MINUTES'] ?: 30); ?>" min="5" max="1440">
          </div>
          <div class="nmx-form-group">
            <label class="nmx-form-label" for="ogc-max-age"><?php echo TEXT_SEEKMODO_AI_CHATBOT_SESSION_MAX_AGE_HOURS; ?></label>
            <input class="nmx-form-control nmx-form-control-sm" type="number" id="ogc-max-age" name="session_max_age_hours" value="<?php echo (int) ($config['SEEKMODO_AI_CHATBOT_SESSION_MAX_AGE_HOURS'] ?: 24); ?>" min="1" max="168">
          </div>
          <div class="nmx-form-group">
            <label class="nmx-form-label" for="ogc-max-history"><?php echo TEXT_SEEKMODO_AI_CHATBOT_MAX_HISTORY; ?></label>
            <input class="nmx-form-control nmx-form-control-sm" type="number" id="ogc-max-history" name="max_history" value="<?php echo (int) ($config['SEEKMODO_AI_CHATBOT_MAX_HISTORY'] ?: 24); ?>" min="8" max="80">
          </div>
        </div>
        <div class="nmx-form-group">
          <label class="nmx-form-label" for="ogc-kb-path"><?php echo TEXT_SEEKMODO_AI_CHATBOT_KB_PATH; ?></label>
          <input class="nmx-form-control" type="text" id="ogc-kb-path" name="kb_path" value="<?php echo htmlspecialchars($config['SEEKMODO_AI_CHATBOT_KB_PATH'], ENT_QUOTES); ?>" placeholder="(default: cache/seekmodo_ai_chatbot/kb)">
        </div>
        <div class="nmx-form-group">
          <label class="nmx-form-label" for="ogc-support-email"><?php echo TEXT_SEEKMODO_AI_CHATBOT_SUPPORT_EMAIL; ?></label>
          <input class="nmx-form-control" type="email" id="ogc-support-email" name="support_email" value="<?php echo htmlspecialchars($config['SEEKMODO_AI_CHATBOT_SUPPORT_EMAIL'], ENT_QUOTES); ?>" placeholder="support@example.com">
        </div>
      </div>
    </div>

    <div class="nmx-panel">
      <div class="nmx-panel-header">
        <div class="nmx-panel-title"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_SECTION_INBOX, ENT_QUOTES); ?></div>
      </div>
      <div class="nmx-panel-body">
        <p class="nmx-form-help"><?php echo TEXT_SEEKMODO_AI_CHATBOT_AUTO_ARCHIVE_HELP; ?></p>
        <div class="nmx-form-group">
          <label class="nmx-form-label" for="ogc-auto-archive-days"><?php echo TEXT_SEEKMODO_AI_CHATBOT_AUTO_ARCHIVE_RESOLVED_DAYS; ?></label>
          <input class="nmx-form-control nmx-form-control-sm" type="number" id="ogc-auto-archive-days" name="auto_archive_resolved_days" value="<?php echo (int) (($config['SEEKMODO_AI_CHATBOT_AUTO_ARCHIVE_RESOLVED_DAYS'] !== '' && $config['SEEKMODO_AI_CHATBOT_AUTO_ARCHIVE_RESOLVED_DAYS'] !== null) ? $config['SEEKMODO_AI_CHATBOT_AUTO_ARCHIVE_RESOLVED_DAYS'] : 30); ?>" min="0" max="3650">
        </div>
        <p class="nmx-form-help"><code><?php echo TEXT_SEEKMODO_AI_CHATBOT_AUTO_ARCHIVE_CRON_HELP; ?></code></p>
      </div>
    </div>

    <div class="nmx-panel">
      <div class="nmx-panel-header">
        <div class="nmx-panel-title"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_SECTION_POST_CHAT, ENT_QUOTES); ?></div>
      </div>
      <div class="nmx-panel-body">
        <p class="nmx-form-help"><?php echo TEXT_SEEKMODO_AI_CHATBOT_POST_CHAT_ANALYSIS_HELP; ?></p>
        <div class="nmx-form-group">
          <label class="nmx-form-label" for="ogc-post-chat-enabled"><?php echo TEXT_SEEKMODO_AI_CHATBOT_POST_CHAT_ANALYSIS_ENABLED; ?></label>
          <select class="nmx-form-control" id="ogc-post-chat-enabled" name="post_chat_analysis_enabled">
            <option value="true"<?php echo $config['SEEKMODO_AI_CHATBOT_POST_CHAT_ANALYSIS_ENABLED'] === 'true' ? ' selected' : ''; ?>>true</option>
            <option value="false"<?php echo $config['SEEKMODO_AI_CHATBOT_POST_CHAT_ANALYSIS_ENABLED'] !== 'true' ? ' selected' : ''; ?>>false</option>
          </select>
        </div>
        <div class="nmx-form-grid">
          <div class="nmx-form-group">
            <label class="nmx-form-label" for="ogc-post-chat-idle"><?php echo TEXT_SEEKMODO_AI_CHATBOT_POST_CHAT_IDLE_MINUTES; ?></label>
            <input class="nmx-form-control nmx-form-control-sm" type="number" id="ogc-post-chat-idle" name="post_chat_idle_minutes" value="<?php echo (int) ($config['SEEKMODO_AI_CHATBOT_POST_CHAT_IDLE_MINUTES'] ?: 120); ?>" min="15" max="10080">
          </div>
          <div class="nmx-form-group">
            <label class="nmx-form-label" for="ogc-post-chat-min-messages"><?php echo TEXT_SEEKMODO_AI_CHATBOT_POST_CHAT_MIN_MESSAGES; ?></label>
            <input class="nmx-form-control nmx-form-control-sm" type="number" id="ogc-post-chat-min-messages" name="post_chat_min_messages" value="<?php echo (int) ($config['SEEKMODO_AI_CHATBOT_POST_CHAT_MIN_MESSAGES'] ?: 3); ?>" min="1" max="50">
          </div>
          <div class="nmx-form-group">
            <label class="nmx-form-label" for="ogc-post-chat-max-night"><?php echo TEXT_SEEKMODO_AI_CHATBOT_POST_CHAT_MAX_PER_NIGHT; ?></label>
            <input class="nmx-form-control nmx-form-control-sm" type="number" id="ogc-post-chat-max-night" name="post_chat_max_per_night" value="<?php echo (int) ($config['SEEKMODO_AI_CHATBOT_POST_CHAT_MAX_PER_NIGHT'] ?: 50); ?>" min="1" max="1000">
          </div>
          <div class="nmx-form-group">
            <label class="nmx-form-label" for="ogc-post-chat-batch"><?php echo TEXT_SEEKMODO_AI_CHATBOT_POST_CHAT_JOB_BATCH_SIZE; ?></label>
            <input class="nmx-form-control nmx-form-control-sm" type="number" id="ogc-post-chat-batch" name="post_chat_job_batch_size" value="<?php echo (int) ($config['SEEKMODO_AI_CHATBOT_POST_CHAT_JOB_BATCH_SIZE'] ?: 20); ?>" min="1" max="200">
          </div>
        </div>
      </div>
      <div class="nmx-panel-footer nmx-sticky-footer">
        <button type="submit" class="nmx-btn nmx-btn-primary"><?php echo TEXT_SEEKMODO_AI_CHATBOT_SAVE; ?></button>
      </div>
    </div>
  </form>

  <div class="nmx-panel nmx-form-narrow">
    <div class="nmx-panel-header">
      <div class="nmx-panel-title"><?php echo htmlspecialchars(TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_SECTION_TOOLS, ENT_QUOTES); ?></div>
    </div>
    <div class="nmx-panel-body">
      <div class="nmx-actions">
        <form method="post">
          <input type="hidden" name="securityToken" value="<?php echo htmlspecialchars((string) ($_SESSION['securityToken'] ?? ''), ENT_QUOTES); ?>">
          <input type="hidden" name="action" value="refresh_store_context">
          <button type="submit" class="nmx-btn nmx-btn-default"><?php echo TEXT_SEEKMODO_AI_CHATBOT_REFRESH_STORE_CONTEXT; ?></button>
        </form>
        <form method="post">
          <input type="hidden" name="securityToken" value="<?php echo htmlspecialchars((string) ($_SESSION['securityToken'] ?? ''), ENT_QUOTES); ?>">
          <input type="hidden" name="action" value="rebuild_kb">
          <button type="submit" class="nmx-btn nmx-btn-default"><?php echo TEXT_SEEKMODO_AI_CHATBOT_REBUILD_KB; ?></button>
        </form>
        <form method="post">
          <input type="hidden" name="securityToken" value="<?php echo htmlspecialchars((string) ($_SESSION['securityToken'] ?? ''), ENT_QUOTES); ?>">
          <input type="hidden" name="action" value="test_connection">
          <button type="submit" class="nmx-btn nmx-btn-default"><?php echo TEXT_SEEKMODO_AI_CHATBOT_TEST_CONNECTION; ?></button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require DIR_WS_INCLUDES . 'footer.php'; ?>
</body>
</html>
