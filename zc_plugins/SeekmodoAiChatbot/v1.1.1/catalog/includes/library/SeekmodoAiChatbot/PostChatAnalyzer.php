<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot;

/**
 * Learns from a resolved ticket that a human answered after a bot failure.
 * Returns either one kb_fact FAQ draft (written only from the human answer)
 * or a permanent skip reason: not_resolved / not_general.
 *
 * Never mutates the live prompt — drafts stay pending until a person
 * approves them under Tools → Chatbot Improvements.
 */
final class PostChatAnalyzer
{
    private const MAX_TRANSCRIPT_MESSAGES = 40;
    private const MAX_MESSAGE_CHARS = 600;

    /** @var string */
    private $apiKey;
    /** @var string */
    private $model;
    /** @var int */
    private $timeout;

    public function __construct(
        string $apiKey,
        string $model = 'gpt-4o-mini',
        int $timeout = 60
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->timeout = $timeout;
    }

    /**
     * @param list<array{role: string, content: string}> $messages Display messages (user/assistant/admin).
     * @param array{status?: string, handed_off?: bool, known_topic_ids?: list<string>} $meta
     * @return array{
     *   ok: bool,
     *   proposals: list<array<string, mixed>>,
     *   skip_reason?: string,
     *   error?: string,
     *   billed_via?: string
     * }
     */
    public function analyze(array $messages, array $meta = []): array
    {
        $excerpt = $this->buildTranscriptExcerpt($messages);
        if ($excerpt === '') {
            return ['ok' => true, 'proposals' => [], 'skip_reason' => 'not_resolved'];
        }

        $promptMessages = [
            ['role' => 'system', 'content' => $this->buildSystemPrompt($meta)],
            ['role' => 'user', 'content' => $excerpt],
        ];

        $billedVia = 'byok';
        try {
            if ($this->apiKey !== '' && $this->apiKey !== 'unavailable') {
                $decoded = $this->callByok($promptMessages);
            } else {
                $hosted = $this->callSeekmodoHosted($promptMessages, $meta);
                if (empty($hosted['ok'])) {
                    return [
                        'ok' => false,
                        'proposals' => [],
                        'error' => (string) ($hosted['error'] ?? 'hosted_llm_failed'),
                    ];
                }
                $decoded = $hosted['decoded'] ?? [];
                $billedVia = 'chat_turns';
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'proposals' => [], 'error' => 'llm_request_failed: ' . $e->getMessage()];
        }

        $content = (string) ($decoded['choices'][0]['message']['content'] ?? '');
        if ($content === '' && isset($decoded['content']) && is_string($decoded['content'])) {
            $content = $decoded['content'];
        }
        $parsed = json_decode($content, true);
        if (!is_array($parsed)) {
            return ['ok' => false, 'proposals' => [], 'error' => 'malformed_analyzer_json'];
        }

        $skipReason = trim((string) ($parsed['skip_reason'] ?? ''));
        if (in_array($skipReason, ['not_resolved', 'not_general'], true)) {
            return [
                'ok' => true,
                'proposals' => [],
                'skip_reason' => $skipReason,
                'billed_via' => $billedVia,
            ];
        }

        $faq = is_array($parsed['faq'] ?? null) ? $parsed['faq'] : null;
        if ($faq === null && isset($parsed['proposals'][0]) && is_array($parsed['proposals'][0])) {
            $faq = $parsed['proposals'][0];
        }
        if (!is_array($faq)) {
            return [
                'ok' => true,
                'proposals' => [],
                'skip_reason' => 'not_resolved',
                'billed_via' => $billedVia,
            ];
        }

        $proposal = $this->sanitizeFaq($faq);
        if ($proposal === null) {
            return [
                'ok' => true,
                'proposals' => [],
                'skip_reason' => 'not_resolved',
                'billed_via' => $billedVia,
            ];
        }

        return [
            'ok' => true,
            'proposals' => [$proposal],
            'billed_via' => $billedVia,
        ];
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return array<string, mixed>
     */
    private function callByok(array $messages): array
    {
        $client = \SeekmodoAiChatbot\Llm\LlmClient::fromConfig();
        $opts = [
            'temperature' => 0.2,
            'max_tokens' => 900,
            'timeout' => $this->timeout,
        ];
        if ($client->provider() === 'openai' || $client->provider() === 'deepseek') {
            $opts['response_format'] = ['type' => 'json_object'];
        }
        return $client->chat($messages, [], $opts);
    }

    /**
     * When the store has no BYOK key, run the analysis on Seekmodo's model
     * and spend one chat_turns unit via chat.analyze_ticket.
     *
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed> $meta
     * @return array{ok: bool, decoded?: array<string, mixed>, error?: string}
     */
    private function callSeekmodoHosted(array $messages, array $meta): array
    {
        try {
            $search = new SeekmodoProductSearch();
            $resp = $search->gatewayPost('/v1/chat.analyze_ticket', [
                'system' => (string) ($messages[0]['content'] ?? ''),
                'transcript' => (string) ($messages[1]['content'] ?? ''),
                'chat_id' => (int) ($meta['chat_id'] ?? 0),
                'session_id' => (string) ($meta['session_id'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'hosted_request_failed: ' . $e->getMessage()];
        }
        if (!is_array($resp)) {
            return ['ok' => false, 'error' => 'hosted_unavailable'];
        }
        if (empty($resp['ok'])) {
            return ['ok' => false, 'error' => (string) ($resp['error'] ?? 'hosted_failed')];
        }
        $content = (string) ($resp['content'] ?? '');
        if ($content === '') {
            return ['ok' => false, 'error' => 'hosted_empty'];
        }
        return [
            'ok' => true,
            'decoded' => [
                'choices' => [
                    ['message' => ['content' => $content]],
                ],
            ],
        ];
    }

    private function buildSystemPrompt(array $meta): string
    {
        $status = (string) ($meta['status'] ?? 'unknown');
        $handedOff = !empty($meta['handed_off']) ? 'yes' : 'no';
        $knownTopics = implode(', ', array_map('strval', $meta['known_topic_ids'] ?? []));

        return <<<PROMPT
You are a quality-review analyst reading a finished shopper support chat that became a ticket. A human later answered after the bot failed. You are NOT talking to the shopper.

Chat status: {$status}. Handed off to a human: {$handedOff}.
Known policy topic ids (optional topic_id only): {$knownTopics}

Decide ONE of:
1) Propose a single reusable FAQ fact drawn ONLY from the human/admin answer (never invent policy the human did not state).
2) Skip permanently with skip_reason "not_resolved" when the human reply does not actually answer the failed shopper question.
3) Skip permanently with skip_reason "not_general" when the question is about one order, one account, one shopper, or other non-reusable personal detail.

Output STRICT JSON only, matching exactly one of:
{"skip_reason":"not_resolved"}
{"skip_reason":"not_general"}
{"faq":{"kind":"kb_fact","title":"...","claim":"...","rationale":"...","suggested_text":"...","landing_target":"","severity":"low|medium|high","topic_id":""}}

Hard rules:
- Write the FAQ claim/suggested_text only from what the admin/human said. Do not invent store policy.
- Prefer skip over a weak or speculative FAQ.
- Never include email addresses, phone numbers, full names, street addresses, order numbers, or long digit sequences — refer generically.
- kind must be kb_fact when proposing a FAQ.
PROMPT;
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     */
    private function buildTranscriptExcerpt(array $messages): string
    {
        $messages = array_values(array_filter($messages, static function ($m) {
            return is_array($m) && in_array($m['role'] ?? '', ['user', 'assistant', 'admin'], true) && trim((string) ($m['content'] ?? '')) !== '';
        }));
        if ($messages === []) {
            return '';
        }
        if (count($messages) > self::MAX_TRANSCRIPT_MESSAGES) {
            $head = array_slice($messages, 0, 12);
            $tail = array_slice($messages, -(self::MAX_TRANSCRIPT_MESSAGES - 12));
            $messages = array_merge($head, [['role' => 'system', 'content' => '...[truncated]...']], $tail);
        }

        $lines = ['Transcript (chronological). ASSISTANT = bot; ADMIN = human ticket reply:'];
        foreach ($messages as $m) {
            $role = (string) ($m['role'] ?? '');
            $content = trim((string) ($m['content'] ?? ''));
            if (mb_strlen($content) > self::MAX_MESSAGE_CHARS) {
                $content = mb_substr($content, 0, self::MAX_MESSAGE_CHARS) . '…';
            }
            $lines[] = strtoupper($role) . ': ' . $content;
        }
        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $faq
     * @return array<string, mixed>|null
     */
    private function sanitizeFaq(array $faq): ?array
    {
        $kind = trim((string) ($faq['kind'] ?? 'kb_fact'));
        if ($kind !== 'kb_fact') {
            $kind = 'kb_fact';
        }
        $claim = $this->redactPii(trim((string) ($faq['claim'] ?? '')));
        if ($claim === '') {
            return null;
        }
        $severity = trim((string) ($faq['severity'] ?? 'medium'));
        if (!in_array($severity, ['low', 'medium', 'high'], true)) {
            $severity = 'medium';
        }
        return [
            'kind' => $kind,
            'title' => mb_substr($this->redactPii(trim((string) ($faq['title'] ?? ''))), 0, 255),
            'claim' => $claim,
            'rationale' => $this->redactPii(trim((string) ($faq['rationale'] ?? ''))),
            'suggested_text' => $this->redactPii(trim((string) ($faq['suggested_text'] ?? ''))),
            'landing_target' => trim((string) ($faq['landing_target'] ?? '')),
            'severity' => $severity,
            'topic_id' => trim((string) ($faq['topic_id'] ?? '')),
        ];
    }

    private function redactPii(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        $text = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[email]', $text) ?? $text;
        $text = preg_replace('/\+?\d[\d\s().-]{7,}\d/', '[number]', $text) ?? $text;
        $text = preg_replace(
            '/\b\d{1,5}\s+[A-Za-z][A-Za-z\'-]+(?:\s+[A-Za-z][A-Za-z\'-]+){0,4}\s+(?:Street|St\.?|Road|Rd\.?|Avenue|Ave\.?|Lane|Ln\.?|Drive|Dr\.?|Close|Court|Ct\.?|Way|Boulevard|Blvd\.?)\b/i',
            '[address]',
            $text
        ) ?? $text;
        $text = preg_replace(
            '/\b(?:my name is|i am|i\'m)\s+[A-Z][a-z]+(?:\s+[A-Z][a-z]+)?\b/i',
            '[name]',
            $text
        ) ?? $text;
        return $text;
    }
}
