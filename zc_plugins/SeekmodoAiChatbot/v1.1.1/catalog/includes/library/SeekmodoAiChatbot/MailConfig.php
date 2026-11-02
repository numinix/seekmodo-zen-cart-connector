<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot;

/**
 * Reads Support Tickets mailbox settings from configuration constants.
 */
final class MailConfig
{
    public static function enabled(): bool
    {
        return self::bool('SEEKMODO_AI_CHATBOT_MAIL_ENABLED', false);
    }

    /**
     * Open a new ticket when inbound mail does not match an existing
     * subject #id or In-Reply-To / References token. Off unless
     * SEEKMODO_AI_CHATBOT_MAIL_CREATE_UNMATCHED is true. Replies to an
     * existing ticket are still imported. The connector may publish the
     * Seekmodo tenant-admin value into that key.
     */
    public static function createUnmatchedEnabled(): bool
    {
        if (!self::enabled()) {
            return false;
        }
        if (!defined('SEEKMODO_AI_CHATBOT_MAIL_CREATE_UNMATCHED')) {
            return false;
        }
        return self::bool('SEEKMODO_AI_CHATBOT_MAIL_CREATE_UNMATCHED', false);
    }

    public static function address(): string
    {
        $mail = self::str('SEEKMODO_AI_CHATBOT_MAIL_ADDRESS');
        if ($mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            return strtolower($mail);
        }
        $support = self::str('SEEKMODO_AI_CHATBOT_SUPPORT_EMAIL');
        if ($support !== '' && filter_var($support, FILTER_VALIDATE_EMAIL)) {
            return strtolower($support);
        }
        return '';
    }

    /** @return array{host:string,port:int,encryption:string,folder:string,username:string,password:string} */
    public static function imap(): array
    {
        return [
            'host' => self::str('SEEKMODO_AI_CHATBOT_MAIL_IMAP_HOST'),
            'port' => self::int('SEEKMODO_AI_CHATBOT_MAIL_IMAP_PORT', 993),
            'encryption' => self::normEnc(self::str('SEEKMODO_AI_CHATBOT_MAIL_IMAP_ENCRYPTION', 'ssl')),
            'folder' => self::str('SEEKMODO_AI_CHATBOT_MAIL_IMAP_FOLDER', 'INBOX') ?: 'INBOX',
            'username' => self::str('SEEKMODO_AI_CHATBOT_MAIL_IMAP_USER'),
            'password' => self::str('SEEKMODO_AI_CHATBOT_MAIL_IMAP_PASSWORD'),
        ];
    }

    /** @return array{host:string,port:int,encryption:string,username:string,password:string} */
    public static function smtp(): array
    {
        $imap = self::imap();
        $host = self::str('SEEKMODO_AI_CHATBOT_MAIL_SMTP_HOST');
        $user = self::str('SEEKMODO_AI_CHATBOT_MAIL_SMTP_USER');
        $pass = self::str('SEEKMODO_AI_CHATBOT_MAIL_SMTP_PASSWORD');
        if ($host === '') {
            $host = $imap['host'];
        }
        if ($user === '') {
            $user = $imap['username'];
        }
        if ($pass === '') {
            $pass = $imap['password'];
        }
        return [
            'host' => $host,
            'port' => self::int('SEEKMODO_AI_CHATBOT_MAIL_SMTP_PORT', 587),
            'encryption' => self::normEnc(self::str('SEEKMODO_AI_CHATBOT_MAIL_SMTP_ENCRYPTION', 'tls')),
            'username' => $user,
            'password' => $pass,
        ];
    }

    public static function imapReady(): bool
    {
        if (!self::enabled()) {
            return false;
        }
        $imap = self::imap();
        return $imap['host'] !== '' && $imap['username'] !== '' && $imap['password'] !== '';
    }

    public static function smtpReady(): bool
    {
        if (!self::enabled()) {
            return false;
        }
        if (self::address() === '') {
            return false;
        }
        $smtp = self::smtp();
        return $smtp['host'] !== '' && $smtp['username'] !== '' && $smtp['password'] !== '';
    }

    private static function str(string $key, string $default = ''): string
    {
        if (defined($key)) {
            return trim((string) constant($key));
        }
        return $default;
    }

    private static function int(string $key, int $default): int
    {
        $v = self::str($key, (string) $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    private static function bool(string $key, bool $default): bool
    {
        $v = strtolower(self::str($key, $default ? 'true' : 'false'));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    private static function normEnc(string $enc): string
    {
        $enc = strtolower(trim($enc));
        return in_array($enc, ['ssl', 'tls', 'none'], true) ? $enc : 'ssl';
    }
}
