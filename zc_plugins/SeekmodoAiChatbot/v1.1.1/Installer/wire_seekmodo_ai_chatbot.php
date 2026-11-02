<?php
/**
 * Wire Seekmodo AI Chatbot → Numinix MCP (URL + chatbot-scoped bearer token).
 *
 * Safe to call from either plugin's installer: no-ops until both sides exist.
 * Only fills empty SEEKMODO_AI_CHATBOT_MCP_* values (does not overwrite admin edits).
 *
 * Also provides a legacy alias numinix_mcp_wire_openai_gpt_chatbot() that forwards here.
 *
 * @return array{skipped:bool,url:bool,token:bool,reason?:string}
 */
if (!function_exists('numinix_mcp_wire_seekmodo_ai_chatbot')) {
function numinix_mcp_wire_seekmodo_ai_chatbot($db)
{
    $out = ['skipped' => true, 'url' => false, 'token' => false];

    if (!defined('TABLE_CONFIGURATION')) {
        $out['reason'] = 'TABLE_CONFIGURATION undefined';
        return $out;
    }

    $urlKey = 'SEEKMODO_AI_CHATBOT_MCP_URL';
    $tokenKey = 'SEEKMODO_AI_CHATBOT_MCP_TOKEN';

    $urlRow = $db->Execute(
        "SELECT configuration_value FROM " . TABLE_CONFIGURATION
        . " WHERE configuration_key = '" . $db->prepareInput($urlKey) . "' LIMIT 1"
    );
    if ($urlRow->EOF) {
        // Fall back to legacy keys during migration window.
        $urlKey = 'OPENAI_GPT_CHATBOT_MCP_URL';
        $tokenKey = 'OPENAI_GPT_CHATBOT_MCP_TOKEN';
        $urlRow = $db->Execute(
            "SELECT configuration_value FROM " . TABLE_CONFIGURATION
            . " WHERE configuration_key = '" . $db->prepareInput($urlKey) . "' LIMIT 1"
        );
        if ($urlRow->EOF) {
            $out['reason'] = 'Seekmodo AI Chatbot not installed';
            return $out;
        }
    }

    $prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
    $tokensTable = $prefix . 'numinix_mcp_tokens';
    $tableCheck = $db->Execute("SHOW TABLES LIKE '" . $db->prepareInput($tokensTable) . "'");
    if ($tableCheck->EOF) {
        $out['reason'] = 'Numinix MCP tokens table missing';
        return $out;
    }

    $out['skipped'] = false;

    $col = $db->Execute(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '" . $db->prepareInput($tokensTable) . "'
            AND COLUMN_NAME = 'scope'
          LIMIT 1"
    );
    $type = (!$col->EOF && isset($col->fields['COLUMN_TYPE']))
        ? (string) $col->fields['COLUMN_TYPE']
        : '';
    if ($type !== '' && stripos($type, "'chatbot'") === false) {
        $db->Execute(
            "ALTER TABLE `{$tokensTable}`
                MODIFY scope ENUM('chatbot','readonly','operator','admin')
                NOT NULL DEFAULT 'readonly'"
        );
    }
    if ($type !== '') {
        $db->Execute(
            "UPDATE `{$tokensTable}`
                SET scope = 'chatbot'
              WHERE (scope = '' OR scope IS NULL)
                AND (label LIKE 'Seekmodo AI Chatbot%' OR label LIKE 'OpenAI GPT Chatbot%')"
        );
    }

    $endpoint = 'numinix_mcp.php';
    $epRow = $db->Execute(
        "SELECT configuration_value FROM " . TABLE_CONFIGURATION
        . " WHERE configuration_key = 'NUMINIX_MCP_ENDPOINT_PATH' LIMIT 1"
    );
    if (!$epRow->EOF && trim((string) $epRow->fields['configuration_value']) !== '') {
        $endpoint = trim((string) $epRow->fields['configuration_value']);
    }

    $base = '';
    if (defined('HTTPS_SERVER') && HTTPS_SERVER !== '') {
        $base = (string) HTTPS_SERVER;
    } elseif (defined('HTTP_SERVER') && HTTP_SERVER !== '') {
        $base = (string) HTTP_SERVER;
    }
    $catalog = '/';
    if (defined('DIR_WS_HTTPS_CATALOG') && DIR_WS_HTTPS_CATALOG !== '') {
        $catalog = (string) DIR_WS_HTTPS_CATALOG;
    } elseif (defined('DIR_WS_CATALOG') && DIR_WS_CATALOG !== '') {
        $catalog = (string) DIR_WS_CATALOG;
    }
    $catalogPart = trim($catalog, '/');
    $mcpUrl = rtrim($base, '/')
        . ($catalogPart !== '' ? '/' . $catalogPart : '')
        . '/' . ltrim($endpoint, '/');

    if (trim((string) $urlRow->fields['configuration_value']) === '' && $base !== '') {
        $db->Execute(
            "UPDATE " . TABLE_CONFIGURATION
            . " SET configuration_value = '" . $db->prepareInput($mcpUrl) . "'"
            . " WHERE configuration_key = '" . $db->prepareInput($urlKey) . "'"
        );
        $out['url'] = true;
    }

    $tokenRow = $db->Execute(
        "SELECT configuration_value FROM " . TABLE_CONFIGURATION
        . " WHERE configuration_key = '" . $db->prepareInput($tokenKey) . "' LIMIT 1"
    );
    if ($tokenRow->EOF || trim((string) $tokenRow->fields['configuration_value']) !== '') {
        return $out;
    }

    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $body = '';
    for ($i = 0; $i < 26; $i++) {
        $body .= $alphabet[random_int(0, 31)];
    }
    $raw = 'numinix_mcp_' . $body;
    $hash = hash('sha256', $raw);
    $tokenPrefix = substr($raw, 0, 19);

    try {
        $db->Execute(
            "INSERT INTO `{$tokensTable}`
                (token_hash, token_prefix, scope, label, created_by, created_at)
             VALUES (
                '" . $db->prepareInput($hash) . "',
                '" . $db->prepareInput($tokenPrefix) . "',
                'chatbot',
                'Seekmodo AI Chatbot (auto)',
                'installer',
                NOW()
             )"
        );
        $db->Execute(
            "UPDATE " . TABLE_CONFIGURATION
            . " SET configuration_value = '" . $db->prepareInput($raw) . "'"
            . " WHERE configuration_key = '" . $db->prepareInput($tokenKey) . "'"
        );
        $out['token'] = true;
    } catch (Throwable $e) {
        $out['reason'] = 'mint failed: ' . $e->getMessage();
    }

    return $out;
}
} // function_exists numinix_mcp_wire_seekmodo_ai_chatbot

// Legacy alias for older Numinix MCP installers / chatbot versions.
if (!function_exists('numinix_mcp_wire_openai_gpt_chatbot')) {
    function numinix_mcp_wire_openai_gpt_chatbot($db)
    {
        return numinix_mcp_wire_seekmodo_ai_chatbot($db);
    }
}
