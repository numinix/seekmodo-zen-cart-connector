<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot\Llm;

/**
 * OpenAI Chat Completions API (also used for Deepseek's OpenAI-compatible endpoint).
 */
final class OpenAiCompatibleAdapter implements ProviderAdapter
{
    private string $apiKey;
    private string $baseUrl;
    private string $providerName;

    public function __construct(string $apiKey, string $baseUrl = 'https://api.openai.com', string $providerName = 'openai')
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->providerName = $providerName;
    }

    public function name(): string
    {
        return $this->providerName;
    }

    public function chat(string $model, array $messages, array $tools, array $opts = []): LlmResult
    {
        if ($this->apiKey === '') {
            return new LlmResult([], $this->providerName, 'provider_unconfigured');
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => isset($opts['temperature']) ? (float) $opts['temperature'] : 0.3,
            'max_tokens' => isset($opts['max_tokens']) ? (int) $opts['max_tokens'] : 2048,
        ];
        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = $opts['tool_choice'] ?? 'auto';
        }
        if (isset($opts['response_format']) && is_array($opts['response_format'])) {
            $payload['response_format'] = $opts['response_format'];
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return new LlmResult([], $this->providerName, 'json_encode_failed');
        }

        $timeout = isset($opts['timeout']) ? max(5, (int) $opts['timeout']) : 30;
        $ch = curl_init($this->baseUrl . '/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_TIMEOUT => $timeout,
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $raw === '') {
            return new LlmResult(['http_code' => $httpCode, 'curl_error' => $curlError], $this->providerName, 'request_failed');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return new LlmResult(['http_code' => $httpCode, 'raw' => substr((string) $raw, 0, 500)], $this->providerName, 'invalid_response');
        }
        if (isset($decoded['error'])) {
            return new LlmResult($decoded, $this->providerName, 'api_error');
        }

        return new LlmResult($decoded, $this->providerName);
    }
}
