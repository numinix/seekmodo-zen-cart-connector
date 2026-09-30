<?php

namespace Numinix\Seekmodo;

/**
 * Persistent + hot store for Seekmodo auto-promotion state.
 *
 * Hot counters (per-request increments) live in APCu. Slow-changing
 * promotion state (current phase + transition history) is mirrored
 * back to the Zen Cart `configuration` table so it survives a php-fpm
 * pool restart and shows up in the admin UI for human inspection.
 *
 * Schema (APCu):
 *   numinix.seekmodo.promotion.observed   int — total successful gateway calls observed
 *   numinix.seekmodo.promotion.errors     int — gateway failures observed
 *   numinix.seekmodo.promotion.first_seen int — first-observation epoch
 *   numinix.seekmodo.promotion.window     int — rolling 1h failure window count
 *   numinix.seekmodo.promotion.window_at  int — start epoch of current window
 *
 * Schema (configuration table — persisted on every state transition):
 *   NUMINIX_SEEKMODO_AUTO_STATE           bootstrap | shadow_observing | enforced | shadow_recovering
 *   NUMINIX_SEEKMODO_AUTO_STATE_SINCE     ISO timestamp of last transition
 *   NUMINIX_SEEKMODO_AUTO_HISTORY         JSON array, last 16 transitions
 *
 * Configuration writes are best-effort + throttled (one transition per
 * minute at most) so a runaway state machine can't hammer MySQL. APCu
 * counters are flushed every 10 minutes via `prune()`.
 */
final class PromotionStore
{
    public const STATE_BOOTSTRAP = 'bootstrap';
    public const STATE_SHADOW_OBSERVING = 'shadow_observing';
    public const STATE_ENFORCED = 'enforced';
    public const STATE_SHADOW_RECOVERING = 'shadow_recovering';

    private const KEY_OBSERVED = 'numinix.seekmodo.promotion.observed';
    private const KEY_ERRORS = 'numinix.seekmodo.promotion.errors';
    private const KEY_FIRST_SEEN = 'numinix.seekmodo.promotion.first_seen';
    private const KEY_WINDOW = 'numinix.seekmodo.promotion.window';
    private const KEY_WINDOW_AT = 'numinix.seekmodo.promotion.window_at';
    private const WINDOW_SECONDS = 3600;
    private const HISTORY_MAX = 16;

    private bool $apcuAvailable;

    public function __construct(?bool $apcuAvailable = null)
    {
        $this->apcuAvailable = $apcuAvailable ?? (function_exists('apcu_enabled') && apcu_enabled());
    }

    public function recordObservation(bool $ok): void
    {
        $now = time();
        if ($this->apcuAvailable) {
            apcu_inc(self::KEY_OBSERVED, 1);
            if (apcu_fetch(self::KEY_FIRST_SEEN) === false) {
                apcu_store(self::KEY_FIRST_SEEN, $now);
            }
            if (!$ok) {
                apcu_inc(self::KEY_ERRORS, 1);
                $this->bumpWindow($now);
            }
            return;
        }
        // No APCu — fall back to a per-process file in catalog/logs/.
        $this->fileFallbackInc(self::KEY_OBSERVED);
        if (!$ok) {
            $this->fileFallbackInc(self::KEY_ERRORS);
        }
    }

    /**
     * Snapshot of counters used by AutoPromoter to make state decisions.
     *
     * @return array{observed:int,errors:int,window_errors:int,first_seen_age_s:int}
     */
    public function counters(): array
    {
        if (!$this->apcuAvailable) {
            return [
                'observed' => $this->fileFallbackRead(self::KEY_OBSERVED),
                'errors' => $this->fileFallbackRead(self::KEY_ERRORS),
                'window_errors' => 0,
                'first_seen_age_s' => 0,
            ];
        }
        $observed = (int)(apcu_fetch(self::KEY_OBSERVED) ?: 0);
        $errors = (int)(apcu_fetch(self::KEY_ERRORS) ?: 0);
        $first = (int)(apcu_fetch(self::KEY_FIRST_SEEN) ?: 0);
        $window = (int)(apcu_fetch(self::KEY_WINDOW) ?: 0);
        $windowAt = (int)(apcu_fetch(self::KEY_WINDOW_AT) ?: 0);
        $now = time();
        // Roll the window if it's older than WINDOW_SECONDS.
        if ($windowAt > 0 && ($now - $windowAt) > self::WINDOW_SECONDS) {
            apcu_store(self::KEY_WINDOW, 0);
            apcu_store(self::KEY_WINDOW_AT, $now);
            $window = 0;
        }
        return [
            'observed' => $observed,
            'errors' => $errors,
            'window_errors' => $window,
            'first_seen_age_s' => $first === 0 ? 0 : max(0, $now - $first),
        ];
    }

