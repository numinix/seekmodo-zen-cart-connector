<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot;

/**
 * Tracks whether any admin has the Chat Inbox (or related admin UI) open recently.
 */
final class AdminPresence
{
    private const DEFAULT_ONLINE_SECONDS = 300;

    public static function touch(int $adminId = 0): void
    {
        $path = self::path();
        if ($path === '') {
            return;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $data = self::read($path);
        $key = $adminId > 0 ? ('a' . $adminId) : 'anon';
        $data[$key] = time();
        // Drop stale entries (> 1 day) so the file stays small.
        $cutoff = time() - 86400;
        foreach ($data as $k => $ts) {
            if (!is_int($ts) || $ts < $cutoff) {
                unset($data[$k]);
            }
        }
        @file_put_contents($path, json_encode($data), LOCK_EX);
    }

    public static function isAnyOnline(?int $withinSeconds = null): bool
    {
        return self::firstOnlineAdminId($withinSeconds) > 0
            || self::hasAnonymousOnline($withinSeconds);
    }

    /**
     * Prefer a concrete Zen Cart admin id from recent presence touches.
     */
    public static function firstOnlineAdminId(?int $withinSeconds = null): int
    {
        $within = $withinSeconds ?? self::DEFAULT_ONLINE_SECONDS;
        $within = max(60, min(3600, (int) $within));
        $path = self::path();
        if ($path === '' || !is_file($path)) {
            return 0;
        }
        $data = self::read($path);
        $cutoff = time() - $within;
        $bestId = 0;
        $bestTs = 0;
        foreach ($data as $key => $ts) {
            if (!is_int($ts) || $ts < $cutoff) {
                continue;
            }
            if (!is_string($key) || !preg_match('/^a(\d+)$/', $key, $m)) {
                continue;
            }
            $adminId = (int) $m[1];
            if ($adminId > 0 && $ts >= $bestTs) {
                $bestTs = $ts;
                $bestId = $adminId;
            }
        }
        return $bestId;
    }

    private static function hasAnonymousOnline(?int $withinSeconds = null): bool
    {
        $within = $withinSeconds ?? self::DEFAULT_ONLINE_SECONDS;
        $within = max(60, min(3600, (int) $within));
        $path = self::path();
        if ($path === '' || !is_file($path)) {
            return false;
        }
        $data = self::read($path);
        $cutoff = time() - $within;
        foreach ($data as $ts) {
            if (is_int($ts) && $ts >= $cutoff) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, int> */
    private static function read(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $k => $v) {
            if (is_string($k) && is_numeric($v)) {
                $out[$k] = (int) $v;
            }
        }
        return $out;
    }

    private static function path(): string
    {
        try {
            $dir = ChatAgent::sessionsDirectory();
        } catch (\Throwable $e) {
            if (defined('DIR_FS_CATALOG')) {
                $dir = rtrim((string) DIR_FS_CATALOG, '/\\') . '/cache/seekmodo_ai_chatbot_sessions';
            } else {
                return '';
            }
        }
        return rtrim($dir, '/\\') . '/admin_presence.json';
    }
}
