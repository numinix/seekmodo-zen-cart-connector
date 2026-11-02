<?php
/**
 * Language defines for seekmodo_ai_chatbot_settings admin page.
 * Filename must match the admin page (LanguageLoader loads currentPage).
 */
if (!defined('BOX_TOOLS_SEEKMODO_AI_CHATBOT_SETTINGS')) {
    define('BOX_TOOLS_SEEKMODO_AI_CHATBOT_SETTINGS', 'Seekmodo AI Chatbot');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_HEADING')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_HEADING', 'Chatbot Settings');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_SUBTITLE')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_SUBTITLE', 'Configure the storefront assistant, MCP tools, prompts, and session limits.');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_SECTION_CORE')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_SECTION_CORE', 'Core');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_SECTION_MCP')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_SECTION_MCP', 'MCP');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_SECTION_PROMPTS')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_SECTION_PROMPTS', 'Prompts');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_SECTION_WIDGET')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_SECTION_WIDGET', 'Widget & Sessions');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_SECTION_TOOLS')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_SECTION_TOOLS', 'Tools');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_SECTION_INBOX')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_SECTION_INBOX', 'Chat Inbox');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_SECTION_POST_CHAT')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_SETTINGS_SECTION_POST_CHAT', 'Post-Chat Analysis');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_ENABLED')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_ENABLED', 'Enable Chatbot');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_API_KEY')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_API_KEY', 'OpenAI API Key');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_MODEL')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_MODEL', 'Model');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_MCP_URL')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_MCP_URL', 'MCP Endpoint URL');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_MCP_TOKEN')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_MCP_TOKEN', 'MCP Bearer Token');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_ALLOW_ORDER_MUTATIONS')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_ALLOW_ORDER_MUTATIONS', 'Allow In-Chat Order Mutations');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_ALLOW_ORDER_MUTATIONS_HELP')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_ALLOW_ORDER_MUTATIONS_HELP', 'Leave false so the bot shares My Account self-service edit links instead of changing orders in chat. Default is false on new installs and upgrades.');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_SYSTEM_PROMPT')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_SYSTEM_PROMPT', 'System Prompt');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_STORE_CONTEXT')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_STORE_CONTEXT', 'Store Context');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_STORE_CONTEXT_HELP')) {
    define(
        'TEXT_SEEKMODO_AI_CHATBOT_STORE_CONTEXT_HELP',
        'What this store sells. Injected into every chat prompt so the assistant stays inside your catalog. Auto-seeded from the store name and category tree — edit freely. Use Tools → Refresh store context from catalog to regenerate (overwrites this field).'
    );
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_REFRESH_STORE_CONTEXT')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_REFRESH_STORE_CONTEXT', 'Refresh store context from catalog');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_STORE_CONTEXT_REFRESHED')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_STORE_CONTEXT_REFRESHED', 'Store context regenerated from the catalog.');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_STORE_CONTEXT_REFRESH_FAIL')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_STORE_CONTEXT_REFRESH_FAIL', 'Could not regenerate store context (StoreContext library missing).');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_ADDITIONAL_RULES')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_ADDITIONAL_RULES', 'Additional Prompt Rules');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_GREETING')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_GREETING', 'Widget Greeting');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_PRIMARY_COLOR')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_PRIMARY_COLOR', 'Widget Primary Color');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_PRIMARY_COLOR_HELP')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_PRIMARY_COLOR_HELP', 'Storefront launcher / header accent. Keep it Personal uses #60A4C9.');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_SECONDARY_COLOR')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_SECONDARY_COLOR', 'Widget Secondary Color');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_SECONDARY_COLOR_HELP')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_SECONDARY_COLOR_HELP', 'Gradient / hover accent. Keep it Personal uses #00618D.');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON', 'Widget Agent Icon');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON_HELP')) {
    define(
        'TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON_HELP',
        'Shown next to bot and agent replies in the storefront chat. Upload a square PNG, WebP, JPEG, or GIF — 96x96 px or larger recommended, transparent or solid-brand background, max 512 KB.'
    );
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON_REMOVE')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON_REMOVE', 'Remove the current icon');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON_SAVED')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON_SAVED', 'Agent icon updated.');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON_REMOVED')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON_REMOVED', 'Agent icon removed.');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON_UPLOAD_FAIL')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON_UPLOAD_FAIL', 'Icon upload failed — check file permissions and try again.');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON_TOO_LARGE')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON_TOO_LARGE', 'Icon is larger than 512 KB — export a smaller image and retry.');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON_BAD_TYPE')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON_BAD_TYPE', 'Icon must be a PNG, WebP, JPEG, or GIF image.');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON_DIR_FAIL')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_WIDGET_ICON_DIR_FAIL', 'Could not create images/seekmodo_ai_chatbot — check catalog images/ permissions.');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_MAX_TOOL_ROUNDS')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_MAX_TOOL_ROUNDS', 'Max Tool Rounds');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_RATE_LIMIT_RPM')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_RATE_LIMIT_RPM', 'Rate Limit (req/min)');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_KB_PATH')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_KB_PATH', 'KB Cache Path');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_SUPPORT_EMAIL')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_SUPPORT_EMAIL', 'Support Ticket Email');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_SESSION_IDLE_MINUTES')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_SESSION_IDLE_MINUTES', 'Session Idle (minutes)');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_SESSION_MAX_AGE_HOURS')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_SESSION_MAX_AGE_HOURS', 'Session Max Age (hours)');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_MAX_HISTORY')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_MAX_HISTORY', 'Max History Messages');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_SAVE')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_SAVE', 'Save Settings');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_REBUILD_KB')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_REBUILD_KB', 'Rebuild Knowledge Base');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_KB_BUILT')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_KB_BUILT', 'Knowledge base rebuilt successfully.');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_SAVED')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_SAVED', 'Settings saved successfully.');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_DOCUMENTATION')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_DOCUMENTATION', 'Documentation');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_TEST_CONNECTION')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_TEST_CONNECTION', 'Test LLM Connection');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_CONNECTION_OK')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_CONNECTION_OK', 'LLM connection test passed.');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_CONNECTION_FAIL')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_CONNECTION_FAIL', 'LLM connection test failed: ');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_POST_CHAT_ANALYSIS_HELP')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_POST_CHAT_ANALYSIS_HELP', 'When enabled, an LLM pass reviews resolved/idle chats and proposes typed improvements (behavior rules, KB facts, topic gaps, tool bugs, escalation patterns). Nothing changes bot behavior until an admin approves a draft under Tools &rarr; Chatbot Improvements.');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_POST_CHAT_ANALYSIS_ENABLED')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_POST_CHAT_ANALYSIS_ENABLED', 'Enable Post-Chat Analysis');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_POST_CHAT_IDLE_MINUTES')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_POST_CHAT_IDLE_MINUTES', 'Idle Catch-up Threshold (minutes)');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_POST_CHAT_MIN_MESSAGES')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_POST_CHAT_MIN_MESSAGES', 'Minimum Messages to Analyze');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_POST_CHAT_MAX_PER_NIGHT')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_POST_CHAT_MAX_PER_NIGHT', 'Max Chats Enqueued Per Night');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_POST_CHAT_JOB_BATCH_SIZE')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_POST_CHAT_JOB_BATCH_SIZE', 'Analysis Jobs Per Cron Run');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_AUTO_ARCHIVE_HELP')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_AUTO_ARCHIVE_HELP', 'Soft-archives resolved chats after the configured number of days so the live inbox stays lean. Transcripts remain under Show archived and can be unarchived. Set days to 0 to disable. Requires the daily cron below (same pattern as inbound mail).');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_AUTO_ARCHIVE_RESOLVED_DAYS')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_AUTO_ARCHIVE_RESOLVED_DAYS', 'Auto-Archive Resolved After (days)');
}
if (!defined('TEXT_SEEKMODO_AI_CHATBOT_AUTO_ARCHIVE_CRON_HELP')) {
    define('TEXT_SEEKMODO_AI_CHATBOT_AUTO_ARCHIVE_CRON_HELP', 'Daily cron: php zc_plugins/SeekmodoAiChatbot/v1.1.0/tools/auto_archive_chats.php --catalog=/path/to/catalog --host=www.example.com');
}
