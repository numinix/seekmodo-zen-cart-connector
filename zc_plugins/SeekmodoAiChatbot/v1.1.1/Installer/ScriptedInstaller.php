<?php
/**
 * Seekmodo AI Chatbot — scripted installer.
 *
 * Must extend Zencart\PluginSupport\ScriptedInstaller so Plugin Manager
 * can construct it with ($dbConn, $errorContainer) and call doInstall().
 */
use Zencart\PluginSupport\ScriptedInstaller as ScriptedInstallBase;

class ScriptedInstaller extends ScriptedInstallBase
{
    private const GROUP_TITLE = 'Seekmodo AI Chatbot';
    private const DEFAULT_ADDITIONAL_RULES = <<<'TXT'
- Answer inquiries using only this business's provided knowledge and tool results.
- If you are unsure, say your knowledge is limited to the information provided by this business.
- If a message contains multiple questions, answer them one by one.
- Refuse to tell jokes.
- When discussing a product from knowledge or tools, include its storefront URL in plain text.
- Only ask the shopper to email engraving/message text if it cannot be entered on the product page.
- Never say the store is closed; give opening hours instead.
- Prefer not to share the telephone number; offer a support ticket (or the configured support email) for human help.
- For shipping / delivery ETA / destination questions: call mcp_kb_topic_get (shipping or international) first when those topics exist; use kb_search for long-form pages (delivery, shippinginfo, gv_faq). Never invent carrier SLAs or claim the store does not ship to a country - offer a support ticket for unclear destinations.
- For general FAQ questions (tracking how-to, discount codes, accounts, engraving characters, invoice removal): call mcp_kb_topic_get (faq) then kb_search (prefer gv_faq). Do not invent policy.
- For returns / exchanges: call mcp_kb_topic_get (returns) when available, kb_search (shipping_and_returns / gv_faq), then mcp_return_request for logged-in shoppers. Never invent refunds or RMA numbers.
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

