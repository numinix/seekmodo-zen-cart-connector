<?php
/**
 * Load Seekmodo AI Chatbot admin helpers from the copied admin tree or zc_plugins.
 *
 * Deploy often leaves the top-level admin pages in place but wipes nested
 * admin/includes copies. Resolve the library helper from either location.
 */
if (function_exists('seekmodo_ai_chatbot_require_library')) {
    return;
}

$candidates = [
    __DIR__ . '/includes/seekmodo_ai_chatbot_library.php',
];

if (defined('DIR_FS_ADMIN')) {
    $candidates[] = rtrim((string) DIR_FS_ADMIN, '/\\') . '/includes/seekmodo_ai_chatbot_library.php';
}

if (defined('DIR_FS_CATALOG')) {
    $catalog = rtrim((string) DIR_FS_CATALOG, '/\\');
    $dirs = glob($catalog . '/zc_plugins/SeekmodoAiChatbot/v*/admin/includes/seekmodo_ai_chatbot_library.php') ?: [];
    usort($dirs, static function ($a, $b) {
        $va = '0';
        $vb = '0';
        if (preg_match('#/SeekmodoAiChatbot/v([^/]+)/#', str_replace('\\', '/', (string) $a), $m)) {
            $va = $m[1];
        }
        if (preg_match('#/SeekmodoAiChatbot/v([^/]+)/#', str_replace('\\', '/', (string) $b), $m)) {
            $vb = $m[1];
        }
        return version_compare($vb, $va);
    });
    foreach ($dirs as $path) {
        $candidates[] = $path;
    }
}

foreach ($candidates as $path) {
    if (is_file($path)) {
        require_once $path;
        return;
    }
}

throw new RuntimeException('seekmodo_ai_chatbot_library.php not found under admin or zc_plugins.');
