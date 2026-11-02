<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot;

/**
 * Extract only the newest reply text from an emailed ticket thread.
 */
final class EmailReplyExtractor
{
    /**
     * @param string $body Preferred plain-text body (HTML acceptable; stripped lightly)
     */
    public static function extractLatest(string $body): string
    {
        $file = dirname(__DIR__) . '/vendor/seekmodo-chat/autoload.php';
        if (is_file($file)) {
            require_once $file;
        }
        if (class_exists(\Numinix\SeekmodoChat\EmailReplyExtractor::class)) {
            return \Numinix\SeekmodoChat\EmailReplyExtractor::extractLatest($body);
        }
        $text = self::normalize($body);
        if ($text === '') {
            return '';
        }

        $cutPatterns = [
            '/^On .{10,200} wrote:\s*$/mi',
            '/^-{2,}\s*Original Message\s*-{2,}\s*$/mi',
            '/^-{5,}Original Message-{5,}\s*$/mi',
            '/^From:\s +.+$/mi',
            '/^_{10,}\s*$/m',
            '/^-{3,}\s*Previous chat history.*$/mi',
            '/^-{5,}\s*Chat transcript\s*-{5,}\s*$/mi',
            '/^-{5,}\s*End transcript\s*-{5,}\s*$/mi',
            '/^----- Chat transcript -----\s*$/mi',
            '/^----- End transcript -----\s*$/mi',
            '/^________________________________\s*$/m',
            '/^>+ /m',
        ];

        $cutAt = null;
        foreach ($cutPatterns as $pattern) {
            if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
                $pos = (int) $m[0][1];
                // Leading ">" quote block: cut from the first continuous quote line run only if early.
                if ($pattern === '/^>+ /m' && $pos > 0) {
                    // Find start of a run of quoted lines that looks like a thread (3+ consecutive).
                    if (preg_match('/(?:^>+ .*$\r?\n){3,}/m', $text, $qm, PREG_OFFSET_CAPTURE)) {
                        $pos = (int) $qm[0][1];
                    } else {
                        continue;
                    }
                }
                if ($cutAt === null || $pos < $cutAt) {
                    $cutAt = $pos;
                }
            }
        }

        if ($cutAt !== null && $cutAt > 0) {
            $text = substr($text, 0, $cutAt);
        }

        $text = self::stripTrailingSignature($text);
        return trim($text);
    }

    public static function stripHtml(string $html): string
    {
        $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<\/p>/i', "\n\n", $html) ?? $html;
        $html = preg_replace('/<\/div>/i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return self::normalize($text);
    }

    private static function normalize(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        // If looks like HTML with almost no newlines, strip tags.
        if (stripos($body, '<html') !== false || stripos($body, '<body') !== false
            || (stripos($body, '<div') !== false && substr_count($body, "\n") < 3)) {
            $body = self::stripHtml($body);
        }
        $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? $body;
        return trim($body);
    }

    private static function stripTrailingSignature(string $text): string
    {
        $lines = explode("\n", $text);
        $n = count($lines);
        // Cut at a lone "-- " signature delimiter in the last third of the message.
        for ($i = (int) floor($n * 2 / 3); $i < $n; $i++) {
            if (trim($lines[$i]) === '--' || trim($lines[$i]) === '-- ') {
                $lines = array_slice($lines, 0, $i);
                break;
            }
        }
        return trim(implode("\n", $lines));
    }
}
