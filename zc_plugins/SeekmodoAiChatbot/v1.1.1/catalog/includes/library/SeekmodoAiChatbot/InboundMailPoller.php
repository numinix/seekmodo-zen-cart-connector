<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot;

/**
 * Poll IMAP inbox, append latest replies to Support Tickets, notify the other party.
 */
final class InboundMailPoller
{
    /**
     * @return array{ok:bool,processed:int,skipped:int,errors:list<string>,lines:list<string>}
     */
    public static function run(int $limit = 40): array
    {
        $lines = [];
        $errors = [];
        $processed = 0;
        $skipped = 0;

        if (!MailConfig::imapReady()) {
            return self::finishEarly('IMAP mailbox is not configured or mail is disabled.');
        }
        if (!ImapInbox::extensionLoaded()) {
            return self::finishEarly('PHP imap extension is not loaded.');
        }

        ChatArchive::ensureTable();
        $inbox = new ImapInbox(MailConfig::imap());
        try {
            $inbox->connect();
            $messages = $inbox->fetchUnseen($limit);
            $lines[] = 'Fetched ' . count($messages) . ' unseen message(s).';
            foreach ($messages as $msg) {
                try {
                    $result = self::processOne($msg, $inbox->folder());
                    if (!empty($result['processed'])) {
                        $processed++;
                        $inbox->markSeen((int) $msg['uid']);
                        $lines[] = 'OK uid=' . $msg['uid'] . ' chat_id=' . ($result['chat_id'] ?? 0);
                    } else {
                        $skipped++;
                        $reason = (string) ($result['reason'] ?? 'skipped');
                        $lines[] = 'SKIP uid=' . $msg['uid'] . ' (' . $reason . ')';
                        // Mark seen when unmatched/empty so we do not loop forever.
                        if (in_array($reason, [
                            'already_processed', 'empty_body', 'no_match', 'bad_sender',
                            'own_mailbox', 'own_message', 'auto_submitted', 'bulk',
                        ], true)) {
                            $inbox->markSeen((int) $msg['uid']);
                        }
                    }
                } catch (\Throwable $e) {
                    $errors[] = 'uid=' . $msg['uid'] . ': ' . $e->getMessage();
                    $lines[] = 'ERR uid=' . $msg['uid'] . ' ' . $e->getMessage();
                }
            }
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        } finally {
            $inbox->disconnect();
        }

        self::log(implode("\n", $lines) . ( $errors !== [] ? "\n" . implode("\n", $errors) : ''));
        self::writeStatus([
            'at' => date('c'),
            'ok' => $errors === [],
            'processed' => $processed,
            'skipped' => $skipped,
            'last_error' => $errors[0] ?? '',
        ]);

        return [
            'ok' => $errors === [],
            'processed' => $processed,
            'skipped' => $skipped,
            'errors' => $errors,
            'lines' => $lines,
        ];
    }

