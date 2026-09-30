<?php
declare(strict_types=1);

namespace Numinix\Seekmodo;

/**
 * Detects Seekmodo AI Chatbot on the storefront and builds the
 * inbox endpoint payload published via tenant.snapshot push so
 * admin.seekmodo.com can HMAC-proxy the cloud Inbox console.
 */
final class InboxProbe
{
    private const CAPABILITIES = [
        'list', 'get', 'poll', 'reply', 'claim', 'note',
        'resume_ai', 'resolve', 'reopen', 'archive', 'unarchive', 'presence',
        'assign', 'assignees', 'list_assignees',
    ];

    /**
     * @return array<string, mixed>|null Inbox payload, or null when
     *   the chatbot plugin is not installed / endpoint not found.
     */
    public static function current(): ?array
    {
        $plugin = self::detectChatbotPlugin();
        if ($plugin === null) {
            return null;
        }
        $apiUrl = self::absoluteCatalogUrl('seekmodo_ai_chatbot_inbox_api.php');
        if ($apiUrl === '') {
            // Endpoint may live only under the plugin tree until
            // catalog files are copied; still publish a discoverable
            // URL rooted at the plugin catalog copy when present.
            $rel = 'zc_plugins/SeekmodoAiChatbot/' . $plugin['version']
                . '/catalog/seekmodo_ai_chatbot_inbox_api.php';
            $apiUrl = self::absoluteCatalogUrl($rel);
        }
        if ($apiUrl === '') {
            return null;
        }
        $mcpUrl = self::detectNuminixMcpUrl();
        return [
            'api_url' => $apiUrl,
            'mcp_url' => $mcpUrl !== '' ? $mcpUrl : null,
            'plugin_version' => $plugin['version'],
            'capabilities' => self::CAPABILITIES,
        ];
    }

    /**
     * @return array{version: string, path: string}|null
     */
    private static function detectChatbotPlugin(): ?array
    {
        $bases = [];
        if (defined('DIR_FS_CATALOG') && is_string(DIR_FS_CATALOG) && DIR_FS_CATALOG !== '') {
            $bases[] = rtrim(str_replace('\\', '/', DIR_FS_CATALOG), '/');
        }
        // Fall back relative to this library file:
        // …/zc_plugins/Seekmodo/vX/catalog/includes/library/Numinix/Seekmodo
        $here = str_replace('\\', '/', __DIR__);
        $guess = dirname($here, 7); // catalog root
        if ($guess !== '' && is_dir($guess)) {
            $bases[] = $guess;
        }
        $bases = array_values(array_unique($bases));
        foreach ($bases as $base) {
            $glob = glob($base . '/zc_plugins/SeekmodoAiChatbot/v*/catalog/includes/library/SeekmodoAiChatbot/TicketInboxService.php') ?: [];
            rsort($glob, SORT_NATURAL);
            foreach ($glob as $svc) {
                if (!is_file($svc)) {
                    continue;
                }
                if (preg_match('#/zc_plugins/SeekmodoAiChatbot/(v[^/]+)/#', $svc, $m) !== 1) {
                    continue;
                }
                return [
                    'version' => $m[1],
                    'path' => $svc,
                ];
            }
        }
        return null;
    }

    private static function absoluteCatalogUrl(string $relativePath): string
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $base = self::catalogBaseUrl();
        if ($base === '') {
            return '';
        }
        // Prefer flat catalog copy when present.
        if (defined('DIR_FS_CATALOG') && is_string(DIR_FS_CATALOG)) {
            $fs = rtrim(str_replace('\\', '/', DIR_FS_CATALOG), '/') . '/' . $relativePath;
            if (!is_file($fs) && !str_contains($relativePath, 'zc_plugins/')) {
                return '';
            }
        }
        return rtrim($base, '/') . '/' . $relativePath;
    }

    private static function catalogBaseUrl(): string
    {
        if (defined('HTTPS_SERVER') && defined('DIR_WS_HTTPS_CATALOG')
            && defined('ENABLE_SSL_CATALOG') && (string) ENABLE_SSL_CATALOG === 'true'
        ) {
            $https = trim((string) HTTPS_SERVER);
            if ($https !== '') {
                return rtrim($https, '/') . (string) DIR_WS_HTTPS_CATALOG;
            }
        }
        if (defined('HTTPS_SERVER') && defined('DIR_WS_CATALOG')) {
            $https = trim((string) HTTPS_SERVER);
            if ($https !== '') {
                return rtrim($https, '/') . (string) DIR_WS_CATALOG;
            }
        }
        if (defined('HTTP_SERVER') && defined('DIR_WS_CATALOG')) {
            $http = trim((string) HTTP_SERVER);
            if ($http !== '') {
                return rtrim($http, '/') . (string) DIR_WS_CATALOG;
            }
        }
        return '';
    }

    private static function detectNuminixMcpUrl(): string
    {
        if (defined('NUMINIX_MCP_URL') && is_string(NUMINIX_MCP_URL) && trim(NUMINIX_MCP_URL) !== '') {
            return trim((string) NUMINIX_MCP_URL);
        }
        // Common configuration key used by Numinix MCP plugin.
        if (function_exists('zen_get_configuration_key_value')) {
            $v = (string) zen_get_configuration_key_value('NUMINIX_MCP_ENDPOINT');
            if (trim($v) !== '') {
                return trim($v);
            }
        }
        return '';
    }
}
