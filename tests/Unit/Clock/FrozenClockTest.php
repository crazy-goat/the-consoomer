<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit\Clock;

use PHPUnit\Framework\TestCase;

final class FrozenClockTest extends TestCase
{
    public function testDefaultConstruction(): void
    {
        $clock = new FrozenClock();

        $this->assertInstanceOf(\DateTimeImmutable::class, $clock->now());
    }

    public function testCustomTimeConstruction(): void
    {
        $time = new \DateTimeImmutable('2025-01-15 10:30:00');
        $clock = new FrozenClock($time);

        $this->assertSame($time, $clock->now());
    }

    public function testNowReturnsSameTime(): void
    {
        $clock = new FrozenClock();

        $time1 = $clock->now();
        $time2 = $clock->now();

        $this->assertSame($time1, $time2);
    }

    public function testAdvancePositiveSeconds(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2025-01-15 10:00:00'));

        $clock->advance(60);

        $this->assertSame('2025-01-15 10:01:00', $clock->now()->format('Y-m-d H:i:s'));
    }

    public function testAdvanceNegativeSeconds(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2025-01-15 10:00:00'));

        $clock->advance(-30);

        $this->assertSame('2025-01-15 09:59:30', $clock->now()->format('Y-m-d H:i:s'));
    }

    public function testAdvanceZeroSeconds(): void
    {
        $time = new \DateTimeImmutable('2025-01-15 10:00:00');
        $clock = new FrozenClock($time);

        $clock->advance(0);

        $this->assertSame('2025-01-15 10:00:00', $clock->now()->format('Y-m-d H:i:s'));
    }

    public function testAdvanceMultipleTimes(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2025-01-15 10:00:00'));

        $clock->advance(60);
        $clock->advance(120);
        $clock->advance(30);

        $this->assertSame('2025-01-15 10:03:30', $clock->now()->format('Y-m-d H:i:s'));
    }
}
