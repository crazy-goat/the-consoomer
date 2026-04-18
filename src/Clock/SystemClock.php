<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Clock;

use CrazyGoat\TheConsoomer\ClockInterface;

final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
