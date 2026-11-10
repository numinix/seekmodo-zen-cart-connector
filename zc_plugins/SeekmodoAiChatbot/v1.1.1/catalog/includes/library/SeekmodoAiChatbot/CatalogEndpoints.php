<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot;

class CatalogEndpoints
{
    public const FILES = ['seekmodo_ai_chat.php', 'seekmodo_ai_chatbot_inbox_api.php', 'seekmodo_ai_chatbot_portal_token.php'];

    public static function ensure(string $catalogRoot, string $pluginCatalog): array
    {
        $failed = [];
        foreach (self::FILES as $file) {
            $target = rtrim($catalogRoot, '/\\') . '/' . $file;
            if (is_file($target)) {
                continue;
            }
            $source = rtrim($pluginCatalog, '/\\') . '/' . $file;
            $temp = $target . '.' . bin2hex(random_bytes(8)) . '.php';
            if (!is_file($source) || !@copy($source, $temp) || !@rename($temp, $target)) {
                if (is_file($temp)) {
                    @unlink($temp);
                }
                $failed[] = $file;
            }
        }
        return $failed;
    }
}