    private const CONFIG_KEYS = [
        'SEEKMODO_AI_CHATBOT_ENABLED' => [
            'title' => 'Chatbot: Enabled',
            'value' => 'false',
            'description' => 'Enable the Seekmodo AI Chatbot on the storefront. Requires a paired paid Seekmodo connector.',
            'sort' => 10,
            'set_function' => "zen_cfg_select_option(array('true', 'false'),",
        ],
        'SEEKMODO_AI_CHATBOT_LLM_PROVIDER' => [
            'title' => 'Chatbot: LLM Provider',
            'value' => 'openai',
            'description' => 'Merchant BYO LLM: openai, claude, or deepseek.',
            'sort' => 15,
            'set_function' => "zen_cfg_select_option(array('openai', 'claude', 'deepseek'),",
        ],
        'SEEKMODO_AI_CHATBOT_API_KEY' => [
            'title' => 'Chatbot: OpenAI API Key',
            'value' => '',
            'description' => 'OpenAI API key (used when provider is openai).',
            'sort' => 20,
        ],
        'SEEKMODO_AI_CHATBOT_CLAUDE_API_KEY' => [
            'title' => 'Chatbot: Claude API Key',
            'value' => '',
            'description' => 'Anthropic API key (used when provider is claude).',
            'sort' => 21,
        ],
        'SEEKMODO_AI_CHATBOT_DEEPSEEK_API_KEY' => [
            'title' => 'Chatbot: DeepSeek API Key',
            'value' => '',
            'description' => 'DeepSeek API key (used when provider is deepseek).',
            'sort' => 22,
        ],
        'SEEKMODO_AI_CHATBOT_MODEL' => [
            'title' => 'Chatbot: Model',
            'value' => 'gpt-4o-mini',
            'description' => 'Model id for the selected LLM provider.',
            'sort' => 30,
        ],
        'SEEKMODO_AI_CHATBOT_MCP_URL' => [
            'title' => 'Chatbot: MCP Endpoint URL',
            'value' => '',
            'description' => 'Numinix MCP endpoint URL (e.g. https://example.com/numinix_mcp.php).',
            'sort' => 40,
        ],
        'SEEKMODO_AI_CHATBOT_MCP_TOKEN' => [
            'title' => 'Chatbot: MCP Bearer Token',
            'value' => '',
            'description' => 'Bearer token for MCP access (chatbot-scoped recommended).',
            'sort' => 50,
        ],
        'SEEKMODO_AI_CHATBOT_ALLOW_ORDER_MUTATIONS' => [
            'title' => 'Chatbot: Allow In-Chat Order Mutations',
            'value' => 'false',
            'description' => 'When true, the chatbot may change editable orders in chat (engraving text, address, shipping upgrade, cancel). When false (default), it only looks up orders and shares My Account self-service edit links.',
            'sort' => 55,
            'set_function' => "zen_cfg_select_option(array('true', 'false'),",
        ],
        'SEEKMODO_AI_CHATBOT_SYSTEM_PROMPT' => [
            'title' => 'Chatbot: System Prompt',
            'value' => 'You are a helpful customer service assistant for this store. Answer questions about products, orders, and store policies using the tools available to you. For product recommendations, search the catalog with seekmodo_product_search (when configured) and cite storefront links from the results.',
            'description' => 'System prompt for the chatbot AI.',
            'sort' => 60,
        ],
        'SEEKMODO_AI_CHATBOT_ADDITIONAL_RULES' => [
            'title' => 'Chatbot: Additional Rules',
            'value' => self::DEFAULT_ADDITIONAL_RULES,
            'description' => 'Extra rules appended after the built-in chatbot prompt guidance.',
            'sort' => 65,
        ],
        'SEEKMODO_AI_CHATBOT_WIDGET_GREETING' => [
            'title' => 'Chatbot: Widget Greeting',
            'value' => 'Hi! How can I help you today?',
            'description' => 'Greeting message shown in the chat widget.',
            'sort' => 70,
        ],
        'SEEKMODO_AI_CHATBOT_WIDGET_PRIMARY_COLOR' => [
            'title' => 'Chatbot: Widget Primary Color',
            'value' => '#10B981',
            'description' => 'Primary accent for the storefront chat launcher, header, and buttons (hex, e.g. #60A4C9 for Keep it Personal).',
            'sort' => 71,
        ],
        'SEEKMODO_AI_CHATBOT_WIDGET_SECONDARY_COLOR' => [
            'title' => 'Chatbot: Widget Secondary Color',
            'value' => '#06B6D4',
            'description' => 'Secondary gradient / hover accent for the storefront chat widget (hex).',
            'sort' => 72,
        ],
        'SEEKMODO_AI_CHATBOT_LAUNCHER_JSON' => [
            'title' => 'Chatbot: Launcher JSON',
            'value' => '',
            'description' => 'Seekmodo chat launcher (icon color, flair, automations). Written by the connector from tenant.snapshot. Leave empty to keep the local widget colors.',
            'sort' => 74,
        ],
        'SEEKMODO_AI_CHATBOT_WIDGET_ICON' => [
            'title' => 'Chatbot: Widget Agent Icon',
            'value' => '',
            'description' => 'Image shown next to bot and agent replies in the chat widget. Upload via Tools → Seekmodo AI Chatbot settings. Stores a catalog-relative path or absolute URL.',
            'sort' => 73,
        ],
        'SEEKMODO_AI_CHATBOT_MAX_TOOL_ROUNDS' => [
            'title' => 'Chatbot: Max Tool Rounds',
            'value' => '10',
            'description' => 'Maximum tool-calling iterations per conversation.',
            'sort' => 80,
        ],
        'SEEKMODO_AI_CHATBOT_RATE_LIMIT_RPM' => [
            'title' => 'Chatbot: Rate Limit (req/min)',
            'value' => '30',
            'description' => 'Rate limit for chat API requests per IP per minute.',
            'sort' => 90,
        ],
        'SEEKMODO_AI_CHATBOT_KB_PATH' => [
            'title' => 'Chatbot: KB Cache Path',
            'value' => '',
            'description' => 'Relative path to compiled KB directory (default: cache/seekmodo_ai_chatbot/kb).',
            'sort' => 100,
        ],
        'SEEKMODO_AI_CHATBOT_SUPPORT_EMAIL' => [
            'title' => 'Chatbot: Support Email',
            'value' => '',
            'description' => 'Inbox that receives chatbot support tickets. Prefer configuring Tools → Support Ticket Mail (kept in sync with the mailbox address).',
            'sort' => 105,
        ],
        'SEEKMODO_AI_CHATBOT_MAIL_ENABLED' => [
            'title' => 'Chatbot Mail: Enabled',
            'value' => 'false',
            'description' => 'Enable IMAP polling and SMTP sending for Support Tickets.',
            'sort' => 200,
            'set_function' => "zen_cfg_select_option(array('true', 'false'),",
        ],
        'SEEKMODO_AI_CHATBOT_MAIL_ADDRESS' => [
            'title' => 'Chatbot Mail: Address',
            'value' => '',
            'description' => 'Shared From/Reply-To mailbox for Support Tickets.',
            'sort' => 201,
        ],
        'SEEKMODO_AI_CHATBOT_MAIL_CREATE_UNMATCHED' => [
            'title' => 'Chatbot Mail: Create Tickets From New Email',
            'value' => 'false',
            'description' => 'Off by default. When true, and mail is enabled, poll inbound email that has no ticket number and open a new support ticket. Replies that already include #id stay on that ticket. The Seekmodo tenant admin setting replaces this value when the connector snapshot publishes it.',
            'sort' => 202,
            'set_function' => "zen_cfg_select_option(array('true', 'false'),",
        ],
        'SEEKMODO_AI_CHATBOT_MAIL_IMAP_HOST' => [
            'title' => 'Chatbot Mail: IMAP Host', 'value' => '', 'description' => '', 'sort' => 210,
        ],
        'SEEKMODO_AI_CHATBOT_MAIL_IMAP_PORT' => [
            'title' => 'Chatbot Mail: IMAP Port', 'value' => '993', 'description' => '', 'sort' => 211,
        ],
        'SEEKMODO_AI_CHATBOT_MAIL_IMAP_ENCRYPTION' => [
            'title' => 'Chatbot Mail: IMAP Encryption', 'value' => 'ssl', 'description' => 'ssl, tls, or none', 'sort' => 212,
        ],
        'SEEKMODO_AI_CHATBOT_MAIL_IMAP_FOLDER' => [
            'title' => 'Chatbot Mail: IMAP Folder', 'value' => 'INBOX', 'description' => '', 'sort' => 213,
        ],
        'SEEKMODO_AI_CHATBOT_MAIL_IMAP_USER' => [
            'title' => 'Chatbot Mail: IMAP Username', 'value' => '', 'description' => '', 'sort' => 214,
        ],
        'SEEKMODO_AI_CHATBOT_MAIL_IMAP_PASSWORD' => [
            'title' => 'Chatbot Mail: IMAP Password', 'value' => '', 'description' => '', 'sort' => 215,
        ],
        'SEEKMODO_AI_CHATBOT_MAIL_SMTP_HOST' => [
            'title' => 'Chatbot Mail: SMTP Host', 'value' => '', 'description' => 'Blank copies IMAP host', 'sort' => 220,
        ],
        'SEEKMODO_AI_CHATBOT_MAIL_SMTP_PORT' => [
            'title' => 'Chatbot Mail: SMTP Port', 'value' => '587', 'description' => '', 'sort' => 221,
        ],
        'SEEKMODO_AI_CHATBOT_MAIL_SMTP_ENCRYPTION' => [
            'title' => 'Chatbot Mail: SMTP Encryption', 'value' => 'tls', 'description' => 'ssl, tls, or none', 'sort' => 222,
        ],
        'SEEKMODO_AI_CHATBOT_MAIL_SMTP_USER' => [
            'title' => 'Chatbot Mail: SMTP Username', 'value' => '', 'description' => 'Blank copies IMAP user', 'sort' => 223,
        ],
        'SEEKMODO_AI_CHATBOT_MAIL_SMTP_PASSWORD' => [
            'title' => 'Chatbot Mail: SMTP Password', 'value' => '', 'description' => 'Blank copies IMAP password', 'sort' => 224,
        ],
        'SEEKMODO_AI_CHATBOT_SESSION_IDLE_MINUTES' => [
            'title' => 'Chatbot: Session Idle Minutes',
            'value' => '30',
            'description' => 'End a chat session after this many minutes without activity (default 30).',
            'sort' => 106,
        ],
        'SEEKMODO_AI_CHATBOT_ESCALATION_WAIT_SECONDS' => [
            'title' => 'Chatbot: Live Escalation Wait Seconds',
            'value' => '45',
            'description' => 'Seconds to wait for a human claim after live escalation before offering continue-with-AI or a support ticket (default 45; clamped 15–300). Overridden by Seekmodo tenant setting when published via connector snapshot.',
            'sort' => 109,
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
        'SEEKMODO_AI_CHATBOT_SEEKMODO_URL' => [
            'title' => 'Chatbot: Seekmodo Gateway URL',
            'value' => 'https://mcp.seekmodo.com',
            'description' => 'Seekmodo MCP gateway base URL used for product search when the connector is not paired (HMAC /v1/search).',
            'sort' => 110,
        ],
        'SEEKMODO_AI_CHATBOT_SEEKMODO_TENANT_ID' => [
            'title' => 'Chatbot: Seekmodo Tenant ID',
            'value' => '',
            'description' => 'Seekmodo tenant id for this storefront. Falls back to NUMINIX_SEEKMODO_TENANT_ID when empty.',
            'sort' => 120,
        ],
        'SEEKMODO_AI_CHATBOT_SEEKMODO_SHARED_SECRET' => [
            'title' => 'Chatbot: Seekmodo Shared Secret',
            'value' => '',
            'description' => 'HMAC shared secret for Seekmodo /v1/search. Falls back to NUMINIX_SEEKMODO_SHARED_SECRET when empty.',
            'sort' => 130,
        ],
        'SEEKMODO_AI_CHATBOT_SEEKMODO_STOREFRONT_HOST' => [
            'title' => 'Chatbot: Seekmodo Storefront Host',
            'value' => '',
            'description' => 'Canonical hostname Seekmodo expects for this tenant (e.g. www.example.com). Required when searching from dig/staging hosts against a production-locked tenant.',
            'sort' => 140,
        ],
        'SEEKMODO_AI_CHATBOT_SEEKMODO_TENANT_HOST_MAP' => [
            'title' => 'Chatbot: Seekmodo Tenant Host Map (JSON)',
            'value' => '',
            'description' => 'Optional JSON map of tenant_id → hostname when one install serves multiple Seekmodo tenants. Leave empty for single-tenant stores.',
            'sort' => 150,
        ],
        'SEEKMODO_AI_CHATBOT_POST_CHAT_ANALYSIS_ENABLED' => [
            'title' => 'Post-Chat Analysis: Enabled',
            'value' => 'false',
            'description' => 'Analyze resolved/idle chats with an LLM pass and propose typed improvement drafts for admin review (Tools → Chatbot Improvements). Never applies anything automatically.',
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
    ];

    protected function executeInstall()
    {
        $this->migrateFromOpenaiGptChatbot();
        $groupId = $this->ensureGroup();
        $this->ensureAdminPages();
        $this->ensureChatArchiveTable();
        $this->hideGroupFromAdmin($groupId);

        foreach (self::CONFIG_KEYS as $key => $meta) {
            $this->ensureConfigurationKey($key, $meta, $groupId);
        }

        $this->wireNuminixMcp();
        $this->ensureReplyEmailTemplate();
        $this->ensureSupportEmailTemplate();
        $this->ensureCatalogHttpEndpoints();
        $this->removeLegacyCatalogChatEndpoint();
        $this->ensureDashboardWidgetInclude();
        $this->disableLegacyOpenaiGptChatbotPlugin();

        return true;
    }

    public function doUpgrade($oldVersion)
    {
        return $this->executeUpgrade($oldVersion);
    }

    protected function executeUpgrade($oldVersion)
    {
        $this->migrateFromOpenaiGptChatbot();
        $this->ensureAdminPages();
        $this->ensureChatArchiveTable();
        $groupId = $this->ensureGroup();
        foreach (self::CONFIG_KEYS as $key => $meta) {
            $this->ensureConfigurationKey($key, $meta, $groupId);
        }
        $this->wireNuminixMcp();
        $this->ensureReplyEmailTemplate();
        $this->ensureSupportEmailTemplate();
        $this->ensureCatalogHttpEndpoints();
        $this->removeLegacyCatalogChatEndpoint();
        $this->ensureDashboardWidgetInclude();
        $this->disableLegacyOpenaiGptChatbotPlugin();
        return true;
    }

    protected function executeUninstall()
    {
        if (function_exists('zen_deregister_admin_pages')) {
            zen_deregister_admin_pages(['seekmodoAiChatbotSettings', 'seekmodoAiChatbotChats', 'seekmodoAiChatbotTickets', 'seekmodoAiChatbotMail']);
        } elseif (function_exists('zen_deregister_admin_page')) {
            zen_deregister_admin_page('seekmodoAiChatbotSettings');
            zen_deregister_admin_page('seekmodoAiChatbotChats');
            zen_deregister_admin_page('seekmodoAiChatbotTickets');
            zen_deregister_admin_page('seekmodoAiChatbotMail');
        }

        $keys = array_keys(self::CONFIG_KEYS);
        foreach ($keys as $key) {
            $this->dbConn->Execute(
                "DELETE FROM " . TABLE_CONFIGURATION
                . " WHERE configuration_key = '" . $this->dbConn->prepareInput($key) . "'"
            );
        }

        $prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
        $this->dbConn->Execute(
            "DELETE FROM `" . $prefix . "configuration_group`"
            . " WHERE configuration_group_title = '" . $this->dbConn->prepareInput(self::GROUP_TITLE) . "'"
        );

        return true;
    }

    private function ensureGroup(): int
    {
        $prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
        $groupTable = '`' . $prefix . 'configuration_group`';
        $result = $this->dbConn->Execute(
            "SELECT configuration_group_id FROM {$groupTable}
              WHERE configuration_group_title = '" . $this->dbConn->prepareInput(self::GROUP_TITLE) . "'
              LIMIT 1"
        );
        if (!$result->EOF) {
            return (int) $result->fields['configuration_group_id'];
        }
        $this->dbConn->Execute(
            "INSERT INTO {$groupTable}
                (configuration_group_title, configuration_group_description, sort_order, visible)
             VALUES (
                '" . $this->dbConn->prepareInput(self::GROUP_TITLE) . "',
                'Runtime settings for the Seekmodo AI Chatbot (managed via Tools menu).',
                920,
                1
             )"
        );
        return (int) $this->dbConn->Insert_ID();
    }

    private function hideGroupFromAdmin(int $groupId): void
    {
        if ($groupId < 1) {
            return;
        }
        $prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
        $this->dbConn->Execute(
            "UPDATE `" . $prefix . "configuration_group`
                SET visible = 0
              WHERE configuration_group_id = " . (int) $groupId
        );
    }

    /**
     * @param array{title:string,value:string,description:string,sort:int,set_function?:string} $meta
     */
    private function ensureConfigurationKey(string $key, array $meta, int $groupId): void
    {
        $check = $this->dbConn->Execute(
            "SELECT configuration_key FROM " . TABLE_CONFIGURATION
            . " WHERE configuration_key = '" . $this->dbConn->prepareInput($key) . "' LIMIT 1"
        );
        if (!$check->EOF) {
            return;
        }

        $setFunction = isset($meta['set_function'])
            ? "'" . $this->dbConn->prepareInput($meta['set_function']) . "'"
            : 'NULL';

        $this->dbConn->Execute(
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
                '" . $this->dbConn->prepareInput($meta['title']) . "',
                '" . $this->dbConn->prepareInput($key) . "',
                '" . $this->dbConn->prepareInput($meta['value']) . "',
                '" . $this->dbConn->prepareInput($meta['description']) . "',
                " . (int) $groupId . ",
                " . (int) $meta['sort'] . ",
                NOW(),
                NULL,
                {$setFunction}
             )"
        );
    }

    private function ensureAdminPages(): void
    {
        // ZC 1.5.7: zen_register_admin_page (singular). 1.5.8+: pages (plural).
        if (function_exists('zen_register_admin_pages')) {
            zen_register_admin_pages(
                'seekmodoAiChatbotSettings',
                'BOX_TOOLS_SEEKMODO_AI_CHATBOT_SETTINGS',
                'FILENAME_SEEKMODO_AI_CHATBOT_SETTINGS',
                '',
                'tools',
                'Y',
                520
            );
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
                'seekmodoAiChatbotTickets',
                'BOX_TOOLS_SEEKMODO_AI_CHATBOT_TICKETS',
                'FILENAME_SEEKMODO_AI_CHATBOT_TICKETS',
                '',
                'tools',
                'Y',
                522
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
            $this->migrateMailAdminPageToConfiguration();
            return;
        }
        if (function_exists('zen_register_admin_page') && function_exists('zen_page_key_exists')) {
            if (!zen_page_key_exists('seekmodoAiChatbotSettings')) {
                if (function_exists('zen_record_admin_activity')) {
                    zen_register_admin_page(
                        'seekmodoAiChatbotSettings',
                        'BOX_TOOLS_SEEKMODO_AI_CHATBOT_SETTINGS',
                        'FILENAME_SEEKMODO_AI_CHATBOT_SETTINGS',
                        '',
                        'tools',
                        'Y',
                        520
                    );
                } else {
                    $this->dbConn->Execute(
                        "INSERT INTO " . TABLE_ADMIN_PAGES . " (
                            page_key, language_key, main_page, page_params, menu_key, display_on_menu, sort_order
                         ) VALUES (
                            'seekmodoAiChatbotSettings',
                            'BOX_TOOLS_SEEKMODO_AI_CHATBOT_SETTINGS',
                            'FILENAME_SEEKMODO_AI_CHATBOT_SETTINGS',
                            '',
                            'tools',
                            'Y',
                            520
                         )"
                    );
                }
            }
            if (!zen_page_key_exists('seekmodoAiChatbotChats')) {
                if (function_exists('zen_record_admin_activity')) {
                    zen_register_admin_page(
                        'seekmodoAiChatbotChats',
                        'BOX_TOOLS_SEEKMODO_AI_CHATBOT_CHATS',
                        'FILENAME_SEEKMODO_AI_CHATBOT_CHATS',
                        '',
                        'tools',
                        'Y',
                        521
                    );
                } else {
                    $this->dbConn->Execute(
                        "INSERT INTO " . TABLE_ADMIN_PAGES . " (
                            page_key, language_key, main_page, page_params, menu_key, display_on_menu, sort_order
                         ) VALUES (
                            'seekmodoAiChatbotChats',
                            'BOX_TOOLS_SEEKMODO_AI_CHATBOT_CHATS',
                            'FILENAME_SEEKMODO_AI_CHATBOT_CHATS',
                            '',
                            'tools',
                            'Y',
                            521
                         )"
                    );
                }
            }
            if (!zen_page_key_exists('seekmodoAiChatbotTickets')) {
                if (function_exists('zen_record_admin_activity')) {
                    zen_register_admin_page(
                        'seekmodoAiChatbotTickets',
                        'BOX_TOOLS_SEEKMODO_AI_CHATBOT_TICKETS',
                        'FILENAME_SEEKMODO_AI_CHATBOT_TICKETS',
                        '',
                        'tools',
                        'Y',
                        522
                    );
                } else {
                    $this->dbConn->Execute(
                        "INSERT INTO " . TABLE_ADMIN_PAGES . " (
                            page_key, language_key, main_page, page_params, menu_key, display_on_menu, sort_order
                         ) VALUES (
                            'seekmodoAiChatbotTickets',
                            'BOX_TOOLS_SEEKMODO_AI_CHATBOT_TICKETS',
                            'FILENAME_SEEKMODO_AI_CHATBOT_TICKETS',
                            '',
                            'tools',
                            'Y',
                            522
                         )"
                    );
                }
            }
            if (!zen_page_key_exists('seekmodoAiChatbotMail')) {
                if (function_exists('zen_record_admin_activity')) {
                    zen_register_admin_page(
                        'seekmodoAiChatbotMail',
                        'BOX_CONFIGURATION_SEEKMODO_AI_CHATBOT_MAIL',
                        'FILENAME_SEEKMODO_AI_CHATBOT_MAIL',
                        '',
                        'configuration',
                        'Y',
                        522
                    );
                } else {
                    $this->dbConn->Execute(
                        "INSERT INTO " . TABLE_ADMIN_PAGES . " (
                            page_key, language_key, main_page, page_params, menu_key, display_on_menu, sort_order
                         ) VALUES (
                            'seekmodoAiChatbotMail',
                            'BOX_CONFIGURATION_SEEKMODO_AI_CHATBOT_MAIL',
                            'FILENAME_SEEKMODO_AI_CHATBOT_MAIL',
                            '',
                            'configuration',
                            'Y',
                            522
                         )"
                    );
                }
            }
        }
        $this->migrateMailAdminPageToConfiguration();
    }


    /**
     * Move Support Ticket Mail from Tools to Configuration (menu + language key).
     * zen_register_admin_pages does not update existing rows.
     */
    private function migrateMailAdminPageToConfiguration(): void
    {
        $this->dbConn->Execute(
            "UPDATE " . TABLE_ADMIN_PAGES . "
             SET menu_key = 'configuration',
                 language_key = 'BOX_CONFIGURATION_SEEKMODO_AI_CHATBOT_MAIL'
             WHERE page_key = 'seekmodoAiChatbotMail'
               AND (menu_key <> 'configuration'
                    OR language_key <> 'BOX_CONFIGURATION_SEEKMODO_AI_CHATBOT_MAIL')"
        );
    }
    /**
     * Ensure SupportTickets widget is registered on the admin dashboard.
     * Prefer the NOTIFY_ADMIN_DASHBOARD_WIDGETS registry (no file patch).
     * Fall back to string-patching index_dashboard.php on older dashboards.
     */
    private function ensureDashboardWidgetInclude(): void
    {
        $this->ensureAdminDashboardFiles();
        $candidates = [];
        if (defined('DIR_FS_ADMIN')) {
            $candidates[] = rtrim((string) DIR_FS_ADMIN, '/\\') . '/index_dashboard.php';
        }
        if (defined('DIR_FS_CATALOG')) {
            $catalog = rtrim((string) DIR_FS_CATALOG, '/\\');
            $candidates[] = $catalog . '/admin/index_dashboard.php';
            $parent = dirname($catalog);
            foreach (glob($parent . '/admin*/index_dashboard.php') ?: [] as $path) {
                $candidates[] = $path;
            }
        }
        $marker = 'SeekmodoAiChatbot SupportTicketsDashboardWidget';
        $usesRegistry = false;
        foreach (array_unique($candidates) as $path) {
            if (!is_file($path)) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            if ($contents === '') {
                continue;
            }
            if (strpos($contents, 'NOTIFY_ADMIN_DASHBOARD_WIDGETS') !== false
                || preg_match("/['\"]SupportTickets['\"]\\s*=>/", $contents)
                || strpos($contents, "'SupportTickets'") !== false
            ) {
                $usesRegistry = true;
                break;
            }
            if (strpos($contents, $marker) !== false) {
                return;
            }
        }
        if ($usesRegistry) {
            // Dashboard registry / notifier already supports SupportTickets (site default or observer).
            return;
        }

        $includeLine = "\n        <?php include DIR_WS_MODULES . 'dashboard_widgets/SupportTicketsDashboardWidget.php'; // {$marker} ?>\n";
        foreach (array_unique($candidates) as $path) {
            if (!is_file($path) || !is_writable($path)) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            if ($contents === '' || strpos($contents, $marker) !== false) {
                continue;
            }
            $block = "include DIR_WS_MODULES . 'dashboard_widgets/OrderStatusDashboardWidget.php';\n        ?>";
            $pos = strpos($contents, $block);
            if ($pos !== false) {
                $insertAt = $pos + strlen($block);
                $contents = substr($contents, 0, $insertAt) . $includeLine . substr($contents, $insertAt);
            } else {
                $needle = "dashboard_widgets/OrderStatusDashboardWidget.php";
                $pos = strpos($contents, $needle);
                if ($pos === false) {
                    $colEnd = strpos($contents, '</div>', strpos($contents, 'id="colone"') ?: 0);
                    if ($colEnd === false) {
                        continue;
                    }
                    $contents = substr($contents, 0, $colEnd) . "\n" . $includeLine . substr($contents, $colEnd);
                } else {
                    $close = strpos($contents, '?>', $pos);
                    if ($close === false) {
                        continue;
                    }
                    $contents = substr($contents, 0, $close + 2) . $includeLine . substr($contents, $close + 2);
                }
            }
            @file_put_contents($path, $contents);
        }
    }

    /**
     * Copy dashboard observer/widget into the live admin tree so NOTIFY_ADMIN_DASHBOARD_WIDGETS
     * loads even when Plugin Manager did not sync admin includes (common on older ZC installs).
     */
    private function ensureAdminDashboardFiles(): void
    {
        $adminRoots = [];
        if (defined('DIR_FS_ADMIN')) {
            $adminRoots[] = rtrim((string) DIR_FS_ADMIN, '/\\');
        }
        if (defined('DIR_FS_CATALOG')) {
            $catalog = rtrim((string) DIR_FS_CATALOG, '/\\');
            $adminRoots[] = $catalog . '/admin';
            $parent = dirname($catalog);
            foreach (glob($parent . '/admin*', GLOB_ONLYDIR) ?: [] as $dir) {
                $adminRoots[] = $dir;
            }
        }
        $adminRoots = array_values(array_unique(array_filter($adminRoots, 'is_dir')));
        if ($adminRoots === []) {
            return;
        }

        $pluginAdmin = dirname(__DIR__) . '/admin';
        $map = [
            'includes/auto_loaders/config.seekmodo_ai_chatbot_dashboard.php',
            'includes/classes/observers/zcObserverSeekmodoAiChatbotDashboardWidget.php',
            'includes/modules/dashboard_widgets/SupportTicketsDashboardWidget.php',
            'includes/seekmodo_ai_chatbot_library.php',
        ];
        foreach ($adminRoots as $adminRoot) {
            foreach ($map as $rel) {
                $src = $pluginAdmin . '/' . $rel;
                if (!is_file($src)) {
                    continue;
                }
                $dst = $adminRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                $dir = dirname($dst);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                if (!is_dir($dir)) {
                    continue;
                }
                $srcBody = (string) @file_get_contents($src);
                $dstBody = is_file($dst) ? (string) @file_get_contents($dst) : '';
                if ($srcBody !== '' && $srcBody !== $dstBody) {
                    @file_put_contents($dst, $srcBody);
                }
            }
        }
    }

    private function ensureChatArchiveTable(): void
    {
        $prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
        $table = $prefix . 'seekmodo_ai_chatbot_chats';
        $check = $this->dbConn->Execute("SHOW TABLES LIKE '" . $table . "'");
        $ogcLibrary = dirname(__DIR__) . '/catalog/includes/library/SeekmodoAiChatbot';
        if ($check && !$check->EOF) {
            if (is_file($ogcLibrary . '/ChatArchive.php')) {
                require_once $ogcLibrary . '/ChatArchive.php';
                \SeekmodoAiChatbot\ChatArchive::ensureTable();
            }
            return;
        }
        $this->dbConn->Execute(
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
        if (is_file($ogcLibrary . '/ChatArchive.php')) {
            require_once $ogcLibrary . '/ChatArchive.php';
            \SeekmodoAiChatbot\ChatArchive::ensureTable();
        }
    }

    /**
     * If Numinix MCP is already installed, fill empty MCP URL/token via its wire helper.
     */
    private function wireNuminixMcp(): void
    {
        $catalog = defined('DIR_FS_CATALOG') ? DIR_FS_CATALOG : dirname(__DIR__, 4) . '/';
        $mcpRoot = rtrim($catalog, '/\\') . '/zc_plugins/NuminixMcp';
        if (!is_dir($mcpRoot)) {
            return;
        }

        $wire = null;
        $versions = glob($mcpRoot . '/v*', GLOB_ONLYDIR) ?: [];
        rsort($versions, SORT_NATURAL);
        foreach ($versions as $versionDir) {
            foreach (['wire_seekmodo_ai_chatbot.php', 'wire_openai_gpt_chatbot.php'] as $name) {
                $candidate = $versionDir . '/Installer/' . $name;
                if (is_file($candidate)) {
                    $wire = $candidate;
                    break 2;
                }
            }
        }
        if ($wire === null) {
            return;
        }

        require_once $wire;
        if (function_exists('numinix_mcp_wire_seekmodo_ai_chatbot')) {
            numinix_mcp_wire_seekmodo_ai_chatbot($this->dbConn);
            return;
        }
        if (function_exists('numinix_mcp_wire_openai_gpt_chatbot')) {
            numinix_mcp_wire_openai_gpt_chatbot($this->dbConn);
        }
    }

    /**
     * Install branded reply template (Transactional Marketing–style KIP shell).
     */
    private function ensureReplyEmailTemplate(): void
    {
        $this->copyEmailTemplate(
            dirname(__DIR__) . '/catalog/email/email_template_seekmodo_ai_chatbot_reply.html',
            'email_template_seekmodo_ai_chatbot_reply.html'
        );
    }

    /**
     * Install branded support-ticket template (open_support_ticket zen_mail module).
     */
    private function ensureSupportEmailTemplate(): void
    {
        $this->copyEmailTemplate(
            dirname(__DIR__) . '/catalog/email/email_template_seekmodo_ai_chatbot_support.html',
            'email_template_seekmodo_ai_chatbot_support.html'
        );
    }

    private function copyEmailTemplate(string $src, string $destName): void
    {
        if (!is_file($src)) {
            return;
        }
        $destDir = defined('DIR_FS_EMAIL_TEMPLATES')
            ? rtrim((string) DIR_FS_EMAIL_TEMPLATES, '/\\')
            : (defined('DIR_FS_CATALOG') ? rtrim((string) DIR_FS_CATALOG, '/\\') . '/email' : '');
        if ($destDir === '') {
            return;
        }
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0755, true);
        }
        @copy($src, $destDir . '/' . $destName);
    }

    /**
     * Zen Cart blocks HTTP to PHP under zc_plugins/, so public API files must
     * live at the catalog root (same pattern as numinix_mcp.php).
     */
    private function ensureCatalogHttpEndpoints(): void
    {
        if (!defined('DIR_FS_CATALOG')) {
            return;
        }
        $catalogRoot = rtrim((string) DIR_FS_CATALOG, '/\\');
        $pluginCatalog = dirname(__DIR__) . '/catalog';
        foreach (['seekmodo_ai_chat.php', 'seekmodo_ai_chatbot_inbox_api.php', 'seekmodo_ai_chatbot_portal_token.php'] as $basename) {
            $src = $pluginCatalog . '/' . $basename;
            if (!is_file($src)) {
                continue;
            }
            @copy($src, $catalogRoot . '/' . $basename);
        }
    }

    /** @deprecated Use ensureCatalogHttpEndpoints() */
    private function ensureCatalogChatEndpoint(): void
    {
        $this->ensureCatalogHttpEndpoints();
    }

    private function removeLegacyCatalogChatEndpoint(): void
    {
        if (!defined('DIR_FS_CATALOG')) {
            return;
        }
        $legacy = rtrim((string) DIR_FS_CATALOG, '/\\') . '/openai_gpt_chat.php';
        if (is_file($legacy)) {
            @unlink($legacy);
        }
    }

    /**
     * After a successful rename install, stop the legacy OpenaiGptChatbot
     * plugin from remaining enabled alongside SeekmodoAiChatbot (duplicate
     * auto_loaders / Plugin Manager "both installed" state).
     */
    private function disableLegacyOpenaiGptChatbotPlugin(): void
    {
        $prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
        $table = '`' . $prefix . 'plugin_control`';
        $check = $this->dbConn->Execute("SHOW TABLES LIKE '" . $this->dbConn->prepareInput($prefix . 'plugin_control') . "'");
        if (!$check || $check->EOF) {
            return;
        }
        $this->dbConn->Execute(
            "UPDATE {$table} SET status = 0 WHERE unique_key = 'OpenaiGptChatbot' AND status <> 0"
        );
    }

    /**
     * One-shot migration from OpenaiGptChatbot config keys + archive table.
     */
    private function migrateFromOpenaiGptChatbot(): void
    {
        $prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
        $oldPrefix = 'OPENAI_GPT_CHATBOT_';
        $newPrefix = 'SEEKMODO_AI_CHATBOT_';

        $rows = $this->dbConn->Execute(
            "SELECT configuration_key, configuration_value FROM " . TABLE_CONFIGURATION
            . " WHERE configuration_key LIKE '" . $this->dbConn->prepareInput($oldPrefix) . "%'"
        );
        while ($rows && !$rows->EOF) {
            $oldKey = (string) $rows->fields['configuration_key'];
            $value = (string) $rows->fields['configuration_value'];
            $suffix = substr($oldKey, strlen($oldPrefix));
            $newKey = $newPrefix . $suffix;
            // Never inherit a legacy "enabled" flag — storefront chat must stay
            // off until explicitly enabled under the Seekmodo AI Chatbot settings.
            if ($suffix === 'ENABLED') {
                $value = 'false';
            }
            $exists = $this->dbConn->Execute(
                "SELECT configuration_id FROM " . TABLE_CONFIGURATION
                . " WHERE configuration_key = '" . $this->dbConn->prepareInput($newKey) . "' LIMIT 1"
            );
            if ($exists && $exists->EOF) {
                // Will be created by ensureConfigurationKey; stash value after.
                $this->dbConn->Execute(
                    "UPDATE " . TABLE_CONFIGURATION
                    . " SET configuration_key = '" . $this->dbConn->prepareInput($newKey) . "'"
                    . ", configuration_value = '" . $this->dbConn->prepareInput($value) . "'"
                    . " WHERE configuration_key = '" . $this->dbConn->prepareInput($oldKey) . "'"
                );
            } else {
                // New key already present — copy value if empty, then drop old.
                // ENABLED is forced false even when the new key already exists.
                if ($exists && !$exists->EOF) {
                    if ($suffix === 'ENABLED') {
                        $this->dbConn->Execute(
                            "UPDATE " . TABLE_CONFIGURATION
                            . " SET configuration_value = 'false'"
                            . " WHERE configuration_key = '" . $this->dbConn->prepareInput($newKey) . "'"
                        );
                    } else {
                        $this->dbConn->Execute(
                            "UPDATE " . TABLE_CONFIGURATION
                            . " SET configuration_value = '" . $this->dbConn->prepareInput($value) . "'"
                            . " WHERE configuration_key = '" . $this->dbConn->prepareInput($newKey) . "'"
                            . " AND (configuration_value = '' OR configuration_value IS NULL)"
                        );
                    }
                }
                $this->dbConn->Execute(
                    "DELETE FROM " . TABLE_CONFIGURATION
                    . " WHERE configuration_key = '" . $this->dbConn->prepareInput($oldKey) . "'"
                );
            }
            $rows->MoveNext();
        }

        // Rename old configuration group title if present.
        $this->dbConn->Execute(
            "UPDATE `" . $prefix . "configuration_group`"
            . " SET configuration_group_title = '" . $this->dbConn->prepareInput(self::GROUP_TITLE) . "'"
            . " WHERE configuration_group_title = 'OpenAI GPT Chatbot'"
        );

        $oldTable = $prefix . 'openai_gpt_chatbot_chats';
        $newTable = $prefix . 'seekmodo_ai_chatbot_chats';
        $oldExists = $this->dbConn->Execute("SHOW TABLES LIKE '" . $this->dbConn->prepareInput($oldTable) . "'");
        $newExists = $this->dbConn->Execute("SHOW TABLES LIKE '" . $this->dbConn->prepareInput($newTable) . "'");
        if ($oldExists && !$oldExists->EOF && $newExists && $newExists->EOF) {
            $this->dbConn->Execute(
                'RENAME TABLE `' . str_replace('`', '``', $oldTable) . '` TO `' . str_replace('`', '``', $newTable) . '`'
            );
        }

        $oldInbound = $prefix . 'openai_gpt_chatbot_inbound_uid';
        $newInbound = $prefix . 'seekmodo_ai_chatbot_inbound_uid';
        $oldIn = $this->dbConn->Execute("SHOW TABLES LIKE '" . $this->dbConn->prepareInput($oldInbound) . "'");
        $newIn = $this->dbConn->Execute("SHOW TABLES LIKE '" . $this->dbConn->prepareInput($newInbound) . "'");
        if ($oldIn && !$oldIn->EOF && $newIn && $newIn->EOF) {
            $this->dbConn->Execute(
                'RENAME TABLE `' . str_replace('`', '``', $oldInbound) . '` TO `' . str_replace('`', '``', $newInbound) . '`'
            );
        }

        // Migrate admin page keys + labels/filenames. Renaming page_key alone
        // leaves BOX_TOOLS_OPENAI_* / FILENAME_OPENAI_* so Tools still shows
        // the old OpenAI menu entries (seen on KIP-dev after v1.1.0 install).
        if (defined('TABLE_ADMIN_PAGES')) {
            $map = [
                'openaiGptChatbotSettings' => [
                    'page_key' => 'seekmodoAiChatbotSettings',
                    'language_key' => 'BOX_TOOLS_SEEKMODO_AI_CHATBOT_SETTINGS',
                    'main_page' => 'FILENAME_SEEKMODO_AI_CHATBOT_SETTINGS',
                    'menu_key' => 'tools',
                ],
                'openaiGptChatbotChats' => [
                    'page_key' => 'seekmodoAiChatbotChats',
                    'language_key' => 'BOX_TOOLS_SEEKMODO_AI_CHATBOT_CHATS',
                    'main_page' => 'FILENAME_SEEKMODO_AI_CHATBOT_CHATS',
                    'menu_key' => 'tools',
                ],
                'openaiGptChatbotMail' => [
                    'page_key' => 'seekmodoAiChatbotMail',
                    'language_key' => 'BOX_CONFIGURATION_SEEKMODO_AI_CHATBOT_MAIL',
                    'main_page' => 'FILENAME_SEEKMODO_AI_CHATBOT_MAIL',
                    'menu_key' => 'configuration',
                ],
            ];
            foreach ($map as $old => $meta) {
                $new = $meta['page_key'];
                // Prefer rename when the new key is absent; otherwise update in place.
                $existsNew = $this->dbConn->Execute(
                    "SELECT page_key FROM " . TABLE_ADMIN_PAGES
                    . " WHERE page_key = '" . $this->dbConn->prepareInput($new) . "' LIMIT 1"
                );
                if ($existsNew && $existsNew->EOF) {
                    $this->dbConn->Execute(
                        "UPDATE " . TABLE_ADMIN_PAGES
                        . " SET page_key = '" . $this->dbConn->prepareInput($new) . "',"
                        . " language_key = '" . $this->dbConn->prepareInput($meta['language_key']) . "',"
                        . " main_page = '" . $this->dbConn->prepareInput($meta['main_page']) . "',"
                        . " menu_key = '" . $this->dbConn->prepareInput($meta['menu_key']) . "'"
                        . " WHERE page_key = '" . $this->dbConn->prepareInput($old) . "'"
                    );
                } else {
                    $this->dbConn->Execute(
                        "UPDATE " . TABLE_ADMIN_PAGES
                        . " SET language_key = '" . $this->dbConn->prepareInput($meta['language_key']) . "',"
                        . " main_page = '" . $this->dbConn->prepareInput($meta['main_page']) . "',"
                        . " menu_key = '" . $this->dbConn->prepareInput($meta['menu_key']) . "'"
                        . " WHERE page_key = '" . $this->dbConn->prepareInput($new) . "'"
                    );
                    $this->dbConn->Execute(
                        "DELETE FROM " . TABLE_ADMIN_PAGES
                        . " WHERE page_key = '" . $this->dbConn->prepareInput($old) . "'"
                    );
                }
            }
            // Also repair half-migrated rows (new page_key, old OpenAI constants).
            foreach ($map as $meta) {
                $this->dbConn->Execute(
                    "UPDATE " . TABLE_ADMIN_PAGES
                    . " SET language_key = '" . $this->dbConn->prepareInput($meta['language_key']) . "',"
                    . " main_page = '" . $this->dbConn->prepareInput($meta['main_page']) . "',"
                    . " menu_key = '" . $this->dbConn->prepareInput($meta['menu_key']) . "'"
                    . " WHERE page_key = '" . $this->dbConn->prepareInput($meta['page_key']) . "'"
                    . " AND (language_key LIKE 'BOX\\_TOOLS\\_OPENAI\\_%'"
                    . "  OR language_key LIKE 'BOX\\_CONFIGURATION\\_OPENAI\\_%'"
                    . "  OR main_page LIKE 'FILENAME\\_OPENAI\\_%')"
                );
            }
        }
    }
}

