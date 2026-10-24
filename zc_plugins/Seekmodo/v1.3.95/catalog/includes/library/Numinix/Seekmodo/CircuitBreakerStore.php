<?php

namespace Numinix\Seekmodo;

/**
 * Read/write contract for the Seekmodo connector's circuit-breaker
 * state. The hot-path code never touches the storage backend directly;
 * it asks the store "are we currently open?" and "record this outcome".
 *
 * Two implementations ship:
 *   - ApcuCircuitBreakerStore   — production default; SHM-shared across
 *     php-fpm workers via APCu.
 *   - StaticCircuitBreakerStore — test seam (file-local static array),
 *     per-process only, used when APCu isn't loaded.
 *
 * The breaker semantics intentionally match
 * services/bot-check/sdks/php/src/CircuitBreakerStore.php: 5 failures
 * inside a 60 s rolling window opens the breaker for 30 s.
 */
interface CircuitBreakerStore
{
    /**
     * @return array{open:bool,opened_at:int,failures:int}
     */
    public function state(string $key, int $cooldownSeconds): array;

    /**
     * Record one outcome. $ok=true resets failure count; $ok=false
     * pushes it and opens the breaker once the threshold is crossed.
     */
    public function record(string $key, bool $ok, int $threshold, int $cooldownSeconds): void;
}
