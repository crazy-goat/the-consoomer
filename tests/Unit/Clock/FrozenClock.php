<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit\Clock;

use CrazyGoat\TheConsoomer\ClockInterface;

final class FrozenClock implements ClockInterface
{
    public function __construct(private ?\DateTimeImmutable $time = new \DateTimeImmutable())
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->time;
    }

    public function advance(int $seconds): void
    {
        $newTime = $this->time->modify("+{$seconds} seconds");
        if ($newTime === false) {
            throw new \RuntimeException("Failed to advance time by {$seconds} seconds");
        }
        $this->time = $newTime;
    }
}
