<?php
/**
 * Seekmodo AI Chatbot init script.
 *
 * Registers PSR-4 autoloader for the SeekmodoAiChatbot namespace
 * and loads widget assets when the chatbot is enabled.
 */
if (!defined('IS_ADMIN_FLAG') || !IS_ADMIN_FLAG) {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'SeekmodoAiChatbot\\';
        // From catalog/includes/init_includes → catalog/includes/library/...
        $base = dirname(__DIR__) . '/library/SeekmodoAiChatbot/';
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        $relative = substr($class, $len);
        $file = $base . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });

    if (defined('SEEKMODO_AI_CHATBOT_ENABLED') && SEEKMODO_AI_CHATBOT_ENABLED === 'true') {
        if (defined('DIR_FS_CATALOG')) {
            $missingEndpoints = \SeekmodoAiChatbot\CatalogEndpoints::ensure((string) DIR_FS_CATALOG, dirname(__DIR__, 2));
            if ($missingEndpoints !== []) {
                error_log('Seekmodo chat endpoint repair failed: ' . implode(', ', $missingEndpoints));
            }
        }
        $observerFile = dirname(__DIR__) . '/classes/observers/ChatbotWidgetObserver.php';
        if (is_file($observerFile)) {
            require_once $observerFile;
        }
        // Observer constructor registers itself with the notifier.
        if (class_exists('\\SeekmodoAiChatbot\\Observers\\ChatbotWidgetObserver', false)) {
            new \SeekmodoAiChatbot\Observers\ChatbotWidgetObserver();
        }
    }
}
