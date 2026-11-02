<?php
/**
 * Seekmodo AI Chatbot — jscript fallback for templates that include plugin
 * jscripts via the resource loader. Primary injection is via
 * ChatbotWidgetObserver on NOTIFY_HTML_HEAD_END.
 *
 * Guarded so it will not double-render if the observer already ran.
 */
if (!defined('SEEKMODO_AI_CHATBOT_ENABLED') || SEEKMODO_AI_CHATBOT_ENABLED !== 'true') {
    return;
}

// From .../includes/templates/genesis/jscript → .../includes/classes/observers
$observerFile = dirname(__DIR__, 3) . '/classes/observers/ChatbotWidgetObserver.php';
if (!is_file($observerFile)) {
    return;
}

require_once $observerFile;
if (!class_exists('\\SeekmodoAiChatbot\\Observers\\ChatbotWidgetObserver', false)) {
    return;
}

$observer = new \SeekmodoAiChatbot\Observers\ChatbotWidgetObserver();
$dummy = null;
$observer->update($dummy, 'NOTIFY_FOOTER_SCRIPTS');
