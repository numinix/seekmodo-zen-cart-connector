<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot;

/**
 * Exception for chatbot and MCP client errors.
 */
final class ChatbotException extends \RuntimeException
{
    /** @var array<string, mixed> */
    private array $context;

    /** @param array<string, mixed> $context */
    public function __construct(string $reason, array $context = [])
    {
        parent::__construct($reason);
        $this->context = $context;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }
}
