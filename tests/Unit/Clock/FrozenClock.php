<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit\Clock;

use CrazyGoat\TheConsoomer\ClockInterface;

final class FrozenClock implements ClockInterface
{
    private \DateTimeImmutable $time;

    public function __construct(?\DateTimeImmutable $time = null)
    {
        $this->time = $time ?? new \DateTimeImmutable();
    }

    public function now(): \DateTimeImmutable
    {
        return $this->time;
    }

    public function advance(int $seconds): void
    {
        $this->time = $this->time->modify("+{$seconds} seconds");
    }
}
