<?php
/**
 * Seekmodo ticket MCP tools — late catalog boot (after Numinix MCP + base).
 * Fail-soft: never break the storefront if MCP is absent or misconfigured.
 */
if (PHP_VERSION_ID < 80100) {
    return;
}

if (!class_exists('base', false)) {
    return;
}

// Trigger Numinix MCP autoload if registered; skip when MCP is not installed.
if (!class_exists('Numinix\\Mcp\\AbstractExtensionObserver')) {
    return;
}

$observerFile = dirname(__DIR__) . '/classes/observers/SeekmodoTicketMcpObserver.php';
if (!is_file($observerFile)) {
    return;
}

try {
    if (!class_exists('SeekmodoTicketMcpObserver', false)) {
        require_once $observerFile;
    }
    if (class_exists('SeekmodoTicketMcpObserver', false)) {
        new SeekmodoTicketMcpObserver();
    }
} catch (\Throwable $e) {
    // Do not take down catalog/MCP for ticket-tool registration failures.
}
