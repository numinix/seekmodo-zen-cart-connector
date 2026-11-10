<?php
declare(strict_types=1);

class base
{
    public function attach($observer, array $events): void
    {
    }
}

define('SEEKMODO_AI_CHATBOT_ENABLED', 'true');
$greeting = 'Hi! Your Store ' . hex2bin('e28094') . ' ' . hex2bin('c3a9e697a5');
define('SEEKMODO_AI_CHATBOT_WIDGET_GREETING', $greeting);
require __DIR__ . '/../zc_plugins/SeekmodoAiChatbot/v1.1.1/catalog/includes/classes/observers/ChatbotWidgetObserver.php';
$observer = new \SeekmodoAiChatbot\Observers\ChatbotWidgetObserver();
$target = null;
ob_start();
$observer->update($target, 'NOTIFY_HTML_HEAD_END');
$html = ob_get_clean();
if (preg_match('/const GREETING\s*=\s*([^;]+);/', $html, $match) !== 1 || json_decode($match[1], true) !== $greeting) {
    throw new RuntimeException('Greeting must survive UTF-8 rendering without Windows-1252 reinterpretation');
}
echo "PASS: em dash, accented text and non-Latin greeting round-trip through widget JSON\n";
