<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot\Llm;

/**
 * Anthropic Messages API with OpenAI-shaped I/O for ChatAgent tool loops.
 */
final class AnthropicAdapter implements ProviderAdapter
{
    private string $apiKey;
    private string $baseUrl;
    private string $apiVersion;

    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://api.anthropic.com',
        string $apiVersion = '2023-06-01'
    ) {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiVersion = $apiVersion;
    }

    public function name(): string
    {
        return 'claude';
    }

    public function chat(string $model, array $messages, array $tools, array $opts = []): LlmResult
    {
        if ($this->apiKey === '') {
            return new LlmResult([], 'claude', 'provider_unconfigured');
        }

        $system = '';
        $anthropicMessages = [];
        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $role = (string) ($msg['role'] ?? '');
            if ($role === 'system') {
                $system .= ($system === '' ? '' : "\n\n") . (string) ($msg['content'] ?? '');
                continue;
            }
            $converted = $this->convertMessage($msg);
            if ($converted !== null) {
                $anthropicMessages[] = $converted;
            }
        }

        // Anthropic requires alternating user/assistant; merge consecutive same roles.
        $anthropicMessages = $this->mergeConsecutive($anthropicMessages);
        if ($anthropicMessages === []) {
            return new LlmResult([], 'claude', 'empty_messages');
        }

        $payload = [
            'model' => $model,
            'max_tokens' => isset($opts['max_tokens']) ? max(1, (int) $opts['max_tokens']) : 2048,
            'temperature' => isset($opts['temperature']) ? (float) $opts['temperature'] : 0.3,
            'messages' => $anthropicMessages,
        ];
        if ($system !== '') {
            $payload['system'] = $system;
        }
        if ($tools !== []) {
            $payload['tools'] = $this->convertTools($tools);
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return new LlmResult([], 'claude', 'json_encode_failed');
        }

        $timeout = isset($opts['timeout']) ? max(5, (int) $opts['timeout']) : 45;
        $ch = curl_init($this->baseUrl . '/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . $this->apiVersion,
            ],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_TIMEOUT => $timeout,
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $raw === '') {
            return new LlmResult(['http_code' => $httpCode, 'curl_error' => $curlError], 'claude', 'request_failed');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return new LlmResult(['http_code' => $httpCode, 'raw' => substr((string) $raw, 0, 500)], 'claude', 'invalid_response');
        }
        if (isset($decoded['error']) || ($httpCode < 200 || $httpCode >= 300)) {
            return new LlmResult($decoded, 'claude', 'api_error');
        }

        return new LlmResult($this->toOpenAiShape($decoded, $model), 'claude');
    }

    /**
     * @param array<string, mixed> $msg
     * @return array{role: string, content: mixed}|null
     */
    private function convertMessage(array $msg): ?array
    {
        $role = (string) ($msg['role'] ?? '');
        if ($role === 'tool') {
            $toolCallId = (string) ($msg['tool_call_id'] ?? '');
            $content = (string) ($msg['content'] ?? '');
            return [
                'role' => 'user',
                'content' => [[
                    'type' => 'tool_result',
                    'tool_use_id' => $toolCallId !== '' ? $toolCallId : 'tool_unknown',
                    'content' => $content,
                ]],
            ];
        }

        if ($role === 'assistant') {
            $blocks = [];
            $text = (string) ($msg['content'] ?? '');
            if ($text !== '') {
                $blocks[] = ['type' => 'text', 'text' => $text];
            }
            $toolCalls = $msg['tool_calls'] ?? [];
            if (is_array($toolCalls)) {
                foreach ($toolCalls as $tc) {
                    if (!is_array($tc)) {
                        continue;
                    }
                    $id = (string) ($tc['id'] ?? '');
                    $fn = is_array($tc['function'] ?? null) ? $tc['function'] : [];
                    $name = (string) ($fn['name'] ?? '');
                    $argsRaw = (string) ($fn['arguments'] ?? '{}');
                    $args = json_decode($argsRaw, true);
                    if (!is_array($args)) {
                        $args = [];
                    }
                    $blocks[] = [
                        'type' => 'tool_use',
                        'id' => $id !== '' ? $id : ('tool_' . substr(md5($name . $argsRaw), 0, 12)),
                        'name' => $name,
                        'input' => $args,
                    ];
                }
            }
            if ($blocks === []) {
                $blocks[] = ['type' => 'text', 'text' => ''];
            }
            return ['role' => 'assistant', 'content' => $blocks];
        }

        if ($role === 'user') {
            return [
                'role' => 'user',
                'content' => (string) ($msg['content'] ?? ''),
            ];
        }

        return null;
    }

    /**
     * @param list<array{role: string, content: mixed}> $messages
     * @return list<array{role: string, content: mixed}>
     */
    private function mergeConsecutive(array $messages): array
    {
        $out = [];
        foreach ($messages as $msg) {
            if ($out === []) {
                $out[] = $msg;
                continue;
            }
            $last = count($out) - 1;
            if ($out[$last]['role'] === $msg['role']) {
                $prev = $out[$last]['content'];
                $next = $msg['content'];
                if (is_string($prev) && is_string($next)) {
                    $out[$last]['content'] = $prev . "\n\n" . $next;
                } elseif (is_array($prev) && is_array($next)) {
                    $out[$last]['content'] = array_merge($prev, $next);
                } elseif (is_array($prev) && is_string($next)) {
                    $out[$last]['content'] = array_merge($prev, [['type' => 'text', 'text' => $next]]);
                } elseif (is_string($prev) && is_array($next)) {
                    $out[$last]['content'] = array_merge([['type' => 'text', 'text' => $prev]], $next);
                }
            } else {
                $out[] = $msg;
            }
        }
        // Anthropic requires first message to be user.
        if ($out !== [] && ($out[0]['role'] ?? '') !== 'user') {
            array_unshift($out, ['role' => 'user', 'content' => '(continued)']);
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $tools
     * @return list<array<string, mixed>>
     */
    private function convertTools(array $tools): array
    {
        $out = [];
        foreach ($tools as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            $fn = is_array($tool['function'] ?? null) ? $tool['function'] : $tool;
            $name = (string) ($fn['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'description' => (string) ($fn['description'] ?? ''),
                'input_schema' => is_array($fn['parameters'] ?? null)
                    ? $fn['parameters']
                    : ['type' => 'object', 'properties' => new \stdClass()],
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function toOpenAiShape(array $body, string $model): array
    {
        $text = '';
        $toolCalls = [];
        $content = $body['content'] ?? [];
        if (is_array($content)) {
            foreach ($content as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $type = (string) ($block['type'] ?? '');
                if ($type === 'text') {
                    $text .= (string) ($block['text'] ?? '');
                } elseif ($type === 'tool_use') {
                    $toolCalls[] = [
                        'id' => (string) ($block['id'] ?? ''),
                        'type' => 'function',
                        'function' => [
                            'name' => (string) ($block['name'] ?? ''),
                            'arguments' => json_encode(
                                is_array($block['input'] ?? null) ? $block['input'] : new \stdClass(),
                                JSON_UNESCAPED_UNICODE
                            ) ?: '{}',
                        ],
                    ];
                }
            }
        }

        $message = ['role' => 'assistant', 'content' => $text !== '' ? $text : null];
        if ($toolCalls !== []) {
            $message['tool_calls'] = $toolCalls;
            $finish = 'tool_calls';
        } else {
            $finish = 'stop';
        }

        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];
        return [
            'id' => (string) ($body['id'] ?? ''),
            'object' => 'chat.completion',
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'message' => $message,
                'finish_reason' => $finish,
            ]],
            'usage' => [
                'prompt_tokens' => (int) ($usage['input_tokens'] ?? 0),
                'completion_tokens' => (int) ($usage['output_tokens'] ?? 0),
                'total_tokens' => (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['output_tokens'] ?? 0),
            ],
        ];
    }
}
