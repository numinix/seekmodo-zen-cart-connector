<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot\Llm;

use SeekmodoAiChatbot\ChatbotException;
use SeekmodoAiChatbot\ModelCatalog;

/**
 * Factory + facade for merchant BYO LLM providers.
 */
final class LlmClient
{
    private ProviderAdapter $adapter;
    private string $model;
    private string $provider;

    public function __construct(ProviderAdapter $adapter, string $model, string $provider)
    {
        $this->adapter = $adapter;
        $this->model = $model;
        $this->provider = $provider;
    }

    public static function fromConfig(): self
    {
        $provider = self::configuredProvider();
        $model = self::configuredModel($provider);
        $adapter = self::adapterFor($provider);
        return new self($adapter, $model, $provider);
    }

    public static function configuredProvider(): string
    {
        $p = defined('SEEKMODO_AI_CHATBOT_LLM_PROVIDER')
            ? strtolower(trim((string) SEEKMODO_AI_CHATBOT_LLM_PROVIDER))
            : 'openai';
        if (!in_array($p, ['openai', 'claude', 'deepseek'], true)) {
            return 'openai';
        }
        return $p;
    }

    public static function configuredModel(string $provider = ''): string
    {
        if ($provider === '') {
            $provider = self::configuredProvider();
        }
        $model = defined('SEEKMODO_AI_CHATBOT_MODEL') ? trim((string) SEEKMODO_AI_CHATBOT_MODEL) : '';
        if ($model === '') {
            $model = ModelCatalog::defaultModel($provider);
        }
        return $model;
    }

    public static function apiKeyFor(string $provider): string
    {
        switch ($provider) {
            case 'claude':
                return defined('SEEKMODO_AI_CHATBOT_CLAUDE_API_KEY')
                    ? trim((string) SEEKMODO_AI_CHATBOT_CLAUDE_API_KEY)
                    : '';
            case 'deepseek':
                return defined('SEEKMODO_AI_CHATBOT_DEEPSEEK_API_KEY')
                    ? trim((string) SEEKMODO_AI_CHATBOT_DEEPSEEK_API_KEY)
                    : '';
            case 'openai':
            default:
                return defined('SEEKMODO_AI_CHATBOT_API_KEY')
                    ? trim((string) SEEKMODO_AI_CHATBOT_API_KEY)
                    : '';
        }
    }

    public static function adapterFor(string $provider): ProviderAdapter
    {
        $key = self::apiKeyFor($provider);
        switch ($provider) {
            case 'claude':
                return new AnthropicAdapter($key);
            case 'deepseek':
                return new OpenAiCompatibleAdapter($key, 'https://api.deepseek.com', 'deepseek');
            case 'openai':
            default:
                return new OpenAiCompatibleAdapter($key, 'https://api.openai.com', 'openai');
        }
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $tools
     * @return array<string, mixed> OpenAI-shaped decoded body
     */
    public function chat(array $messages, array $tools = [], array $opts = []): array
    {
        $result = $this->adapter->chat($this->model, $messages, $tools, $opts);
        if (!$result->ok()) {
            $err = $result->raw['error'] ?? [];
            throw new ChatbotException('llm_request_failed', [
                'provider' => $this->provider,
                'model' => $this->model,
                'error' => $result->error,
                'type' => is_array($err) ? (string) ($err['type'] ?? '') : '',
                'code' => is_array($err) ? (string) ($err['code'] ?? '') : '',
                'message' => is_array($err) ? (string) ($err['message'] ?? $result->error) : $result->error,
                'http_code' => $result->raw['http_code'] ?? null,
            ]);
        }
        return $result->raw;
    }

    /**
     * Lightweight connection test (no tools).
     *
     * @return array{ok: bool, provider: string, model: string, error?: string, reply?: string}
     */
    public function testConnection(): array
    {
        try {
            $raw = $this->chat([
                ['role' => 'user', 'content' => 'Reply with exactly: ok'],
            ], [], ['max_tokens' => 16, 'temperature' => 0]);
            $content = (string) ($raw['choices'][0]['message']['content'] ?? '');
            return [
                'ok' => true,
                'provider' => $this->provider,
                'model' => $this->model,
                'reply' => $content,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'provider' => $this->provider,
                'model' => $this->model,
                'error' => $e->getMessage(),
            ];
        }
    }
}