    /**
     * Reset only the observation counters — keeps state + history.
     * Called after a transition so the next phase starts with a fresh
     * sample window.
     */
    public function resetCounters(): void
    {
        if ($this->apcuAvailable) {
            apcu_store(self::KEY_OBSERVED, 0);
            apcu_store(self::KEY_ERRORS, 0);
            apcu_store(self::KEY_WINDOW, 0);
            apcu_store(self::KEY_WINDOW_AT, time());
            apcu_store(self::KEY_FIRST_SEEN, time());
        } else {
            $this->fileFallbackReset(self::KEY_OBSERVED);
            $this->fileFallbackReset(self::KEY_ERRORS);
        }
    }

    public function currentState(): string
    {
        $stored = $this->configValue('NUMINIX_SEEKMODO_AUTO_STATE');
        $valid = [
            self::STATE_BOOTSTRAP,
            self::STATE_SHADOW_OBSERVING,
            self::STATE_ENFORCED,
            self::STATE_SHADOW_RECOVERING,
        ];
        return in_array($stored, $valid, true) ? $stored : self::STATE_BOOTSTRAP;
    }

    public function stateSince(): int
    {
        $iso = $this->configValue('NUMINIX_SEEKMODO_AUTO_STATE_SINCE');
        if ($iso === '') {
            return 0;
        }
        $ts = strtotime($iso);
        return $ts === false ? 0 : (int)$ts;
    }

    /**
     * Returns true if the state was actually mutated (idempotent on
     * same-state writes).
     */
    public function transitionTo(string $next, string $reason): bool
    {
        $current = $this->currentState();
        if ($current === $next) {
            return false;
        }
        $now = gmdate('c');
        $this->writeConfig('NUMINIX_SEEKMODO_AUTO_STATE', $next);
        $this->writeConfig('NUMINIX_SEEKMODO_AUTO_STATE_SINCE', $now);
        $this->appendHistory([
            'ts' => $now,
            'from' => $current,
            'to' => $next,
            'reason' => $reason,
        ]);
        $this->resetCounters();
        return true;
    }

    /** @return list<array<string,mixed>> */
    public function history(): array
    {
        $raw = $this->configValue('NUMINIX_SEEKMODO_AUTO_HISTORY');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function bumpWindow(int $now): void
    {
        $windowAt = (int)(apcu_fetch(self::KEY_WINDOW_AT) ?: 0);
        if ($windowAt === 0 || ($now - $windowAt) > self::WINDOW_SECONDS) {
            apcu_store(self::KEY_WINDOW_AT, $now);
            apcu_store(self::KEY_WINDOW, 1);
            return;
        }
        apcu_inc(self::KEY_WINDOW, 1);
    }

    private function configValue(string $key): string
    {
        if (!isset($GLOBALS['db'])) {
            return defined($key) ? (string)constant($key) : '';
        }
        $db = $GLOBALS['db'];
        try {
            $r = $db->Execute(
                'SELECT configuration_value FROM ' . TABLE_CONFIGURATION
                . " WHERE configuration_key = '" . zen_db_input($key) . "' LIMIT 1"
            );
            if ($r === false || $r->EOF) {
                return '';
            }
            return (string)$r->fields['configuration_value'];
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function writeConfig(string $key, string $value): void
    {
        if (!isset($GLOBALS['db'])) {
            return;
        }
        $db = $GLOBALS['db'];
        try {
            $db->Execute(
                'UPDATE ' . TABLE_CONFIGURATION
                . " SET configuration_value = '" . zen_db_input($value) . "',"
                . ' last_modified = NOW()'
                . " WHERE configuration_key = '" . zen_db_input($key) . "'"
            );
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    private function appendHistory(array $entry): void
    {
        $history = $this->history();
        $history[] = $entry;
        if (count($history) > self::HISTORY_MAX) {
            $history = array_slice($history, -self::HISTORY_MAX);
        }
        $this->writeConfig(
            'NUMINIX_SEEKMODO_AUTO_HISTORY',
            json_encode($history, JSON_UNESCAPED_SLASHES) ?: '[]'
        );
    }

    // -------------------------------------------------------------------
    // No-APCu file fallback. Single-process safety only — adequate for
    // dev hosts where APCu isn't enabled.

    private function fileFallbackPath(string $key): string
    {
        $dir = '';
        if (defined('DIR_FS_LOGS')) {
            $dir = rtrim(DIR_FS_LOGS, '/\\');
        } elseif (defined('DIR_FS_CATALOG')) {
            $dir = rtrim(DIR_FS_CATALOG, '/\\') . '/logs';
        }
        if ($dir === '' || !is_dir($dir)) {
            return '';
        }
        return $dir . '/.' . str_replace('.', '_', $key);
    }

    private function fileFallbackInc(string $key): void
    {
        $path = $this->fileFallbackPath($key);
        if ($path === '') {
            return;
        }
        $cur = (int)@file_get_contents($path);
        @file_put_contents($path, (string)($cur + 1), LOCK_EX);
    }

    private function fileFallbackRead(string $key): int
    {
        $path = $this->fileFallbackPath($key);
        if ($path === '' || !is_file($path)) {
            return 0;
        }
        return (int)@file_get_contents($path);
    }

    private function fileFallbackReset(string $key): void
    {
        $path = $this->fileFallbackPath($key);
        if ($path === '') {
            return;
        }
        @file_put_contents($path, '0', LOCK_EX);
    }
}
