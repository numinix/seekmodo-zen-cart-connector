<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot;

/**
 * Analyzes a finished (resolved/idle) chat transcript in an isolated LLM
 * pass — never inside the shopper-facing reply turn — and proposes zero or
 * more typed improvement drafts (see docs/plans "Post-chat improvement
 * queue"). Every proposal is written to the Numinix MCP improvement_drafts
 * queue via improvement.propose and stays pending until a human approves it;
 * this class never mutates the live prompt, topics, or tools itself.
 *
 * Hard rules (see buildSystemPrompt): prefer no proposal over noise, never
 * invent store policy, max 3 proposals per chat, no prices/refunds/shipping
 * promises/medical claims, and no verbatim PII (emails/phones/long digit
 * runs) in the stored claim/evidence — this is an internal admin queue, but
 * we still redact defensively both in the prompt and again in code.
 */
final class PostChatAnalyzer
{
    private const ALLOWED_KINDS = ['kb_fact', 'prompt_rule', 'topic_gap', 'tool_bug', 'escalation_pattern'];
    private const ALLOWED_SEVERITIES = ['low', 'medium', 'high'];
    private const MAX_PROPOSALS = 3;
    private const MAX_TRANSCRIPT_MESSAGES = 40;
    private const MAX_MESSAGE_CHARS = 600;

    public function __construct(
        private string $apiKey,
        private string $model = 'gpt-4o-mini',
        private int $timeout = 60
    ) {
    }

    /**
     * @param list<array{role: string, content: string}> $messages Display messages (user/assistant/admin).
     * @param array{status?: string, handed_off?: bool, known_topic_ids?: list<string>} $meta
     * @return array{ok: bool, proposals: list<array<string, mixed>>, error?: string}
     */
    public function analyze(array $messages, array $meta = []): array
    {
        if ($this->apiKey === '' || $this->apiKey === 'unavailable') {
            return ['ok' => false, 'proposals' => [], 'error' => 'api_key_unavailable'];
        }
        $excerpt = $this->buildTranscriptExcerpt($messages);
        if ($excerpt === '') {
            return ['ok' => true, 'proposals' => []];
        }

        $messages = [
            ['role' => 'system', 'content' => $this->buildSystemPrompt($meta)],
            ['role' => 'user', 'content' => $excerpt],
        ];
        try {
            $client = \SeekmodoAiChatbot\Llm\LlmClient::fromConfig();
            $opts = [
                'temperature' => 0.2,
                'max_tokens' => 1200,
                'timeout' => $this->timeout,
            ];
            if ($client->provider() === 'openai' || $client->provider() === 'deepseek') {
                $opts['response_format'] = ['type' => 'json_object'];
            }
            $decoded = $client->chat($messages, [], $opts);
        } catch (\Throwable $e) {
            return ['ok' => false, 'proposals' => [], 'error' => 'llm_request_failed: ' . $e->getMessage()];
        }

        $content = (string) ($decoded['choices'][0]['message']['content'] ?? '');
        $parsed = json_decode($content, true);
        if (!is_array($parsed) || !isset($parsed['proposals']) || !is_array($parsed['proposals'])) {
            return ['ok' => false, 'proposals' => [], 'error' => 'malformed_analyzer_json'];
        }

        return ['ok' => true, 'proposals' => $this->sanitizeProposals($parsed['proposals'])];
    }

