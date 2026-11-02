<?php
/**
 * Registers Seekmodo ticket inbox MCP tools when Numinix MCP is present.
 */
if (PHP_VERSION_ID < 80100) {
    return;
}

if (!class_exists('Numinix\\Mcp\\AbstractExtensionObserver', false)) {
    // Prefer MCP autoload (init_numinix_mcp). Fall back to newest installed plugin copy.
    if (!class_exists('Numinix\\Mcp\\AbstractExtensionObserver') && defined('DIR_FS_CATALOG')) {
        $base = rtrim((string) DIR_FS_CATALOG, '/\\') . '/zc_plugins/NuminixMcp';
        $candidates = glob($base . '/v*/catalog/includes/library/Numinix/Mcp/AbstractExtensionObserver.php') ?: [];
        rsort($candidates, SORT_NATURAL);
        foreach ($candidates as $parent) {
            if (is_file($parent)) {
                require_once $parent;
                break;
            }
        }
    }
}

if (!class_exists('Numinix\\Mcp\\AbstractExtensionObserver', false)) {
    return;
}

$ticketTools = dirname(__DIR__, 2) . '/library/SeekmodoAiChatbot/Mcp/TicketTools.php';
if (is_file($ticketTools) && !class_exists('SeekmodoAiChatbot\\Mcp\\SeekmodoTicketListTool', false)) {
    require_once $ticketTools;
}

use SeekmodoAiChatbot\Mcp\SeekmodoTicketArchiveTool;
use SeekmodoAiChatbot\Mcp\SeekmodoTicketClaimTool;
use SeekmodoAiChatbot\Mcp\SeekmodoTicketGetTool;
use SeekmodoAiChatbot\Mcp\SeekmodoTicketListTool;
use SeekmodoAiChatbot\Mcp\SeekmodoTicketNoteTool;
use SeekmodoAiChatbot\Mcp\SeekmodoTicketPollTool;
use SeekmodoAiChatbot\Mcp\SeekmodoTicketPresenceTool;
use SeekmodoAiChatbot\Mcp\SeekmodoTicketReopenTool;
use SeekmodoAiChatbot\Mcp\SeekmodoTicketReplyTool;
use SeekmodoAiChatbot\Mcp\SeekmodoTicketResolveTool;
use SeekmodoAiChatbot\Mcp\SeekmodoTicketResumeAiTool;
use SeekmodoAiChatbot\Mcp\SeekmodoTicketUnarchiveTool;

if (!class_exists('SeekmodoTicketMcpObserver', false)) {
    class SeekmodoTicketMcpObserver extends Numinix\Mcp\AbstractExtensionObserver
    {
        /** @return list<Numinix\Mcp\Tool> */
        protected function tools(): array
        {
            if (!interface_exists('Numinix\\Mcp\\Tool', false) && !interface_exists('Numinix\\Mcp\\Tool')) {
                return [];
            }
            return [
                new SeekmodoTicketListTool(),
                new SeekmodoTicketGetTool(),
                new SeekmodoTicketPollTool(),
                new SeekmodoTicketReplyTool(),
                new SeekmodoTicketClaimTool(),
                new SeekmodoTicketResumeAiTool(),
                new SeekmodoTicketResolveTool(),
                new SeekmodoTicketReopenTool(),
                new SeekmodoTicketNoteTool(),
                new SeekmodoTicketArchiveTool(),
                new SeekmodoTicketUnarchiveTool(),
                new SeekmodoTicketPresenceTool(),
            ];
        }
    }
}
