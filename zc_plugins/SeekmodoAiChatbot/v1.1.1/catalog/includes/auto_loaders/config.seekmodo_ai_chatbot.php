<?php
/**
 * Seekmodo AI Chatbot auto-loader configuration.
 */
$autoLoadConfig[90][] = [
    'autoType' => 'init_script',
    'loadFile' => 'init_seekmodo_ai_chatbot.php',
];

// After Numinix MCP (80): register ticket tools (KipMcpExtensions-style class boot + fail-soft init).
if (PHP_VERSION_ID >= 80100) {
    $autoLoadConfig[205][] = [
        'autoType' => 'init_script',
        'loadFile' => 'init_seekmodo_ticket_mcp.php',
    ];
    $autoLoadConfig[205][] = [
        'autoType' => 'class',
        'loadFile' => 'observers/SeekmodoTicketMcpObserver.php',
        'classPath' => DIR_WS_CLASSES,
        'classInstantiate' => [
            'className' => 'SeekmodoTicketMcpObserver',
            'objectName' => 'SeekmodoTicketMcpObserver',
        ],
    ];
}
