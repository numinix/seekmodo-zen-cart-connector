<?php
/**
 * Seekmodo central ticketing client.
 *
 * Tickets live in Seekmodo (one queue per tenant). The Zen Cart admin proxies
 * the shared ticket UI through this class: every call is signed with the
 * chatbot's Seekmodo pairing (body-only HMAC-SHA256, same envelope as the
 * inbox API) and carries the logged-in admin as acting_agent so Seekmodo can
 * map them to a support agent by email.
 */

namespace SeekmodoAiChatbot;

class SeekmodoTickets
{
    public const DEFAULT_API_URL = 'https://seekmodo.com/api/platform/tickets_api';

    /** Actions the admin UI may call. inbound_email is reserved for the mail poller. */
    public const ADMIN_ACTIONS = [
        'list', 'get', 'poll', 'agents', 'create', 'reply', 'note', 'update',
        'assign', 'watch', 'unwatch', 'presence', 'convert_from_chat', 'settings_get',
    ];

    /** @return array{0:string,1:string}|null tenant id + shared secret */
    public static function credentials(): ?array
    {
        $pairs = [
            ['SEEKMODO_AI_CHATBOT_SEEKMODO_TENANT_ID', 'SEEKMODO_AI_CHATBOT_SEEKMODO_SHARED_SECRET'],
            ['NUMINIX_SEEKMODO_TENANT_ID', 'NUMINIX_SEEKMODO_SHARED_SECRET'],
        ];
        foreach ($pairs as [$tenantKey, $secretKey]) {
            $tenant = defined($tenantKey) ? trim((string) constant($tenantKey)) : '';
            $secret = defined($secretKey) ? trim((string) constant($secretKey)) : '';
            if ($tenant !== '' && $secret !== '') {
                return [$tenant, $secret];
            }
        }
        return null;
    }

    public static function isPaired(): bool
    {
        return self::credentials() !== null;
    }

    public static function apiUrl(): string
    {
        $url = defined('SEEKMODO_AI_CHATBOT_TICKETS_API_URL')
            ? trim((string) SEEKMODO_AI_CHATBOT_TICKETS_API_URL) : '';
        if ($url === '') {
            return self::DEFAULT_API_URL;
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $loopback = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
        if ($scheme === 'https' || ($scheme === 'http' && $loopback)) {
            return $url;
        }
        return self::DEFAULT_API_URL;
    }

    /** @return array{email:string,name:string}|null */
    public static function actingAgent(int $adminId): ?array
    {
        global $db;
        if ($adminId < 1 || !isset($db) || !defined('TABLE_ADMIN')) {
            return null;
        }
        $row = $db->Execute(
            'SELECT admin_name, admin_email FROM ' . TABLE_ADMIN
            . ' WHERE admin_id = ' . (int) $adminId . ' LIMIT 1'
        );
        if ($row->EOF) {
            return null;
        }
        $email = strtolower(trim((string) $row->fields['admin_email']));
        if ($email === '' || strpos($email, '@') === false) {
            return null;
        }
        $name = trim((string) $row->fields['admin_name']);
        return ['email' => $email, 'name' => $name !== '' ? $name : $email];
    }

    /**
     * Signed POST to tickets_api.
     *
     * @param array<string, mixed> $payload must include action
     * @return array<string, mixed>
     */
    public static function dispatch(array $payload, int $timeout = 20): array
    {
        $creds = self::credentials();
        if ($creds === null) {
            return ['ok' => false, 'error' => 'not_paired'];
        }
        [$tenant, $secret] = $creds;
        $payload['tenant_id'] = $tenant;
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            return ['ok' => false, 'error' => 'encode_failed'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'curl_missing'];
        }
        $ch = curl_init(self::apiUrl());
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Seekmodo-Tenant: ' . $tenant,
                'X-Seekmodo-Timestamp: ' . time(),
                'X-Seekmodo-Signature: ' . hash_hmac('sha256', $body, $secret),
            ],
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if (!is_string($raw) || $raw === '') {
            return ['ok' => false, 'error' => 'network_error', 'detail' => $err];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'bad_response', 'status' => $status];
        }
        if ($status >= 400 && !isset($decoded['ok'])) {
            $decoded['ok'] = false;
        }
        return $decoded;
    }

    /**
     * Convert a store chat into a Seekmodo ticket.
     *
     * @param array<string, mixed> $chat ChatArchive::getById row (messages decoded)
     * @param array{email:string,name:string} $agent
     * @return array<string, mixed>
     */
    public static function convertChat(array $chat, array $agent): array
    {
        $transcript = [];
        foreach ((array) ($chat['messages'] ?? []) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $content = trim((string) ($m['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $transcript[] = [
                'role' => (string) ($m['role'] ?? 'user'),
                'content' => $content,
                'date' => (string) ($m['date'] ?? ''),
                'admin_name' => (string) ($m['admin_name'] ?? ''),
            ];
        }
        return self::dispatch([
            'action' => 'convert_from_chat',
            'chat_ref' => 'store:' . (int) ($chat['chat_id'] ?? 0),
            'transcript' => $transcript,
            'requester_email' => (string) ($chat['customer_email'] ?? ''),
            'requester_name' => (string) ($chat['customer_name'] ?? ''),
            'assign_self' => true,
            'acting_agent' => $agent,
        ]);
    }

    /**
     * Hand a parsed inbound email to Seekmodo. Seekmodo threads it onto the
     * matching ticket (by reply token / subject number) or opens a new one.
     *
     * @return array<string, mixed>
     */
    public static function inboundEmail(
        string $fromEmail,
        string $fromName,
        string $subject,
        string $body,
        string $messageId,
        string $inReplyTo = '',
        string $references = ''
    ): array {
        return self::dispatch([
            'action' => 'inbound_email',
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'subject' => $subject,
            'body' => $body,
            'message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
            'references' => $references,
            'channel' => 'store_imap',
        ]);
    }
}
