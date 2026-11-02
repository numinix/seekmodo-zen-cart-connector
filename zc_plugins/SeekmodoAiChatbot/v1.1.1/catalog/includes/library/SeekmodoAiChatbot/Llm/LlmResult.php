<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot\Llm;

/**
 * Normalized chat-completions style result (OpenAI-shaped) for all providers.
 */
final class LlmResult
{
    /** @var array<string, mixed> */
    public array $raw;
    public string $provider;
    public string $error;

    /**
     * @param array<string, mixed> $raw OpenAI-compatible decoded body
     */
    public function __construct(array $raw, string $provider = '', string $error = '')
    {
        $this->raw = $raw;
        $this->provider = $provider;
        $this->error = $error;
    }

    public function ok(): bool
    {
        return $this->error === '' && isset($this->raw['choices']);
    }
}
