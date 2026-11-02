<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot\Llm;

interface ProviderAdapter
{
    public function name(): string;

    /**
     * @param list<array<string, mixed>> $messages OpenAI-style messages (may include system)
     * @param list<array<string, mixed>> $tools OpenAI-style tool definitions
     * @return LlmResult OpenAI-shaped response (choices[].message with content / tool_calls)
     */
    public function chat(string $model, array $messages, array $tools, array $opts = []): LlmResult;
}
