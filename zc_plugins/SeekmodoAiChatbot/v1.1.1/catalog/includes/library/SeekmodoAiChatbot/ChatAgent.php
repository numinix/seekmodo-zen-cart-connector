<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot;

/**
 * OpenAI tool-calling chat agent.
 *
 * Maintains per-session conversation history and orchestrates the
 * tool-calling loop: user → OpenAI → tool execution → re-prompt → final.
 */
final class ChatAgent
{
    private string $apiKey;
    private string $model;
    private string $systemPrompt;
    private McpClient $mcpClient;
    private KbStore $kbStore;
    private SitemapProductIndex $sitemapIndex;
    private SeekmodoProductSearch $seekmodoSearch;
    private ?SeekmodoTenantChatTools $tenantChatTools = null;
    private int $maxToolRounds;
    private string $kbPath;
    private string $additionalRules;
    /** @var array{logged_in: bool, customers_id: int, email: string, first_name: string, last_name: string} */
    private array $shopper;

    /** @var array<string, list<array{role: string, content: string, tool_calls?: array, tool_call_id?: string}>> */
    private static array $sessions = [];

    /** @var array<string, array{created: int, updated: int, tickets_sent: int, verified_emails?: list<string>, assistant_offline?: bool}> */
    private static array $sessionMeta = [];

    private bool $historyReset = false;
    private string $activeSessionId = '';
    /** @var list<array{role: string, content: string, tool_calls?: array, tool_call_id?: string}> */
    private array $activeMessages = [];

    /**
     * @param array{logged_in?: bool, customers_id?: int, email?: string, first_name?: string, last_name?: string}|null $shopper
     */
    public function __construct(
        string $apiKey,
        string $model,
        string $systemPrompt,
        McpClient $mcpClient,
        KbStore $kbStore,
        SitemapProductIndex $sitemapIndex,
        int $maxToolRounds = 10,
        string $kbPath = '',
        ?SeekmodoProductSearch $seekmodoSearch = null,
        ?array $shopper = null,
        string $additionalRules = ''
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->systemPrompt = self::utf8SafeText($systemPrompt);
        $this->mcpClient = $mcpClient;
        $this->kbStore = $kbStore;
        $this->sitemapIndex = $sitemapIndex;
        $this->maxToolRounds = $maxToolRounds;
        $this->kbPath = $kbPath;
        $this->additionalRules = trim(self::utf8SafeText($additionalRules));
        $this->seekmodoSearch = $seekmodoSearch ?? new SeekmodoProductSearch();
        $this->shopper = [
            'logged_in' => !empty($shopper['logged_in']),
            'customers_id' => (int) ($shopper['customers_id'] ?? 0),
            'email' => strtolower(trim((string) ($shopper['email'] ?? ''))),
            'first_name' => trim((string) ($shopper['first_name'] ?? '')),
            'last_name' => trim((string) ($shopper['last_name'] ?? '')),
        ];
    }

