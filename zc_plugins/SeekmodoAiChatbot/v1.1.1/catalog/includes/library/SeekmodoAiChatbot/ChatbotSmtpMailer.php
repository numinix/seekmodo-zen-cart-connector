<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot;

/**
 * Send Support Tickets emails via configured SMTP (PHPMailer) or zen_mail fallback.
 */
final class ChatbotSmtpMailer
{
    /**
     * @param array<string, mixed> $block Extra template block (EMAIL_MESSAGE_HTML etc.)
     * @return array{ok:bool,message_id:string,error?:string}
     */
    public static function send(
        string $toName,
        string $toEmail,
        string $subject,
        string $plainBody,
        string $fromName,
        string $fromEmail,
        string $module,
        array $block = [],
        string $replyToEmail = '',
        string $inReplyTo = '',
        string $references = '',
        ?string $forcedMessageId = null
    ): array {
        $toEmail = trim($toEmail);
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message_id' => '', 'error' => 'invalid_to'];
        }

        $mailAddress = MailConfig::address();
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = $mailAddress !== '' ? $mailAddress : (defined('EMAIL_FROM') ? (string) EMAIL_FROM : '');
        }
        if ($replyToEmail === '') {
            $replyToEmail = $mailAddress !== '' ? $mailAddress : $fromEmail;
        }
        if ($fromName === '') {
            $fromName = defined('STORE_NAME') ? (string) STORE_NAME : 'Store';
        }

        $messageId = $forcedMessageId ?? self::mintMessageId();

        if (MailConfig::smtpReady()) {
            $result = self::sendViaSmtp(
                $toName,
                $toEmail,
                $subject,
                $plainBody,
                $fromName,
                $fromEmail,
                $module,
                $block,
                $replyToEmail,
                $inReplyTo,
                $references,
                $messageId
            );
            if ($result['ok']) {
                return $result;
            }
            // Fall through to zen_mail on SMTP failure.
        }

        if (!function_exists('zen_mail')) {
            return ['ok' => false, 'message_id' => '', 'error' => 'mailer_unavailable'];
        }

        $html = (string) ($block['EMAIL_MESSAGE_HTML'] ?? '');
        $sent = zen_mail(
            $toName,
            $toEmail,
            $subject,
            $plainBody,
            $fromName,
            $fromEmail,
            $block,
            $module,
            $html !== '' ? $html : '',
            '',
            $replyToEmail
        );
        if ($sent === false) {
            return ['ok' => false, 'message_id' => '', 'error' => 'send_failed'];
        }
        return ['ok' => true, 'message_id' => $messageId];
    }

    public static function mintMessageId(int $chatId = 0): string
    {
        $host = self::messageIdHost();
        $uniq = bin2hex(random_bytes(8));
        if ($chatId > 0) {
            return '<chat-' . $chatId . '-' . $uniq . '@' . $host . '>';
        }
        return '<chat-' . $uniq . '@' . $host . '>';
    }

    public static function messageIdHost(): string
    {
        if (!empty($_SERVER['HTTP_HOST'])) {
            return preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST']) ?: 'localhost';
        }
        if (defined('HTTP_SERVER')) {
            $host = parse_url((string) HTTP_SERVER, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return $host;
            }
        }
        return 'localhost';
    }

    /**
     * @param array<string, mixed> $block
     * @return array{ok:bool,message_id:string,error?:string}
     */
    private static function sendViaSmtp(
        string $toName,
        string $toEmail,
        string $subject,
        string $plainBody,
        string $fromName,
        string $fromEmail,
        string $module,
        array $block,
        string $replyToEmail,
        string $inReplyTo,
        string $references,
        string $messageId
    ): array {
        $smtp = MailConfig::smtp();
        try {
            $mailer = self::newPhpMailer();
            if ($mailer === null) {
                return ['ok' => false, 'message_id' => '', 'error' => 'phpmailer_missing'];
            }

            $mailer->isSMTP();
            $mailer->Host = $smtp['host'];
            $mailer->Port = $smtp['port'];
            $mailer->SMTPAuth = true;
            $mailer->Username = $smtp['username'];
            $mailer->Password = $smtp['password'];
            if ($smtp['encryption'] === 'ssl') {
                $mailer->SMTPSecure = 'ssl';
            } elseif ($smtp['encryption'] === 'tls') {
                $mailer->SMTPSecure = 'tls';
            } else {
                $mailer->SMTPSecure = '';
                $mailer->SMTPAutoTLS = false;
            }

            $mailer->CharSet = 'UTF-8';
            $mailer->setFrom($fromEmail, $fromName);
            $mailer->addAddress($toEmail, $toName);
            if ($replyToEmail !== '' && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
                $mailer->addReplyTo($replyToEmail, $fromName);
            }
            $mailer->Subject = $subject;
            $mailer->MessageID = $messageId;
            if ($inReplyTo !== '') {
                $mailer->addCustomHeader('In-Reply-To', $inReplyTo);
            }
            if ($references !== '') {
                $mailer->addCustomHeader('References', $references);
            }
            $mailer->addCustomHeader('X-Openai-Gpt-Chatbot-Module', $module);

            $html = (string) ($block['EMAIL_MESSAGE_HTML'] ?? '');
            if ($html !== '') {
                $mailer->isHTML(true);
                $mailer->Body = $html;
                $mailer->AltBody = $plainBody;
            } else {
                $mailer->isHTML(false);
                $mailer->Body = $plainBody;
            }

            if (!$mailer->send()) {
                return ['ok' => false, 'message_id' => '', 'error' => 'smtp_send_failed:' . $mailer->ErrorInfo];
            }
            return ['ok' => true, 'message_id' => $messageId];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message_id' => '', 'error' => 'smtp_exception:' . $e->getMessage()];
        }
    }

    private static function newPhpMailer(): ?object
    {
        $candidates = [];
        if (defined('DIR_FS_CATALOG')) {
            $root = rtrim((string) DIR_FS_CATALOG, '/\\');
            $candidates[] = $root . '/includes/classes/vendors/PHPMailer/src/PHPMailer.php';
            $candidates[] = $root . '/includes/classes/class.phpmailer.php';
        }
        foreach ($candidates as $file) {
            if (is_file($file)) {
                require_once $file;
                $dir = dirname($file);
                if (is_file($dir . '/SMTP.php')) {
                    require_once $dir . '/SMTP.php';
                }
                if (is_file($dir . '/Exception.php')) {
                    require_once $dir . '/Exception.php';
                }
                break;
            }
        }
        if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer', false)) {
            return new \PHPMailer\PHPMailer\PHPMailer(true);
        }
        if (class_exists('PHPMailer', false)) {
            return new \PHPMailer(true);
        }
        return null;
    }
}
