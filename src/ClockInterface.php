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
     * Returns current DateTimeImmutable.
     */
    public function now(): \DateTimeImmutable;
}
