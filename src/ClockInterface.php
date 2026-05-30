<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

/**
 * Clock interface for time tracking.
 *
 * Used for testable time-dependent operations like circuit breaker timeouts.
 */
interface ClockInterface
{
    /**
     * Returns current DateTimeImmutable (wall-clock time).
     */
    public function now(): \DateTimeImmutable;

    /**
     * Returns a monotonic timestamp in seconds (never decreases).
     *
     * Use for measuring elapsed time intervals. Backed by hrtime(true) in production.
     */
    public function monotonic(): float;
}
