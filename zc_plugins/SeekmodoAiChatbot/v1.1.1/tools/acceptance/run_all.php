<?php
/**
 * Acceptance test orchestrator.
 *
 * Usage:
 *   php tools/acceptance/run_all.php --base https://example.com
 *                                      [--mcp-token TOKEN] [--operator-token TOKEN]
 *
 * Exits 0 on all PASS, 1 on any FAIL.
 */

declare(strict_types=1);

// Parse arguments
$options = getopt('', ['base:', 'mcp-token:', 'operator-token:']);
$baseUrl = $options['base'] ?? '';
$chatbotToken = $options['mcp-token'] ?? '';
$operatorToken = $options['operator-token'] ?? '';

if ($baseUrl === '') {
    fwrite(STDERR, "ERROR: --base URL is required (e.g. https://example.com)\n");
    exit(1);
}

if ($chatbotToken === '') {
    fwrite(STDERR, "WARNING: --mcp-token not provided; MCP cases will be skipped\n");
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/mcp_cases.php';
require_once __DIR__ . '/chatbot_cases.php';

$startTime = microtime(true);
$allResults = [];

// ---- A: MCP Direct Cases ----
if ($chatbotToken !== '') {
    $mcpResults = run_mcp_cases($baseUrl, $chatbotToken, $operatorToken);
    $allResults = array_merge($allResults, $mcpResults);
} else {
    echo "\n--- Skipping MCP Direct Cases (A) --- no --mcp-token\n";
}

// ---- B: Chatbot End-to-End Cases ----
$chatbotResults = run_chatbot_cases($baseUrl);
$allResults = array_merge($allResults, $chatbotResults);

// ---- Summary ----
$total = count($allResults);
$passed = 0;
$failed = 0;
$na = 0;
foreach ($allResults as $r) {
    if ($r['passes']) {
        $passed++;
        if (str_starts_with($r['detail'] ?? '', 'N/A')) {
            $na++;
        }
    } else {
        $failed++;
    }
}

$elapsed = round(microtime(true) - $startTime, 2);

echo "\n========== RESULTS ==========\n";
printf("  Total:  %d\n", $total);
printf("  Passed: %d", $passed);
if ($na > 0) {
    printf(" (%d N/A)", $na);
}
echo "\n";
printf("  Failed: %d\n", $failed);
printf("  Time:   %.2fs\n", $elapsed);

if ($failed > 0) {
    echo "\nFAILED cases:\n";
    foreach ($allResults as $r) {
        if (!$r['passes']) {
            printf("  %s: %s\n", $r['id'], $r['detail']);
        }
    }
}

echo "\n";
exit($failed > 0 ? 1 : 0);
