<?php
/**
 * Resolve and require seekmodo_ai_chatbot_load.php from admin or zc_plugins.
 * Intended to be require_once'd from admin pages after application_top.php.
 */
$__ogcLoadCandidates = [
    __DIR__ . '/seekmodo_ai_chatbot_load.php',
];
if (defined('DIR_FS_CATALOG')) {
    $__ogcLoads = glob(rtrim((string) DIR_FS_CATALOG, '/\\') . '/zc_plugins/SeekmodoAiChatbot/v*/admin/seekmodo_ai_chatbot_load.php') ?: [];
    usort($__ogcLoads, static function ($a, $b) {
        preg_match('#/SeekmodoAiChatbot/v([^/]+)/#', str_replace('\\', '/', $a), $ma);
        preg_match('#/SeekmodoAiChatbot/v([^/]+)/#', str_replace('\\', '/', $b), $mb);
        return version_compare($mb[1] ?? '0', $ma[1] ?? '0');
    });
    foreach ($__ogcLoads as $__ogcLoadPath) {
        $__ogcLoadCandidates[] = $__ogcLoadPath;
    }
}
$__ogcLoaded = false;
foreach ($__ogcLoadCandidates as $__ogcLoadPath) {
    if (is_file($__ogcLoadPath)) {
        require_once $__ogcLoadPath;
        $__ogcLoaded = true;
        break;
    }
}
unset($__ogcLoadCandidates, $__ogcLoads, $__ogcLoadPath);
if (!$__ogcLoaded) {
    throw new RuntimeException('seekmodo_ai_chatbot_load.php not found under admin or zc_plugins.');
}
unset($__ogcLoaded);
