<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot;

/**
 * Operator-facing ticket actions shared by admin UI, MCP tools, and the
 * Seekmodo cloud Inbox HMAC API.
 */
final class TicketInboxService
{
    public const CAPABILITIES = [
        'list', 'get', 'poll', 'reply', 'claim', 'note',
        'resume_ai', 'resolve', 'reopen', 'archive', 'unarchive', 'presence',
        'assign', 'assignees', 'list_assignees',
        'create', 'mail_status',
        'shopper_list', 'shopper_get', 'shopper_reply',
    ];

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int, awaiting_count: int}
     */
    public function list(array $filters): array
    {
        $result = ChatArchive::search($filters);
        $rows = [];
        foreach ($result['rows'] as $row) {
            $rows[] = $this->summaryRow($row);
        }
        $result['rows'] = $rows;
        $archived = (string) ($filters['archived'] ?? '0');
        $result['awaiting_count'] = $archived === '1' ? 0 : ChatArchive::countAwaitingHuman();
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $chatId, bool $includeInternalNotes = true): array
    {
        $row = ChatArchive::getById($chatId);
        if ($row === null) {
            throw new \InvalidArgumentException('chat_not_found');
        }
        return $this->detailRow($row, $includeInternalNotes);
    }

    /**
     * Dashboard / console poll payload (near-parity with ajax=inbox_poll).
     *
     * @return array<string, mixed>
     */
    public function poll(int $itemLimit = 12): array
    {
        $awaitingIds = ChatArchive::awaitingHumanIds();
        $items = [];
        foreach (ChatArchive::listOpenForDashboard(max(1, min(50, $itemLimit))) as $ticket) {
            $id = (int) ($ticket['chat_id'] ?? 0);
            $name = trim((string) ($ticket['customer_name'] ?? ''));
            $email = trim((string) ($ticket['customer_email'] ?? ''));
            $label = $name !== '' ? $name : ($email !== '' ? $email : ('Chat #' . $id));
            $items[] = [
                'chat_id' => $id,
                'label' => $label,
                'preview' => (string) ($ticket['preview'] ?? ''),
                'status' => (string) ($ticket['status'] ?? 'open'),
                'awaiting' => !empty($ticket['awaiting_human']) || ChatArchive::isAwaitingHuman($ticket),
                'present' => ChatArchive::isShopperPresent($ticket),
                'updated' => (string) ($ticket['date_updated'] ?? ''),
                'assigned_admin_id' => (int) ($ticket['assigned_admin_id'] ?? 0),
                'assigned_seekmodo_user_id' => (string) ($ticket['assigned_seekmodo_user_id'] ?? ''),
                'assigned_seekmodo_name' => (string) ($ticket['assigned_seekmodo_name'] ?? ''),
                'assigned_seekmodo_email' => (string) ($ticket['assigned_seekmodo_email'] ?? ''),
            ];
        }
        return [
            'ok' => true,
            'awaiting_count' => count($awaitingIds),
            'awaiting_ids' => $awaitingIds,
            'open_count' => ChatArchive::countOpenTickets(),
            'items' => $items,
            'ts' => time(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reply(int $chatId, string $mode, string $body, int $adminId = 0, string $adminName = ''): array
    {
        $body = trim($body);
        if ($body === '') {
            throw new \InvalidArgumentException('body_required');
        }
        $row = ChatArchive::getById($chatId);
        if ($row === null) {
            throw new \InvalidArgumentException('chat_not_found');
        }
        $mode = strtolower(trim($mode));
        $adminName = trim($adminName);
        if ($mode === 'live') {
            // Live reply is an explicit takeover — mark live before writing so any
            // in-flight AI turn can see status=live and suppress its reply.
            $current = strtolower(trim((string) ($row['status'] ?? 'open')));
            if ($current !== 'live') {
                if ($adminId >= 1) {
                    ChatArchive::claimChat($chatId, $adminId);
                } else {
                    ChatArchive::setStatus($chatId, 'live', 0);
                }
            }
            if (!ChatArchive::appendLiveAdminMessage($chatId, $body, $adminId, $adminName !== '' ? $adminName : 'Agent')) {
                throw new \RuntimeException('live_reply_failed');
            }
            $sid = (string) ($row['session_id'] ?? '');
            ChatAgent::appendLiveAdminToSession($sid, $body, $adminName !== '' ? $adminName : 'Agent');
            ChatAgent::stampSessionLive($sid, $adminId);
            return ['ok' => true, 'mode' => 'live', 'chat_id' => $chatId, 'status' => 'live'];
        }
        if ($mode !== 'email') {
            throw new \InvalidArgumentException('invalid_mode');
        }
        if (!ChatArchive::canAdminReply($row)) {
            throw new \InvalidArgumentException('email_verified_required');
        }
        if (!ChatArchive::appendAdminMessage($chatId, $body, $adminId)) {
            throw new \RuntimeException('email_reply_failed');
        }
        $row = ChatArchive::getById($chatId) ?? $row;
        $notify = TicketMail::notifyOtherParty($row, 'admin', $body);
        if (empty($notify['ok'])) {
            throw new \RuntimeException('email_send_failed');
        }
        ChatAgent::stampSessionHandedOff((string) ($row['session_id'] ?? ''));
        return ['ok' => true, 'mode' => 'email', 'chat_id' => $chatId, 'status' => 'handed_off'];
    }

    /** @return array<string, mixed> */
    public function claim(int $chatId, int $adminId = 0, string $adminName = ''): array
    {
        $row = ChatArchive::getById($chatId);
        if ($row === null) {
            throw new \InvalidArgumentException('chat_not_found');
        }
        // Remote Seekmodo agents often have no Zen admin_id. Prefer real claim
        // when we have one; otherwise set live without assignment.
        if ($adminId >= 1) {
            if (!ChatArchive::claimChat($chatId, $adminId)) {
                throw new \RuntimeException('claim_failed');
            }
        } elseif (!ChatArchive::setStatus($chatId, 'live', 0)) {
            throw new \RuntimeException('claim_failed');
        }
        ChatAgent::stampSessionLive((string) ($row['session_id'] ?? ''), $adminId);
        return [
            'ok' => true,
            'chat_id' => $chatId,
            'status' => 'live',
            'admin_name' => trim($adminName),
        ];
    }

    /** @return array<string, mixed> */
    public function resumeAi(int $chatId, int $adminId = 0): array
    {
        $row = ChatArchive::getById($chatId);
        if ($row === null) {
            throw new \InvalidArgumentException('chat_not_found');
        }
        if (!ChatArchive::resumeAi($chatId, $adminId)) {
            throw new \RuntimeException('resume_failed');
        }
        $sid = (string) ($row['session_id'] ?? '');
        ChatAgent::syncSessionFromArchive($sid);
        ChatAgent::stampSessionOpen($sid);

        // Generate a reply to the pending shopper message without waiting for
        // another send. Runs after the HTTP response so the Seekmodo gateway
        // (25s curl timeout) is not blocked by the LLM round-trip.
        $shopper = $this->shopperFromArchiveRow($row);
        if ($sid !== '') {
            register_shutdown_function(static function () use ($sid, $shopper): void {
                try {
                    @ignore_user_abort(true);
                    if (function_exists('set_time_limit')) {
                        @set_time_limit(120);
                    }
                    ChatAgent::continueAfterResume($sid, $shopper);
                } catch (\Throwable $e) {
                    // best-effort — shopper can still message to continue
                }
            });
        }

        return [
            'ok' => true,
            'chat_id' => $chatId,
            'status' => 'open',
            'session_id' => $sid,
            'ai_continuing' => $sid !== '',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{logged_in: bool, customers_id: int, email: string, first_name: string, last_name: string}
     */
    private function shopperFromArchiveRow(array $row): array
    {
        $name = trim((string) ($row['customer_name'] ?? ''));
        $parts = $name !== '' ? (preg_split('/\s+/', $name, 2) ?: []) : [];
        $customersId = (int) ($row['customers_id'] ?? 0);
        return [
            'logged_in' => $customersId > 0 && empty($row['is_guest']),
            'customers_id' => $customersId,
            'email' => strtolower(trim((string) ($row['customer_email'] ?? ''))),
            'first_name' => trim((string) ($parts[0] ?? '')),
            'last_name' => trim((string) ($parts[1] ?? '')),
        ];
    }

    /** @return array<string, mixed> */
    public function resolve(int $chatId, int $adminId = 0): array
    {
        $row = ChatArchive::getById($chatId);
        if ($row === null) {
            throw new \InvalidArgumentException('chat_not_found');
        }
        if (!ChatArchive::setStatus($chatId, 'resolved', $adminId)) {
            throw new \RuntimeException('resolve_failed');
        }
        ChatAgent::stampSessionResolved((string) ($row['session_id'] ?? ''));
        ChatAgent::enqueuePostChatAnalysis($chatId, 'resolve');
        return ['ok' => true, 'chat_id' => $chatId, 'status' => 'resolved'];
    }

    /** @return array<string, mixed> */
    public function reopen(int $chatId, int $adminId = 0): array
    {
        $row = ChatArchive::getById($chatId);
        if ($row === null) {
            throw new \InvalidArgumentException('chat_not_found');
        }
        $newStatus = ChatArchive::reopenChat($chatId, $adminId);
        if ($newStatus === false) {
            throw new \RuntimeException('reopen_failed');
        }
        $sid = (string) ($row['session_id'] ?? '');
        if ($newStatus === 'handed_off') {
            ChatAgent::stampSessionHandedOff($sid);
        } else {
            ChatAgent::stampSessionOpen($sid);
        }
        return ['ok' => true, 'chat_id' => $chatId, 'status' => $newStatus];
    }

    /** @return array<string, mixed> */
    public function note(int $chatId, string $body, int $adminId = 0, string $adminName = ''): array
    {
        $body = trim($body);
        if ($body === '') {
            throw new \InvalidArgumentException('body_required');
        }
        if (ChatArchive::getById($chatId) === null) {
            throw new \InvalidArgumentException('chat_not_found');
        }
        $label = trim($adminName) !== '' ? trim($adminName) : 'Agent';
        if (!ChatArchive::appendAgentNote($chatId, $body, $adminId, $label)) {
            throw new \RuntimeException('note_failed');
        }
        return ['ok' => true, 'chat_id' => $chatId];
    }

    /** @return array<string, mixed> */
    public function archive(int $chatId): array
    {
        if (ChatArchive::getById($chatId) === null) {
            throw new \InvalidArgumentException('chat_not_found');
        }
        if (!ChatArchive::archiveChat($chatId)) {
            throw new \RuntimeException('archive_failed');
        }
        return ['ok' => true, 'chat_id' => $chatId, 'archived' => true];
    }

    /** @return array<string, mixed> */
    public function unarchive(int $chatId): array
    {
        if (ChatArchive::getById($chatId) === null) {
            throw new \InvalidArgumentException('chat_not_found');
        }
        if (!ChatArchive::unarchiveChat($chatId)) {
            throw new \RuntimeException('unarchive_failed');
        }
        return ['ok' => true, 'chat_id' => $chatId, 'archived' => false];
    }

    /** @return array<string, mixed> */
    public function presenceTouch(int $adminId = 0, string $adminName = ''): array
    {
        AdminPresence::touch($adminId > 0 ? $adminId : 0);
        return [
            'ok' => true,
            'online' => AdminPresence::isAnyOnline(),
            'admin_name' => trim($adminName),
        ];
    }

    /**
     * Assign to a store Zen Cart admin or a Seekmodo teammate.
     * Seekmodo cloud sends seekmodo_user_id (+ seekmodo_name/email); empty
     * seekmodo_user_id clears the Seekmodo assignee (unassign).
     *
     * @return array<string, mixed>
     */
    public function assign(int $chatId, array $payload): array
    {
        $row = ChatArchive::getById($chatId);
        if ($row === null) {
            throw new \InvalidArgumentException('chat_not_found');
        }
        $hasSeekmodoKey = array_key_exists('seekmodo_user_id', $payload)
            || array_key_exists('assigned_seekmodo_user_id', $payload);
        $seekmodoUserId = trim((string) ($payload['seekmodo_user_id'] ?? $payload['assigned_seekmodo_user_id'] ?? ''));
        $adminId = (int) ($payload['admin_id'] ?? $payload['assigned_admin_id'] ?? 0);
        if ($hasSeekmodoKey) {
            if ($seekmodoUserId === '') {
                if (!ChatArchive::clearSeekmodoAssignee($chatId)) {
                    throw new \RuntimeException('assign_failed');
                }
                $fresh = ChatArchive::getById($chatId) ?? $row;
                return [
                    'ok' => true,
                    'chat_id' => $chatId,
                    'assigned_seekmodo_user_id' => '',
                    'assigned_seekmodo_name' => '',
                    'assigned_seekmodo_email' => '',
                    'status' => (string) ($fresh['status'] ?? $row['status'] ?? 'open'),
                ];
            }
            // Prefer the teammate display name from Seekmodo; admin_name is the actor.
            $name = trim((string) ($payload['seekmodo_name'] ?? $payload['name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($payload['admin_name'] ?? ''));
            }
            $email = strtolower(trim((string) ($payload['seekmodo_email'] ?? $payload['email'] ?? '')));
            if (!ChatArchive::assignToSeekmodoUser($chatId, $seekmodoUserId, $name, $email)) {
                throw new \RuntimeException('assign_failed');
            }
            $sid = (string) ($row['session_id'] ?? '');
            $status = strtolower(trim((string) ($row['status'] ?? 'open')));
            if ($status !== 'handed_off') {
                ChatAgent::stampSessionLive($sid, 0);
            }
            return [
                'ok' => true,
                'chat_id' => $chatId,
                'assigned_seekmodo_user_id' => $seekmodoUserId,
                'assigned_seekmodo_name' => $name,
                'assigned_seekmodo_email' => $email,
                'status' => $status === 'handed_off' ? 'handed_off' : 'live',
            ];
        }
        if ($adminId < 1) {
            throw new \InvalidArgumentException('assignee_required');
        }
        if (!ChatArchive::assignToAdmin($chatId, $adminId)) {
            throw new \RuntimeException('assign_failed');
        }
        $sid = (string) ($row['session_id'] ?? '');
        $fresh = ChatArchive::getById($chatId) ?? $row;
        $status = (string) ($fresh['status'] ?? 'live');
        if ($status === 'live') {
            ChatAgent::stampSessionLive($sid, $adminId);
        }
        return [
            'ok' => true,
            'chat_id' => $chatId,
            'assigned_admin_id' => $adminId,
            'admin_name' => ChatArchive::assigneeDisplayName($adminId),
            'status' => $status,
        ];
    }

    /** @return array<string, mixed> */
    public function assignees(): array
    {
        return [
            'ok' => true,
            'assignees' => ChatArchive::listStoreAssignees(),
        ];
    }

    /**
     * Dispatch a named action (HMAC inbox API + shared MCP surface).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function dispatch(string $action, array $payload): array
    {
        $action = strtolower(trim($action));
        $chatId = (int) ($payload['chat_id'] ?? 0);
        $adminId = (int) ($payload['admin_id'] ?? 0);
        $adminName = (string) ($payload['admin_name'] ?? '');

        return match ($action) {
            'list' => $this->list([
                'status' => (string) ($payload['status'] ?? ''),
                'awaiting' => (string) ($payload['awaiting'] ?? ''),
                'q' => (string) ($payload['q'] ?? ''),
                'archived' => (string) ($payload['archived'] ?? '0'),
                'date_from' => (string) ($payload['date_from'] ?? ''),
                'date_to' => (string) ($payload['date_to'] ?? ''),
                'guest' => (string) ($payload['guest'] ?? ''),
                'assigned_admin_id' => (string) ($payload['assigned_admin_id'] ?? ''),
                'assigned_seekmodo_user_id' => (string) ($payload['assigned_seekmodo_user_id'] ?? ''),
                'current_admin_id' => $adminId,
                'sort' => (string) ($payload['sort'] ?? 'updated'),
                'dir' => (string) ($payload['dir'] ?? 'desc'),
                'page' => max(1, (int) ($payload['page'] ?? 1)),
                'per_page' => max(5, min(100, (int) ($payload['per_page'] ?? $payload['limit'] ?? 10))),
            ]),
            'get' => $this->get($chatId, (bool) ($payload['include_notes'] ?? true)),
            'poll' => $this->poll((int) ($payload['limit'] ?? 12)),
            'reply' => $this->reply($chatId, (string) ($payload['mode'] ?? ''), (string) ($payload['body'] ?? ''), $adminId, $adminName),
            'claim' => $this->claim($chatId, $adminId, $adminName),
            'note' => $this->note($chatId, (string) ($payload['body'] ?? ''), $adminId, $adminName),
            'resume_ai' => $this->resumeAi($chatId, $adminId),
            'resolve' => $this->resolve($chatId, $adminId),
            'reopen' => $this->reopen($chatId, $adminId),
            'archive' => $this->archive($chatId),
            'unarchive' => $this->unarchive($chatId),
            'presence' => $this->presenceTouch($adminId, $adminName),
            'assign' => $this->assign($chatId, $payload),
            'assignees', 'list_assignees' => $this->assignees(),
            'create' => $this->createFromEmail($payload),
            'mail_status' => $this->mailStatus(),
            'shopper_list' => $this->shopperList($payload),
            'shopper_get' => $this->shopperGet($payload),
            'shopper_reply' => $this->shopperReply($payload),
            default => throw new \InvalidArgumentException('unknown_action'),
        };
    }

    /**
     * Mailbox status for tenant admin. Passwords and hosts are omitted.
     *
     * @return array<string, mixed>
     */
    public function mailStatus(): array
    {
        $poll = InboundMailPoller::lastStatus();
        return [
            'ok' => true,
            'enabled' => MailConfig::enabled(),
            'address' => MailConfig::address(),
            'imap_ready' => MailConfig::imapReady(),
            'smtp_ready' => MailConfig::smtpReady(),
            'create_unmatched' => MailConfig::createUnmatchedEnabled(),
            'last_poll_at' => $poll['at'],
            'last_poll_ok' => $poll['ok'],
            'last_error' => $poll['last_error'],
            'processed' => $poll['processed'],
            'skipped' => $poll['skipped'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createFromEmail(array $payload): array
    {
        $email = strtolower(trim((string) ($payload['from_email'] ?? $payload['customer_email'] ?? '')));
        $name = trim((string) ($payload['from_name'] ?? $payload['customer_name'] ?? ''));
        $subject = trim((string) ($payload['subject'] ?? ''));
        $body = trim((string) ($payload['body'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('from_email_required');
        }
        if ($body === '' && $subject === '') {
            throw new \InvalidArgumentException('body_required');
        }
        $chatId = ChatArchive::createEmailTicket($email, $name, $subject, $body, '');
        if ($chatId < 1) {
            throw new \RuntimeException('create_failed');
        }
        $row = ChatArchive::getById($chatId);
        $ack = $row !== null ? TicketMail::acknowledgeNewTicket($row) : ['ok' => false];
        return [
            'ok' => true,
            'chat_id' => $chatId,
            'created' => true,
            'ack_ok' => !empty($ack['ok']),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function shopperList(array $payload): array
    {
        $email = $this->requireShopperEmail($payload);
        $result = ChatArchive::search([
            'customer_email' => $email,
            'archived' => 'all',
            'status' => (string) ($payload['status'] ?? ''),
            'q' => '',
            'sort' => 'updated',
            'dir' => 'desc',
            'page' => max(1, (int) ($payload['page'] ?? 1)),
            'per_page' => max(5, min(100, (int) ($payload['per_page'] ?? $payload['limit'] ?? 20))),
        ]);
        $rows = [];
        foreach ($result['rows'] as $row) {
            $rows[] = $this->summaryRow($row);
        }
        $result['rows'] = $rows;
        $result['ok'] = true;
        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function shopperGet(array $payload): array
    {
        $email = $this->requireShopperEmail($payload);
        $chatId = (int) ($payload['chat_id'] ?? 0);
        $row = ChatArchive::getById($chatId);
        if ($row === null || !$this->shopperOwns($row, $email)) {
            throw new \InvalidArgumentException('chat_not_found');
        }
        $detail = $this->detailRow($row, false);
        unset($detail['actions']['can_delete']);
        return ['ok' => true, 'chat' => $detail];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function shopperReply(array $payload): array
    {
        $email = $this->requireShopperEmail($payload);
        $chatId = (int) ($payload['chat_id'] ?? 0);
        $body = trim((string) ($payload['body'] ?? ''));
        if ($body === '') {
            throw new \InvalidArgumentException('body_required');
        }
        $row = ChatArchive::getById($chatId);
        if ($row === null || !$this->shopperOwns($row, $email)) {
            throw new \InvalidArgumentException('chat_not_found');
        }
        if (!ChatArchive::appendInboundMessage($chatId, 'user', $body, [
            'from_email' => $email,
            'channel' => 'portal',
        ])) {
            throw new \RuntimeException('reply_failed');
        }
        $row = ChatArchive::getById($chatId) ?? $row;
        TicketMail::notifyOtherParty($row, 'user', $body);
        return ['ok' => true, 'chat_id' => $chatId, 'status' => 'handed_off'];
    }

    /** @param array<string, mixed> $payload */
    private function requireShopperEmail(array $payload): string
    {
        $email = strtolower(trim((string) ($payload['customer_email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('customer_email_required');
        }
        return $email;
    }

    /** @param array<string, mixed> $row */
    private function shopperOwns(array $row, string $email): bool
    {
        $rowEmail = strtolower(trim((string) ($row['customer_email'] ?? '')));
        if ($rowEmail === '' || strlen($rowEmail) !== strlen($email)) {
            return false;
        }
        return hash_equals($rowEmail, $email);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function summaryRow(array $row): array
    {
        $name = trim((string) ($row['customer_name'] ?? ''));
        $email = trim((string) ($row['customer_email'] ?? ''));
        $initials = '';
        if ($name !== '') {
            $parts = preg_split('/\s+/', $name) ?: [];
            $initials = strtoupper(substr((string) ($parts[0] ?? ''), 0, 1) . substr((string) ($parts[1] ?? ''), 0, 1));
        } elseif ($email !== '') {
            $initials = strtoupper(substr($email, 0, 1));
        }
        return [
            'chat_id' => (int) ($row['chat_id'] ?? 0),
            'session_id' => (string) ($row['session_id'] ?? ''),
            'status' => (string) ($row['status'] ?? 'open'),
            'customer_email' => $email,
            'customer_name' => $name,
            'customers_id' => (int) ($row['customers_id'] ?? 0),
            'is_guest' => (int) ($row['is_guest'] ?? 0),
            'preview' => (string) ($row['preview'] ?? ''),
            'message_count' => (int) ($row['message_count'] ?? 0),
            'awaiting_human' => !empty($row['awaiting_human']) || ChatArchive::isAwaitingHuman($row),
            'date_updated' => (string) ($row['date_updated'] ?? ''),
            'date_created' => (string) ($row['date_created'] ?? ''),
            'handed_off_at' => (string) ($row['handed_off_at'] ?? ''),
            'archived_at' => (string) ($row['archived_at'] ?? ''),
            'assigned_admin_id' => (int) ($row['assigned_admin_id'] ?? 0),
            'assigned_admin_name' => ChatArchive::assigneeDisplayName((int) ($row['assigned_admin_id'] ?? 0)),
            'assigned_seekmodo_user_id' => (string) ($row['assigned_seekmodo_user_id'] ?? ''),
            'assigned_seekmodo_name' => (string) ($row['assigned_seekmodo_name'] ?? ''),
            'assigned_seekmodo_email' => (string) ($row['assigned_seekmodo_email'] ?? ''),
            'shopper_present' => ChatArchive::isShopperPresent($row),
            'present' => ChatArchive::isShopperPresent($row),
            'initials' => $initials !== '' ? $initials : '?',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function detailRow(array $row, bool $includeInternalNotes): array
    {
        $status = (string) ($row['status'] ?? 'open');
        $isResolved = $status === 'resolved';
        $isArchived = ChatArchive::isArchived($row);
        $assigned = (int) ($row['assigned_admin_id'] ?? 0);
        $who = trim((string) ($row['customer_name'] ?? ''));
        $email = trim((string) ($row['customer_email'] ?? ''));
        $shopperLabel = $who !== '' ? $who : ($email !== '' ? $email : 'Guest');
        $canEmail = ChatArchive::canAdminReply($row) && !$isResolved;

        $messages = [];
        foreach (($row['messages'] ?? []) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $role = (string) ($m['role'] ?? '');
            if ($role === 'agent_note' && !$includeInternalNotes) {
                continue;
            }
            if (!in_array($role, ['user', 'assistant', 'admin', 'agent_note'], true)) {
                continue;
            }
            $adminName = (string) ($m['admin_name'] ?? '');
            $authorLabel = match ($role) {
                'user' => $shopperLabel,
                'assistant' => 'AI',
                'admin' => $adminName !== '' ? ('Agent — ' . $adminName) : 'Agent',
                'agent_note' => $adminName !== '' ? ('Note — ' . $adminName) : 'Note',
                default => $role,
            };
            $messages[] = [
                'role' => $role,
                'content' => (string) ($m['content'] ?? ''),
                'date' => (string) ($m['date'] ?? ''),
                'channel' => (string) ($m['channel'] ?? ''),
                'admin_name' => $adminName,
                'admin_id' => (int) ($m['admin_id'] ?? 0),
                'seq' => (int) ($m['seq'] ?? 0),
                'author_label' => $authorLabel,
            ];
        }

        $out = $this->summaryRow($row);
        $out['shopper_label'] = $shopperLabel;
        $out['email_verified'] = (int) ($row['email_verified'] ?? 0);
        $out['can_email_reply'] = $canEmail;
        $out['messages'] = $messages;
        $out['actions'] = [
            'can_claim' => !$isResolved && $assigned === 0,
            'can_assign' => !$isResolved,
            'can_live_reply' => !$isResolved,
            'can_email_reply' => $canEmail,
            'can_resume_ai' => in_array($status, ['live', 'handed_off'], true),
            'can_resolve' => !$isResolved,
            'can_reopen' => $isResolved,
            'can_archive' => !$isArchived,
            'can_unarchive' => $isArchived,
            'can_delete' => false,
        ];
        return $out;
    }
}
