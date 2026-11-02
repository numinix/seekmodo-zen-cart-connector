<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot;

/**
 * Supported LLM models per provider.
 */
final class ModelCatalog
{
    /**
     * @return list<array{id: string, name: string, provider: string, max_tokens: int, description: string}>
     */
    public static function all(?string $provider = null): array
    {
        $all = [
            ['id' => 'gpt-4o-mini', 'name' => 'GPT-4o Mini', 'provider' => 'openai', 'max_tokens' => 128000, 'description' => 'Fast, affordable OpenAI model for most CS queries.'],
            ['id' => 'gpt-4o', 'name' => 'GPT-4o', 'provider' => 'openai', 'max_tokens' => 128000, 'description' => 'Full-power OpenAI flagship for complex multi-turn chat.'],
            ['id' => 'gpt-4.1-mini', 'name' => 'GPT-4.1 Mini', 'provider' => 'openai', 'max_tokens' => 1048576, 'description' => 'Latest OpenAI mini with large context.'],
            ['id' => 'gpt-4.1', 'name' => 'GPT-4.1', 'provider' => 'openai', 'max_tokens' => 1048576, 'description' => 'Latest OpenAI flagship for complex tool-calling.'],
            ['id' => 'claude-sonnet-4-5', 'name' => 'Claude Sonnet 4.5', 'provider' => 'claude', 'max_tokens' => 200000, 'description' => 'Anthropic Sonnet — strong tool use and reasoning.'],
            ['id' => 'claude-3-5-haiku-latest', 'name' => 'Claude 3.5 Haiku', 'provider' => 'claude', 'max_tokens' => 200000, 'description' => 'Fast Anthropic model for lighter CS traffic.'],
            ['id' => 'claude-3-5-sonnet-latest', 'name' => 'Claude 3.5 Sonnet', 'provider' => 'claude', 'max_tokens' => 200000, 'description' => 'Anthropic Sonnet 3.5.'],
            ['id' => 'deepseek-chat', 'name' => 'DeepSeek Chat', 'provider' => 'deepseek', 'max_tokens' => 128000, 'description' => 'DeepSeek V3 chat (OpenAI-compatible).'],
            ['id' => 'deepseek-reasoner', 'name' => 'DeepSeek Reasoner', 'provider' => 'deepseek', 'max_tokens' => 128000, 'description' => 'DeepSeek reasoner model.'],
        ];
        if ($provider === null || $provider === '') {
            return $all;
        }
        $provider = strtolower($provider);
        return array_values(array_filter($all, static function (array $m) use ($provider): bool {
            return $m['provider'] === $provider;
        }));
    }

    public static function defaultModel(string $provider): string
    {
        switch (strtolower($provider)) {
            case 'claude':
                return 'claude-sonnet-4-5';
            case 'deepseek':
                return 'deepseek-chat';
            case 'openai':
            default:
                return 'gpt-4o-mini';
        }
    }

    public static function exists(string $modelId, ?string $provider = null): bool
    {
        foreach (self::all($provider) as $m) {
            if ($m['id'] === $modelId) {
                return true;
            }
        }
        return false;
    }

    public static function maxTokens(string $modelId): int
    {
        foreach (self::all() as $m) {
            if ($m['id'] === $modelId) {
                return $m['max_tokens'];
            }
        }
        return 128000;
    }

    /**
     * @return list<string>
     */
    public static function providers(): array
    {
        return ['openai', 'claude', 'deepseek'];
    }
}
