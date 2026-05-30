<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Clock;

use CrazyGoat\TheConsoomer\ClockInterface;

/**
 * System clock implementation using current system time.
 *
 * now()   — returns wall-clock DateTimeImmutable (may go backward via NTP)
 * monotonic() — returns hrtime(true), never decreases, safe for elapsed measurement
 */
final class SystemClock implements ClockInterface
{
    /**
     * {@inheritdoc}
     */
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    /**
     * {@inheritdoc}
     */
    public function monotonic(): float
    {
        return hrtime(true) / 1e9;
    }
}
