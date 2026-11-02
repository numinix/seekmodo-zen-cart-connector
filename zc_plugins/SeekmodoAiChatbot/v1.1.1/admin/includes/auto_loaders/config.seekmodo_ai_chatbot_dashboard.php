<?php
/**
 * Register Support Tickets dashboard widget via NOTIFY_ADMIN_DASHBOARD_WIDGETS.
 * Admin copy + plugin path both ship this file; only register once per request.
 */
if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

if (defined('SEEKMODO_AI_CHATBOT_DASHBOARD_AUTOLOAD')) {
    return;
}
define('SEEKMODO_AI_CHATBOT_DASHBOARD_AUTOLOAD', true);

if (class_exists('zcObserverSeekmodoAiChatbotDashboardWidget', false)) {
    return;
}

$autoLoadConfig[201][] = [
    'autoType' => 'class',
    'loadFile' => 'observers/zcObserverSeekmodoAiChatbotDashboardWidget.php',
    'classPath' => DIR_WS_CLASSES,
];

$autoLoadConfig[202][] = [
    'autoType' => 'classInstantiate',
    'className' => 'zcObserverSeekmodoAiChatbotDashboardWidget',
    'objectName' => 'zcObserverSeekmodoAiChatbotDashboardWidget',
];
