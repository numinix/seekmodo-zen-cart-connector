<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot;

/**
 * JSON-RPC client for Numinix MCP endpoint.
 */
final class McpClient
{
    private string $endpointUrl;
    private string $bearerToken;
    private int $timeout;

    /**
     * Default 120s covers Customily re-export + stable CDN PDF waits on
     * kip.order.engraving.update (15s was timing out mid-update).
     */
    public function __construct(string $endpointUrl, string $bearerToken, int $timeout = 120)
    {
        $this->endpointUrl = $endpointUrl;
        $this->bearerToken = $bearerToken;
        $this->timeout = max(1, $timeout);
    }

    /**
     * Call an MCP tool via tools/call.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function callTool(string $toolName, array $arguments = []): array
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $toolName,
                'arguments' => $arguments,
            ],
        ];

        $response = $this->send($payload);
        return $this->parseResult($response, $toolName);
    }

    /**
     * List available tools.
     *
     * @return list<array<string, mixed>>
     */
    public function listTools(): array
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [],
        ];
        $response = $this->send($payload);
        return $response['result']['tools'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function send(array $payload): array
    {
        $target = $this->resolveTransport();
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->bearerToken,
            'X-MCP-Authorization: Bearer ' . $this->bearerToken,
        ];
        if ($target['host_header'] !== null) {
            $headers[] = 'Host: ' . $target['host_header'];
        }

        $ch = curl_init($target['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $target['ssl_verify'],
            CURLOPT_SSL_VERIFYHOST => $target['ssl_verify'] ? 2 : 0,
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $raw === '') {
            throw new ChatbotException('mcp_request_failed', [
                'http_code' => $httpCode,
                'curl_error' => $error,
                'url' => $target['url'],
            ]);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new ChatbotException('mcp_invalid_response', [
                'http_code' => $httpCode,
                'raw' => substr($raw, 0, 500),
            ]);
        }
        return $decoded;
    }

    /**
     * Same-host HTTPS often fails OpenSSL verify on dig/staging when the
     * certificate is expired or mismatched. Keep the public URL (correct
     * vhost) but disable TLS verification for local store hosts only.
     *
     * @return array{url: string, host_header: ?string, ssl_verify: bool}
     */
    private function resolveTransport(): array
    {
        $parts = parse_url($this->endpointUrl);
        if (!is_array($parts) || empty($parts['host'])) {
            return [
                'url' => $this->endpointUrl,
                'host_header' => null,
                'ssl_verify' => true,
            ];
        }

        $host = strtolower((string) $parts['host']);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $local = $this->isLocalStoreHost($host);

        return [
            'url' => $this->endpointUrl,
            'host_header' => null,
            'ssl_verify' => !($local && $scheme === 'https'),
        ];
    }

    private function isLocalStoreHost(string $host): bool
    {
        $candidates = [];
        foreach (['HTTP_HOST', 'SERVER_NAME'] as $key) {
            if (!empty($_SERVER[$key])) {
                $candidates[] = strtolower(preg_replace('/:\d+$/', '', (string) $_SERVER[$key]) ?? '');
            }
        }
        foreach ([defined('HTTPS_SERVER') ? HTTPS_SERVER : '', defined('HTTP_SERVER') ? HTTP_SERVER : ''] as $server) {
            if ($server === '') {
                continue;
            }
            $parsed = parse_url((string) $server);
            if (!empty($parsed['host'])) {
                $candidates[] = strtolower((string) $parsed['host']);
            }
        }

        $hostNoWww = preg_replace('/^www\./', '', $host) ?? $host;
        foreach (array_unique(array_filter($candidates)) as $candidate) {
            $candidateNoWww = preg_replace('/^www\./', '', $candidate) ?? $candidate;
            if ($host === $candidate || $hostNoWww === $candidateNoWww) {
                return true;
            }
        }

        return in_array($host, ['127.0.0.1', 'localhost'], true);
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function parseResult(array $response, string $toolName): array
    {
        if (isset($response['error'])) {
            throw new ChatbotException('mcp_tool_error', [
                'tool' => $toolName,
                'code' => $response['error']['code'] ?? 0,
                'message' => $response['error']['message'] ?? 'unknown',
                'data' => $response['error']['data'] ?? null,
            ]);
        }
        if (!empty($response['result']['isError'])) {
            $errText = '';
            if (isset($response['result']['content'][0]['text'])) {
                $errText = (string) $response['result']['content'][0]['text'];
            }
            throw new ChatbotException('mcp_tool_error', [
                'tool' => $toolName,
                'message' => $errText !== '' ? $errText : 'tool_isError',
            ]);
        }
        $content = $response['result']['structuredContent'] ?? [];
        if (empty($content) && isset($response['result']['content'][0]['text'])) {
            $parsed = json_decode($response['result']['content'][0]['text'], true);
            $content = is_array($parsed) ? $parsed : [];
        }
        if (is_array($content) && array_key_exists('ok', $content) && $content['ok'] === false) {
            throw new ChatbotException('mcp_tool_error', [
                'tool' => $toolName,
                'message' => (string) ($content['error'] ?? 'ok_false'),
            ]);
        }
        return $content;
    }
}
