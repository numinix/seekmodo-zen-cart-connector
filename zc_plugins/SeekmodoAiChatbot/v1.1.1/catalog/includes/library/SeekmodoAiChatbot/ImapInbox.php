<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot;

/**
 * Thin wrapper around PHP imap_* for Support Tickets inbox polling.
 */
final class ImapInbox
{
    /** @var resource|\IMAP\Connection|null */
    private $conn = null;
    private string $folder = 'INBOX';

    /** @var array{host:string,port:int,encryption:string,folder:string,username:string,password:string} */
    private array $cfg;

    /**
     * @param array{host:string,port:int,encryption:string,folder:string,username:string,password:string} $cfg
     */
    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
        $this->folder = $cfg['folder'] !== '' ? $cfg['folder'] : 'INBOX';
    }

    public static function extensionLoaded(): bool
    {
        return function_exists('imap_open');
    }

    public function connect(): void
    {
        if (!self::extensionLoaded()) {
            throw new \RuntimeException('PHP imap extension is not loaded.');
        }
        $mailbox = $this->mailboxString();
        $conn = @imap_open($mailbox, $this->cfg['username'], $this->cfg['password'], 0, 1);
        if ($conn === false) {
            $err = imap_last_error() ?: 'unknown IMAP error';
            throw new \RuntimeException('IMAP connect failed: ' . $err);
        }
        $this->conn = $conn;
    }

    public function disconnect(): void
    {
        if ($this->conn) {
            @imap_close($this->conn);
            $this->conn = null;
        }
    }

    public function folder(): string
    {
        return $this->folder;
    }

    /**
     * @return list<array{uid:int,message_id:string,subject:string,from:string,from_email:string,in_reply_to:string,references:string,date:string,body:string,auto_submitted:string,precedence:string,list_id:string}>
     */
    public function fetchUnseen(int $limit = 40): array
    {
        $this->requireConn();
        $emails = @imap_search($this->conn, 'UNSEEN', SE_UID);
        if ($emails === false || $emails === []) {
            return [];
        }
        sort($emails, SORT_NUMERIC);
        if (count($emails) > $limit) {
            $emails = array_slice($emails, -$limit);
        }
        $out = [];
        foreach ($emails as $uid) {
            $msg = $this->fetchByUid((int) $uid);
            if ($msg !== null) {
                $out[] = $msg;
            }
        }
        return $out;
    }

    public function markSeen(int $uid): void
    {
        $this->requireConn();
        @imap_setflag_full($this->conn, (string) $uid, '\\Seen', ST_UID);
    }

    /**
     * @return array{uid:int,message_id:string,subject:string,from:string,from_email:string,in_reply_to:string,references:string,date:string,body:string,auto_submitted:string,precedence:string,list_id:string}|null
     */
    private function fetchByUid(int $uid): ?array
    {
        $this->requireConn();
        // imap_fetch_overview sequence is a string; fetchheader/structure/body want int msg nums (UIDs with FT_UID).
        $overview = @imap_fetch_overview($this->conn, (string) $uid, FT_UID);
        if (!$overview || !isset($overview[0])) {
            return null;
        }
        $ov = $overview[0];
        $headerRaw = @imap_fetchheader($this->conn, $uid, FT_UID) ?: '';
        $structure = @imap_fetchstructure($this->conn, $uid, FT_UID);
        $body = $this->extractBody($uid, $structure);

        $from = isset($ov->from) ? $this->decodeMime((string) $ov->from) : '';
        $subject = isset($ov->subject) ? $this->decodeMime((string) $ov->subject) : '';
        $messageId = isset($ov->message_id) ? trim((string) $ov->message_id) : '';
        if ($messageId === '' && preg_match('/^Message-ID:\s*(.+)$/mi', $headerRaw, $m)) {
            $messageId = trim($m[1]);
        }
        $inReplyTo = '';
        if (preg_match('/^In-Reply-To:\s*(.+)$/mi', $headerRaw, $m)) {
            $inReplyTo = trim($m[1]);
        }
        $references = '';
        if (preg_match('/^References:\s*(.+?)(?=\r?\n\S|\r?\n\r?\n)/si', $headerRaw, $m)) {
            $references = trim(preg_replace('/\s+/', ' ', $m[1]) ?? $m[1]);
        }

        return [
            'uid' => $uid,
            'message_id' => $messageId,
            'subject' => $subject,
            'from' => $from,
            'from_email' => self::extractEmail($from),
            'in_reply_to' => $inReplyTo,
            'references' => $references,
            'date' => isset($ov->date) ? (string) $ov->date : '',
            'body' => $body,
            'auto_submitted' => self::headerValue($headerRaw, 'Auto-Submitted'),
            'precedence' => self::headerValue($headerRaw, 'Precedence'),
            'list_id' => self::headerValue($headerRaw, 'List-Id'),
        ];
    }

    private static function headerValue(string $headerRaw, string $name): string
    {
        $pattern = '/^' . preg_quote($name, '/') . ':\s*(.+)$/mi';
        if (preg_match($pattern, $headerRaw, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /** @param object|false|null $structure */
    private function extractBody(int $uid, $structure): string
    {
        if (!$structure) {
            $raw = @imap_body($this->conn, $uid, FT_UID | FT_PEEK) ?: '';
            return $this->decodePart($raw, 0);
        }
        $plain = $this->findPart($uid, $structure, 'TEXT', 'PLAIN', '');
        if ($plain !== '') {
            return $plain;
        }
        $html = $this->findPart($uid, $structure, 'TEXT', 'HTML', '');
        if ($html !== '') {
            return EmailReplyExtractor::stripHtml($html);
        }
        $raw = @imap_body($this->conn, $uid, FT_UID | FT_PEEK) ?: '';
        return $this->decodePart($raw, (int) ($structure->encoding ?? 0));
    }

    /** @param object $structure */
    private function findPart(int $uid, object $structure, string $type, string $subtype, string $prefix): string
    {
        $typeMap = [0 => 'TEXT', 1 => 'MULTIPART', 2 => 'MESSAGE', 3 => 'APPLICATION', 4 => 'AUDIO', 5 => 'IMAGE', 6 => 'VIDEO', 7 => 'OTHER'];
        $stype = $typeMap[(int) ($structure->type ?? 0)] ?? 'OTHER';
        $ssub = strtoupper((string) ($structure->subtype ?? ''));

        if ($stype === $type && $ssub === $subtype) {
            $partNum = $prefix === '' ? '1' : $prefix;
            // single-part messages use section 1
            if ($prefix === '' && empty($structure->parts)) {
                $raw = @imap_body($this->conn, $uid, FT_UID | FT_PEEK) ?: '';
            } else {
                $raw = @imap_fetchbody($this->conn, $uid, $partNum, FT_UID | FT_PEEK) ?: '';
            }
            return $this->decodePart($raw, (int) ($structure->encoding ?? 0));
        }

        if (!empty($structure->parts) && is_array($structure->parts)) {
            foreach ($structure->parts as $i => $part) {
                $num = $prefix === '' ? (string) ($i + 1) : ($prefix . '.' . ($i + 1));
                $found = $this->findPart($uid, $part, $type, $subtype, $num);
                if ($found !== '') {
                    return $found;
                }
            }
        }
        return '';
    }

    private function decodePart(string $raw, int $encoding): string
    {
        if ($encoding === 3) { // BASE64
            $decoded = base64_decode($raw, true);
            $raw = $decoded !== false ? $decoded : $raw;
        } elseif ($encoding === 4) { // QUOTED-PRINTABLE
            $raw = quoted_printable_decode($raw);
        }
        // Attempt UTF-8
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $converted = @mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1, Windows-1252, UTF-8');
            if (is_string($converted) && $converted !== '') {
                $raw = $converted;
            }
        }
        return $raw;
    }

    private function decodeMime(string $value): string
    {
        $decoded = @imap_mime_header_decode($value);
        if (!is_array($decoded)) {
            return $value;
        }
        $out = '';
        foreach ($decoded as $part) {
            $charset = strtoupper((string) ($part->charset ?? 'default'));
            $text = (string) ($part->text ?? '');
            if ($charset !== 'DEFAULT' && $charset !== 'UTF-8' && $charset !== '') {
                $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
                $text = is_string($converted) ? $converted : $text;
            }
            $out .= $text;
        }
        return $out !== '' ? $out : $value;
    }

    public static function extractEmail(string $from): string
    {
        if (preg_match('/<([^>]+)>/', $from, $m)) {
            return strtolower(trim($m[1]));
        }
        $from = strtolower(trim($from));
        return filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : '';
    }

    private function mailboxString(): string
    {
        $host = $this->cfg['host'];
        $port = (int) $this->cfg['port'];
        $enc = $this->cfg['encryption'];
        $flags = '/imap';
        if ($enc === 'ssl') {
            $flags .= '/ssl';
        } elseif ($enc === 'tls') {
            $flags .= '/tls';
        } else {
            $flags .= '/notls';
        }
        $flags .= '/novalidate-cert';
        return '{' . $host . ':' . $port . $flags . '}' . $this->folder;
    }

    private function requireConn(): void
    {
        if (!$this->conn) {
            throw new \RuntimeException('IMAP not connected.');
        }
    }
}
