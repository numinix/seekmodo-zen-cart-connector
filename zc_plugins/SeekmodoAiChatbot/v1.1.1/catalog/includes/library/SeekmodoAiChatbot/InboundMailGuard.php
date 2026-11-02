<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot;

/**
 * Decides which inbound messages must not open or append a ticket.
 * Pure: no IMAP or database, so the rules can be checked without a store.
 */
final class InboundMailGuard
{
    /**
     * @param array<string, mixed> $msg
     * @return string|null Skip reason, or null when the message may be filed.
     */
    public static function ignoreReason(array $msg, string $mailboxAddress): ?string
    {
        $from = strtolower(trim((string) ($msg['from_email'] ?? '')));
        $mailbox = strtolower(trim($mailboxAddress));
        if ($from !== '' && $mailbox !== '' && $from === $mailbox) {
            return 'own_mailbox';
        }

        $messageId = trim((string) ($msg['message_id'] ?? ''));
        if (self::isOwnMessageId($messageId)) {
            return 'own_message';
        }

        $auto = strtolower(trim((string) ($msg['auto_submitted'] ?? '')));
        if ($auto !== '' && $auto !== 'no') {
            return 'auto_submitted';
        }

        $precedence = strtolower(trim((string) ($msg['precedence'] ?? '')));
        if (in_array($precedence, ['bulk', 'list', 'junk'], true)) {
            return 'bulk';
        }

        if (trim((string) ($msg['list_id'] ?? '')) !== '') {
            return 'bulk';
        }

        return null;
    }

    public static function isOwnMessageId(string $messageId): bool
    {
        $messageId = trim($messageId);
        if ($messageId === '') {
            return false;
        }
        return preg_match('/^<?chat-[a-z0-9-]+@/i', $messageId) === 1;
    }
}
