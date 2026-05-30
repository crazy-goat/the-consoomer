<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit\Clock;

use CrazyGoat\TheConsoomer\ClockInterface;

final class FrozenClock implements ClockInterface
{
    private float $monotonicTime;

    public function __construct(
        private \DateTimeImmutable $time = new \DateTimeImmutable(),
        ?float $monotonicTime = null,
    ) {
        $this->monotonicTime = $monotonicTime ?? hrtime(true) / 1e9;
    }

    public function now(): \DateTimeImmutable
    {
        return $this->time;
    }

    public function monotonic(): float
    {
        return $this->monotonicTime;
    }

    public function advance(int $seconds): void
    {
        $newTime = $this->time->modify("+{$seconds} seconds");
        if ($newTime === false) {
            throw new \RuntimeException("Failed to advance time by {$seconds} seconds");
        }
        $this->time = $newTime;

        if ($seconds > 0) {
            $this->monotonicTime += $seconds;
        }
    }
}