    /**
     * Repair prompt text that was corrupted by latin1 configuration storage
     * (em dashes often become 0x97 / 0x9d and break json_encode for OpenAI).
     */
    private static function utf8SafeText(string $text): string
    {
        if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        $map = [
            "\x97" => ' - ',
            "\x96" => '-',
            "\x93" => '"',
            "\x94" => '"',
            "\x91" => "'",
            "\x92" => "'",
            "\x9d" => ' - ',
        ];
        $text = strtr($text, $map);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if (is_string($converted)) {
                $text = $converted;
            }
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $text) ?? $text;
        }
        return $text;
    }

    /**
     * Process a user message and return the assistant reply.
     *
     * @return array{reply: string, tools_called: list<string>, session_reset: bool, assistant_unavailable?: bool, needs_email?: bool, has_contact?: bool, contact_email?: string}
     */
    public function processMessage(string $sessionId, string $message): array
    {
        $toolsCalled = [];
        $this->historyReset = false;
        $this->activeSessionId = $sessionId;
        $escalationFlags = [];

        // Shopper returned after an email handoff — reopen and keep full history.
        $this->reopenEmailHandoffIfNeeded($sessionId);

        // Unclaimed live handoff timed out — reopen AI and offer continue or ticket.
        $escalationRecovery = self::recoverTimedOutEscalationForSession($sessionId);
        if ($escalationRecovery !== null && !empty($escalationRecovery['recovered'])) {
            $escalationFlags = $escalationRecovery;
            // Ensure session meta is loaded into this instance after recovery stamps.
            $this->loadHistory($sessionId);
        }

        $blocked = $this->blockedHandoffReply($sessionId);
        if ($blocked !== null) {
            return array_merge([
                'reply' => $blocked,
                'tools_called' => [],
                'session_reset' => false,
            ], $escalationFlags);
        }

        $live = $this->handleLiveAgentMessage($sessionId, $message);
        if ($live !== null) {
            return array_merge($live, $escalationFlags);
        }

        // After an OpenAI outage, keep logging without calling the API again.
        if ($this->isAssistantOffline($sessionId)) {
            return $this->continueOfflineMessage($sessionId, $message);
        }

        $messages = $this->loadHistory($sessionId);
        $this->activeMessages = $messages;

        $messages[] = ['role' => 'user', 'content' => $message];
        $this->activeMessages = $messages;

        for ($round = 0; $round < $this->maxToolRounds; $round++) {
            // Human claimed / live-replied while we were generating — stop publishing AI output.
            $suppressed = $this->suppressIfHumanTookOver($sessionId, $message, $toolsCalled);
            if ($suppressed !== null) {
                return array_merge($suppressed, $escalationFlags);
            }

            $response = $this->callOpenAI($messages);
            $choice = $response['choices'][0] ?? null;
            if ($choice === null) {
                throw new ChatbotException('llm_no_choice', ['response' => $response]);
            }

            $assistantMsg = $choice['message'];

            if (!empty($assistantMsg['tool_calls'])) {
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $assistantMsg['content'] ?? '',
                    'tool_calls' => $assistantMsg['tool_calls'],
                ];
                $this->activeMessages = $messages;

                foreach ($assistantMsg['tool_calls'] as $toolCall) {
                    $toolName = $toolCall['function']['name'];
                    $toolsCalled[] = $toolName;
                    $arguments = json_decode($toolCall['function']['arguments'], true) ?? [];

                    try {
                        $result = $this->executeTool($toolName, $arguments);
                    } catch (ChatbotException $e) {
                        $result = ['error' => $e->getMessage(), 'context' => $e->context()];
                    } catch (\Throwable $e) {
                        $result = ['error' => $e->getMessage()];
                    }

                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'],
                        'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    ];
                    $this->activeMessages = $messages;
                }

                continue;
            }

            $suppressed = $this->suppressIfHumanTookOver($sessionId, $message, $toolsCalled);
            if ($suppressed !== null) {
                return array_merge($suppressed, $escalationFlags);
            }

            $replyText = $assistantMsg['content'] ?? '';

            if ($replyText === '' || $replyText === null) {
                $replyText = 'I\'m sorry, I couldn\'t generate a response. Please try rephrasing your question.';
            }

            $messages[] = ['role' => 'assistant', 'content' => $replyText];
            $this->saveHistory($sessionId, $messages);

            return array_merge([
                'reply' => $replyText,
                'tools_called' => array_unique($toolsCalled),
                'session_reset' => $this->historyReset,
            ], $escalationFlags);
        }

        $fallback = 'I\'ve used too many tools to find an answer. Could you please rephrase or ask a simpler question?';
        $suppressed = $this->suppressIfHumanTookOver($sessionId, $message, $toolsCalled);
        if ($suppressed !== null) {
            return array_merge($suppressed, $escalationFlags);
        }
        $messages[] = ['role' => 'assistant', 'content' => $fallback];
        $this->saveHistory($sessionId, $messages);
        return array_merge([
            'reply' => $fallback,
            'tools_called' => array_unique($toolsCalled),
            'session_reset' => $this->historyReset,
        ], $escalationFlags);
    }

    /**
     * Log the shopper message and handoff notice when OpenAI cannot respond.
     * Does not open an admin ticket until the shopper confirms (logged-in) or
     * verifies email (guest).
     *
     * @return array{reply: string, tools_called: list<string>, session_reset: bool, assistant_unavailable: bool, needs_email: bool, needs_ticket_confirm: bool, ticket_opened: bool, has_contact: bool, contact_email: string}
     */
    public function handleAssistantUnavailable(string $sessionId, string $userMessage): array
    {
        $this->historyReset = false;
        $this->activeSessionId = $sessionId;

        $messages = $this->loadHistory($sessionId);
        $this->markAssistantOffline($sessionId, true);
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $flags = $this->offlineHandoffFlags($sessionId);
        $loggedIn = !empty($this->shopper['logged_in']) && trim((string) ($this->shopper['email'] ?? '')) !== '';

        if ($loggedIn) {
            $reply = 'I\'m sorry — our chatbot isn\'t available right now, and no one is immediately available in chat. '
                . 'Would you like us to open a support ticket so an agent can follow up'
                . ($flags['contact_email'] !== '' ? ' at ' . $flags['contact_email'] : '')
                . '?';
        } else {
            $reply = 'I\'m sorry — our chatbot isn\'t available right now, and no one is immediately available in chat. '
                . 'You can open a support ticket for an agent — please leave and verify your email address below.';
        }

        $messages[] = ['role' => 'assistant', 'content' => $reply];
        $this->activeMessages = $messages;
        $this->saveHistory($sessionId, $messages);

        return array_merge([
            'reply' => $reply,
            'tools_called' => [],
            'session_reset' => $this->historyReset,
            'assistant_unavailable' => true,
        ], $flags);
    }

    /**
     * Further messages while the AI remains offline — log without calling OpenAI.
     *
     * @return array{reply: string, tools_called: list<string>, session_reset: bool, assistant_unavailable: bool, needs_email: bool, needs_ticket_confirm: bool, ticket_opened: bool, has_contact: bool, contact_email: string}
     */
    public function continueOfflineMessage(string $sessionId, string $userMessage): array
    {
        $this->historyReset = false;
        $this->activeSessionId = $sessionId;

        $messages = $this->loadHistory($sessionId);
        $this->markAssistantOffline($sessionId, true);
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $flags = $this->offlineHandoffFlags($sessionId);

        if (!empty($flags['ticket_opened'])) {
            $reply = 'Thanks — we\'ve added that to your support ticket. '
                . 'An agent will follow up by email'
                . ($flags['contact_email'] !== '' ? ' at ' . $flags['contact_email'] : '')
                . '.';
        } elseif ($this->isOfflineTicketDeclined($sessionId)) {
            $reply = 'Thanks — we\'ve noted that. We won\'t open a support ticket. '
                . 'You can try the chatbot again later.';
        } elseif (!empty($flags['needs_ticket_confirm'])) {
            $reply = 'Thanks — we\'ve noted that. Please choose Yes or No below if you\'d like us to open a support ticket '
                . 'so an agent can follow up.';
        } else {
            $reply = 'Thanks — we\'ve noted that. Please leave and verify your email below so we can open a support ticket '
                . 'for an agent to reply.';
        }

        $messages[] = ['role' => 'assistant', 'content' => $reply];
        $this->activeMessages = $messages;
        $this->saveHistory($sessionId, $messages);

        return array_merge([
            'reply' => $reply,
            'tools_called' => [],
            'session_reset' => $this->historyReset,
            'assistant_unavailable' => true,
        ], $flags);
    }

    /**
     * Keep the shopper text that never reached the server when the widget lost the network.
     */
    private function rememberPendingShopperMessage(string $sessionId, string $pendingMessage): void
    {
        $pendingMessage = trim($pendingMessage);
        if ($pendingMessage === '') {
            return;
        }
        $messages = self::$sessions[$sessionId] ?? [];
        if (!is_array($messages)) {
            $messages = [];
        }
        $last = '';
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (is_array($messages[$i]) && ($messages[$i]['role'] ?? '') === 'user') {
                $last = (string) ($messages[$i]['content'] ?? '');
                break;
            }
        }
        if ($last === $pendingMessage) {
            return;
        }
        $messages[] = ['role' => 'user', 'content' => $pendingMessage];
        self::$sessions[$sessionId] = $messages;
        $this->activeMessages = $messages;
        $this->saveHistory($sessionId, $messages);
    }

    /**
     * Logged-in shopper confirms opening an admin support ticket while AI is down.
     *
     * @return array<string, mixed>
     */
    public function confirmOfflineTicket(string $sessionId, string $pendingMessage = ''): array
    {
        $this->historyReset = false;
        $this->activeSessionId = $sessionId;
        $this->loadHistory($sessionId);
        $this->rememberPendingShopperMessage($sessionId, $pendingMessage);

        $meta = self::$sessionMeta[$sessionId] ?? [];
        $isEscalationOffer = !empty($meta['escalation_timed_out']) || !empty($meta['needs_ticket_confirm']);

        if (!$isEscalationOffer) {
            $this->markAssistantOffline($sessionId, true);
        }

        if (empty($this->shopper['logged_in']) || trim((string) ($this->shopper['email'] ?? '')) === '') {
            return [
                'ok' => false,
                'error' => 'login_required',
                'message' => 'Please sign in, or leave and verify an email address instead.',
                'assistant_unavailable' => !$isEscalationOffer,
                'needs_email' => true,
            ];
        }

        if (!$isEscalationOffer && $this->isOfflineTicketDeclined($sessionId)) {
            return [
                'ok' => false,
                'error' => 'ticket_declined',
                'message' => 'You already chose not to open a ticket for this chat.',
                'assistant_unavailable' => true,
            ];
        }

        $email = strtolower(trim((string) $this->shopper['email']));
        $name = trim($this->shopper['first_name'] . ' ' . $this->shopper['last_name']);

        if ($isEscalationOffer) {
            $ticket = $this->openSupportTicket([
                'customer_email' => $email,
                'customer_name' => $name !== '' ? $name : 'Chatbot customer',
                'summary' => 'Shopper requested an email support ticket after live escalation timed out.',
                'confirm_email_ticket' => true,
            ]);
            if (empty($ticket['ok'])) {
                return array_merge([
                    'ok' => false,
                    'error' => (string) ($ticket['error'] ?? 'ticket_failed'),
                    'message' => (string) ($ticket['message'] ?? 'Could not open a support ticket.'),
                    'needs_ticket_confirm' => true,
                ], $ticket);
            }
            $reply = 'Thanks — we\'ve opened a support ticket. An agent will follow up by email at ' . $email . '.';
            $messages = self::$sessions[$sessionId] ?? [];
            if (!is_array($messages)) {
                $messages = [];
            }
            $messages[] = ['role' => 'assistant', 'content' => $reply];
            $this->activeMessages = $messages;
            $this->saveHistory($sessionId, $messages);
            return [
                'ok' => true,
                'reply' => $reply,
                'ticket_opened' => true,
                'contact_email' => $email,
                'needs_ticket_confirm' => false,
                'escalation_timed_out' => false,
                'session_reset' => $this->historyReset,
            ];
        }

        $this->markOfflineTicketOpened($sessionId, true);

        $messages = self::$sessions[$sessionId] ?? [];
        if (!is_array($messages)) {
            $messages = [];
        }
        $reply = 'Thanks — we\'ve opened a support ticket. An agent will follow up by email at ' . $email . '.';
        $messages[] = ['role' => 'assistant', 'content' => $reply];
        $this->activeMessages = $messages;
        $this->saveHistory($sessionId, $messages);

        try {
            ChatArchive::attachContact(
                $sessionId,
                $email,
                $name,
                (int) ($this->shopper['customers_id'] ?? 0)
            );
        } catch (\Throwable $e) {
            // best-effort
        }

        $flags = $this->offlineHandoffFlags($sessionId);
        return array_merge([
            'ok' => true,
            'reply' => $reply,
            'assistant_unavailable' => true,
            'session_reset' => $this->historyReset,
        ], $flags);
    }

    /**
     * Logged-in shopper declines opening an admin ticket while AI is down.
     *
     * @return array<string, mixed>
     */
    public function declineOfflineTicket(string $sessionId): array
    {
        $this->historyReset = false;
        $this->activeSessionId = $sessionId;
        $this->loadHistory($sessionId);

        // Escalation timeout CTA: shopper chose to continue with AI (not offline).
        $meta = self::$sessionMeta[$sessionId] ?? [];
        if (!empty($meta['escalation_timed_out']) || !empty($meta['needs_ticket_confirm'])) {
            unset($meta['needs_ticket_confirm']);
            // Keep escalation_timed_out so open_support_ticket still skips live re-entry
            // until a confirmed ticket or a later fresh live request clears it.
            self::$sessionMeta[$sessionId] = $meta;
            $reply = 'No problem — we can keep going here. How else can I help?';
            $messages = self::$sessions[$sessionId] ?? [];
            if (!is_array($messages)) {
                $messages = [];
            }
            $messages[] = ['role' => 'assistant', 'content' => $reply];
            $this->activeMessages = $messages;
            $this->saveHistory($sessionId, $messages);
            return [
                'ok' => true,
                'reply' => $reply,
                'escalation_timed_out' => true,
                'needs_ticket_confirm' => false,
                'session_reset' => $this->historyReset,
            ];
        }

        $this->markAssistantOffline($sessionId, true);

        if ($this->isOfflineTicketOpened($sessionId)) {
            return [
                'ok' => false,
                'error' => 'ticket_already_opened',
                'message' => 'A support ticket was already opened for this chat.',
                'assistant_unavailable' => true,
                'ticket_opened' => true,
            ];
        }

        $this->markOfflineTicketDeclined($sessionId, true);

        $messages = self::$sessions[$sessionId] ?? [];
        if (!is_array($messages)) {
            $messages = [];
        }
        $reply = 'No problem — we won\'t open a support ticket. You can try the chatbot again later, or start a new chat.';
        $messages[] = ['role' => 'assistant', 'content' => $reply];
        $this->activeMessages = $messages;
        $this->saveHistory($sessionId, $messages);

        $flags = $this->offlineHandoffFlags($sessionId);
        return array_merge([
            'ok' => true,
            'reply' => $reply,
            'assistant_unavailable' => true,
            'session_reset' => $this->historyReset,
        ], $flags);
    }

    /**
     * Send an OTP so a guest can leave a verified contact while the AI is down.
     *
     * @return array<string, mixed>
     */
    public function leaveContactSend(string $sessionId, string $email): array
    {
        $this->activeSessionId = $sessionId;
        $this->loadHistory($sessionId);
        return $this->sendEmailVerification(['email' => $email]);
    }

    /**
     * Verify guest OTP and open an admin support ticket with verified email.
     *
     * @return array<string, mixed>
     */
    public function leaveContactVerify(string $sessionId, string $email, string $code): array
    {
        $this->activeSessionId = $sessionId;
        $this->loadHistory($sessionId);
        $this->markAssistantOffline($sessionId, true);
        $result = $this->verifyEmailVerification(['email' => $email, 'code' => $code]);
        if (!empty($result['ok']) && !empty($result['verified'])) {
            $this->markOfflineTicketOpened($sessionId, true);
            $verifiedEmail = strtolower(trim((string) ($result['email'] ?? $email)));
            $messages = self::$sessions[$sessionId] ?? [];
            if (!is_array($messages)) {
                $messages = [];
            }
            $notice = 'Thanks — we\'ve verified ' . $verifiedEmail
                . ' and opened a support ticket. An agent will follow up by email when someone is available.';
            $messages[] = ['role' => 'assistant', 'content' => $notice];
            $this->activeMessages = $messages;
            $this->saveHistory($sessionId, $messages);
            $flags = $this->offlineHandoffFlags($sessionId);
            $result = array_merge($result, $flags, [
                'reply' => $notice,
                'ticket_opened' => true,
                'assistant_unavailable' => true,
            ]);
        }
        return $result;
    }

    /**
     * @return array{has_contact: bool, email: string}
     */
    private function resolvedContactForSession(string $sessionId): array
    {
        if ($this->shopper['logged_in'] && $this->shopper['email'] !== '') {
            return ['has_contact' => true, 'email' => $this->shopper['email']];
        }

        try {
            $archive = ChatArchive::getBySessionId($sessionId);
            if ($archive !== null && ChatArchive::canAdminReply($archive)) {
                return [
                    'has_contact' => true,
                    'email' => strtolower(trim((string) ($archive['customer_email'] ?? ''))),
                ];
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $meta = self::$sessionMeta[$sessionId] ?? [];
        $list = is_array($meta['verified_emails'] ?? null) ? $meta['verified_emails'] : [];
        foreach ($list as $item) {
            $email = strtolower(trim((string) $item));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['has_contact' => true, 'email' => $email];
            }
        }

        return ['has_contact' => false, 'email' => ''];
    }

    /**
     * Widget/API flags for the offline handoff UI.
     *
     * @return array{needs_email: bool, needs_ticket_confirm: bool, ticket_opened: bool, has_contact: bool, contact_email: string}
     */
    private function offlineHandoffFlags(string $sessionId): array
    {
        $contact = $this->resolvedContactForSession($sessionId);
        $ticketOpened = $this->isOfflineTicketOpened($sessionId);
        $declined = $this->isOfflineTicketDeclined($sessionId);
        $loggedIn = !empty($this->shopper['logged_in']) && trim((string) ($this->shopper['email'] ?? '')) !== '';

        $needsTicketConfirm = $loggedIn && !$ticketOpened && !$declined;
        // Guests need email until a verified contact exists (or ticket already opened).
        $needsEmail = !$loggedIn && !$ticketOpened && !$contact['has_contact'];

        return [
            'needs_email' => $needsEmail,
            'needs_ticket_confirm' => $needsTicketConfirm,
            'ticket_opened' => $ticketOpened,
            'has_contact' => $contact['has_contact'] || $loggedIn,
            'contact_email' => $contact['email'],
        ];
    }

    private function isOfflineTicketOpened(string $sessionId): bool
    {
        $meta = self::$sessionMeta[$sessionId] ?? null;
        if (is_array($meta) && !empty($meta['offline_ticket_opened'])) {
            return true;
        }
        $file = $this->sessionFile($sessionId);
        if (!is_file($file)) {
            return false;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return false;
        }
        $data = json_decode($raw, true);
        return is_array($data) && !empty($data['offline_ticket_opened']);
    }

    private function isOfflineTicketDeclined(string $sessionId): bool
    {
        $meta = self::$sessionMeta[$sessionId] ?? null;
        if (is_array($meta) && !empty($meta['offline_ticket_declined'])) {
            return true;
        }
        $file = $this->sessionFile($sessionId);
        if (!is_file($file)) {
            return false;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return false;
        }
        $data = json_decode($raw, true);
        return is_array($data) && !empty($data['offline_ticket_declined']);
    }

    private function markOfflineTicketOpened(string $sessionId, bool $opened): void
    {
        if ($sessionId === '') {
            return;
        }
        $meta = self::$sessionMeta[$sessionId] ?? [
            'created' => time(),
            'updated' => time(),
            'tickets_sent' => 0,
            'verified_emails' => [],
        ];
        $meta['offline_ticket_opened'] = $opened;
        if ($opened) {
            $meta['offline_ticket_declined'] = false;
        }
        self::$sessionMeta[$sessionId] = $meta;
    }

    private function markOfflineTicketDeclined(string $sessionId, bool $declined): void
    {
        if ($sessionId === '') {
            return;
        }
        $meta = self::$sessionMeta[$sessionId] ?? [
            'created' => time(),
            'updated' => time(),
            'tickets_sent' => 0,
            'verified_emails' => [],
        ];
        $meta['offline_ticket_declined'] = $declined;
        self::$sessionMeta[$sessionId] = $meta;
    }

    private function isAssistantOffline(string $sessionId): bool
    {
        $meta = self::$sessionMeta[$sessionId] ?? null;
        if (is_array($meta) && !empty($meta['assistant_offline'])) {
            return true;
        }
        $file = $this->sessionFile($sessionId);
        if (!is_file($file)) {
            return false;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return false;
        }
        $data = json_decode($raw, true);
        return is_array($data) && !empty($data['assistant_offline']);
    }

    private function markAssistantOffline(string $sessionId, bool $offline): void
    {
        if ($sessionId === '') {
            return;
        }
        $meta = self::$sessionMeta[$sessionId] ?? [
            'created' => time(),
            'updated' => time(),
            'tickets_sent' => 0,
            'verified_emails' => [],
        ];
        $meta['assistant_offline'] = $offline;
        self::$sessionMeta[$sessionId] = $meta;
    }

    private function reopenEmailHandoffIfNeeded(string $sessionId): void
    {
        try {
            $archive = ChatArchive::getBySessionId($sessionId);
            if ($archive === null) {
                return;
            }
            if ((string) ($archive['status'] ?? '') !== 'handed_off') {
                return;
            }
            $chatId = (int) ($archive['chat_id'] ?? 0);
            if ($chatId > 0 && ChatArchive::reopenFromEmailHandoff($chatId)) {
                self::stampSessionOpen($sessionId);
            }
        } catch (\Throwable $e) {
        }
    }

    private function blockedHandoffReply(string $sessionId): ?string
    {
        $status = $this->sessionArchiveStatus($sessionId);
        // handed_off is reopened above when the shopper messages again.
        if ($status === 'resolved') {
            return 'This chat has been closed. If you need further help, please start a new chat.';
        }
        return null;
    }

    /**
     * @return array{reply: string, tools_called: list<string>, session_reset: bool, live_agent: bool}|null
     */
    private function handleLiveAgentMessage(string $sessionId, string $message): ?array
    {
        $status = $this->sessionArchiveStatus($sessionId);
        if ($status !== 'live') {
            return null;
        }
        try {
            $archive = ChatArchive::getBySessionId($sessionId);
            if ($archive !== null) {
                ChatArchive::appendLiveShopperMessage((int) $archive['chat_id'], $message);
            }
        } catch (\Throwable $e) {
        }
        return [
            'reply' => '',
            'tools_called' => [],
            'session_reset' => false,
            'live_agent' => true,
            'status' => 'live',
        ];
    }

    /**
     * If a human claimed / live-replied mid-generation, persist the shopper
     * turn without publishing the AI draft.
     *
     * @param list<string> $toolsCalled
     * @return array{reply: string, tools_called: list<string>, session_reset: bool, live_agent: bool, status: string, suppressed?: bool}|null
     */
    private function suppressIfHumanTookOver(string $sessionId, string $userMessage, array $toolsCalled): ?array
    {
        $status = $this->sessionArchiveStatus($sessionId);
        if ($status !== 'live' && $status !== 'handed_off') {
            return null;
        }

        // Drop in-flight tool junk; keep a clean transcript ending on the shopper message.
        $messages = $this->loadHistory($sessionId);
        $last = $messages !== [] ? $messages[count($messages) - 1] : null;
        $lastIsSameUser = is_array($last)
            && ($last['role'] ?? '') === 'user'
            && trim((string) ($last['content'] ?? '')) === trim($userMessage);
        if (!$lastIsSameUser && trim($userMessage) !== '') {
            $messages[] = ['role' => 'user', 'content' => $userMessage];
        }
        $this->activeMessages = $messages;
        try {
            $this->saveHistory($sessionId, $messages);
        } catch (\Throwable $e) {
            // best-effort
        }

        return [
            'reply' => '',
            'tools_called' => array_values(array_unique($toolsCalled)),
            'session_reset' => $this->historyReset,
            'live_agent' => $status === 'live',
            'status' => $status,
            'suppressed' => true,
        ];
    }

    private function sessionArchiveStatus(string $sessionId): ?string
    {
        try {
            $archive = ChatArchive::getBySessionId($sessionId);
            if ($archive !== null) {
                $status = (string) ($archive['status'] ?? 'open');
                if (in_array($status, ['live', 'handed_off', 'resolved'], true)) {
                    return $status;
                }
            }
        } catch (\Throwable $e) {
        }

        $file = $this->sessionFile($sessionId);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        $meta = $data['meta'] ?? [];
        if (!is_array($meta)) {
            return null;
        }
        if (($meta['status'] ?? '') === 'live' || !empty($meta['live_agent'])) {
            return 'live';
        }
        if (!empty($meta['handed_off']) || (($meta['status'] ?? '') === 'handed_off')) {
            return 'handed_off';
        }
        if (($meta['status'] ?? '') === 'resolved') {
            return 'resolved';
        }
        return null;
    }

    public static function stampSessionHandedOff(string $sessionId): void
    {
        self::stampSessionMeta($sessionId, [
            'handed_off' => true,
            'live_agent' => false,
            'status' => 'handed_off',
        ]);
    }

    public static function stampSessionLive(string $sessionId, int $adminId = 0): void
    {
        self::stampSessionMeta($sessionId, [
            'handed_off' => false,
            'live_agent' => true,
            'status' => 'live',
            'assigned_admin_id' => $adminId,
        ]);
    }

    public static function stampSessionOpen(string $sessionId): void
    {
        self::stampSessionMeta($sessionId, [
            'handed_off' => false,
            'live_agent' => false,
            'status' => 'open',
            'assigned_admin_id' => 0,
        ]);
    }

    /**
     * If an unclaimed live handoff has waited longer than the configured
     * escalation timeout, reopen AI, stamp recovery meta, and append a
     * customer-visible continue-or-ticket notice.
     *
     * @return array<string, mixed>|null Recovery payload, or null when no timeout.
     */
    public static function recoverTimedOutEscalationForSession(string $sessionId): ?array
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return null;
        }
        try {
            $archive = ChatArchive::getBySessionId($sessionId);
        } catch (\Throwable $e) {
            return null;
        }
        if ($archive === null || !ChatArchive::isLiveEscalationTimedOut($archive)) {
            return null;
        }
        $chatId = (int) ($archive['chat_id'] ?? 0);
        if ($chatId < 1) {
            return null;
        }

        $notice = 'Support wasn\'t able to join the live chat in time. '
            . 'You can keep chatting with me, or open a support ticket '
            . 'so an agent can follow up by email.';

        try {
            ChatArchive::setStatus($chatId, 'open', 0);
            ChatArchive::appendAssistantMessage($chatId, $notice, [
                'status' => 'open',
                'channel' => 'system',
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        self::stampSessionMeta($sessionId, [
            'handed_off' => false,
            'live_agent' => false,
            'status' => 'open',
            'assigned_admin_id' => 0,
            'escalation_timed_out' => true,
            'needs_ticket_confirm' => true,
        ]);

        // Also append into the session transcript so the widget poll merges it.
        try {
            $dir = self::sessionsDirectory();
            $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId) ?: '';
            if ($safe !== '' && is_dir($dir)) {
                $file = $dir . '/' . $safe . '.json';
                $messages = [];
                $meta = [
                    'created' => time(),
                    'updated' => time(),
                    'tickets_sent' => 0,
                    'verified_emails' => [],
                ];
                if (is_file($file)) {
                    $raw = @file_get_contents($file);
                    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
                    if (is_array($data)) {
                        $messages = is_array($data['messages'] ?? null) ? $data['messages'] : [];
                        if (is_array($data['meta'] ?? null)) {
                            $meta = array_merge($meta, $data['meta']);
                        }
                    }
                }
                $messages[] = ['role' => 'assistant', 'content' => $notice];
                $meta['escalation_timed_out'] = true;
                $meta['needs_ticket_confirm'] = true;
                unset($meta['force_email_ticket']);
                $meta['handed_off'] = false;
                $meta['live_agent'] = false;
                $meta['status'] = 'open';
                $meta['assigned_admin_id'] = 0;
                $meta['updated'] = time();
                self::$sessions[$sessionId] = $messages;
                self::$sessionMeta[$sessionId] = $meta;
                @file_put_contents($file, json_encode([
                    'messages' => $messages,
                    'meta' => $meta,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
            }
        } catch (\Throwable $e) {
            // Archive notice is enough if session file write fails.
        }

        $loggedIn = false;
        $email = strtolower(trim((string) ($archive['customer_email'] ?? '')));
        if ((int) ($archive['customers_id'] ?? 0) > 0 && empty($archive['is_guest'])) {
            $loggedIn = true;
        }

        return [
            'recovered' => true,
            'escalation_timed_out' => true,
            'needs_ticket_confirm' => $loggedIn || $email !== '',
            'needs_email' => !$loggedIn && $email === '',
            'status' => 'open',
            'recovery_message' => $notice,
            'contact_email' => $email,
        ];
    }

    /**
     * Build a ChatAgent from store configuration (admin / Inbox resume path).
     *
     * @param array{logged_in?: bool, customers_id?: int, email?: string, first_name?: string, last_name?: string}|null $shopper
     */
    public static function fromStoreConfig(?array $shopper = null): self
    {
        $apiKey = defined('SEEKMODO_AI_CHATBOT_API_KEY') ? (string) SEEKMODO_AI_CHATBOT_API_KEY : '';
        $model = defined('SEEKMODO_AI_CHATBOT_MODEL') ? (string) SEEKMODO_AI_CHATBOT_MODEL : 'gpt-4o-mini';
        $mcpUrl = defined('SEEKMODO_AI_CHATBOT_MCP_URL') ? (string) SEEKMODO_AI_CHATBOT_MCP_URL : '';
        $mcpToken = defined('SEEKMODO_AI_CHATBOT_MCP_TOKEN') ? (string) SEEKMODO_AI_CHATBOT_MCP_TOKEN : '';
        $systemPrompt = defined('SEEKMODO_AI_CHATBOT_SYSTEM_PROMPT') ? (string) SEEKMODO_AI_CHATBOT_SYSTEM_PROMPT : '';
        $additionalRules = defined('SEEKMODO_AI_CHATBOT_ADDITIONAL_RULES') ? (string) SEEKMODO_AI_CHATBOT_ADDITIONAL_RULES : '';
        $maxToolRounds = defined('SEEKMODO_AI_CHATBOT_MAX_TOOL_ROUNDS') ? (int) SEEKMODO_AI_CHATBOT_MAX_TOOL_ROUNDS : 10;
        $kbPath = defined('SEEKMODO_AI_CHATBOT_KB_PATH') ? (string) SEEKMODO_AI_CHATBOT_KB_PATH : '';
        $mcpTimeout = defined('SEEKMODO_AI_CHATBOT_MCP_TIMEOUT')
            ? (int) SEEKMODO_AI_CHATBOT_MCP_TIMEOUT
            : 120;

        $mcpClient = new McpClient($mcpUrl, $mcpToken, $mcpTimeout);
        $kbStore = new KbStore($kbPath !== '' ? $kbPath : null);
        $sitemapIndex = new SitemapProductIndex($kbPath !== '' ? $kbPath : null);

        return new self(
            $apiKey !== '' ? $apiKey : 'unavailable',
            $model,
            $systemPrompt,
            $mcpClient,
            $kbStore,
            $sitemapIndex,
            $maxToolRounds,
            $kbPath,
            null,
            $shopper,
            $additionalRules
        );
    }

    /**
     * After Resume AI: if the transcript ends on a shopper message, generate
     * the assistant reply without waiting for another shopper send.
     *
     * @param array{logged_in?: bool, customers_id?: int, email?: string, first_name?: string, last_name?: string}|null $shopper
     * @return array{ok: bool, skipped?: bool, reason?: string, reply?: string, tools_called?: list<string>}
     */
    public static function continueAfterResume(string $sessionId, ?array $shopper = null): array
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId) ?: '';
        if ($safe === '') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'invalid_session'];
        }
        try {
            $agent = self::fromStoreConfig($shopper);
            return $agent->continuePendingUserTurn($safe);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'skipped' => true,
                'reason' => 'continue_failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Run the tool-calling loop against existing history when the last turn
     * is still awaiting an assistant reply (no duplicate user message).
     *
     * @return array{ok: bool, skipped?: bool, reason?: string, reply?: string, tools_called?: list<string>, session_reset?: bool}
     */
    public function continuePendingUserTurn(string $sessionId): array
    {
        $toolsCalled = [];
        $this->historyReset = false;
        $this->activeSessionId = $sessionId;

        if ($this->isAssistantOffline($sessionId)) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'assistant_offline'];
        }

        $status = $this->sessionArchiveStatus($sessionId);
        if ($status === 'live' || $status === 'handed_off' || $status === 'resolved') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'not_open'];
        }

        $messages = $this->loadHistory($sessionId);
        $this->activeMessages = $messages;
        if ($messages === []) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'empty_history'];
        }

        $lastRole = '';
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $role = (string) ($messages[$i]['role'] ?? '');
            if ($role === 'tool') {
                continue;
            }
            $lastRole = $role;
            break;
        }
        if ($lastRole !== 'user') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'no_pending_user_message'];
        }

        for ($round = 0; $round < $this->maxToolRounds; $round++) {
            $liveStatus = $this->sessionArchiveStatus($sessionId);
            if ($liveStatus === 'live' || $liveStatus === 'handed_off') {
                return ['ok' => false, 'skipped' => true, 'reason' => 'human_took_over'];
            }

            $response = $this->callOpenAI($messages);
            $choice = $response['choices'][0] ?? null;
            if ($choice === null) {
                throw new ChatbotException('llm_no_choice', ['response' => $response]);
            }

            $assistantMsg = $choice['message'];

            if (!empty($assistantMsg['tool_calls'])) {
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $assistantMsg['content'] ?? '',
                    'tool_calls' => $assistantMsg['tool_calls'],
                ];
                $this->activeMessages = $messages;

                foreach ($assistantMsg['tool_calls'] as $toolCall) {
                    $toolName = $toolCall['function']['name'];
                    $toolsCalled[] = $toolName;
                    $arguments = json_decode($toolCall['function']['arguments'], true) ?? [];

                    try {
                        $result = $this->executeTool($toolName, $arguments);
                    } catch (ChatbotException $e) {
                        $result = ['error' => $e->getMessage(), 'context' => $e->context()];
                    } catch (\Throwable $e) {
                        $result = ['error' => $e->getMessage()];
                    }

                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'],
                        'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    ];
                    $this->activeMessages = $messages;
                }

                continue;
            }

            $liveStatus = $this->sessionArchiveStatus($sessionId);
            if ($liveStatus === 'live' || $liveStatus === 'handed_off') {
                return ['ok' => false, 'skipped' => true, 'reason' => 'human_took_over'];
            }

            $replyText = $assistantMsg['content'] ?? '';
            if ($replyText === '' || $replyText === null) {
                $replyText = 'I\'m sorry, I couldn\'t generate a response. Please try rephrasing your question.';
            }

            $messages[] = ['role' => 'assistant', 'content' => $replyText];
            $this->saveHistory($sessionId, $messages);

            return [
                'ok' => true,
                'reply' => $replyText,
                'tools_called' => array_unique($toolsCalled),
                'session_reset' => $this->historyReset,
            ];
        }

        $liveStatus = $this->sessionArchiveStatus($sessionId);
        if ($liveStatus === 'live' || $liveStatus === 'handed_off') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'human_took_over'];
        }

        $fallback = 'I\'ve used too many tools to find an answer. Could you please rephrase or ask a simpler question?';
        $messages[] = ['role' => 'assistant', 'content' => $fallback];
        $this->saveHistory($sessionId, $messages);
        return [
            'ok' => true,
            'reply' => $fallback,
            'tools_called' => array_unique($toolsCalled),
            'session_reset' => $this->historyReset,
        ];
    }

    /**
     * Rebuild the LLM session transcript from the public archive (includes live admin replies).
     * Call after Resume AI so the model can see what the human agent said.
     */
    public static function syncSessionFromArchive(string $sessionId): void
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId) ?: '';
        if ($safe === '') {
            return;
        }
        try {
            $archive = ChatArchive::getBySessionId($safe);
        } catch (\Throwable $e) {
            return;
        }
        if ($archive === null) {
            return;
        }
        $public = ChatArchive::publicMessages($archive);
        $llm = [];
        foreach ($public as $m) {
            if (!is_array($m)) {
                continue;
            }
            $role = (string) ($m['role'] ?? '');
            $content = trim((string) ($m['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            if ($role === 'user') {
                $llm[] = ['role' => 'user', 'content' => $content];
            } elseif ($role === 'assistant') {
                $llm[] = ['role' => 'assistant', 'content' => $content];
            } elseif ($role === 'admin') {
                $who = trim((string) ($m['admin_name'] ?? ''));
                $prefix = $who !== '' ? ('[Human agent — ' . $who . ']: ') : '[Human agent]: ';
                $llm[] = ['role' => 'assistant', 'content' => $prefix . $content];
            }
        }

        $file = self::sessionsDirectory() . '/' . $safe . '.json';
        $now = time();
        $meta = [
            'created' => $now,
            'updated' => $now,
            'tickets_sent' => 0,
            'verified_emails' => [],
        ];
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            if (is_array($data)) {
                $meta['created'] = (int) ($data['created'] ?? $now) ?: $now;
                $meta['tickets_sent'] = (int) ($data['tickets_sent'] ?? 0);
                $verified = $data['verified_emails'] ?? [];
                $meta['verified_emails'] = is_array($verified) ? array_values($verified) : [];
                foreach (['assistant_offline', 'offline_ticket_opened', 'offline_ticket_declined', 'handed_off', 'live_agent', 'status', 'assigned_admin_id'] as $k) {
                    if (array_key_exists($k, $data)) {
                        $meta[$k] = $data[$k];
                    }
                    if (isset($data['meta']) && is_array($data['meta']) && array_key_exists($k, $data['meta'])) {
                        $meta[$k] = $data['meta'][$k];
                    }
                }
            }
        }
        $meta['updated'] = $now;
        $meta['handed_off'] = false;
        $meta['live_agent'] = false;
        $meta['status'] = 'open';
        $meta['assigned_admin_id'] = 0;

        $payload = [
            'created' => (int) $meta['created'],
            'updated' => (int) $meta['updated'],
            'tickets_sent' => (int) ($meta['tickets_sent'] ?? 0),
            'verified_emails' => array_values(is_array($meta['verified_emails'] ?? null) ? $meta['verified_emails'] : []),
            'assistant_offline' => !empty($meta['assistant_offline']),
            'offline_ticket_opened' => !empty($meta['offline_ticket_opened']),
            'offline_ticket_declined' => !empty($meta['offline_ticket_declined']),
            'meta' => [
                'handed_off' => false,
                'live_agent' => false,
                'status' => 'open',
                'assigned_admin_id' => 0,
            ],
            'messages' => $llm,
        ];
        @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE), LOCK_EX);
        self::$sessions[$safe] = $llm;
        self::$sessionMeta[$safe] = array_merge($meta, $payload['meta']);
    }

    /**
     * Append a live admin reply into the LLM session file (so Resume AI keeps context).
     */
    public static function appendLiveAdminToSession(string $sessionId, string $content, string $adminName = ''): void
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId) ?: '';
        $content = trim($content);
        if ($safe === '' || $content === '') {
            return;
        }
        $prefix = $adminName !== '' ? ('[Human agent — ' . $adminName . ']: ') : '[Human agent]: ';
        $file = self::sessionsDirectory() . '/' . $safe . '.json';
        $now = time();
        $data = [
            'created' => $now,
            'updated' => $now,
            'tickets_sent' => 0,
            'verified_emails' => [],
            'messages' => [],
            'meta' => ['status' => 'live', 'live_agent' => true],
        ];
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $data = array_merge($data, $decoded);
            }
        }
        if (!isset($data['messages']) || !is_array($data['messages'])) {
            $data['messages'] = [];
        }
        $data['messages'][] = ['role' => 'assistant', 'content' => $prefix . $content];
        $data['updated'] = $now;
        if (!isset($data['meta']) || !is_array($data['meta'])) {
            $data['meta'] = [];
        }
        $data['meta']['live_agent'] = true;
        $data['meta']['status'] = 'live';
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    public static function stampSessionResolved(string $sessionId): void
    {
        self::stampSessionMeta($sessionId, [
            'handed_off' => false,
            'live_agent' => false,
            'status' => 'resolved',
        ]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function stampSessionMeta(string $sessionId, array $fields): void
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId) ?: '';
        if ($safe === '') {
            return;
        }
        $file = self::sessionsDirectory() . '/' . $safe . '.json';
        if (!is_file($file)) {
            return;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return;
        }
        if (!isset($data['meta']) || !is_array($data['meta'])) {
            $data['meta'] = [];
        }
        foreach ($fields as $k => $v) {
            $data['meta'][$k] = $v;
        }
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    /**
     * Queue a chat for post-chat analysis (see PostChatAnalyzer). Called
     * right after a chat resolves/closes, from both the shopper-facing
     * resolve_chat endpoint and the admin "Mark Resolved" action. Enqueuing
     * only writes a lightweight job row via MCP — the actual LLM analysis
     * pass runs later, out of band, from the nightly cron runner (see
     * tools/post_chat_analysis.php) so resolving a chat is never slowed
     * down by an extra model call. Best-effort: failures are swallowed so
     * they never block the shopper/admin action that triggered this.
     */
    public static function enqueuePostChatAnalysis(int $chatId, string $source = 'resolve'): void
    {
        if ($chatId < 1 || !defined('SEEKMODO_AI_CHATBOT_POST_CHAT_ANALYSIS_ENABLED') || SEEKMODO_AI_CHATBOT_POST_CHAT_ANALYSIS_ENABLED !== 'true') {
            return;
        }
        $mcpUrl = defined('SEEKMODO_AI_CHATBOT_MCP_URL') ? (string) SEEKMODO_AI_CHATBOT_MCP_URL : '';
        $mcpToken = defined('SEEKMODO_AI_CHATBOT_MCP_TOKEN') ? (string) SEEKMODO_AI_CHATBOT_MCP_TOKEN : '';
        if ($mcpUrl === '' || $mcpToken === '') {
            return;
        }
        try {
            $client = new McpClient($mcpUrl, $mcpToken, 3);
            $client->callTool('analysis.job.enqueue', ['chat_id' => $chatId, 'source' => $source]);
        } catch (\Throwable $e) {
            // Best-effort — nightly cron also enqueues recently resolved
            // chats (ChatArchive::listResolvedForAnalysis) and requeues
            // jobs that previously landed in 'error', so a transient MCP
            // failure here does not permanently drop the transcript.
        }
    }

    public static function idleSeconds(): int
    {
        $minutes = defined('SEEKMODO_AI_CHATBOT_SESSION_IDLE_MINUTES')
            ? (int) SEEKMODO_AI_CHATBOT_SESSION_IDLE_MINUTES
            : 30;
        return max(5, min(1440, $minutes)) * 60;
    }

    public static function maxAgeSeconds(): int
    {
        $hours = defined('SEEKMODO_AI_CHATBOT_SESSION_MAX_AGE_HOURS')
            ? (int) SEEKMODO_AI_CHATBOT_SESSION_MAX_AGE_HOURS
            : 24;
        return max(1, min(168, $hours)) * 3600;
    }

    public static function maxHistoryMessages(): int
    {
        $max = defined('SEEKMODO_AI_CHATBOT_MAX_HISTORY')
            ? (int) SEEKMODO_AI_CHATBOT_MAX_HISTORY
            : 24;
        return max(8, min(80, $max));
    }

    public static function supportEmail(): string
    {
        $mail = MailConfig::address();
        if ($mail !== '') {
            return $mail;
        }
        return defined('SEEKMODO_AI_CHATBOT_SUPPORT_EMAIL')
            ? trim((string) SEEKMODO_AI_CHATBOT_SUPPORT_EMAIL)
            : '';
    }

    /**
     * Storefront Contact Us URL (Zen Cart standard page).
     */
    public static function contactUsUrl(): string
    {
        if (!function_exists('zen_href_link')) {
            return '';
        }
        $page = defined('FILENAME_CONTACT_US') ? FILENAME_CONTACT_US : 'contact_us';
        $url = (string) zen_href_link($page, '', 'SSL');
        return trim($url);
    }

    /**
     * Delete abandoned session files. Returns number of files removed.
     */
    public static function gcExpiredSessions(): int
    {
        $dir = self::sessionsDirectory();
        if (!is_dir($dir)) {
            return 0;
        }
        $idle = self::idleSeconds();
        $maxAge = self::maxAgeSeconds();
        $now = time();
        $removed = 0;
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false || $raw === '') {
                @unlink($file);
                $removed++;
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                @unlink($file);
                $removed++;
                continue;
            }
            $updated = (int) ($data['updated'] ?? 0);
            $created = (int) ($data['created'] ?? $updated);
            $expired = ($updated > 0 && ($now - $updated) > $idle)
                || ($created > 0 && ($now - $created) > $maxAge);
            if ($expired) {
                @unlink($file);
                $removed++;
            }
        }
        return $removed;
    }

    public static function sessionsDirectory(): string
    {
        $cacheDir = defined('DIR_FS_CATALOG')
            ? DIR_FS_CATALOG . 'cache/seekmodo_ai_chatbot_sessions'
            : sys_get_temp_dir() . '/seekmodo_ai_chatbot_sessions';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        return $cacheDir;
    }

    /**
     * @param list<array{role: string, content: string, tool_calls?: array, tool_call_id?: string}> $messages
     * @return array<string, mixed>
     */
    private function callOpenAI(array $messages): array
    {
        $systemMessage = ['role' => 'system', 'content' => $this->effectiveSystemPrompt()];
        $apiMessages = array_merge([$systemMessage], $this->cleanMessages($messages));
        $tools = $this->buildToolDefinitions();
        $client = \SeekmodoAiChatbot\Llm\LlmClient::fromConfig();
        return $client->chat($apiMessages, $tools, [
            'temperature' => 0.3,
            'max_tokens' => 2048,
        ]);
    }
    /**
     * Execute a tool by name with given arguments.
     *
     * @return array<string, mixed>
     */
    private function tenantChatTools(): SeekmodoTenantChatTools
    {
        return $this->tenantChatTools ??= new SeekmodoTenantChatTools($this->seekmodoSearch);
    }

    private function executeTool(string $toolName, array $arguments): array
    {
        if (!self::orderMutationsEnabled() && self::isOrderMutationTool($toolName)) {
            return [
                'ok' => false,
                'error' => 'order_mutations_disabled',
                'message' => 'In-chat order changes are disabled. Use mcp_order_lookup and share self-service links (personalise_url / My Account) or open a support ticket.',
            ];
        }

        if (str_starts_with($toolName, 'seekmodo_x_')) {
            return $this->tenantChatTools()->call($toolName, $arguments);
        }

        switch ($toolName) {
            case 'kb_search':
                $query = $arguments['query'] ?? '';
                if ($query === '') {
                    return ['error' => 'query is required'];
                }
                $results = $this->kbStore->search($query);
                return [
                    'results' => $results,
                    'total' => count($results),
                ];

            case 'seekmodo_product_search':
                $query = (string) ($arguments['query'] ?? '');
                $limit = isset($arguments['limit']) ? (int) $arguments['limit'] : 8;
                $filterBy = !empty($arguments['photo_upload_only']) ? 'supports_photo_upload:=true' : null;
                $seekmodo = $this->seekmodoSearch->search($query, $limit, $filterBy);
                $seekmodoCategories = [];
                if (!empty($seekmodo['categories']) && is_array($seekmodo['categories'])) {
                    $seekmodoCategories = $seekmodo['categories'];
                }
                if (!empty($seekmodo['products']) && is_array($seekmodo['products'])) {
                    return $seekmodo;
                }
                // Categories alone are still a useful answer (Tawk-style).
                if ($seekmodoCategories !== [] && empty($seekmodo['empty'])) {
                    return $seekmodo;
                }
                // Hard fallback when Seekmodo is unavailable or the paired
                // connector catalog is empty (common on dig/staging).
                try {
                    $mcpArgs = ['query' => $query];
                    if ($limit > 0) {
                        $mcpArgs['limit'] = $limit;
                    }
                    $mcp = $this->mcpClient->callTool('product.search', $mcpArgs);
                } catch (\Throwable $e) {
                    $seekmodo['mcp_fallback_error'] = $e->getMessage();
                    return $seekmodo;
                }
                $mcpProducts = [];
                $rows = [];
                if (is_array($mcp)) {
                    if (isset($mcp['products']) && is_array($mcp['products'])) {
                        $rows = $mcp['products'];
                    } elseif (isset($mcp['results']) && is_array($mcp['results'])) {
                        $rows = $mcp['results'];
                    }
                }
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $pid = (int) ($row['products_id'] ?? $row['id'] ?? 0);
                    $name = trim((string) ($row['name'] ?? $row['products_name'] ?? ''));
                    if ($pid <= 0 || $name === '') {
                        continue;
                    }
                    $mcpProducts[] = [
                        'products_id' => $pid,
                        'name' => $name,
                        'model' => (string) ($row['model'] ?? $row['products_model'] ?? ''),
                        'price' => $row['price'] ?? $row['products_price'] ?? null,
                        'currency' => (string) ($row['currency'] ?? 'GBP'),
                        'url' => (string) ($row['url'] ?? $row['products_url'] ?? ''),
                        'image_url' => (string) ($row['image_url'] ?? $row['products_image'] ?? ''),
                        'description' => trim(strip_tags((string) ($row['description'] ?? $row['products_description'] ?? ''))),
                    ];
                    if (count($mcpProducts) >= $limit) {
                        break;
                    }
                }
                if ($mcpProducts === []) {
                    $seekmodo['mcp_fallback'] = 'empty';
                    return $seekmodo;
                }
                return [
                    'available' => true,
                    'source' => 'mcp_product_search_fallback',
                    'query' => $query,
                    'found' => count($mcpProducts),
                    'products' => $mcpProducts,
                    'categories' => $seekmodoCategories,
                    'seekmodo' => [
                        'available' => $seekmodo['available'] ?? false,
                        'error' => $seekmodo['error'] ?? null,
                        'found' => $seekmodo['found'] ?? 0,
                    ],
                ];

            case 'sitemap_find_product':
                $query = $arguments['query'] ?? '';
                if ($query === '') {
                    return ['error' => 'query is required'];
                }
                $results = $this->sitemapIndex->search($query);
                return [
                    'results' => $results,
                    'total' => count($results),
                ];

            case 'mcp_product_get':
                $pid = (int) ($arguments['products_id'] ?? 0);
                if ($pid <= 0) {
                    return ['error' => 'valid products_id is required'];
                }
                return $this->mcpClient->callTool('product.get', ['products_id' => $pid]);

            case 'mcp_product_search':
                return $this->mcpClient->callTool('product.search', $arguments);

            case 'mcp_product_resolve':
                return $this->mcpClient->callTool('product.resolve', $arguments);

            case 'mcp_order_lookup':
                if (!$this->shopper['logged_in'] || $this->shopper['email'] === '') {
                    return [
                        'ok' => false,
                        'error' => 'login_required',
                        'message' => 'Private order details are only available when the shopper is logged in to their store account.',
                    ];
                }
                $ordersId = (int) ($arguments['orders_id'] ?? 0);
                if ($ordersId <= 0) {
                    return ['ok' => false, 'error' => 'orders_id is required'];
                }
                // Never trust a client-supplied email — use the logged-in session only.
                $email = $this->shopper['email'];
                try {
                    $verify = $this->mcpClient->callTool('kip.order.verify_customer', [
                        'orders_id' => $ordersId,
                        'customer_email' => $email,
                    ]);
                    $lines = $this->mcpClient->callTool('kip.order.lines.list', [
                        'orders_id' => $ordersId,
                        'customer_email' => $email,
                    ]);
                } catch (ChatbotException $e) {
                    try {
                        $generic = $this->mcpClient->callTool('order.get', [
                            'orders_id' => $ordersId,
                            'customer_email' => $email,
                        ]);
                        return [
                            'ok' => true,
                            'verify' => is_array($generic) ? $generic : [],
                            'lines' => ['lines' => is_array($generic['products'] ?? null) ? $generic['products'] : []],
                            'source' => 'order.get',
                            'message' => 'Order loaded via generic MCP (store-specific order extensions not installed).',
                        ];
                    } catch (ChatbotException $e2) {
                        return [
                            'ok' => false,
                            'error' => 'order_not_found_for_account',
                            'message' => 'Could not find that order on the logged-in account.',
                        ];
                    }
                }
                $safeLines = $lines;
                if (isset($lines['lines']) && is_array($lines['lines'])) {
                    $safeLines = $lines;
                    $engravingEditable = !empty($verify['engraving_editable']);
                    $helper = defined('DIR_FS_CATALOG')
                        ? DIR_FS_CATALOG . 'includes/library/kip_account_personalise.php'
                        : '';
                    if ($helper !== '' && is_file($helper)) {
                        require_once $helper;
                    }
                    $safeLines['lines'] = array_map(
                        static function ($line) use ($engravingEditable, $ordersId) {
                            if (!is_array($line)) {
                                return $line;
                            }
                            unset($line['customily_meta'], $line['production_url']);
                            $opId = (int) ($line['orders_products_id'] ?? 0);
                            $hasEngraving = !empty($line['engravings']) && is_array($line['engravings']);
                            $personaliseUrl = '';
                            if ($engravingEditable && $hasEngraving && $opId > 0 && $ordersId > 0) {
                                if (function_exists('kip_account_personalise_url')) {
                                    $personaliseUrl = kip_account_personalise_url($ordersId, $opId);
                                } elseif (function_exists('zen_href_link')) {
                                    $personaliseUrl = str_replace('&amp;', '&', (string) zen_href_link(
                                        'account_order_personalise',
                                        'order_id=' . $ordersId . '&orders_products_id=' . $opId,
                                        'SSL'
                                    ));
                                }
                            }
                            if ($personaliseUrl !== '') {
                                $line['personalise_url'] = $personaliseUrl;
                            }
                            return $line;
                        },
                        $lines['lines']
                    );
                }
                return [
                    'ok' => true,
                    'verify' => $this->publicOrderVerify($verify),
                    'lines' => $safeLines,
                ];

            case 'mcp_order_engraving_update':
                if (!$this->shopper['logged_in'] || $this->shopper['email'] === '') {
                    return [
                        'ok' => false,
                        'error' => 'login_required',
                        'message' => 'Engraving changes require the shopper to be logged in.',
                    ];
                }
                $ordersId = (int) ($arguments['orders_id'] ?? 0);
                $oldText = trim((string) ($arguments['old_text'] ?? ''));
                $newText = trim((string) ($arguments['new_text'] ?? ''));
                if ($ordersId <= 0 || $oldText === '' || $newText === '') {
                    return [
                        'ok' => false,
                        'error' => 'invalid_params',
                        'message' => 'orders_id, old_text, and new_text are required.',
                    ];
                }
                $payload = [
                    'orders_id' => $ordersId,
                    'customer_email' => $this->shopper['email'],
                    'old_text' => $oldText,
                    'new_text' => $newText,
                    'regenerate_pdf' => true,
                ];
                if (!empty($arguments['product_name_contains'])) {
                    $payload['product_name_contains'] = (string) $arguments['product_name_contains'];
                }
                if (!empty($arguments['orders_products_id'])) {
                    $payload['orders_products_id'] = (int) $arguments['orders_products_id'];
                }
                try {
                    $result = $this->mcpClient->callTool('kip.order.engraving.update', $payload);
                } catch (ChatbotException $e) {
                    $ctx = $e->context();
                    $curlError = strtolower((string) ($ctx['curl_error'] ?? ''));
                    $timedOut = $e->getMessage() === 'mcp_request_failed'
                        && (str_contains($curlError, 'timed out') || str_contains($curlError, 'timeout'));
                    return [
                        'ok' => false,
                        'error' => $timedOut ? 'engraving_update_timeout' : 'engraving_update_failed',
                        'message' => $timedOut
                            ? 'The engraving update is taking longer than expected (Customily PDF re-export). Ask the shopper to wait a moment, then look up the order again before opening a ticket — the change may still have completed.'
                            : 'Could not update the engraving on this order.',
                        'detail' => [
                            'exception' => $e->getMessage(),
                            'curl_error' => (string) ($ctx['curl_error'] ?? ''),
                        ],
                    ];
                }
                if (!empty($result['error'])) {
                    $detail = is_array($result['detail'] ?? null) ? $result['detail'] : [];
                    $errorName = (string) $result['error'];
                    if ($errorName === 'order_not_editable') {
                        $statusName = (string) ($detail['orders_status_name'] ?? 'already processed');
                        return [
                            'ok' => false,
                            'error' => 'order_not_editable',
                            'orders_status_name' => $statusName,
                            'message' => (string) ($detail['message'] ?? (
                                'This order cannot be modified because it is already ' . $statusName . '.'
                            )),
                        ];
                    }
                    if ($errorName === 'engraving_too_long') {
                        $max = (int) ($detail['max_length'] ?? 0);
                        $actual = (int) ($detail['actual_length'] ?? 0);
                        return [
                            'ok' => false,
                            'error' => 'engraving_too_long',
                            'max_length' => $max,
                            'actual_length' => $actual,
                            'message' => $max > 0
                                ? ('That personalisation text is too long for this field (max ' . $max . ' characters'
                                    . ($actual > 0 ? ', got ' . $actual : '')
                                    . '). Ask the shopper for a shorter version.')
                                : 'That personalisation text is too long for this field. Ask the shopper for a shorter version.',
                            'detail' => $detail,
                        ];
                    }
                    return [
                        'ok' => false,
                        'error' => $errorName,
                        'message' => 'Could not update the engraving on this order.',
                        'detail' => $detail,
                    ];
                }
                $pdfRegen = is_array($result['pdf_regeneration'] ?? null) ? $result['pdf_regeneration'] : null;
                if (is_array($pdfRegen) && array_key_exists('ok', $pdfRegen) && empty($pdfRegen['ok'])) {
                    return [
                        'ok' => false,
                        'error' => 'pdf_regeneration_failed',
                        'message' => 'The engraving text was updated, but the production PDF could not be regenerated. A team member must regenerate the PDF before dispatch.',
                        'result' => $this->publicEngravingUpdateResult($result),
                    ];
                }
                return ['ok' => true, 'result' => $this->publicEngravingUpdateResult($result), 'message' => 'Engraving updated successfully. Confirm the new text to the shopper. If result.pdf_url is present, share that exact Markdown link.'];

            case 'mcp_email_verification_send':
                return $this->sendEmailVerification($arguments);

            case 'mcp_email_verification_verify':
                return $this->verifyEmailVerification($arguments);

            case 'set_shopper_name':
                return $this->setShopperName($arguments);

            case 'mcp_shipping_upgrade_options':
                return $this->shippingUpgradeOptions($arguments);

            case 'mcp_shipping_upgrade_start':
                return $this->shippingUpgradeStart($arguments);

            case 'mcp_shipping_upgrade_status':
                return $this->shippingUpgradeStatus($arguments);

            case 'mcp_kb_topics_list':
                return $this->kbTopicsList();

            case 'mcp_kb_topic_get':
                return $this->kbTopicGet($arguments);

            case 'mcp_kb_fact_propose':
                return $this->kbFactPropose($arguments);

            case 'mcp_order_address_change_preview':
                return $this->orderAddressChangePreview($arguments);

            case 'mcp_order_address_change_start':
                return $this->orderAddressChangeStart($arguments);

            case 'mcp_order_address_change_status':
                return $this->orderAddressChangeStatus($arguments);

            case 'mcp_order_cancel_capabilities':
                return $this->orderCancelCapabilities($arguments);

            case 'mcp_order_cancel_request':
                return $this->orderCancelRequest($arguments);

            case 'mcp_order_confirmation_resend':
                return $this->orderConfirmationResend($arguments);

            case 'mcp_password_reset':
                return $this->passwordReset($arguments);

            case 'mcp_return_request':
                return $this->returnRequest($arguments);

            case 'mcp_coupon_validate':
                return $this->couponValidate($arguments);

            case 'mcp_store_credit_balance':
                return $this->storeCreditBalance($arguments);

            case 'mcp_bulk_request':
                return $this->bulkRequest($arguments);

            case 'mcp_warranty_request':
                return $this->warrantyRequest($arguments);

            case 'mcp_newsletter_set':
                return $this->newsletterSet($arguments);

            case 'open_support_ticket':
                return $this->openSupportTicket($arguments);

            default:
                return ['error' => "Unknown tool: {$toolName}"];
        }
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function shippingUpgradeOptions(array $arguments): array
    {
        if (!$this->shopper['logged_in'] || $this->shopper['customers_id'] < 1) {
            return [
                'ok' => false,
                'error' => 'login_required',
                'message' => 'Shipping upgrades require the shopper to be logged in.',
            ];
        }
        $ordersId = (int) ($arguments['orders_id'] ?? 0);
        if ($ordersId <= 0) {
            return ['ok' => false, 'error' => 'orders_id is required'];
        }
        try {
            $result = $this->mcpClient->callTool('order.shipping.upgrade.options', [
                'orders_id' => $ordersId,
                'customers_id' => $this->shopper['customers_id'],
            ]);
        } catch (ChatbotException $e) {
            $ctx = $e->context();
            return [
                'ok' => false,
                'error' => 'shipping_upgrade_quote_failed',
                'message' => 'Could not load shipping upgrade options right now. Do not invent reasons (especially do not claim the order already shipped). Apologize briefly and offer a support ticket or retry.',
                'mcp_error' => (string) ($ctx['message'] ?? $e->getMessage()),
                'mcp_data' => $ctx['data'] ?? null,
            ];
        }

        if (!empty($result['error'])) {
            $code = (string) $result['error'];
            $hint = '';
            if (is_array($result['detail'] ?? null) && !empty($result['detail']['hint'])) {
                $hint = (string) $result['detail']['hint'];
            }
            return [
                'ok' => false,
                'error' => $code !== '' ? $code : 'shipping_upgrade_unavailable',
                'message' => $hint !== ''
                    ? $hint . ' Do not invent reasons (especially do not claim the order already shipped).'
                    : 'Shipping upgrades are not available for that order right now. Do not invent reasons (especially do not claim the order already shipped). Use the error code and offer a support ticket if needed.',
                'detail' => $result['detail'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Create fee product via MCP, add to the shopper cart, return checkout URL.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function shippingUpgradeStart(array $arguments): array
    {
        if (!$this->shopper['logged_in'] || $this->shopper['customers_id'] < 1) {
            return [
                'ok' => false,
                'error' => 'login_required',
                'message' => 'Shipping upgrades require the shopper to be logged in.',
            ];
        }
        $ordersId = (int) ($arguments['orders_id'] ?? 0);
        $module = trim((string) ($arguments['module'] ?? ''));
        $method = trim((string) ($arguments['method'] ?? ''));
        if ($ordersId <= 0 || $module === '') {
            return [
                'ok' => false,
                'error' => 'invalid_params',
                'message' => 'orders_id and module are required (from mcp_shipping_upgrade_options).',
            ];
        }

        $payload = [
            'orders_id' => $ordersId,
            'customers_id' => $this->shopper['customers_id'],
            'module' => $module,
        ];
        if ($method !== '') {
            $payload['method'] = $method;
        }

        try {
            $created = $this->mcpClient->callTool('order.shipping.upgrade.create', $payload);
        } catch (ChatbotException $e) {
            return [
                'ok' => false,
                'error' => 'shipping_upgrade_create_failed',
                'message' => 'Could not create the shipping upgrade fee for that order.',
            ];
        }

        if (empty($created['ok']) || empty($created['products_id'])) {
            return [
                'ok' => false,
                'error' => 'shipping_upgrade_create_failed',
                'message' => (string) ($created['message'] ?? 'Could not create the shipping upgrade fee.'),
                'detail' => $created,
            ];
        }

        $productsId = (int) $created['products_id'];
        $cartOk = $this->addMcpFeeProductToCart($productsId, 'MCP-SHIP-UPG-');
        $checkoutUrl = self::checkoutUrl();

        return [
            'ok' => true,
            'upgrade_id' => (int) ($created['upgrade_id'] ?? 0),
            'products_id' => $productsId,
            'source_orders_id' => $ordersId,
            'delta' => (float) ($created['delta'] ?? 0),
            'method_title' => (string) ($created['method_title'] ?? ''),
            'cart_added' => $cartOk,
            'checkout_url' => $checkoutUrl,
            'message' => $cartOk
                ? 'Fee product added to cart. Share the checkout_url Markdown link so the shopper can pay. After payment, call mcp_shipping_upgrade_status to confirm the original order method changed.'
                : 'Fee product was created but could not be added to the session cart. Ask the shopper to log in on this browser and try again, or open a support ticket.',
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function shippingUpgradeStatus(array $arguments): array
    {
        if (!$this->shopper['logged_in'] || $this->shopper['customers_id'] < 1) {
            return [
                'ok' => false,
                'error' => 'login_required',
                'message' => 'Shipping upgrade status requires the shopper to be logged in.',
            ];
        }
        $ordersId = (int) ($arguments['orders_id'] ?? 0);
        if ($ordersId <= 0) {
            return ['ok' => false, 'error' => 'orders_id is required'];
        }
        try {
            return $this->mcpClient->callTool('order.shipping.upgrade.status', [
                'orders_id' => $ordersId,
                'customers_id' => $this->shopper['customers_id'],
            ]);
        } catch (ChatbotException $e) {
            return [
                'ok' => false,
                'error' => 'shipping_upgrade_status_failed',
                'message' => 'Could not load shipping upgrade status for that order.',
            ];
        }
    }

    /** @return array<string, mixed> */
    private function kbTopicsList(): array
    {
        try {
            return $this->mcpClient->callTool('kb.topics.list', []);
        } catch (ChatbotException $e) {
            return [
                'ok' => false,
                'error' => 'kb_topics_unavailable',
                'message' => 'Could not list policy topics.',
            ];
        }
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function kbTopicGet(array $arguments): array
    {
        $topicId = trim((string) ($arguments['topic_id'] ?? ''));
        if ($topicId === '') {
            return ['ok' => false, 'error' => 'topic_id is required'];
        }
        try {
            return $this->mcpClient->callTool('kb.topic.get', ['topic_id' => $topicId]);
        } catch (ChatbotException $e) {
            return [
                'ok' => false,
                'error' => 'kb_topic_unavailable',
                'message' => 'Could not load that policy topic.',
            ];
        }
    }

    /**
     * Queue a knowledge-fact draft for operator review. Never shopper-visible
     * and never auto-published — an operator must approve it separately.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function kbFactPropose(array $arguments): array
    {
        $claim = trim((string) ($arguments['claim'] ?? ''));
        if ($claim === '') {
            return ['ok' => false, 'error' => 'claim is required'];
        }
        $evidence = $arguments['evidence'] ?? [];
        if (!is_array($evidence)) {
            $evidence = [];
        }
        $payload = [
            'claim' => $claim,
            'evidence' => $evidence,
        ];
        if (!empty($arguments['topic_id'])) {
            $payload['topic_id'] = (string) $arguments['topic_id'];
        }
        if (!empty($arguments['scope'])) {
            $payload['scope'] = (string) $arguments['scope'];
        }
        $payload['source_session'] = $this->activeSessionId;

        try {
            return $this->mcpClient->callTool('kb.fact.propose', $payload);
        } catch (ChatbotException $e) {
            return [
                'ok' => false,
                'error' => 'kb_fact_propose_unavailable',
                'message' => 'Could not record that for review.',
            ];
        }
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function orderAddressChangePreview(array $arguments): array
    {
        if (!$this->shopper['logged_in'] || $this->shopper['customers_id'] < 1) {
            return [
                'ok' => false,
                'error' => 'login_required',
                'message' => 'Address changes require the shopper to be logged in.',
            ];
        }
        $ordersId = (int) ($arguments['orders_id'] ?? 0);
        $fields = $this->addressChangeFieldsFromArgs($arguments);
        if ($ordersId <= 0 || $fields === []) {
            return [
                'ok' => false,
                'error' => 'invalid_params',
                'message' => 'orders_id and fields (delivery address object) are required.',
            ];
        }
        try {
            $preview = $this->mcpClient->callTool('order.address.change.preview', [
                'orders_id' => $ordersId,
                'customers_id' => $this->shopper['customers_id'],
                'delivery' => $fields,
            ]);
            return $this->normalizeAddressPreview($preview);
        } catch (ChatbotException $e) {
            return [
                'ok' => false,
                'error' => 'address_change_preview_failed',
                'message' => 'Could not preview an address change for that order.',
            ];
        }
    }

    /**
     * Preview then apply immediately, or create a fee product + cart checkout when shipping increases.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function orderAddressChangeStart(array $arguments): array
    {
        if (!$this->shopper['logged_in'] || $this->shopper['customers_id'] < 1) {
            return [
                'ok' => false,
                'error' => 'login_required',
                'message' => 'Address changes require the shopper to be logged in.',
            ];
        }
        $ordersId = (int) ($arguments['orders_id'] ?? 0);
        $fields = $this->addressChangeFieldsFromArgs($arguments);
        if ($ordersId <= 0 || $fields === []) {
            return [
                'ok' => false,
                'error' => 'invalid_params',
                'message' => 'orders_id and fields (delivery address object) are required.',
            ];
        }

        $customersId = $this->shopper['customers_id'];
        $payload = [
            'orders_id' => $ordersId,
            'customers_id' => $customersId,
            'delivery' => $fields,
        ];

        try {
            $preview = $this->normalizeAddressPreview(
                $this->mcpClient->callTool('order.address.change.preview', $payload)
            );
        } catch (ChatbotException $e) {
            return [
                'ok' => false,
                'error' => 'address_change_preview_failed',
                'message' => 'Could not preview an address change for that order.',
            ];
        }

        if (empty($preview['ok'])) {
            return $preview;
        }

        $needsFee = !empty($preview['needs_fee']) || !empty($preview['requires_fee']);
        if (!$needsFee) {
            try {
                $applied = $this->mcpClient->callTool('order.address.change.apply', $payload);
            } catch (ChatbotException $e) {
                return [
                    'ok' => false,
                    'error' => 'address_change_apply_failed',
                    'message' => 'Could not apply the address change.',
                    'preview' => $preview,
                ];
            }
            if (empty($applied['ok'])) {
                return [
                    'ok' => false,
                    'error' => 'address_change_apply_failed',
                    'message' => (string) ($applied['message'] ?? $applied['customer_summary'] ?? 'Could not apply the address change.'),
                    'detail' => $applied,
                    'preview' => $preview,
                ];
            }
            return [
                'ok' => true,
                'applied' => true,
                'needs_fee' => false,
                'orders_id' => $ordersId,
                'preview' => $preview,
                'result' => $applied,
                'message' => (string) ($applied['customer_summary'] ?? 'Delivery address updated. Confirm the new address to the shopper.'),
            ];
        }

        try {
            $created = $this->mcpClient->callTool('order.address.change.create', $payload);
        } catch (ChatbotException $e) {
            return [
                'ok' => false,
                'error' => 'address_change_create_failed',
                'message' => 'Could not create the address-change shipping fee.',
                'preview' => $preview,
            ];
        }

        if (empty($created['ok']) || empty($created['products_id'])) {
            return [
                'ok' => false,
                'error' => 'address_change_create_failed',
                'message' => (string) ($created['message'] ?? $created['customer_summary'] ?? 'Could not create the address-change fee.'),
                'detail' => $created,
                'preview' => $preview,
            ];
        }

        $productsId = (int) $created['products_id'];
        $cartOk = $this->addMcpFeeProductToCart($productsId, 'MCP-ADDR-CHG');
        $checkoutUrl = self::checkoutUrl();

        return [
            'ok' => true,
            'applied' => false,
            'needs_fee' => true,
            'upgrade_id' => (int) ($created['upgrade_id'] ?? 0),
            'products_id' => $productsId,
            'source_orders_id' => $ordersId,
            'delta' => (float) ($created['delta'] ?? $preview['shipping_delta'] ?? 0),
            'method_title' => (string) ($created['method_title'] ?? ''),
            'cart_added' => $cartOk,
            'checkout_url' => $checkoutUrl,
            'preview' => $preview,
            'message' => $cartOk
                ? 'Shipping fee added to cart. Share the checkout_url Markdown link so the shopper can pay. After payment, call mcp_order_address_change_status to confirm the address was applied.'
                : 'Fee product was created but could not be added to the session cart. Ask the shopper to log in on this browser and try again, or open a support ticket.',
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function orderAddressChangeStatus(array $arguments): array
    {
        if (!$this->shopper['logged_in'] || $this->shopper['customers_id'] < 1) {
            return [
                'ok' => false,
                'error' => 'login_required',
                'message' => 'Address-change status requires the shopper to be logged in.',
            ];
        }
        $ordersId = (int) ($arguments['orders_id'] ?? 0);
        if ($ordersId <= 0) {
            return ['ok' => false, 'error' => 'orders_id is required'];
        }
        try {
            return $this->mcpClient->callTool('order.address.change.status', [
                'orders_id' => $ordersId,
                'customers_id' => $this->shopper['customers_id'],
            ]);
        } catch (ChatbotException $e) {
            return [
                'ok' => false,
                'error' => 'address_change_status_failed',
                'message' => 'Could not load address-change status for that order.',
            ];
        }
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function orderCancelCapabilities(array $arguments): array
    {
        if (!$this->shopper['logged_in'] || $this->shopper['customers_id'] < 1) {
            return [
                'ok' => false,
                'error' => 'login_required',
                'message' => 'Order cancel checks require the shopper to be logged in.',
            ];
        }
        $ordersId = (int) ($arguments['orders_id'] ?? 0);
        if ($ordersId <= 0) {
            return ['ok' => false, 'error' => 'orders_id is required'];
        }
        try {
            return $this->mcpClient->callTool('order.cancel.capabilities', [
                'orders_id' => $ordersId,
                'customers_id' => $this->shopper['customers_id'],
            ]);
        } catch (ChatbotException $e) {
            return [
                'ok' => false,
                'error' => 'cancel_capabilities_failed',
                'message' => 'Could not load cancel capabilities for that order.',
            ];
        }
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function orderCancelRequest(array $arguments): array
    {
        if (!$this->shopper['logged_in'] || $this->shopper['customers_id'] < 1) {
            return [
                'ok' => false,
                'error' => 'login_required',
                'message' => 'Order cancel requests require the shopper to be logged in.',
            ];
        }
        $ordersId = (int) ($arguments['orders_id'] ?? 0);
        if ($ordersId <= 0) {
            return ['ok' => false, 'error' => 'orders_id is required'];
        }

        $mode = strtolower(trim((string) ($arguments['mode'] ?? 'full')));
        if ($mode === '') {
            $mode = !empty($arguments['partial']) ? 'partial' : 'full';
        }
        if (!in_array($mode, ['full', 'partial'], true)) {
            return [
                'ok' => false,
                'error' => 'invalid_params',
                'message' => 'mode must be full or partial.',
            ];
        }

        $payload = [
            'orders_id' => $ordersId,
            'customers_id' => $this->shopper['customers_id'],
            'partial' => $mode === 'partial',
        ];

        $lineIds = [];
        if (isset($arguments['orders_products_ids']) && is_array($arguments['orders_products_ids'])) {
            foreach ($arguments['orders_products_ids'] as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $lineIds[] = $id;
                }
            }
        }
        if ($lineIds === [] && isset($arguments['orders_products_id'])) {
            $id = (int) $arguments['orders_products_id'];
            if ($id > 0) {
                $lineIds[] = $id;
            }
        }
        if ($mode === 'partial' && $lineIds !== []) {
            $payload['orders_products_id'] = $lineIds[0];
            if (count($lineIds) > 1) {
                $payload['orders_products_ids'] = $lineIds;
            }
        }

        $reason = trim((string) ($arguments['reason'] ?? ''));
        if ($reason !== '') {
            $payload['reason'] = $reason;
        }
        $summary = trim((string) ($arguments['customer_summary'] ?? ''));
        if ($summary !== '') {
            $payload['customer_summary'] = $summary;
        }

        try {
            $result = $this->mcpClient->callTool('order.cancel.request', $payload);
        } catch (ChatbotException $e) {
            return [
                'ok' => false,
                'error' => 'cancel_request_failed',
                'message' => 'Could not submit the cancel request for that order.',
            ];
        }

        if (empty($result['ok'])) {
            return [
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'cancel_request_failed'),
                'message' => (string) ($result['message'] ?? $result['customer_summary'] ?? 'Cancel request was refused.'),
                'detail' => $result,
            ];
        }

        return [
            'ok' => true,
            'result' => $result,
            'message' => (string) ($result['customer_summary'] ?? 'Cancel request completed. Tell the shopper only what this result says — do not invent refunds.'),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function orderConfirmationResend(array $arguments): array
    {
        if (!$this->shopper['logged_in'] || $this->shopper['customers_id'] < 1) {
            return [
                'ok' => false,
                'error' => 'login_required',
                'message' => 'Resending an order confirmation requires the shopper to be logged in.',
            ];
        }
        $ordersId = (int) ($arguments['orders_id'] ?? 0);
        if ($ordersId <= 0) {
            return ['ok' => false, 'error' => 'orders_id is required'];
        }
        try {
            $result = $this->mcpClient->callTool('order.confirmation.resend', [
                'orders_id' => $ordersId,
                'customers_id' => $this->shopper['customers_id'],
            ]);
        } catch (ChatbotException $e) {
            return [
                'ok' => false,
                'error' => 'confirmation_resend_failed',
                'message' => 'Could not resend the order confirmation for that order.',
            ];
        }
        if (empty($result['ok']) && empty($result['dry_run'])) {
            return [
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'confirmation_resend_failed'),
                'message' => (string) ($result['message'] ?? $result['customer_summary'] ?? 'Could not resend confirmation.'),
                'detail' => $result,
            ];
        }
        return [
            'ok' => true,
            'result' => $result,
            'message' => (string) ($result['customer_summary'] ?? 'Order confirmation email was resent.'),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function passwordReset(array $arguments): array
    {
        $email = trim((string) ($arguments['email'] ?? ''));
        if ($email === '' && $this->shopper['logged_in'] && $this->shopper['email'] !== '') {
            $email = (string) $this->shopper['email'];
        }
        if ($email === '') {
            return [
                'ok' => false,
                'error' => 'email_required',
                'message' => 'Ask the shopper for the email address on their account, then call mcp_password_reset again.',
            ];
        }
        try {
            $result = $this->mcpClient->callTool('customer.password.reset', [
                'email' => $email,
            ]);
        } catch (ChatbotException $e) {
            // Still use anti-enumeration wording on transport failures.
            return [
                'ok' => true,
                'message' => 'If an account exists for that email, a password reset link has been sent. Check inbox and spam.',
            ];
        }
        return [
            'ok' => true,
            'message' => (string) ($result['message'] ?? 'If an account exists for that email, a password reset link has been sent. Check inbox and spam.'),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function returnRequest(array $arguments): array
    {
        if (!$this->shopper['logged_in'] || $this->shopper['customers_id'] < 1) {
            return [
                'ok' => false,
                'error' => 'login_required',
                'message' => 'Return/exchange requests require the shopper to be logged in. Ask them to log in, or open a support ticket after email verification.',
            ];
        }
        $ordersId = (int) ($arguments['orders_id'] ?? 0);
        $reason = trim((string) ($arguments['reason'] ?? ''));
        if ($ordersId <= 0 || $reason === '') {
            return [
                'ok' => false,
                'error' => 'invalid_params',
                'message' => 'orders_id and reason are required for a return/exchange request.',
            ];
        }

        $requestType = strtolower(trim((string) ($arguments['request_type'] ?? 'return')));
        if (!in_array($requestType, ['return', 'exchange'], true)) {
            $requestType = 'return';
        }

        $payload = [
            'orders_id' => $ordersId,
            'customers_id' => $this->shopper['customers_id'],
            'reason' => $reason,
            'request_type' => $requestType,
        ];
        $items = trim((string) ($arguments['items'] ?? ''));
        if ($items !== '') {
            $payload['items'] = $items;
        }
        $summary = trim((string) ($arguments['customer_summary'] ?? ''));
        if ($summary !== '') {
            $payload['customer_summary'] = $summary;
        }

        try {
            $result = $this->mcpClient->callTool('support.return.create', $payload);
        } catch (ChatbotException $e) {
            return [
                'ok' => false,
                'error' => 'return_request_failed',
                'message' => 'Could not submit the return/exchange request for that order.',
            ];
        }

        if (empty($result['ok'])) {
            return [
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'return_request_failed'),
                'message' => (string) ($result['message'] ?? $result['customer_summary'] ?? 'Return request was refused.'),
                'detail' => $result,
            ];
        }

        return [
            'ok' => true,
            'result' => $result,
            'message' => (string) ($result['customer_summary'] ?? $result['message'] ?? 'Return request queued. Do not invent refunds or RMA numbers.'),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function couponValidate(array $arguments): array
    {
        $code = trim((string) ($arguments['code'] ?? ''));
        if ($code === '') {
            return [
                'ok' => false,
                'error' => 'invalid_params',
                'message' => 'code is required to validate a coupon.',
            ];
        }

        try {
            $result = $this->mcpClient->callTool('coupon.validate', ['code' => $code]);
        } catch (ChatbotException $e) {
            return [
                'ok' => false,
                'error' => 'coupon_validate_failed',
                'message' => 'Could not validate that coupon code right now.',
            ];
        }

        return [
            'ok' => true,
            'result' => $result,
            'message' => (string) ($result['message'] ?? 'Report only the tool result — never invent discount amounts.'),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function storeCreditBalance(array $arguments): array
    {
        if (!$this->shopper['logged_in'] || $this->shopper['customers_id'] < 1) {
            return [
                'ok' => false,
                'error' => 'login_required',
                'message' => 'Store credit / gift voucher balance requires the shopper to be logged in.',
            ];
        }

        try {
            $result = $this->mcpClient->callTool('customer.gift_voucher.balance', [
                'customers_id' => $this->shopper['customers_id'],
            ]);
        } catch (ChatbotException $e) {
            return [
                'ok' => false,
                'error' => 'store_credit_balance_failed',
                'message' => 'Could not look up store credit balance right now.',
            ];
        }

        return [
            'ok' => true,
            'result' => $result,
            'message' => (string) ($result['message'] ?? 'Report only the returned balance — never invent amounts.'),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function bulkRequest(array $arguments): array
    {
        $contactEmail = strtolower(trim((string) ($arguments['contact_email'] ?? '')));
        $quantity = trim((string) ($arguments['quantity'] ?? ''));
        $logoNotes = trim((string) ($arguments['logo_notes'] ?? ''));
        if ($contactEmail === '' || $quantity === '' || $logoNotes === '') {
            return [
                'ok' => false,
                'error' => 'invalid_params',
                'message' => 'contact_email, quantity, and logo_notes are required for a bulk/logo request. Never quote prices.',
            ];
        }

        $payload = [
            'contact_email' => $contactEmail,
            'quantity' => $quantity,
            'logo_notes' => $logoNotes,
        ];
        if ($this->shopper['logged_in'] && $this->shopper['customers_id'] > 0) {
            $payload['customers_id'] = $this->shopper['customers_id'];
        }
        $name = trim((string) ($arguments['contact_name'] ?? ''));
        if ($name !== '') {
            $payload['contact_name'] = $name;
        }
        $deadline = trim((string) ($arguments['deadline'] ?? ''));
        if ($deadline !== '') {
            $payload['deadline'] = $deadline;
        }
        $ordersId = (int) ($arguments['orders_id'] ?? 0);
        if ($ordersId > 0) {
            $payload['orders_id'] = $ordersId;
        }
        $summary = trim((string) ($arguments['customer_summary'] ?? ''));
        if ($summary !== '') {
            $payload['customer_summary'] = $summary;
        }

        try {
            $result = $this->mcpClient->callTool('support.bulk.create', $payload);
        } catch (ChatbotException $e) {
            return [
                'ok' => false,
                'error' => 'bulk_request_failed',
                'message' => 'Could not submit the bulk/logo request. Never invent prices — escalate with a support ticket if needed.',
            ];
        }

        if (empty($result['ok'])) {
            return [
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'bulk_request_failed'),
                'message' => (string) ($result['message'] ?? $result['customer_summary'] ?? 'Bulk request was refused. Never invent prices.'),
                'detail' => $result,
            ];
        }

        return [
            'ok' => true,
            'result' => $result,
            'message' => (string) ($result['customer_summary'] ?? $result['message'] ?? 'Bulk/logo request queued. Never invent or quote prices.'),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function warrantyRequest(array $arguments): array
    {
        $contactEmail = strtolower(trim((string) ($arguments['contact_email'] ?? '')));
        if ($contactEmail === '' && $this->shopper['logged_in'] && $this->shopper['email'] !== '') {
            $contactEmail = $this->shopper['email'];
        }
        $issueSummary = trim((string) ($arguments['issue_summary'] ?? ''));
        if ($contactEmail === '' || $issueSummary === '') {
            return [
                'ok' => false,
                'error' => 'invalid_params',
                'message' => 'contact_email and issue_summary are required for a warranty request. Never invent approvals or refunds.',
            ];
        }

        $payload = [
            'contact_email' => $contactEmail,
            'issue_summary' => $issueSummary,
        ];
        if ($this->shopper['logged_in'] && $this->shopper['customers_id'] > 0) {
            $payload['customers_id'] = $this->shopper['customers_id'];
        }
        $name = trim((string) ($arguments['contact_name'] ?? ''));
        if ($name !== '') {
            $payload['contact_name'] = $name;
        }
        $items = trim((string) ($arguments['items'] ?? ''));
        if ($items !== '') {
            $payload['items'] = $items;
        }
        $ordersId = (int) ($arguments['orders_id'] ?? 0);
        if ($ordersId > 0) {
            $payload['orders_id'] = $ordersId;
        }

        try {
            $result = $this->mcpClient->callTool('support.warranty.create', $payload);
        } catch (ChatbotException $e) {
            return [
                'ok' => false,
                'error' => 'warranty_request_failed',
                'message' => 'Could not submit the warranty request. Never invent approvals or refunds — escalate with a support ticket if needed.',
            ];
        }

        if (empty($result['ok'])) {
            return [
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'warranty_request_failed'),
                'message' => (string) ($result['message'] ?? $result['customer_summary'] ?? 'Warranty request was refused. Never invent approvals or refunds.'),
                'detail' => $result,
            ];
        }

        return [
            'ok' => true,
            'result' => $result,
            'message' => (string) ($result['customer_summary'] ?? $result['message'] ?? 'Warranty request queued. Never invent approvals or refunds.'),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function newsletterSet(array $arguments): array
    {
        if (!$this->shopper['logged_in'] || $this->shopper['customers_id'] < 1) {
            return [
                'ok' => false,
                'error' => 'login_required',
                'message' => 'Newsletter subscribe/unsubscribe requires the shopper to be logged in.',
            ];
        }
        if (!array_key_exists('subscribed', $arguments)) {
            return [
                'ok' => false,
                'error' => 'invalid_params',
                'message' => 'subscribed (boolean) is required.',
            ];
        }

        $subscribed = !empty($arguments['subscribed']);

        try {
            $result = $this->mcpClient->callTool('customer.newsletter.set', [
                'customers_id' => $this->shopper['customers_id'],
                'subscribed' => $subscribed,
            ]);
        } catch (ChatbotException $e) {
            return [
                'ok' => false,
                'error' => 'newsletter_set_failed',
                'message' => 'Could not update newsletter preference right now. Never invent subscription state.',
            ];
        }

        if (empty($result['ok'])) {
            return [
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'newsletter_set_failed'),
                'message' => (string) ($result['message'] ?? 'Newsletter update was refused. Never invent subscription state.'),
                'detail' => $result,
            ];
        }

        return [
            'ok' => true,
            'result' => $result,
            'message' => (string) ($result['message'] ?? 'Newsletter preference updated. External ESP sync may lag — never invent subscription state.'),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function addressChangeFieldsFromArgs(array $arguments): array
    {
        if (isset($arguments['fields']) && is_array($arguments['fields'])) {
            return $arguments['fields'];
        }
        if (isset($arguments['delivery']) && is_array($arguments['delivery'])) {
            return $arguments['delivery'];
        }
        return [];
    }

    /**
     * @param array<string, mixed> $preview
     * @return array<string, mixed>
     */
    private function normalizeAddressPreview(array $preview): array
    {
        if (isset($preview['requires_fee']) && !isset($preview['needs_fee'])) {
            $preview['needs_fee'] = !empty($preview['requires_fee']);
        } elseif (isset($preview['needs_fee']) && !isset($preview['requires_fee'])) {
            $preview['requires_fee'] = !empty($preview['needs_fee']);
        } elseif (isset($preview['can_apply_immediately']) && !isset($preview['needs_fee'])) {
            $preview['needs_fee'] = empty($preview['can_apply_immediately']);
            $preview['requires_fee'] = $preview['needs_fee'];
        }
        return $preview;
    }

    /**
     * Add an MCP fee product to the session cart; remove other pending fees with the same model prefix.
     */
    private function addMcpFeeProductToCart(int $productsId, string $modelPrefix): bool
    {
        if ($productsId < 1) {
            return false;
        }
        if (!isset($_SESSION['cart']) || !is_object($_SESSION['cart'])) {
            return false;
        }
        $cart = $_SESSION['cart'];
        if (!method_exists($cart, 'add_cart')) {
            return false;
        }

        if (method_exists($cart, 'get_products') && method_exists($cart, 'remove')) {
            $products = $cart->get_products() ?: [];
            foreach ($products as $line) {
                $pid = (int) ($line['id'] ?? 0);
                $model = (string) ($line['model'] ?? '');
                if ($pid > 0 && $pid !== $productsId && str_starts_with($model, $modelPrefix)) {
                    $cart->remove($pid);
                }
            }
        }

        $cart->add_cart($productsId, 1);
        return true;
    }

    /**
     * Storefront checkout URL (SSL).
     */
    public static function checkoutUrl(): string
    {
        if (!function_exists('zen_href_link')) {
            return '';
        }
        $page = defined('FILENAME_ONE_PAGE_CHECKOUT')
            ? FILENAME_ONE_PAGE_CHECKOUT
            : (defined('FILENAME_CHECKOUT_SHIPPING')
                ? FILENAME_CHECKOUT_SHIPPING
                : (defined('FILENAME_CHECKOUT_PAYMENT') ? FILENAME_CHECKOUT_PAYMENT : 'one_page_checkout'));
        return trim((string) zen_href_link($page, '', 'SSL'));
    }

    /**
     * Email the configured support address with the chat transcript.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function openSupportTicket(array $arguments): array
    {
        $toEmail = self::supportEmail();
        $contactUrl = self::contactUsUrl();
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $msg = 'Customer support email is not configured.';
            if ($contactUrl !== '') {
                $msg .= ' Share this Markdown link so the shopper can contact the store: [Contact us](' . $contactUrl . ').';
            } else {
                $msg .= ' Ask the shopper to use the store Contact Us page.';
            }
            return [
                'ok' => false,
                'error' => 'support_email_not_configured',
                'message' => $msg,
                'contact_url' => $contactUrl,
            ];
        }

        $sessionId = $this->activeSessionId !== '' ? $this->activeSessionId : 'unknown';
        $meta = self::$sessionMeta[$sessionId] ?? ['created' => time(), 'updated' => time(), 'tickets_sent' => 0];
        if ((int) ($meta['tickets_sent'] ?? 0) >= 3) {
            $msg = 'A support request was already sent for this chat. Tell the shopper support will reply by email.';
            if ($contactUrl !== '') {
                $msg .= ' You may also share [Contact us](' . $contactUrl . ').';
            }
            return [
                'ok' => false,
                'error' => 'ticket_limit_reached',
                'message' => $msg,
                'contact_url' => $contactUrl,
            ];
        }

        $customerEmail = strtolower(trim((string) ($arguments['customer_email'] ?? '')));
        if ($customerEmail === '' && $this->shopper['logged_in'] && $this->shopper['email'] !== '') {
            $customerEmail = $this->shopper['email'];
        }
        if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'ok' => false,
                'error' => 'customer_email_required',
                'message' => 'Ask the shopper for a valid email address, then call this tool again.',
            ];
        }

        // Guests may provide an email for support; logged-in shoppers may confirm theirs.
        if (function_exists('zen_validate_email') && !zen_validate_email($customerEmail)) {
            return [
                'ok' => false,
                'error' => 'invalid_email',
                'message' => 'That email address looks invalid. Ask the shopper to re-check it.',
            ];
        }

        $customerName = trim((string) ($arguments['customer_name'] ?? ''));
        if ($customerName === '') {
            $metaName = trim((string) ((self::$sessionMeta[$sessionId]['provided_name'] ?? '') ?: ''));
            if ($metaName !== '') {
                $customerName = $metaName;
            }
        }
        if ($customerName === '') {
            $customerName = trim($this->shopper['first_name'] . ' ' . $this->shopper['last_name']);
        }
        if ($customerName !== '' && $customerName !== 'Chatbot customer') {
            $this->rememberShopperName($customerName);
        }
        if ($customerName === '') {
            $customerName = 'Chatbot customer';
        }

        $summary = trim((string) ($arguments['summary'] ?? ''));
        if ($summary === '') {
            $summary = 'Customer requested human support via the storefront chatbot.';
        }

        // Prefer live agent when an admin is online in admin (Chat Inbox / dashboard poll).
        // Live path does not require email verification — the agent can still claim and reply in-widget.
        // After an escalation timeout, do not immediately re-enter live handoff — offer email ticket.
        $confirmEmailTicket = !empty($arguments['confirm_email_ticket'])
            || !empty($arguments['open_email_ticket']);
        $escalationTimedOut = !empty($meta['escalation_timed_out']);
        $forceEmail = !empty($meta['force_email_ticket']) || $confirmEmailTicket;
        // After timeout, skip live re-entry even if admins are online.
        if (!$forceEmail && !$escalationTimedOut && AdminPresence::isAnyOnline()) {
            return $this->requestLiveHuman($sessionId, $customerEmail, $customerName, $summary, $meta, $contactUrl);
        }

        if ($escalationTimedOut && !$confirmEmailTicket) {
            return [
                'ok' => false,
                'error' => 'escalation_timed_out',
                'message' => 'Live support did not pick up earlier. Tell the shopper they can continue with you, or open an email support ticket if they want. If they agree to a ticket, call open_support_ticket again with the same customer_email and confirm_email_ticket=true (guests must complete email verification first). Do not call for another live handoff yet.',
                'can_open_email_ticket' => true,
                'customer_email' => $customerEmail,
                'contact_url' => $contactUrl,
            ];
        }

        if (!$forceEmail) {
            return [
                'ok' => false,
                'error' => 'no_admin_online',
                'message' => 'No store admin is currently online. Tell the shopper clearly that a live human agent is not available right now, and that you can open an email support ticket instead if they want. If they agree, call open_support_ticket again with the same customer_email and confirm_email_ticket=true (guests must complete email verification first). Do not pretend a live agent was notified.',
                'can_open_email_ticket' => true,
                'customer_email' => $customerEmail,
                'contact_url' => $contactUrl,
            ];
        }

        // Guests must confirm ownership of the address via email verification before opening a ticket.
        if (!$this->shopper['logged_in'] && !$this->isEmailVerifiedInSession($sessionId, $customerEmail)) {
            return [
                'ok' => false,
                'error' => 'email_verification_required',
                'message' => 'This guest email is not verified yet. Call mcp_email_verification_send, ask the shopper to paste the 6-digit code from their inbox, then mcp_email_verification_verify before opening the ticket.',
                'email' => $customerEmail,
            ];
        }

        unset($meta['force_email_ticket']);
        unset($meta['escalation_timed_out']);
        unset($meta['needs_ticket_confirm']);
        self::$sessionMeta[$sessionId] = $meta;

        $transcript = $this->formatTranscriptForEmail($this->activeMessages);
        if ($transcript === '') {
            $transcript = '(No prior messages in this chat.)';
        }

        // Ensure archive row exists so the email can include a matchable #chat_id.
        $chatId = 0;
        try {
            ChatArchive::upsert(
                $sessionId,
                $this->activeMessages,
                [
                    'logged_in' => !empty($this->shopper['logged_in']),
                    'customers_id' => (int) ($this->shopper['customers_id'] ?? 0),
                    'email' => $customerEmail,
                    'first_name' => (string) ($this->shopper['first_name'] ?? ''),
                    'last_name' => (string) ($this->shopper['last_name'] ?? ''),
                ],
                $meta
            );
            ChatArchive::attachContact(
                $sessionId,
                $customerEmail,
                $customerName === 'Chatbot customer' ? '' : $customerName,
                (int) $this->shopper['customers_id']
            );
            $archive = ChatArchive::getBySessionId($sessionId);
            $chatId = $archive !== null ? (int) ($archive['chat_id'] ?? 0) : 0;
        } catch (\Throwable $e) {
            // continue without chat id if archive fails
        }

        $subject = TicketMail::supportRequestSubject($chatId, $customerEmail);
        $body = "A shopper asked the storefront chatbot to contact customer support.\n\n"
            . "Customer name: {$customerName}\n"
            . "Customer email: {$customerEmail}\n"
            . "Chat session: {$sessionId}\n"
            . ($chatId > 0 ? "Support ticket: #{$chatId}\n" : '')
            . "Logged in: " . ($this->shopper['logged_in'] ? 'yes' : 'no') . "\n"
            . "Summary: {$summary}\n\n"
            . "----- Chat transcript -----\n"
            . $transcript
            . "\n----- End transcript -----\n"
            . TicketMail::footerWithChatId($chatId);

        $messageId = ChatbotSmtpMailer::mintMessageId($chatId);
        $mailbox = MailConfig::address() !== '' ? MailConfig::address() : $toEmail;
        $fromName = defined('STORE_NAME') ? (string) STORE_NAME : 'Store Chatbot';
        $fromEmail = MailConfig::smtpReady() ? $mailbox : $customerEmail;
        $result = ChatbotSmtpMailer::send(
            'Customer Support',
            $toEmail,
            $subject,
            $body,
            $fromName,
            $fromEmail,
            'seekmodo_ai_chatbot_support',
            [],
            $customerEmail,
            '',
            '',
            $messageId
        );

        if (empty($result['ok'])) {
            $msg = 'Could not send the support email.';
            if ($contactUrl !== '') {
                $msg .= ' Share this Markdown link: [Contact us](' . $contactUrl . ').';
            } else {
                $msg .= ' Ask the shopper to email support directly.';
            }
            return [
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'send_failed'),
                'message' => $msg,
                'contact_url' => $contactUrl,
            ];
        }

        $meta['tickets_sent'] = (int) ($meta['tickets_sent'] ?? 0) + 1;
        self::$sessionMeta[$sessionId] = $meta;
        // Persist ticket count even before the next assistant turn finishes.
        $this->saveHistory($sessionId, $this->activeMessages);

        if ($chatId > 0 && !empty($result['message_id'])) {
            try {
                ChatArchive::setLastOutboundMessageId($chatId, (string) $result['message_id']);
                ChatArchive::setStatus($chatId, 'handed_off');
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return [
            'ok' => true,
            'message' => 'Support request emailed successfully. Tell the shopper that customer support will reply to their email address.',
            'support_email' => $toEmail,
            'customer_email' => $customerEmail,
            'chat_id' => $chatId,
            'contact_url' => $contactUrl,
        ];
    }

    /**
     * Admin is online in Chat Inbox — queue for live takeover instead of email ticket.
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function requestLiveHuman(
        string $sessionId,
        string $customerEmail,
        string $customerName,
        string $summary,
        array $meta,
        string $contactUrl
    ): array {
        $chatId = 0;
        try {
            ChatArchive::upsert(
                $sessionId,
                $this->activeMessages,
                [
                    'logged_in' => !empty($this->shopper['logged_in']),
                    'customers_id' => (int) ($this->shopper['customers_id'] ?? 0),
                    'email' => $customerEmail,
                    'first_name' => (string) ($this->shopper['first_name'] ?? ''),
                    'last_name' => (string) ($this->shopper['last_name'] ?? ''),
                ],
                $meta
            );
            ChatArchive::attachContact(
                $sessionId,
                $customerEmail,
                $customerName === 'Chatbot customer' ? '' : $customerName,
                (int) $this->shopper['customers_id']
            );
            $archive = ChatArchive::getBySessionId($sessionId);
            $chatId = $archive !== null ? (int) ($archive['chat_id'] ?? 0) : 0;
            if ($chatId > 0) {
                ChatArchive::setStatus($chatId, 'live', 0);
            }
        } catch (\Throwable $e) {
            // Fall through to email ticket if we cannot mark live.
            return $this->openSupportTicketEmailFallback(
                $sessionId,
                $customerEmail,
                $customerName,
                $summary,
                $meta,
                $contactUrl
            );
        }

        self::stampSessionLive($sessionId, 0);

        // Lightweight ping so operators watching email still notice (does not hand off).
        $toEmail = self::supportEmail();
        if ($toEmail !== '' && filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $subject = '[Live chat] Customer waiting'
                . ($chatId > 0 ? (' #' . $chatId) : '')
                . ' — ' . $customerEmail;
            $body = "A shopper asked for a human agent in the storefront chatbot.\n\n"
                . "An admin session is currently active — please open Chat Inbox and Claim live.\n\n"
                . "Customer: {$customerName} <{$customerEmail}>\n"
                . "Session: {$sessionId}\n"
                . ($chatId > 0 ? "Chat ID: #{$chatId}\n" : '')
                . "Summary: {$summary}\n";
            try {
                ChatbotSmtpMailer::send(
                    'Customer Support',
                    $toEmail,
                    $subject,
                    $body,
                    defined('STORE_NAME') ? (string) STORE_NAME : 'Store Chatbot',
                    MailConfig::smtpReady() && MailConfig::address() !== '' ? MailConfig::address() : $customerEmail,
                    'seekmodo_ai_chatbot_support',
                    [],
                    $customerEmail
                );
            } catch (\Throwable $e) {
                // Dashboard / status live is enough if mail fails.
            }
        }

        return [
            'ok' => true,
            'mode' => 'live',
            'message' => 'A human agent has been notified in Chat Inbox. Tell the shopper an agent will join this chat shortly — they can keep typing here. Do not say a support email ticket was opened.',
            'customer_email' => $customerEmail,
            'chat_id' => $chatId,
            'contact_url' => $contactUrl,
        ];
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function openSupportTicketEmailFallback(
        string $sessionId,
        string $customerEmail,
        string $customerName,
        string $summary,
        array $meta,
        string $contactUrl
    ): array {
        // Re-enter the email path by temporarily clearing the live-online gate:
        // call the original mail body inline via a flag on meta.
        $meta['force_email_ticket'] = true;
        self::$sessionMeta[$sessionId] = $meta;
        return $this->openSupportTicket([
            'customer_email' => $customerEmail,
            'customer_name' => $customerName,
            'summary' => $summary,
        ]);
    }

    /**
     * @param list<array{role: string, content: string, tool_calls?: array, tool_call_id?: string}> $messages
     */
    private function formatTranscriptForEmail(array $messages): string
    {
        $lines = [];
        foreach ($messages as $msg) {
            $role = (string) ($msg['role'] ?? '');
            $content = trim((string) ($msg['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            if ($role === 'user') {
                $lines[] = 'Customer: ' . $content;
            } elseif ($role === 'assistant') {
                // Strip overly long product-recommendation markdown for support readability.
                if (strlen($content) > 4000) {
                    $content = substr($content, 0, 4000) . '…';
                }
                $lines[] = 'Assistant: ' . $content;
            }
        }
        return implode("\n\n", $lines);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function sendEmailVerification(array $arguments): array
    {
        if ($this->shopper['logged_in'] && $this->shopper['email'] !== '') {
            return [
                'ok' => true,
                'skipped' => true,
                'verified' => true,
                'email' => $this->shopper['email'],
                'message' => 'Shopper is logged in; email verification is not required. Use their account email.',
            ];
        }

        $email = strtolower(trim((string) ($arguments['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'ok' => false,
                'error' => 'invalid_email',
                'message' => 'Ask the shopper for a valid email address, then call this tool again.',
            ];
        }
        if (function_exists('zen_validate_email') && !zen_validate_email($email)) {
            return [
                'ok' => false,
                'error' => 'invalid_email',
                'message' => 'That email address looks invalid. Ask the shopper to re-check it.',
            ];
        }

        $sessionId = $this->activeSessionId !== '' ? $this->activeSessionId : 'unknown';
        if ($this->isEmailVerifiedInSession($sessionId, $email)) {
            return [
                'ok' => true,
                'skipped' => true,
                'verified' => true,
                'email' => $email,
                'message' => 'This email was already verified in this chat session.',
            ];
        }

        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name !== '') {
            $this->rememberShopperName($name);
        }
        try {
            $result = $this->mcpClient->callTool('email.verification.send', [
                'email' => $email,
                'name' => $name,
            ]);
        } catch (ChatbotException $e) {
            return [
                'ok' => false,
                'error' => 'send_failed',
                'message' => 'Could not send the verification email. Ask the shopper to try again or use another address.',
            ];
        }

        if (!empty($result['error']) || empty($result['ok'])) {
            return [
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'send_failed'),
                'message' => (string) ($result['message'] ?? $result['hint'] ?? 'Could not send the verification email.'),
                'detail' => is_array($result['detail'] ?? null) ? $result['detail'] : [],
            ];
        }

        // MCP returns verification_code to this requestor; never expose it to the model/shopper.
        unset($result['verification_code']);

        return [
            'ok' => true,
            'email' => $email,
            'sent' => !empty($result['sent']),
            'expires_in_seconds' => (int) ($result['expires_in_seconds'] ?? 900),
            'message' => 'Verification email sent. Ask the shopper to open their inbox and paste the 6-digit code here. Never invent or display a code yourself.',
        ];
    }

    /**
     * Persist a shopper-provided display name on the chat archive (admin Inbox label).
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function setShopperName(array $arguments): array
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        if ($name === '' || mb_strlen($name) < 2) {
            return [
                'ok' => false,
                'error' => 'invalid_name',
                'message' => 'Ask the shopper for their name (at least 2 characters), then call set_shopper_name again.',
            ];
        }
        if (mb_strlen($name) > 128) {
            $name = mb_substr($name, 0, 128);
        }
        // Reject obvious non-names (emails / URLs).
        if (str_contains($name, '@') || preg_match('#https?://#i', $name)) {
            return [
                'ok' => false,
                'error' => 'invalid_name',
                'message' => 'That looks like an email or URL, not a name. Ask for their name only.',
            ];
        }

        $this->rememberShopperName($name);

        return [
            'ok' => true,
            'name' => $name,
            'message' => 'Shopper name saved on this chat for the admin Inbox. You may greet them by this name.',
        ];
    }

    /**
     * Store a provided display name in session meta + shopper context and push to ChatArchive.
     */
    private function rememberShopperName(string $name): void
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        if ($name === '') {
            return;
        }

        $parts = preg_split('/\s+/', $name, 2) ?: [];
        $this->shopper['first_name'] = trim((string) ($parts[0] ?? $name));
        $this->shopper['last_name'] = trim((string) ($parts[1] ?? ''));

        $sessionId = $this->activeSessionId !== '' ? $this->activeSessionId : '';
        if ($sessionId === '') {
            return;
        }

        $meta = self::$sessionMeta[$sessionId] ?? [
            'created' => time(),
            'updated' => time(),
            'tickets_sent' => 0,
            'verified_emails' => [],
        ];
        $meta['provided_name'] = mb_substr($name, 0, 128);
        $meta['updated'] = time();
        self::$sessionMeta[$sessionId] = $meta;

        // Persist meta even if there is no new message this turn.
        $messages = self::$sessions[$sessionId] ?? $this->activeMessages;
        if (!is_array($messages)) {
            $messages = [];
        }
        $this->saveHistory($sessionId, $messages);

        try {
            ChatArchive::attachContact($sessionId, '', $name, (int) ($this->shopper['customers_id'] ?? 0));
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function verifyEmailVerification(array $arguments): array
    {
        if ($this->shopper['logged_in'] && $this->shopper['email'] !== '') {
            return [
                'ok' => true,
                'skipped' => true,
                'verified' => true,
                'email' => $this->shopper['email'],
                'message' => 'Shopper is logged in; email verification is not required.',
            ];
        }

        $email = strtolower(trim((string) ($arguments['email'] ?? '')));
        $code = preg_replace('/\D+/', '', trim((string) ($arguments['code'] ?? ''))) ?? '';
        if ($email === '' || strlen($code) !== 6) {
            return [
                'ok' => false,
                'error' => 'invalid_code_or_email',
                'message' => 'Need the email address and the 6-digit code from the shopper\'s inbox.',
            ];
        }

        try {
            $result = $this->mcpClient->callTool('email.verification.verify', [
                'email' => $email,
                'code' => $code,
            ]);
        } catch (ChatbotException $e) {
            return [
                'ok' => false,
                'error' => 'verify_failed',
                'message' => 'Could not verify that code. Ask the shopper to re-check it or request a new code.',
            ];
        }

        if (!empty($result['error']) || empty($result['verified'])) {
            return [
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'code_mismatch'),
                'message' => (string) ($result['message'] ?? $result['hint'] ?? 'That code did not match. Ask them to try again or request a new code.'),
                'detail' => is_array($result['detail'] ?? null) ? $result['detail'] : [],
            ];
        }

        $sessionId = $this->activeSessionId !== '' ? $this->activeSessionId : 'unknown';
        $this->markEmailVerifiedInSession($sessionId, $email);

        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            $metaName = trim((string) ((self::$sessionMeta[$sessionId]['provided_name'] ?? '') ?: ''));
            if ($metaName !== '') {
                $name = $metaName;
            }
        } else {
            $this->rememberShopperName($name);
        }
        try {
            ChatArchive::attachContact($sessionId, $email, $name, 0);
        } catch (\Throwable $e) {
            // Archive attachment is best-effort.
        }

        return [
            'ok' => true,
            'verified' => true,
            'email' => $email,
            'message' => 'Email verified successfully. You may now proceed (for example open_support_ticket).',
        ];
    }

    private function isEmailVerifiedInSession(string $sessionId, string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }
        $meta = self::$sessionMeta[$sessionId] ?? [];
        $list = $meta['verified_emails'] ?? [];
        if (!is_array($list)) {
            return false;
        }
        foreach ($list as $item) {
            if (strtolower(trim((string) $item)) === $email) {
                return true;
            }
        }
        return false;
    }

    private function markEmailVerifiedInSession(string $sessionId, string $email): void
    {
        $email = strtolower(trim($email));
        if ($sessionId === '' || $email === '') {
            return;
        }
        $meta = self::$sessionMeta[$sessionId] ?? [
            'created' => time(),
            'updated' => time(),
            'tickets_sent' => 0,
            'verified_emails' => [],
        ];
        $list = is_array($meta['verified_emails'] ?? null) ? $meta['verified_emails'] : [];
        if (!in_array($email, $list, true)) {
            $list[] = $email;
        }
        $meta['verified_emails'] = array_values($list);
        self::$sessionMeta[$sessionId] = $meta;
        $this->saveHistory($sessionId, $this->activeMessages);
    }

    private function effectiveSystemPrompt(): string
    {
        $base = trim($this->systemPrompt);
        if ($base === '') {
            $storeName = defined('STORE_NAME') ? trim((string) STORE_NAME) : '';
            $base = $storeName !== ''
                ? 'You are a helpful customer service assistant for ' . $storeName . ' only.'
                : 'You are a helpful customer service assistant for this store only.';
        }

        $storeContext = StoreContext::forPrompt();
        $storeContextBlock = $storeContext !== ''
            ? "Store context (what this business sells — stay inside this):\n{$storeContext}\n"
            : '';

        if ($this->shopper['logged_in']) {
            $who = $this->shopper['first_name'] !== ''
                ? $this->shopper['first_name']
                : 'a logged-in customer';
            $authLine = "Shopper authentication: LOGGED IN as {$who}. You may look up THIS shopper's own orders with mcp_order_lookup using only the order number.";
        } else {
            $authLine = 'Shopper authentication: GUEST (not logged in). You must NOT look up orders, change addresses, cancel orders, reveal order contents, engraving text, addresses, payment details, or any private account information. If they ask about an order, address change, cancel, or similar, politely ask them to log in to their account first. Do not ask a guest for an email address to look up an order.';
        }

        $contactUrl = self::contactUsUrl();
        $contactGuidance = $contactUrl !== ''
            ? "- Store contact page URL (always use this exact URL as a Markdown link when directing the shopper to contact the store): [Contact us]({$contactUrl})\n"
              . "- When you cannot answer confidently, or the shopper asks for a human/agent/customer support, include that Contact us Markdown link in your reply.\n"
              . "- Do not invent or guess a contact URL; only use the URL above.\n"
            : "- When you cannot answer confidently, or the shopper asks for a human/agent/customer support, direct them to the store Contact Us page.\n";

        $ticketGuidance = self::supportEmail() !== ''
            ? "- You may also offer to open a support ticket in chat.\n"
              . "- Logged-in shoppers: use/confirm their account email, then call open_support_ticket. No email verification code is needed.\n"
              . "- Guest shoppers: (1) collect their email, (2) call mcp_email_verification_send, (3) ask them to paste the 6-digit code from their inbox, (4) call mcp_email_verification_verify, (5) only then call open_support_ticket.\n"
              . "- NEVER display, invent, or guess a verification_code. The code arrives only in the shopper's email.\n"
              . "- Optionally ask for their name and a short summary before opening the ticket.\n"
              . "- Do not invent a ticket ID. Only confirm after open_support_ticket returns ok=true.\n"
              . "- Do not use customer_email from chat for order ownership checks — that remains login-only. Support tickets are the exception where collecting an email is allowed.\n"
            : "- Support tickets are not configured for this store; use the Contact us link (or Contact Us page) instead.\n";

        $nameGuidance = "- When the shopper clearly states their name (e.g. \"I'm Alex\", \"My name is Sam Taylor\"), call set_shopper_name with that name so store admins see it in the chat Inbox instead of Guest. Greeting them is not enough — you must call the tool.\n"
            . "- Do not invent or guess a name. Only call set_shopper_name with a name they actually provided.\n";

        $guidance = <<<TXT

{$storeContextBlock}
Store catalog boundary (HARD RULE — never break this):
- You ONLY represent THIS store and its own catalog/policies. The Store context above defines what this business sells.
- NEVER recommend products, product types, brands, gift ideas, or category pages that did not come from THIS store's tools (seekmodo_product_search, mcp_product_search, mcp_product_get, sitemap_find_product, or KB topics).
- If catalog search is empty or weak: do NOT invent alternatives from general knowledge (blankets, gardening tools, jewellery, photo albums, tea sets, flowers, chocolates from memory, etc.). Say you could not find a match in this store's catalog, ask ONE clarifying question, and call seekmodo_product_search again.
- Never mention, compare, or link other retailers.
- When categories are returned by seekmodo_product_search, lead with those category links, then products from the same tool result.

Shopper voice:
- Write for gift shoppers: clear, warm, concise UK English.
- Never use internal terms with shoppers: shipping delta, fee product, needs_fee, requires_fee, editable flags, tool names, topic ids, MCP, runbook steps, or status ids.
- Translate tool results into everyday language (for example "we may need you to pay a bit more postage", "we can update that before it ships", "please check out to confirm the postage difference").
- Do not narrate agent procedures (numbered tool steps) to the shopper. Give a short plain summary, then collect what you need.

Ambiguous intent:
- If the shopper asks to "change an order" / "change something" / "the process to change an order" without saying what, briefly list common options (delivery address, personalisation/engraving text, cancel, faster delivery), then ask which they mean before starting a flow or explaining a full process.
- Only after they choose (or intent is obvious) call the matching topic/tools and continue.

Privacy:
- {$authLine}
- Never reveal private order or customer-account details to guests.
- Never invent order or account details.

Product recommendations:
- For any product discovery, gift idea, or "what do you recommend" question, call seekmodo_product_search FIRST with the shopper's intent as the query.
- Prefer Seekmodo results over sitemap_find_product and mcp_product_search. If seekmodo_product_search returns available=false / seekmodo_unavailable, call mcp_product_search once as a fallback before giving up.
- Recommend only products and category pages returned by tools. Never suggest other retailers. Never invent category or product URLs.
- When seekmodo_product_search returns a non-empty categories array, ALWAYS lead with those category page links (1–3 Markdown links like [70th Birthday Gifts](url)) before listing products — especially for broad occasion/age/gift queries. Categories let the shopper browse the full range; products are concrete starters.
- Never invent generic gift ideas from general knowledge when the catalog search is empty or weak. That reads as "I couldn't find anything" filler — do not do it.
- Open-ended gift asks (recipient/age/occasion with little product type — e.g. "gift for my 70-year-old nan", "something for dad"):
  1) Call seekmodo_product_search with a practical catalog query (use "personalised gift", recipient words like nan/mum/dad, and any age/occasion hints — not a literal dump of the whole sentence if that looks too verbose).
  2) If categories and/or products come back: present matching categories first (when present), then up to 5 catalog products in the Markdown format below, then ask ONE short clarifying question (interests/hobbies, product type, budget, photo vs text personalisation) so the next search can be tighter.
  3) If the search returns nothing useful (empty products AND empty categories, found=0, or available=false even after the mcp_product_search fallback): do NOT say you "couldn't find specific products" and then invent ideas. Ask ONE clarifying question (interests, product type, budget) and say you will search again with that focus — then on their reply, call seekmodo_product_search again with the tighter query.
- When the shopper says suggestions feel generic / common / not unique, stop dumping more SKUs and ask about tastes/interests before searching again.
- Format each product recommendation in Markdown like this (use the real `url` and `image_url` values from the tool result — never placeholder hosts like storefront-url or image-url):
  1. **[Product Name](URL_FROM_TOOL)**
     Price: £X.XX
     One short sentence about why it fits.
     ![Product Name](IMAGE_URL_FROM_TOOL)
- Include the storefront URL as a Markdown link and the image_url as a Markdown image when available. If `image_url` is empty, omit the image line entirely — do not invent or guess an image path.
- Use mcp_product_get for deeper detail after you have a products_id.
- Use kb_search for long-form policy pages after fetching the matching topic when one exists.
- For general FAQ questions (tracking how-to, discount codes, accounts, engraving characters/emojis, invoice removal, order changes): call mcp_kb_topic_get with topic_id faq, then kb_search (prefer compiled page gv_faq). Do not invent policy.
- For shipping, delivery ETA, or destination questions, call mcp_kb_topic_get with topic_id shipping (or international for abroad) BEFORE answering, then kb_search for delivery / shippinginfo / gv_faq. Do not invent transit times or claim the store does not ship to a country.
- For returns or exchanges, call mcp_kb_topic_get with topic_id returns, then kb_search for shipping_and_returns / gv_faq, then use mcp_return_request for logged-in shoppers (never invent refunds or RMA numbers). Guests should log in or use a support ticket after email verification.
- For cancel or address-change policy, call mcp_kb_topic_get with topic_id order-cancel or order-address-change (and order-edit for personalisation edits) BEFORE guiding the shopper. Prefer self-service My Account links when order mutations are disabled. Do not invent refunds or outcomes.
- Coupons: mcp_kb_topic_get coupons then mcp_coupon_validate; never invent discount amounts.
- Store credit: logged-in mcp_store_credit_balance; never invent balances.
- Production/proof: use verify.production_stage, has_customily_line, pdf_available from mcp_order_lookup.
- Bulk/logo: mcp_kb_topic_get bulk-orders; collect qty/logo notes/contact/deadline; mcp_bulk_request; NEVER quote prices.
- Photo upload / image personalisation / logo upload ("can I upload a photo", "can I upload a logo", "is this personalisable", "which products let me add my own picture"): call mcp_kb_topic_get with topic_id personalisation BEFORE answering, every time — never rely on memory of the topic from earlier in the chat. Many products support photo/logo upload on the product page — never invent a sitewide "we do not support photo uploads". Then:
  - Bare/no product named ("can I upload a photo/logo?" with nothing else): call seekmodo_product_search FIRST (prefer photo_upload_only=true; include any hints like "logo"/"photo" in the query). If results come back, briefly confirm uploads are available on those kinds of products and present up to 5–8 Markdown links, then ask ONE short question only if you need a tighter category (e.g. mug vs glass) for a better list. If the search returns nothing useful (or the facet is unavailable), say you could not find matching products yet and ask ONE clarifying question (which product, or what kind of gift) so you can search again with a focused query — never stop at "I couldn't find anything" without offering that next step.
  - Named product or type given (in this message or the clarifying answer): call seekmodo_product_search with that product/type as the query, then mcp_product_get on the top match; answer strictly from personalisation_capabilities.supports_photo_upload / .supports_text_personalisation, and link the product URL. If unconfirmed for that SKU (capability false or absent), say so plainly and offer photo-capable alternatives via seekmodo_product_search — never invent.
  - "Which products allow photo uploads" / any broad list request with no specific item: call seekmodo_product_search with photo_upload_only=true (add the shopper's category/occasion words to query when given, e.g. "mug", "christmas"; omit query entirely for a fully generic list). Present up to 5-8 results as a short Markdown list with links; if the search tool has no supports_photo_upload facet yet (older index), say you cannot give a reliable full list yet and offer to check specific products instead.
  - Never answer any of the above from kb_search or general knowledge alone — the topic sets policy, the product tools confirm facts.
  - Never claim you "couldn't find" a product capability without having called seekmodo_product_search this turn, and after an empty search always ask a clarifying question for a focused re-search.
- Care/cleaning: mcp_kb_topic_get care; use kb_search for long-form care pages; never invent care steps.
- Gift wrap / gift bag / sending directly to a recipient (no invoice, gift card message): mcp_kb_topic_get gift-packaging first. This is a per-product option, not sitewide — confirm for a named product via seekmodo_product_search + mcp_product_get before promising availability.
- Payment methods ("what cards do you accept", checkout payment help): mcp_kb_topic_get payments first. Only confirm methods the topic or kb_search returns; never invent a gateway; never ask for or repeat card numbers/CVV in chat.
- Gift vouchers / store credit (balance, purchase, redeem): mcp_kb_topic_get gift-vouchers first. Balance requires login + mcp_store_credit_balance; purchase/redeem how-to comes only from kb_search — never invent amounts or process.
- Learning from tool results: after you give a shopper a genuinely useful product-capability answer that came from a tool this turn (e.g. confirmed via mcp_product_get / seekmodo_product_search, not from memory or guessing), you MAY silently call mcp_kb_fact_propose with a short claim + the evidence fields the tool actually returned. Never tell the shopper you are doing this. Never propose prices, refund amounts/timing, shipping promises, or medical/safety claims — skip proposing rather than guess. This never changes what you tell the shopper and nothing is auto-published; an operator reviews every draft separately.

- For warranty policy or damaged-item questions: call mcp_kb_topic_get with topic_id warranty BEFORE answering; then mcp_warranty_request for claims. Never invent approvals or refunds.
- Account/login/newsletter: mcp_kb_topic_get account; forgot password → mcp_password_reset; newsletter (logged-in) → mcp_newsletter_set; never invent account state.
- Warranty: For warranty policy or damaged-item questions call mcp_kb_topic_get with topic_id warranty BEFORE answering; then mcp_warranty_request for claims. Never invent approvals/refunds; contact within 14 days of delivery when policy applies.
- Newsletter: logged-in only mcp_newsletter_set; confirm intent; Mailchimp sync may lag; never invent subscription state without the tool.
- Wishlist: no tool — offer product links / Seekmodo or support ticket; do not invent wishlist APIs.

Orders / account help:
- Private order lookup requires a logged-in shopper. Call mcp_order_lookup with orders_id only.
- Do not accept or use a customer_email from the chat message for ownership checks.
- If lookup returns login_required, tell the shopper to log in and try again. If they cannot log in / forgot their password, collect their email and call mcp_password_reset, then tell them to check inbox/spam — never reveal whether an account exists.
- If the shopper did not receive an order confirmation email, call mcp_order_confirmation_resend with their orders_id (logged-in only).
- If lookup fails ownership verification, say you could not find that order on their account; do not reveal whether the order exists for someone else.
- When the shopper asks for shipping/tracking status, call mcp_order_lookup and use verify.has_tracking, verify.tracking_reference, and verify.tracking_url.
- If has_tracking is true, share the tracking number and the Markdown tracking link. If false, say tracking is not available yet (often before dispatch).
- Before helping with personalisation/order edits, ALWAYS call mcp_order_lookup first and inspect verify.orders_status_name and verify.engraving_editable. Also read lines[].engravings / attributes for the current text, current_length, and character_limit when present. Call mcp_kb_topic_get (order-edit) for general "can I edit my order?" questions.
- Engraving editability is decided ONLY by verify.engraving_editable. Tracking can exist on still-editable orders (including test tracking) — never treat has_tracking as "already processed".
- If engraving_editable is false (for example status Printed, Dispatched, Shipped, Delivered), tell the shopper the order has already been processed/shipped and cannot be modified online. Do not offer an account personalisation editor link; offer a support ticket if they still need help.
- If engraving_editable is true, share a Markdown link to lines[].personalise_url when present (My Account live-preview editor) so the shopper can edit themselves. Never invent a personalise_url — only use the exact URL returned from mcp_order_lookup.
- In-chat order mutations (mcp_order_engraving_update, address-change start/apply, shipping-upgrade start, cancel request) are available ONLY when those tools are listed for this session. When they are not listed, do not claim you can change the order in chat — guide the shopper to the self-service link or a support ticket.
- When mcp_order_engraving_update IS available and the shopper wants you to change text in chat: honour character_limit, pass old_text/new_text from lookup, and never invent a different limit. When sharing a production PDF after an update, ONLY use result.pdf_url exactly as returned.
- NEVER invent order status changes or refunds. Only report what tools return. If a tool refuses or the order is shipped/not editable, escalate via support ticket.
- NEVER claim you changed an order unless the relevant tool returned ok=true. If it returned ok=true, confirm using the tool's customer_summary / message (and share result.pdf_url when present for engraving). Never contradict a successful tool result.
- Agent-only procedure (do not narrate these steps to the shopper; summarise in plain language and ask for what you need):
  - Personalisation edits (default): mcp_order_lookup → if editable, Markdown-link personalise_url; if not editable, explain status and offer support. Use mcp_order_engraving_update only when that tool is available and the shopper wants the change applied in chat.
  - Shipping upgrades: only when mcp_shipping_upgrade_* tools are available — options → start → status after payment. Otherwise explain they may need support for a faster method, or open a ticket.
  - Address changes: call mcp_kb_topic_get (order-address-change). Prefer My Account order history self-service when mutation tools are unavailable; when address-change tools are available, preview/start then confirm. If tools refuse or the order has shipped, offer a support ticket.
  - Order cancel: call mcp_kb_topic_get (order-cancel). When cancel tools are available, use capabilities then request. When not available, offer a support ticket — never invent refunds.
  - Returns / exchanges (logged-in): call mcp_kb_topic_get (returns), collect order number/reason/items, call mcp_return_request, and share only the customer_summary. Guests: ask them to log in, or open a support ticket after email verification.

Shopper name:
{$nameGuidance}
Human support / contact:
{$contactGuidance}{$ticketGuidance}
TXT;

        if ($this->additionalRules !== '') {
            $guidance .= "\n\nAdditional admin rules:\n" . $this->additionalRules;
        }

        return $base . "\n" . $guidance;
    }

    /**
     * @param array<string, mixed> $verify
     * @return array<string, mixed>
     */
    private function publicOrderVerify(array $verify): array
    {
        // Keep chat responses limited to status/id/tracking for the logged-in owner.
        $tracking = trim((string) ($verify['tracking_reference'] ?? ''));
        $trackingUrl = trim((string) ($verify['tracking_url'] ?? ''));
        return [
            'orders_id' => (int) ($verify['orders_id'] ?? 0),
            'orders_status' => (int) ($verify['orders_status'] ?? 0),
            'orders_status_name' => (string) ($verify['orders_status_name'] ?? ''),
            'engraving_editable' => !empty($verify['engraving_editable']),
            'has_customily_line' => !empty($verify['has_customily_line']),
            'has_native_engraving_line' => !empty($verify['has_native_engraving_line']),
            'pdf_available' => !empty($verify['pdf_available']),
            'production_stage' => (string) ($verify['production_stage'] ?? ''),
            'has_tracking' => $tracking !== '',
            'tracking_reference' => $tracking,
            'tracking_url' => $trackingUrl,
            'owned_by_logged_in_customer' => true,
        ];
    }

    /**
     * Expose only the admin-linked PDF URL to the model (not Customily CDN URLs).
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function publicEngravingUpdateResult(array $result): array
    {
        $pdfRegen = is_array($result['pdf_regeneration'] ?? null) ? $result['pdf_regeneration'] : [];
        $pdfUrl = $this->encodePdfUrlForChat((string) ($pdfRegen['pdf_url'] ?? ''));
        $public = [
            'ok' => !empty($result['ok']),
            'orders_id' => (int) ($result['orders_id'] ?? 0),
            'orders_products_id' => (int) ($result['orders_products_id'] ?? 0),
            'old_text' => (string) ($result['old_text'] ?? ''),
            'new_text' => (string) ($result['new_text'] ?? ''),
            'source' => (string) ($result['source'] ?? ''),
            'pdf_url' => $pdfUrl,
            'pdf_regenerated' => !empty($pdfRegen['ok']) || $pdfUrl !== '',
        ];
        if ((string) ($pdfRegen['error'] ?? '') !== '') {
            $public['pdf_error'] = (string) $pdfRegen['error'];
        }

        return $public;
    }

    /**
     * Ensure Converted_to_pdf "#" filenames are safe to paste into Markdown links.
     */
    private function encodePdfUrlForChat(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strpos($url, '#') === false) {
            return $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return str_replace('#', '%23', $url);
        }
        $path = (string) ($parts['path'] ?? '/');
        $encodedPath = implode('/', array_map(
            static function (string $segment): string {
                return rawurlencode(rawurldecode($segment));
            },
            explode('/', ltrim($path, '/'))
        ));
        $out = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $out .= ':' . $parts['port'];
        }
        $out .= '/' . $encodedPath;
        if (!empty($parts['query'])) {
            $out .= '?' . $parts['query'];
        }
        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildToolDefinitions(): array
    {
        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'kb_search',
                    'description' => 'Search the store knowledge base for policy, shipping, returns, about-us, and FAQ information.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'Search query for knowledge base content'],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'seekmodo_product_search',
                    'description' => 'PRIMARY catalog search via Seekmodo. Returns products AND matching category pages (name + url). Use first for recommendations and gift ideas. Prefer leading with categories when present, then products. Product fields: name, price, URL, short description, and (when indexed) supports_photo_upload / supports_text_personalisation / personalisation_engine.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'Natural-language product search query (e.g. "guinness pint glass", "engraved wine glass for mum", "70th birthday gifts"). May be omitted only when photo_upload_only is set and the shopper wants a broad "which products" list rather than a named item/type.',
                            ],
                            'limit' => [
                                'type' => 'integer',
                                'description' => 'Max products to return (default 8, max 20)',
                            ],
                            'photo_upload_only' => [
                                'type' => 'boolean',
                                'description' => 'Set true to restrict results to products that support photo/image upload personalisation. Use for "which products allow photo uploads" style questions instead of guessing from descriptions.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'sitemap_find_product',
                    'description' => 'Fallback product URL lookup from the sitemap index. Prefer seekmodo_product_search for recommendations.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'Product name or keyword to search for'],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'mcp_product_get',
                    'description' => 'Get full product details including description, price, attributes, Q&A, canonical storefront URL, and personalisation_capabilities (supports_photo_upload, supports_text_personalisation, engine) — use this to confirm capability for one named product.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'products_id' => ['type' => 'integer', 'description' => 'The numeric product ID'],
                        ],
                        'required' => ['products_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'mcp_product_search',
                    'description' => 'Secondary SQL product search by name/SKU via Numinix MCP. Prefer seekmodo_product_search for shopper recommendations.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string', 'description' => 'Product name to search for'],
                            'sku' => ['type' => 'string', 'description' => 'Product SKU/model to search for'],
                            'limit' => ['type' => 'integer', 'description' => 'Maximum results (default 25)'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'mcp_product_resolve',
                    'description' => 'Resolve a product by products_id, model/SKU, or storefront URL.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'products_id' => ['type' => 'integer', 'description' => 'Resolve by products_id'],
                            'model' => ['type' => 'string', 'description' => 'Resolve by product model/SKU'],
                            'url' => ['type' => 'string', 'description' => 'Resolve by storefront URL'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'mcp_order_lookup',
                    'description' => 'Look up an order for the currently logged-in shopper only. Returns status name, engraving_editable, Royal Mail tracking when available, lines[].engravings (text/current_length/character_limit), and lines[].personalise_url for editable personalisation lines (account live-preview editor). Ownership is checked against the storefront login session.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'orders_id' => ['type' => 'integer', 'description' => 'The order number'],
                        ],
                        'required' => ['orders_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'mcp_order_engraving_update',
                    'description' => 'Update engraving/personalisation text on a logged-in shopper\'s editable order. Call mcp_order_lookup first and honour lines[].engravings[].character_limit. Use only when verify.engraving_editable is true — tracking alone does not block edits. Fails with engraving_too_long when new_text exceeds the field limit, or if the order is Printed, Dispatched, Shipped, or Delivered. Never use this to change order status.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'orders_id' => ['type' => 'integer', 'description' => 'The order number'],
                            'old_text' => ['type' => 'string', 'description' => 'Current engraving text to replace'],
                            'new_text' => ['type' => 'string', 'description' => 'Replacement engraving text (must fit character_limit from mcp_order_lookup when set)'],
                            'product_name_contains' => [
                                'type' => 'string',
                                'description' => 'Optional product name fragment to target the correct line',
                            ],
                            'orders_products_id' => [
                                'type' => 'integer',
                                'description' => 'Optional order line id from mcp_order_lookup',
                            ],
                        ],
                        'required' => ['orders_id', 'old_text', 'new_text'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'mcp_shipping_upgrade_options',
                    'description' => 'Live-quote shipping upgrades for a logged-in shopper\'s editable order. Returns current method and upgrade options with price deltas. Call this when the shopper wants faster shipping on an existing order.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'orders_id' => ['type' => 'integer', 'description' => 'The order number'],
                        ],
                        'required' => ['orders_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'mcp_shipping_upgrade_start',
                    'description' => 'Create the shipping-upgrade fee product and auto-add it to the shopper cart. Call after mcp_shipping_upgrade_options when the shopper chooses a method. Returns checkout_url to share as a Markdown link.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'orders_id' => ['type' => 'integer', 'description' => 'The order number'],
                            'module' => [
                                'type' => 'string',
                                'description' => 'module or shipping_id from mcp_shipping_upgrade_options',
                            ],
                            'method' => [
                                'type' => 'string',
                                'description' => 'Optional method id from options when needed',
                            ],
                        ],
                        'required' => ['orders_id', 'module'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'mcp_shipping_upgrade_status',
                    'description' => 'Check whether a shipping upgrade is pending or applied for a logged-in shopper\'s order. Use after they say they paid the upgrade fee.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'orders_id' => ['type' => 'integer', 'description' => 'The original order number (not the fee order)'],
                        ],
                        'required' => ['orders_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'mcp_kb_topics_list',
                    'description' => 'List customer-service policy topic ids available via MCP (e.g. shipping, returns, international, order-cancel, order-address-change, shipping-upgrade).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'mcp_kb_topic_get',
                    'description' => 'Fetch a customer-service policy topic (process + customer guidance). Call with shipping, returns, international, order-cancel, or order-address-change before those flows.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'topic_id' => [
                                'type' => 'string',
                                'description' => 'Topic id from mcp_kb_topics_list (e.g. shipping, returns, personalisation, international, order-cancel, gift-packaging, payments, gift-vouchers)',

                            ],
                        ],
                        'required' => ['topic_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'mcp_kb_fact_propose',
                    'description' => 'Queue a knowledge-fact draft for operator review after answering a shopper from live tool data (e.g. product.get / seekmodo_product_search). '
                        . 'NEVER tell the shopper you are doing this — it is silent and internal. Only propose facts directly evidenced by a tool result you just called this turn, never opinions or guesses. '
                        . 'NEVER propose prices, refund amounts/timing, shipping promises, or medical/safety claims. Nothing here is auto-published; an operator must approve it separately. Use sparingly, only after a genuinely useful product/catalog capability answer.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'claim' => [
                                'type' => 'string',
                                'description' => 'Short factual statement evidenced by the tool result (e.g. "Engraved Wine Glass (SKU 12345) supports photo upload via a native FILE option").',
                            ],
                            'evidence' => [
                                'type' => 'object',
                                'description' => 'Key/value evidence actually returned by a tool this turn (e.g. products_id, tool name, matched option names). Never fabricate values.',
                            ],
                            'topic_id' => [
                                'type' => 'string',
                                'description' => 'Optional existing topic id (from mcp_kb_topics_list) this claim relates to, e.g. personalisation.',
                            ],
                            'scope' => [
                                'type' => 'string',
                                'enum' => ['general', 'product', 'policy', 'topic'],
                                'description' => 'Category of the claim. Defaults to general.',
                            ],
                        ],
                        'required' => ['claim'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'mcp_order_address_change_preview',
                    'description' => 'Preview a delivery address change for a logged-in shopper\'s editable order. Returns shipping_delta and needs_fee/requires_fee. Call before or as part of confirming an address change.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'orders_id' => ['type' => 'integer', 'description' => 'The order number'],
                            'fields' => [
                                'type' => 'object',
                                'description' => 'Proposed delivery fields (name, street_address, city, postcode, state, country, or delivery_* keys)',
                            ],
                        ],
                        'required' => ['orders_id', 'fields'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'mcp_order_address_change_start',
                    'description' => 'Apply a delivery address change for a logged-in shopper: previews, applies immediately when shipping does not increase, or creates a fee product + checkout_url when it does. Prefer this after the shopper confirms the new address.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'orders_id' => ['type' => 'integer', 'description' => 'The order number'],
                            'fields' => [
                                'type' => 'object',
                                'description' => 'Proposed delivery fields (name, street_address, city, postcode, state, country, or delivery_* keys)',
                            ],
                        ],
                        'required' => ['orders_id', 'fields'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'mcp_order_address_change_status',
                    'description' => 'Check pending/applied address-change fee records for a logged-in shopper\'s order. Use after they pay an address-change shipping fee.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'orders_id' => ['type' => 'integer', 'description' => 'The original order number'],
                        ],
                        'required' => ['orders_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'mcp_order_cancel_capabilities',
                    'description' => 'Report cancel/hold capabilities for a logged-in shopper\'s order (editable vs shipped, cancel mode, allowed actions). Call before mcp_order_cancel_request.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'orders_id' => ['type' => 'integer', 'description' => 'The order number'],
                        ],
                        'required' => ['orders_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'mcp_order_cancel_request',
                    'description' => 'Request full or partial cancel for a logged-in shopper\'s order per store cancel mode. Follow mcp_kb_topic_get order-cancel and mcp_order_cancel_capabilities first. Do not invent refunds — only report the tool customer_summary.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'orders_id' => ['type' => 'integer', 'description' => 'The order number'],
                            'mode' => [
                                'type' => 'string',
                                'enum' => ['full', 'partial'],
                                'description' => 'full cancels the whole order; partial targets line item(s)',
                            ],
                            'orders_products_ids' => [
                                'type' => 'array',
                                'items' => ['type' => 'integer'],
                                'description' => 'Optional order line ids for partial cancel',
                            ],
                            'reason' => [
                                'type' => 'string',
                                'description' => 'Optional shopper reason for the cancel request',
                            ],
                        ],
                        'required' => ['orders_id', 'mode'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'mcp_order_confirmation_resend',
                    'description' => 'Resend the order confirmation email for a logged-in shopper\'s order. Use when they say they did not receive confirmation.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'orders_id' => ['type' => 'integer', 'description' => 'The order number'],
                        ],
                        'required' => ['orders_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'mcp_password_reset',
                    'description' => 'Send a native store password-reset email for the given account email. Use when the shopper cannot log in or forgot their password. Never reveal whether an account exists — always tell them to check inbox/spam if an account exists.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'email' => [
                                'type' => 'string',
                                'description' => 'Email address on the shopper account',
                            ],
                        ],
                        'required' => ['email'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'mcp_return_request',
                    'description' => 'Queue a returns or exchanges request for a logged-in shopper\'s order (CS intake only — never auto-refunds). Call mcp_kb_topic_get with topic_id returns first. Do not invent RMA numbers or refund timing.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'orders_id' => ['type' => 'integer', 'description' => 'The order number'],
                            'reason' => [
                                'type' => 'string',
                                'description' => 'Why the shopper wants a return or exchange',
                            ],
                            'items' => [
                                'type' => 'string',
                                'description' => 'Optional description of which items are affected',
                            ],
                            'request_type' => [
                                'type' => 'string',
                                'enum' => ['return', 'exchange'],
                                'description' => 'return (default) or exchange',
                            ],
                        ],
                        'required' => ['orders_id', 'reason'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'mcp_coupon_validate',
                    'description' => 'Validate a coupon or discount code (active, dates, restrictions). Call mcp_kb_topic_get with topic_id coupons first. Never invent discount amounts — only report what the tool returns.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'code' => [
                                'type' => 'string',
                                'description' => 'Coupon / discount code to check',
                            ],
                        ],
                        'required' => ['code'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'mcp_store_credit_balance',
                    'description' => 'Return the logged-in shopper\'s gift voucher / store credit balance. Login required. Never invent balances.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [],
                        'required' => [],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'mcp_bulk_request',
                    'description' => 'Queue a bulk order, logo, or design-change enquiry for humans. Call mcp_kb_topic_get with topic_id bulk-orders first. Collect quantity, logo notes, and contact email (deadline optional). NEVER quote prices.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'contact_email' => [
                                'type' => 'string',
                                'description' => 'Shopper email for follow-up',
                            ],
                            'quantity' => [
                                'type' => 'string',
                                'description' => 'Approximate quantity or volume needed',
                            ],
                            'logo_notes' => [
                                'type' => 'string',
                                'description' => 'Logo / artwork / file notes (describe what they have — no file upload)',
                            ],
                            'contact_name' => [
                                'type' => 'string',
                                'description' => 'Optional shopper name',
                            ],
                            'deadline' => [
                                'type' => 'string',
                                'description' => 'Optional needed-by date or event',
                            ],
                            'orders_id' => [
                                'type' => 'integer',
                                'description' => 'Optional related order number',
                            ],
                        ],
                        'required' => ['contact_email', 'quantity', 'logo_notes'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'mcp_warranty_request',
                    'description' => 'Queue a warranty claim for humans (CS intake only — never auto-approves or refunds). Call mcp_kb_topic_get with topic_id warranty first. Do not invent approvals or refunds.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'contact_email' => [
                                'type' => 'string',
                                'description' => 'Shopper email for follow-up (optional if logged in — uses account email)',
                            ],
                            'issue_summary' => [
                                'type' => 'string',
                                'description' => 'What is wrong / warranty claim description',
                            ],
                            'contact_name' => [
                                'type' => 'string',
                                'description' => 'Optional shopper name',
                            ],
                            'items' => [
                                'type' => 'string',
                                'description' => 'Optional product / line description',
                            ],
                            'orders_id' => [
                                'type' => 'integer',
                                'description' => 'Optional related order number',
                            ],
                        ],
                        'required' => ['issue_summary'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'mcp_newsletter_set',
                    'description' => 'Subscribe or unsubscribe the logged-in shopper from the store newsletter. Login required. Confirm intent first. Never invent subscription state — only report the tool message. Mailchimp/ESP sync may lag.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'subscribed' => [
                                'type' => 'boolean',
                                'description' => 'true to subscribe, false to unsubscribe',
                            ],
                        ],
                        'required' => ['subscribed'],
                    ],
                ],
            ],
        ];

        $tools[] = [
            'type' => 'function',
            'function' => [
                'name' => 'set_shopper_name',
                'description' => 'Save the shopper\'s display name on this chat so store admins see it in the Inbox (instead of Guest). Call whenever a guest (or logged-in shopper without a name on file) clearly gives their name — e.g. "I\'m Sarah", "My name is Jeff". Do not invent a name.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'description' => 'Shopper first name, or first and last name',
                        ],
                    ],
                    'required' => ['name'],
                ],
            ],
        ];

        $tools[] = [
            'type' => 'function',
            'function' => [
                'name' => 'mcp_email_verification_send',
                'description' => 'Guest only: send a 6-digit email verification code via the store email template. Call when a guest provides an email (e.g. before a support ticket). Logged-in shoppers do not need this. Never show a code to the shopper — ask them to paste it from their inbox.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'email' => [
                            'type' => 'string',
                            'description' => 'Guest email address to verify',
                        ],
                        'name' => [
                            'type' => 'string',
                            'description' => 'Optional recipient display name',
                        ],
                    ],
                    'required' => ['email'],
                ],
            ],
        ];

        $tools[] = [
            'type' => 'function',
            'function' => [
                'name' => 'mcp_email_verification_verify',
                'description' => 'Guest only: confirm the 6-digit code the shopper pasted from their verification email. Call after mcp_email_verification_send. On success you may proceed with open_support_ticket.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'email' => [
                            'type' => 'string',
                            'description' => 'Email address that received the code',
                        ],
                        'code' => [
                            'type' => 'string',
                            'description' => '6-digit code pasted by the shopper',
                        ],
                        'name' => [
                            'type' => 'string',
                            'description' => 'Optional shopper name to attach to the chat archive',
                        ],
                    ],
                    'required' => ['email', 'code'],
                ],
            ],
        ];

        if (self::supportEmail() !== '') {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'open_support_ticket',
                    'description' => 'Escalate when you cannot help or the shopper asks for a human. If a store admin is online (admin/dashboard), notifies them for live chat (no guest email verification). If no admin is online, returns no_admin_online — tell the shopper a live agent is unavailable and offer an email ticket; only if they agree, call again with confirm_email_ticket=true (guests must verify email first). On failure, share contact_url Markdown.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'customer_email' => [
                                'type' => 'string',
                                'description' => 'Shopper email address for support to reply to',
                            ],
                            'customer_name' => [
                                'type' => 'string',
                                'description' => 'Optional shopper name',
                            ],
                            'summary' => [
                                'type' => 'string',
                                'description' => 'Short summary of what the shopper needs help with',
                            ],
                            'confirm_email_ticket' => [
                                'type' => 'boolean',
                                'description' => 'Set true only after the shopper agrees to an email ticket because no live admin is available',
                            ],
                        ],
                        'required' => ['customer_email'],
                    ],
                ],
            ];
        }

        $tools = array_merge($tools, $this->tenantChatTools()->definitions());

        return self::filterToolsByOrderMutationPolicy($tools);
    }

    /**
     * When false (default), the assistant must not mutate orders in chat —
     * guide shoppers to My Account self-service links from mcp_order_lookup.
     */
    private static function orderMutationsEnabled(): bool
    {
        if (!defined('SEEKMODO_AI_CHATBOT_ALLOW_ORDER_MUTATIONS')) {
            return false;
        }
        $value = strtolower(trim((string) SEEKMODO_AI_CHATBOT_ALLOW_ORDER_MUTATIONS));
        return $value === 'true' || $value === '1' || $value === 'yes' || $value === 'on';
    }

    /**
     * Tools that change an existing order (or start a paid mutation flow).
     *
     * @return list<string>
     */
    private static function orderMutationToolNames(): array
    {
        return [
            'mcp_order_engraving_update',
            'mcp_shipping_upgrade_options',
            'mcp_shipping_upgrade_start',
            'mcp_shipping_upgrade_status',
            'mcp_order_address_change_preview',
            'mcp_order_address_change_start',
            'mcp_order_address_change_status',
            'mcp_order_cancel_capabilities',
            'mcp_order_cancel_request',
        ];
    }

    private static function isOrderMutationTool(string $toolName): bool
    {
        return in_array($toolName, self::orderMutationToolNames(), true);
    }

    /**
     * @param list<array<string, mixed>> $tools
     * @return list<array<string, mixed>>
     */
    private static function filterToolsByOrderMutationPolicy(array $tools): array
    {
        if (self::orderMutationsEnabled()) {
            return $tools;
        }
        $blocked = array_fill_keys(self::orderMutationToolNames(), true);
        $filtered = [];
        foreach ($tools as $tool) {
            $name = (string) ($tool['function']['name'] ?? '');
            if ($name !== '' && isset($blocked[$name])) {
                continue;
            }
            $filtered[] = $tool;
        }
        return $filtered;
    }

    /**
     * @param list<array{role: string, content: string, tool_calls?: array, tool_call_id?: string}> $messages
     * @return list<array{role: string, content: string, tool_calls?: array, tool_call_id?: string}>
     */
    private function cleanMessages(array $messages): array
    {
        $messages = $this->compactToolResults($messages);
        $cleaned = [];
        foreach ($messages as $msg) {
            $entry = ['role' => $msg['role'], 'content' => $msg['content'] ?? ''];
            if (!empty($msg['tool_calls'])) {
                $entry['tool_calls'] = $msg['tool_calls'];
            }
            if (!empty($msg['tool_call_id'])) {
                $entry['tool_call_id'] = $msg['tool_call_id'];
            }
            $cleaned[] = $entry;
        }
        return $cleaned;
    }

    /**
     * Shrink older tool payloads so long product-search dumps do not fill the context window.
     *
     * @param list<array{role: string, content: string, tool_calls?: array, tool_call_id?: string}> $messages
     * @return list<array{role: string, content: string, tool_calls?: array, tool_call_id?: string}>
     */
    private function compactToolResults(array $messages): array
    {
        $toolIndexes = [];
        foreach ($messages as $i => $msg) {
            if (($msg['role'] ?? '') === 'tool') {
                $toolIndexes[] = $i;
            }
        }
        if ($toolIndexes === []) {
            return $messages;
        }

        // Keep the most recent tool results fuller; compress older ones aggressively.
        $keepFull = array_slice($toolIndexes, -2);
        $keepFullLookup = array_fill_keys($keepFull, true);
        $maxFull = 2500;
        $maxCompact = 400;

        foreach ($toolIndexes as $i) {
            $content = (string) ($messages[$i]['content'] ?? '');
            $limit = isset($keepFullLookup[$i]) ? $maxFull : $maxCompact;
            if (strlen($content) <= $limit) {
                continue;
            }
            $messages[$i]['content'] = substr($content, 0, $limit) . '…[truncated]';
        }

        return $messages;
    }

    /**
     * @return list<array{role: string, content: string, tool_calls?: array, tool_call_id?: string}>
     */
    private function loadHistory(string $sessionId): array
    {
        if (isset(self::$sessions[$sessionId])) {
            return self::$sessions[$sessionId];
        }

        $file = $this->sessionFile($sessionId);
        if (!is_file($file)) {
            self::$sessionMeta[$sessionId] = [
                'created' => time(),
                'updated' => time(),
                'tickets_sent' => 0,
                'verified_emails' => [],
            ];
            return [];
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }

        $now = time();
        $updated = (int) ($data['updated'] ?? 0);
        $created = (int) ($data['created'] ?? $updated);
        $idle = self::idleSeconds();
        $maxAge = self::maxAgeSeconds();

        $expired = ($updated > 0 && ($now - $updated) > $idle)
            || ($created > 0 && ($now - $created) > $maxAge);

        if ($expired) {
            @unlink($file);
            $this->historyReset = true;
            self::$sessionMeta[$sessionId] = [
                'created' => $now,
                'updated' => $now,
                'tickets_sent' => 0,
                'verified_emails' => [],
            ];
            return [];
        }

        $messages = $data['messages'] ?? [];
        if (!is_array($messages)) {
            return [];
        }

        $verified = $data['verified_emails'] ?? [];
        if (!is_array($verified)) {
            $verified = [];
        }
        $verified = array_values(array_filter(array_map(static function ($v) {
            return strtolower(trim((string) $v));
        }, $verified)));

        $providedName = trim((string) ($data['provided_name'] ?? ''));
        self::$sessionMeta[$sessionId] = [
            'created' => $created > 0 ? $created : $now,
            'updated' => $updated > 0 ? $updated : $now,
            'tickets_sent' => (int) ($data['tickets_sent'] ?? 0),
            'verified_emails' => $verified,
            'provided_name' => $providedName,
            'assistant_offline' => !empty($data['assistant_offline']),
            'offline_ticket_opened' => !empty($data['offline_ticket_opened']),
            'offline_ticket_declined' => !empty($data['offline_ticket_declined']),
        ];
        if ($providedName !== '' && trim($this->shopper['first_name'] . ' ' . $this->shopper['last_name']) === '') {
            $parts = preg_split('/\s+/', $providedName, 2) ?: [];
            $this->shopper['first_name'] = trim((string) ($parts[0] ?? $providedName));
            $this->shopper['last_name'] = trim((string) ($parts[1] ?? ''));
        }

        /** @var list<array{role: string, content: string, tool_calls?: array, tool_call_id?: string}> $messages */
        self::$sessions[$sessionId] = $messages;
        return $messages;
    }

    /**
     * @param list<array{role: string, content: string, tool_calls?: array, tool_call_id?: string}> $messages
     */
    private function saveHistory(string $sessionId, array $messages): void
    {
        $messages = $this->compactToolResults($messages);
        $maxHistory = self::maxHistoryMessages();
        if (count($messages) > $maxHistory) {
            $messages = $this->trimHistoryPreservingToolPairs($messages, $maxHistory);
        }

        self::$sessions[$sessionId] = $messages;

        $now = time();
        $meta = self::$sessionMeta[$sessionId] ?? [
            'created' => $now,
            'updated' => $now,
            'tickets_sent' => 0,
        ];
        if ((int) ($meta['created'] ?? 0) <= 0) {
            $meta['created'] = $now;
        }
        $meta['updated'] = $now;
        self::$sessionMeta[$sessionId] = $meta;

        $file = $this->sessionFile($sessionId);
        @file_put_contents(
            $file,
            json_encode([
                'created' => (int) $meta['created'],
                'updated' => (int) $meta['updated'],
                'tickets_sent' => (int) ($meta['tickets_sent'] ?? 0),
                'verified_emails' => array_values(is_array($meta['verified_emails'] ?? null) ? $meta['verified_emails'] : []),
                'provided_name' => trim((string) ($meta['provided_name'] ?? '')),
                'assistant_offline' => !empty($meta['assistant_offline']),
                'offline_ticket_opened' => !empty($meta['offline_ticket_opened']),
                'offline_ticket_declined' => !empty($meta['offline_ticket_declined']),
                'messages' => $messages,
            ], JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        // Defer admin archive until the shopper confirms (logged-in) or verifies email (guest).
        $deferArchive = !empty($meta['assistant_offline']) && empty($meta['offline_ticket_opened']);
        if (!$deferArchive) {
            try {
                ChatArchive::upsert($sessionId, $messages, $this->shopper, $meta);
            } catch (\Throwable $e) {
                // Archive failures must not break the shopper chat path.
            }
        }
    }

    /**
     * Trim from the front while trying not to leave orphan tool messages without their assistant tool_calls.
     *
     * @param list<array{role: string, content: string, tool_calls?: array, tool_call_id?: string}> $messages
     * @return list<array{role: string, content: string, tool_calls?: array, tool_call_id?: string}>
     */
    private function trimHistoryPreservingToolPairs(array $messages, int $maxHistory): array
    {
        if (count($messages) <= $maxHistory) {
            return $messages;
        }

        $slice = array_slice($messages, -$maxHistory);
        // Drop leading tool messages that lost their parent assistant tool_calls turn.
        while ($slice !== [] && ($slice[0]['role'] ?? '') === 'tool') {
            array_shift($slice);
        }
        return array_values($slice);
    }

    private function sessionFile(string $sessionId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId) ?: 'default';
        return self::sessionsDirectory() . '/' . $safe . '.json';
    }

    /**
     * Trim in-memory history to last N sessions (files remain until TTL expiry / GC).
     */
    public static function pruneHistory(int $maxSessions = 100): void
    {
        if (count(self::$sessions) > $maxSessions) {
            self::$sessions = array_slice(self::$sessions, -$maxSessions, null, true);
        }
        if (count(self::$sessionMeta) > $maxSessions) {
            self::$sessionMeta = array_slice(self::$sessionMeta, -$maxSessions, null, true);
        }
    }
}
