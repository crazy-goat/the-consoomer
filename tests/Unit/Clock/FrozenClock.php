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
        // modify() only returns false for an unparsable string; an integer
        // offset always parses, so there is no failure branch to handle.
        $this->time = $this->time->modify("+{$seconds} seconds");

        if ($seconds > 0) {
            $this->monotonicTime += $seconds;
        }
    }
}
