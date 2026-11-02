<?php
/**
 * Acceptance test helpers — thin cURL + JSON-RPC wrappers.
 */

declare(strict_types=1);

/**
 * Send a JSON-RPC request to the MCP endpoint.
 *
 * @return array<string, mixed>
 */
function mcp_call(string $endpoint, string $method, array $params, ?string $bearerToken = null, int $timeout = 15): array
{
    $payload = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => $method,
            'arguments' => $params,
        ],
    ];
    return mcp_send($endpoint, $payload, $bearerToken, $timeout);
}

/**
 * Send a raw JSON-RPC envelope.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function mcp_send(string $endpoint, array $payload, ?string $bearerToken = null, int $timeout = 15): array
{
    $ch = curl_init($endpoint);
    $headers = ['Content-Type: application/json'];
    if ($bearerToken !== null) {
        $headers[] = 'Authorization: Bearer ' . $bearerToken;
        $headers[] = 'X-MCP-Authorization: Bearer ' . $bearerToken;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $raw === '') {
        return ['_http_code' => $httpCode, '_curl_error' => $error, '_error' => 'request_failed'];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['_http_code' => $httpCode, '_error' => 'invalid_json', '_raw' => substr($raw, 0, 500)];
    }
    $decoded['_http_code'] = $httpCode;
    return $decoded;
}

/**
 * Call the chatbot API endpoint.
 *
 * @return array<string, mixed>
 */
function chatbot_call(string $baseUrl, string $message, string $sessionId = 'test-session'): array
{
    $url = rtrim($baseUrl, '/') . '/seekmodo_ai_chat.php';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'message' => $message,
            'session_id' => $sessionId,
        ]),
        CURLOPT_TIMEOUT => 60,
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $raw === '') {
        return ['_http_code' => $httpCode, '_error' => 'request_failed'];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['_http_code' => $httpCode, '_error' => 'invalid_json', '_raw' => substr($raw, 0, 500)];
    }
    $decoded['_http_code'] = $httpCode;
    return $decoded;
}

/**
 * PASS / FAIL printer.
 */
function pass(string $id, string $detail = ''): void
{
    printf("  %-6s %-40s %s\n", 'PASS', $id, $detail);
}

function fail(string $id, string $reason): void
{
    printf("  %-6s %-40s %s\n", 'FAIL', $id, $reason);
}