    /**
     * Last poll summary for mail_status. Never includes mailbox secrets.
     *
     * @return array{at:?string,ok:?bool,processed:int,skipped:int,last_error:string}
     */
    public static function lastStatus(): array
    {
        $empty = [
            'at' => null,
            'ok' => null,
            'processed' => 0,
            'skipped' => 0,
            'last_error' => '',
        ];
        $file = self::statusPath();
        if ($file === '' || !is_file($file)) {
            return $empty;
        }
        $raw = @file_get_contents($file);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return $empty;
        }
        return [
            'at' => isset($decoded['at']) && is_string($decoded['at']) ? $decoded['at'] : null,
            'ok' => array_key_exists('ok', $decoded) ? (bool) $decoded['ok'] : null,
            'processed' => (int) ($decoded['processed'] ?? 0),
            'skipped' => (int) ($decoded['skipped'] ?? 0),
            'last_error' => (string) ($decoded['last_error'] ?? ''),
        ];
    }

    /**
     * @param array{uid:int,message_id:string,subject:string,from:string,from_email:string,in_reply_to:string,references:string,date:string,body:string} $msg
     * @return array{processed:bool,chat_id?:int,reason?:string}
     */
    private static function processOne(array $msg, string $folder): array
    {
        $uid = (int) $msg['uid'];
        $messageId = trim((string) $msg['message_id']);
        if (ChatArchive::inboundAlreadyProcessed($folder, $uid, $messageId)) {
            return ['processed' => false, 'reason' => 'already_processed'];
        }

        $ignore = InboundMailGuard::ignoreReason($msg, MailConfig::address());
        if ($ignore !== null) {
            ChatArchive::markInboundProcessed($folder, $uid, $messageId, 0);
            return ['processed' => false, 'reason' => $ignore];
        }

        $latest = EmailReplyExtractor::extractLatest((string) $msg['body']);
        if ($latest === '') {
            ChatArchive::markInboundProcessed($folder, $uid, $messageId, 0);
            return ['processed' => false, 'reason' => 'empty_body'];
        }

        $chatId = TicketMail::extractChatIdFromSubject((string) $msg['subject']);
        if ($chatId < 1) {
            $chatId = TicketMail::extractChatIdFromHeaders(
                (string) $msg['in_reply_to'],
                (string) $msg['references']
            );
        }

        $chat = $chatId > 0 ? ChatArchive::getById($chatId) : null;

        // Paired stores keep tickets in Seekmodo: email lands there, not in chat.
        if (class_exists(SeekmodoTickets::class) && SeekmodoTickets::isPaired()) {
            return self::forwardToSeekmodoTickets($msg, $folder, $uid, $messageId, $latest, $chat);
        }
        if ($chat === null || $chatId < 1) {
            return self::openFromUnmatched($msg, $folder, $uid, $messageId, $latest);
        }

        $customerEmail = strtolower(trim((string) ($chat['customer_email'] ?? '')));
        $fromEmail = strtolower(trim((string) $msg['from_email']));
        $role = ($fromEmail !== '' && $customerEmail !== '' && $fromEmail === $customerEmail) ? 'user' : 'admin';

        $ok = ChatArchive::appendInboundMessage($chatId, $role, $latest, [
            'from_email' => $fromEmail,
            'subject' => (string) $msg['subject'],
            'inbound_message_id' => $messageId,
        ]);
        if (!$ok) {
            return ['processed' => false, 'reason' => 'append_failed'];
        }

        $chat = ChatArchive::getById($chatId) ?? $chat;
        TicketMail::notifyOtherParty($chat, $role, $latest);
        ChatArchive::markInboundProcessed($folder, $uid, $messageId, $chatId);

        return ['processed' => true, 'chat_id' => $chatId];
    }

    /**
     * Hand the message to Seekmodo tickets_api. A reply on an older local
     * email thread converts that chat first (idempotent per chat) so the
     * history moves with it. Failures leave the message unseen for retry;
     * Seekmodo dedupes on Message-ID.
     *
     * @param array<string, mixed> $msg
     * @param array<string, mixed>|null $chat
     * @return array{processed:bool,chat_id?:int,reason?:string}
     */
    private static function forwardToSeekmodoTickets(
        array $msg,
        string $folder,
        int $uid,
        string $messageId,
        string $latest,
        ?array $chat
    ): array {
        $fromEmail = strtolower(trim((string) ($msg['from_email'] ?? '')));
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            ChatArchive::markInboundProcessed($folder, $uid, $messageId, 0);
            return ['processed' => false, 'reason' => 'bad_sender'];
        }
        $fromName = trim((string) preg_replace('/<[^>]+>/', '', (string) ($msg['from'] ?? '')));
        $fromName = trim($fromName, " \t\"'");
        if (strcasecmp($fromName, $fromEmail) === 0) {
            $fromName = '';
        }

        $number = 0;
        if ($chat !== null) {
            $ownerEmail = defined('STORE_OWNER_EMAIL_ADDRESS') ? strtolower(trim((string) STORE_OWNER_EMAIL_ADDRESS)) : '';
            $ownerName = defined('STORE_OWNER') ? trim((string) STORE_OWNER) : '';
            if ($ownerEmail !== '') {
                $agent = ['email' => $ownerEmail, 'name' => $ownerName !== '' ? $ownerName : $ownerEmail];
                $converted = SeekmodoTickets::dispatch([
                    'action' => 'convert_from_chat',
                    'chat_ref' => 'store:' . (int) ($chat['chat_id'] ?? 0),
                    'transcript' => self::transcriptFor($chat),
                    'requester_email' => (string) ($chat['customer_email'] ?? ''),
                    'requester_name' => (string) ($chat['customer_name'] ?? ''),
                    'subject' => (string) ($msg['subject'] ?? ''),
                    'assign_self' => false,
                    'acting_agent' => $agent,
                ]);
                $number = (int) ($converted['number'] ?? 0);
            }
        }

        $payload = [
            'action' => 'inbound_email',
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'subject' => (string) ($msg['subject'] ?? ''),
            'body' => $latest,
            'message_id' => $messageId,
            'in_reply_to' => (string) ($msg['in_reply_to'] ?? ''),
            'references' => (string) ($msg['references'] ?? ''),
            'channel' => 'store_imap',
        ];
        if ($number > 0) {
            $payload['number'] = $number;
        }
        $result = SeekmodoTickets::dispatch($payload);
        if (empty($result['ok'])) {
            return ['processed' => false, 'reason' => 'tickets_api_' . (string) ($result['error'] ?? 'error')];
        }
        $chatId = $chat !== null ? (int) ($chat['chat_id'] ?? 0) : 0;
        ChatArchive::markInboundProcessed($folder, $uid, $messageId, $chatId);
        return ['processed' => true, 'chat_id' => $chatId];
    }

    /**
     * @param array<string, mixed> $chat
     * @return list<array{role:string,content:string,date:string,admin_name:string}>
     */
    private static function transcriptFor(array $chat): array
    {
        $out = [];
        foreach ((array) ($chat['messages'] ?? []) as $m) {
            if (!is_array($m) || trim((string) ($m['content'] ?? '')) === '') {
                continue;
            }
            $out[] = [
                'role' => (string) ($m['role'] ?? 'user'),
                'content' => (string) $m['content'],
                'date' => (string) ($m['date'] ?? ''),
                'admin_name' => (string) ($m['admin_name'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $msg
     * @return array{processed:bool,chat_id?:int,reason?:string,created?:bool}
     */
    private static function openFromUnmatched(array $msg, string $folder, int $uid, string $messageId, string $latest): array
    {
        if (!MailConfig::createUnmatchedEnabled()) {
            ChatArchive::markInboundProcessed($folder, $uid, $messageId, 0);
            return ['processed' => false, 'reason' => 'no_match'];
        }

        $fromEmail = strtolower(trim((string) ($msg['from_email'] ?? '')));
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            ChatArchive::markInboundProcessed($folder, $uid, $messageId, 0);
            return ['processed' => false, 'reason' => 'bad_sender'];
        }

        $fromName = trim((string) preg_replace('/<[^>]+>/', '', (string) ($msg['from'] ?? '')));
        $fromName = trim($fromName, " \t\"'");
        if (strcasecmp($fromName, $fromEmail) === 0) {
            $fromName = '';
        }

        $chatId = ChatArchive::createEmailTicket(
            $fromEmail,
            $fromName,
            (string) ($msg['subject'] ?? ''),
            $latest,
            $messageId
        );
        if ($chatId < 1) {
            return ['processed' => false, 'reason' => 'create_failed'];
        }

        $chat = ChatArchive::getById($chatId);
        if ($chat !== null) {
            TicketMail::acknowledgeNewTicket($chat);
        }
        ChatArchive::markInboundProcessed($folder, $uid, $messageId, $chatId);

        return ['processed' => true, 'chat_id' => $chatId, 'created' => true];
    }

    /**
     * @return array{ok:bool,processed:int,skipped:int,errors:list<string>,lines:list<string>}
     */
    private static function finishEarly(string $error): array
    {
        self::writeStatus([
            'at' => date('c'),
            'ok' => false,
            'processed' => 0,
            'skipped' => 0,
            'last_error' => $error,
        ]);
        return [
            'ok' => false,
            'processed' => 0,
            'skipped' => 0,
            'errors' => [$error],
            'lines' => [],
        ];
    }

    /** @param array<string, mixed> $status */
    private static function writeStatus(array $status): void
    {
        $file = self::statusPath();
        if ($file === '') {
            return;
        }
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $json = json_encode($status, JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            @file_put_contents($file, $json);
        }
    }

    private static function statusPath(): string
    {
        if (!defined('DIR_FS_CATALOG')) {
            return '';
        }
        return rtrim((string) DIR_FS_CATALOG, '/\\') . '/cache/seekmodo_ai_chatbot/inbound_mail_status.json';
    }

    private static function log(string $text): void
    {
        $dir = '';
        if (defined('DIR_FS_CATALOG')) {
            $dir = rtrim((string) DIR_FS_CATALOG, '/\\') . '/cache/seekmodo_ai_chatbot';
        }
        if ($dir === '') {
            return;
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . '/inbound_mail.log';
        @file_put_contents(
            $file,
            '[' . date('Y-m-d H:i:s') . "]\n" . $text . "\n\n",
            FILE_APPEND
        );
    }
}
