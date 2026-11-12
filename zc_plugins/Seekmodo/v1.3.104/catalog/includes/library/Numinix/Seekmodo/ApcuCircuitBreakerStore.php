<?php

namespace Numinix\Seekmodo;

/**
 * APCu-backed circuit-breaker store.
 *
 * Shared across php-fpm workers via APCu shared memory, so a refused
 * TCP connect on worker A immediately disarms the breaker for worker
 * B's next request — exactly the behavior we need on a high-traffic
 * storefront where five workers can each fire one slow request before
 * any one of them sees the threshold.
 *
 * Falls back to per-process static state when APCu isn't available.
 * This keeps the SDK usable in CLI contexts (cron, fallback verifier)
 * without forcing a hard dependency on the extension.
 */
class ApcuCircuitBreakerStore implements CircuitBreakerStore
{
    /** @var array<string, array{failures:int,opened_at:int,window_started_at:int}> */
    private static array $local = [];

    public function state(string $key, int $cooldownSeconds): array
    {
        $now = time();
        $state = ['open' => false, 'opened_at' => 0, 'failures' => 0];
        $cached = $this->fetch($key);
        if (!is_array($cached)) {
            return $state;
        }
        $openedAt = (int)($cached['opened_at'] ?? 0);
        $state['failures'] = (int)($cached['failures'] ?? 0);
        $state['opened_at'] = $openedAt;
        if ($openedAt > 0 && ($now - $openedAt) < $cooldownSeconds) {
            $state['open'] = true;
        }
        return $state;
    }

    public function record(string $key, bool $ok, int $threshold, int $cooldownSeconds): void
    {
        $cached = $this->fetch($key);
        if (!is_array($cached)) {
            $cached = ['failures' => 0, 'opened_at' => 0, 'window_started_at' => time()];
        }
        $now = time();
        // Window failures over the rolling cooldownSeconds window only —
        // a quick blip an hour ago shouldn't permanently weight the
        // counter against current traffic.
        if (($now - (int)($cached['window_started_at'] ?? 0)) > $cooldownSeconds) {
            $cached['failures'] = 0;
            $cached['window_started_at'] = $now;
        }
        if ($ok) {
            $cached['failures'] = 0;
            $cached['opened_at'] = 0;
        } else {
            $cached['failures'] = (int)$cached['failures'] + 1;
            if ($cached['failures'] >= $threshold) {
                $cached['opened_at'] = $now;
            }
        }
        $this->store($key, $cached, $cooldownSeconds * 4);
    }

    private function fetch(string $key)
    {
        if (self::apcuAvailable()) {
            $ok = false;
            $val = apcu_fetch($key, $ok);
            if ($ok) {
                return $val;
            }
            return null;
        }
        return self::$local[$key] ?? null;
    }

    private function store(string $key, array $value, int $ttl): void
    {
        if (self::apcuAvailable()) {
            apcu_store($key, $value, $ttl);
            return;
        }
        self::$local[$key] = $value;
    }

    private static function apcuAvailable(): bool
    {
        return function_exists('apcu_fetch')
            && (bool)ini_get('apc.enabled')
            && (function_exists('apcu_enabled') ? apcu_enabled() : true);
    }
}