    private function buildSystemPrompt(array $meta): string
    {
        $status = (string) ($meta['status'] ?? 'unknown');
        $handedOff = !empty($meta['handed_off']) ? 'yes' : 'no';
        $knownTopics = implode(', ', array_map('strval', $meta['known_topic_ids'] ?? []));

        return <<<PROMPT
You are a quality-review analyst reading a finished shopper support-chat transcript AFTER the conversation ended. You are NOT talking to the shopper. Your only job is to decide whether this transcript reveals anything worth a human operator reviewing to improve the store's chatbot, knowledge base, or tools.

Chat status: {$status}. Handed off to a human: {$handedOff}.
Known policy topic ids (for topic_gap/kb_fact topic_id only, do not invent others): {$knownTopics}

Output STRICT JSON only, matching exactly: {"proposals": [ {"kind": "...", "title": "...", "claim": "...", "rationale": "...", "suggested_text": "...", "landing_target": "...", "severity": "low|medium|high", "topic_id": "..."} ]}

Rules for "kind" (pick the single best fit per proposal):
- kb_fact: a durable, tool-evidenced product/policy fact the transcript proves true. suggested_text may be empty.
- prompt_rule: the bot skipped a step or behaved wrongly across the conversation (e.g. recommended products before asking who a gift is for, budget, or occasion). suggested_text = one short ADDITIONAL_RULES-style bullet starting with "-".
- topic_gap: the bot lacked or gave weak guidance on a policy/domain topic. suggested_text = a short markdown draft for that topic.
- tool_bug: a tool returned wrong, missing, or contradictory data. suggested_text = repro steps + expected vs actual.
- escalation_pattern: the shopper needed a human for a reason that keeps recurring and could be encoded. suggested_text = one short rule/process bullet.

Hard rules:
- Prefer emitting {"proposals": []} over any low-value or speculative finding. Most chats deserve zero proposals.
- Never invent store policy, prices, refund amounts/timing, shipping promises, or medical/safety claims — if unsure whether something is store policy, use kind "topic_gap" and say "needs human verification" in rationale, do not assert it as fact.
- Never include the shopper's email address, phone number, full name, street address, order number, or any other long digit sequence (card/order numbers) in any field — refer to them generically (e.g. "the shopper's email", "an order number") instead of quoting them.
- Maximum 3 proposals total, ordered most-impactful first.
- severity: high = repeatedly blocks purchase/trust, medium = notable friction, low = minor polish.
- If the same issue would apply to many chats (a systemic prompt/topic gap), still propose it once — do not propose the same claim more than once here.
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
            // Keep the start (how it opened) and the end (how it was left) — the
            // middle of a long chat is the least likely place to find a miss.
            $head = array_slice($messages, 0, 12);
            $tail = array_slice($messages, -(self::MAX_TRANSCRIPT_MESSAGES - 12));
            $messages = array_merge($head, [['role' => 'system', 'content' => '...[truncated]...']], $tail);
        }

        $lines = ['Transcript (chronological):'];
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
     * @param list<mixed> $rawProposals
     * @return list<array<string, mixed>>
     */
    private function sanitizeProposals(array $rawProposals): array
    {
        $out = [];
        foreach ($rawProposals as $p) {
            if (!is_array($p) || count($out) >= self::MAX_PROPOSALS) {
                continue;
            }
            $kind = trim((string) ($p['kind'] ?? ''));
            if (!in_array($kind, self::ALLOWED_KINDS, true)) {
                continue;
            }
            $claim = $this->redactPii(trim((string) ($p['claim'] ?? '')));
            if ($claim === '') {
                continue;
            }
            $severity = trim((string) ($p['severity'] ?? 'low'));
            if (!in_array($severity, self::ALLOWED_SEVERITIES, true)) {
                $severity = 'low';
            }
            $out[] = [
                'kind' => $kind,
                'title' => mb_substr($this->redactPii(trim((string) ($p['title'] ?? ''))), 0, 255),
                'claim' => $claim,
                'rationale' => $this->redactPii(trim((string) ($p['rationale'] ?? ''))),
                'suggested_text' => $this->redactPii(trim((string) ($p['suggested_text'] ?? ''))),
                'landing_target' => trim((string) ($p['landing_target'] ?? '')),
                'severity' => $severity,
                'topic_id' => trim((string) ($p['topic_id'] ?? '')),
            ];
        }
        return $out;
    }

    /**
     * Defense-in-depth redaction in case the model still echoes PII despite
     * the system-prompt instruction not to.
     */
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
        // Light name scrub for common self-introductions only — broad
        // proper-noun redaction creates too many false positives in product copy.
        $text = preg_replace(
            '/\b(?:my name is|i am|i\'m)\s+[A-Z][a-z]+(?:\s+[A-Z][a-z]+)?\b/i',
            '[name]',
            $text
        ) ?? $text;
        return $text;
    }
}
