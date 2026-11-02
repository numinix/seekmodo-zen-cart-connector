<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot;

/**
 * Subject helpers and update emails for Support Tickets.
 */
final class TicketMail
{
    public static function supportRequestSubject(int $chatId, string $customerEmail): string
    {
        $store = defined('STORE_NAME') ? (string) STORE_NAME : 'Store';
        $idPart = $chatId > 0 ? ('#' . $chatId . ' ') : '';
        return '[' . $store . ' Chatbot] Support request ' . $idPart . 'from ' . $customerEmail;
    }

    public static function threadSubject(int $chatId): string
    {
        $store = defined('STORE_NAME') ? (string) STORE_NAME : 'Store';
        return '[' . $store . '] Chat support #' . max(0, $chatId);
    }

    public static function extractChatIdFromSubject(string $subject): int
    {
        // Prefer "#123" tokens; allow "Chat support #123" / "Support request #123"
        if (preg_match('/(?:chat\s+support|support\s+request)?\s*#(\d{1,10})\b/i', $subject, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/\b#(\d{1,10})\b/', $subject, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    public static function extractChatIdFromHeaders(string $inReplyTo, string $references): int
    {
        $blob = $inReplyTo . ' ' . $references;
        if (preg_match('/chat-(\d+)-\w+@/i', $blob, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    public static function footerWithChatId(int $chatId): string
    {
        if ($chatId < 1) {
            return '';
        }
        return "\n\n--\nSupport ticket #" . $chatId . " (please keep this number in the subject when replying)\n";
    }

    /**
     * Build HTML transcript for notification emails (newest first).
     *
     * @param list<array<string, mixed>> $messages
     */
    public static function buildTranscriptHtml(array $messages, string $highlight = ''): string
    {
        $ordered = array_reverse($messages);
        if ($highlight !== '') {
            array_unshift($ordered, [
                'role' => 'admin',
                'content' => $highlight,
                '_highlight' => true,
            ]);
        }
        $html = '';
        $seenHighlight = false;
        foreach ($ordered as $msg) {
            $role = (string) ($msg['role'] ?? '');
            $content = trim((string) ($msg['content'] ?? ''));
            if ($content === '' || in_array($role, ['tool', 'system'], true)) {
                continue;
            }
            if (!empty($msg['_highlight'])) {
                if ($seenHighlight) {
                    continue;
                }
                $seenHighlight = true;
            } elseif ($highlight !== '' && $role === 'admin' && $content === $highlight) {
                continue;
            }
            $label = $role === 'admin' ? 'Support' : ($role === 'assistant' ? 'Assistant' : 'Customer');
            $bg = $role === 'admin' ? '#e8f4fb' : ($role === 'assistant' ? '#f4f4f4' : '#fff8e8');
            $html .= '<div style="margin:0 21px 12px;padding:12px 14px;border-radius:8px;background:' . $bg . ';">'
                . '<div style="font-size:12px;font-weight:600;color:#555;margin-bottom:6px;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>'
                . '<div style="font-size:14px;line-height:1.45;color:#222;white-space:pre-wrap;">'
                . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '</div></div>';
        }
        return $html !== '' ? $html : '<p style="margin:0 21px;">(No messages.)</p>';
    }

    /**
     * Email the other party after a new ticket message.
     *
     * @param array<string, mixed> $chat hydrated ChatArchive row
     */
    public static function notifyOtherParty(array $chat, string $newRole, string $newText): array
    {
        $chatId = (int) ($chat['chat_id'] ?? 0);
        $customerEmail = strtolower(trim((string) ($chat['customer_email'] ?? '')));
        $customerName = trim((string) ($chat['customer_name'] ?? ''));
        if ($customerName === '') {
            $customerName = $customerEmail !== '' ? $customerEmail : 'Customer';
        }
        $supportEmail = MailConfig::address();
        if ($supportEmail === '') {
            $supportEmail = defined('SEEKMODO_AI_CHATBOT_SUPPORT_EMAIL')
                ? trim((string) SEEKMODO_AI_CHATBOT_SUPPORT_EMAIL) : '';
        }
        $storeName = defined('STORE_NAME') ? (string) STORE_NAME : 'Store';
        $subject = self::threadSubject($chatId);
        $messages = is_array($chat['messages'] ?? null) ? $chat['messages'] : [];
        $plain = $newText . "\n\n--- Previous chat history (newest first) ---\n";
        foreach (array_reverse($messages) as $m) {
            $role = (string) ($m['role'] ?? '');
            $content = trim((string) ($m['content'] ?? ''));
            if ($content === '' || in_array($role, ['tool', 'system'], true)) {
                continue;
            }
            $label = $role === 'admin' ? 'Support' : ($role === 'assistant' ? 'Assistant' : 'Customer');
            $plain .= $label . ': ' . $content . "\n\n";
        }
        $plain .= self::footerWithChatId($chatId);
        $html = self::buildTranscriptHtml($messages);
        $block = ['EMAIL_MESSAGE_HTML' => $html];
        $messageId = ChatbotSmtpMailer::mintMessageId($chatId);
        $inReplyTo = trim((string) ($chat['last_outbound_message_id'] ?? ''));

        if ($newRole === 'user') {
            // Shopper wrote → notify support inbox (From = mailbox; cite customer in body)
            if ($supportEmail === '' || !filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) {
                return ['ok' => false, 'error' => 'support_email_missing'];
            }
            $plainNotify = "New customer reply on support ticket #{$chatId} from {$customerEmail}:\n\n" . $plain;
            $result = ChatbotSmtpMailer::send(
                'Customer Support',
                $supportEmail,
                $subject,
                $plainNotify,
                $storeName,
                $supportEmail,
                'seekmodo_ai_chatbot_support',
                $block,
                $customerEmail !== '' ? $customerEmail : $supportEmail,
                $inReplyTo,
                $inReplyTo,
                $messageId
            );
        } else {
            // Staff wrote → notify shopper (From/Reply-To = support mailbox)
            if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                return ['ok' => false, 'error' => 'customer_email_missing'];
            }
            $from = $supportEmail !== '' ? $supportEmail : (defined('EMAIL_FROM') ? (string) EMAIL_FROM : '');
            $result = ChatbotSmtpMailer::send(
                $customerName,
                $customerEmail,
                $subject,
                $plain,
                $storeName,
                $from,
                'seekmodo_ai_chatbot_reply',
                $block,
                $supportEmail !== '' ? $supportEmail : $from,
                $inReplyTo,
                $inReplyTo,
                $messageId
            );
        }

        if (!empty($result['ok']) && $chatId > 0 && !empty($result['message_id'])) {
            ChatArchive::setLastOutboundMessageId($chatId, (string) $result['message_id']);
        }
        return $result;
    }

    /**
     * Tell the sender their mail opened a ticket, with #id in the subject
     * so the next reply threads instead of opening another ticket.
     *
     * @param array<string, mixed> $chat
     * @return array<string, mixed>
     */
    public static function acknowledgeNewTicket(array $chat): array
    {
        $chatId = (int) ($chat['chat_id'] ?? 0);
        if ($chatId < 1) {
            return ['ok' => false, 'error' => 'chat_id_missing'];
        }
        $text = "We received your message and opened support ticket #{$chatId}.\n"
            . "Please keep #{$chatId} in the subject when you reply so your message stays on this ticket.";
        return self::notifyOtherParty($chat, 'admin', $text);
    }
}
