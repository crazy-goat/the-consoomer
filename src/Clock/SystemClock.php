<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Clock;

use CrazyGoat\TheConsoomer\ClockInterface;

/**
 * System clock implementation using current system time.
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
}
