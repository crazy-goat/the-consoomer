<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit\Clock;

use PHPUnit\Framework\TestCase;

final class FrozenClockTest extends TestCase
{
    public function testDefaultConstruction(): void
    {
        $before = new \DateTimeImmutable();
        $clock = new FrozenClock();
        $now = $clock->now();

        // The default is "now", which is the property worth asserting. An
        // instanceof check against the declared return type would tell the
        // analyser nothing and prove nothing at run time either.
        $this->assertGreaterThanOrEqual($before->getTimestamp(), $now->getTimestamp());
        $this->assertLessThanOrEqual((new \DateTimeImmutable())->getTimestamp(), $now->getTimestamp());
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

    public function testMonotonicReturnsFloat(): void
    {
        $clock = new FrozenClock();
        $monotonic = $clock->monotonic();

        // The default is hrtime() in seconds, so assert that relationship: an
        // is_float() check on the declared float return type is a tautology for
        // the analyser and would pass even for a broken implementation.
        $this->assertGreaterThan(0.0, $monotonic);
        $this->assertEqualsWithDelta(hrtime(true) / 1e9, $monotonic, 5.0);
    }

    public function testMonotonicIncreasesWithPositiveAdvance(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2025-01-15 10:00:00'), 0.0);

        $this->assertSame(0.0, $clock->monotonic());

        $clock->advance(60);

        $this->assertSame(60.0, $clock->monotonic());
    }

    public function testMonotonicDoesNotIncreaseWithNegativeAdvance(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2025-01-15 10:00:00'), 100.0);

        $clock->advance(-30);

        $this->assertSame(100.0, $clock->monotonic());
    }

    public function testMonotonicNeverDecreases(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2025-01-15 10:00:00'), 0.0);

        $clock->advance(60);
        $this->assertSame(60.0, $clock->monotonic());

        $clock->advance(-120);
        $this->assertSame(60.0, $clock->monotonic());
    }
}
