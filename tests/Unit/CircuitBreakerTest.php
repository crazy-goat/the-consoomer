<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\CircuitBreaker;
use CrazyGoat\TheConsoomer\CircuitState;
use CrazyGoat\TheConsoomer\ClockInterface;
use CrazyGoat\TheConsoomer\Tests\Unit\Clock\FrozenClock;
use PHPUnit\Framework\TestCase;

class CircuitBreakerTest extends TestCase
{
    public function testDefaultSuccessThresholdIsTwo(): void
    {
        $cb = new CircuitBreaker();

        $this->assertSame(2, $this->getSuccessThreshold($cb));
    }

    public function testCustomSuccessThreshold(): void
    {
        $cb = new CircuitBreaker(successThreshold: 3);

        $this->assertSame(3, $this->getSuccessThreshold($cb));
    }

    public function testSuccessThresholdValidationRejectsZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('successThreshold must be at least 2');

        new CircuitBreaker(successThreshold: 0);
    }

    public function testSuccessThresholdValidationRejectsOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('successThreshold must be at least 2');

        new CircuitBreaker(successThreshold: 1);
    }

    public function testSuccessThresholdValidationRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('successThreshold must be at least 2');

        new CircuitBreaker(successThreshold: -1);
    }

    public function testTransitionsToClosedAfterSuccessThresholdInHalfOpen(): void
    {
        $cb = new CircuitBreaker(
            threshold: 1,
            timeout: 1,
            successThreshold: 2,
        );

        // Record the state at each step and assert the whole transition sequence in
        // one go. Asserting each step separately makes the analyser narrow
        // getState() to the first value and never invalidate it, even though
        // acquire() and recordSuccess() both change the state - which turned
        // correct assertions into "always false" / "unresolvable type".
        $states = [];

        $cb->recordFailure();
        $states[] = $cb->getState();

        usleep(1100000);

        $this->assertTrue($cb->acquire());
        $states[] = $cb->getState();

        $cb->recordSuccess();
        $states[] = $cb->getState();

        $cb->recordSuccess();
        $states[] = $cb->getState();

        $this->assertSame(
            [
                CircuitState::OPEN,
                CircuitState::HALF_OPEN,
                CircuitState::HALF_OPEN,
                CircuitState::CLOSED,
            ],
            $states,
        );
    }

    /**
     * Every elapsed-time check needs a clock, so an explicit null must fall back
     * to the system clock rather than leaving a landmine that fails on the first
     * recordFailure().
     */
    public function testNullClockFallsBackToTheSystemClock(): void
    {
        $cb = new CircuitBreaker(threshold: 1, timeout: 60, clock: null);

        $reflection = new \ReflectionClass(CircuitBreaker::class);
        $clockProperty = $reflection->getProperty('clock');

        $clock = $clockProperty->getValue($cb);
        $this->assertInstanceOf(ClockInterface::class, $clock);

        // The breaker still works end to end on the fallback clock.
        $cb->recordFailure();
        $this->assertSame(CircuitState::OPEN, $cb->getState());
        $this->assertFalse($cb->acquire());
    }

    public function testCustomSuccessThresholdRequiresMoreSuccesses(): void
    {
        $cb = new CircuitBreaker(
            threshold: 1,
            timeout: 1,
            successThreshold: 3,
        );

        $cb->recordFailure();
        usleep(1100000);

        $cb->acquire();
        $this->assertSame(CircuitState::HALF_OPEN, $cb->getState());

        $cb->recordSuccess();
        $this->assertSame(CircuitState::HALF_OPEN, $cb->getState());

        $cb->recordSuccess();
        $this->assertSame(CircuitState::HALF_OPEN, $cb->getState());

        $cb->recordSuccess();
        $this->assertSame(CircuitState::CLOSED, $cb->getState());
    }

    public function testMonotonicClockUsedForElapsedMeasurement(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2025-01-15 10:00:00'), 0.0);
        $cb = new CircuitBreaker(
            threshold: 1,
            timeout: 60,
            clock: $clock,
        );

        $cb->recordFailure();
        $this->assertSame(CircuitState::OPEN, $cb->getState());
        $this->assertFalse($cb->isAvailable());

        $clock->advance(60);

        $this->assertTrue($cb->isAvailable());
        $this->assertSame(CircuitState::OPEN, $cb->getState(), 'isAvailable() must not mutate state (#252)');

        $this->assertTrue($cb->acquire());
        $this->assertSame(CircuitState::HALF_OPEN, $cb->getState());
    }

    public function testBackwardNtpStepDoesNotStickCircuitOpen(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2025-01-15 10:00:00'), 0.0);
        $cb = new CircuitBreaker(
            threshold: 1,
            timeout: 60,
            clock: $clock,
        );

        $cb->recordFailure();
        $this->assertSame(CircuitState::OPEN, $cb->getState());
        $this->assertFalse($cb->isAvailable());

        $clock->advance(120);

        $clock->advance(-120);

        $this->assertSame('2025-01-15 10:00:00', $clock->now()->format('Y-m-d H:i:s'));

        $this->assertTrue($cb->acquire());
        $this->assertSame(CircuitState::HALF_OPEN, $cb->getState());
    }

    /**
     * Observability polling ({@see CircuitBreaker::isAvailable()}) must never
     * flip an OPEN breaker to HALF_OPEN; only the explicit execution-path
     * {@see CircuitBreaker::acquire()} may (#252).
     */
    public function testIsAvailableNeverTransitionsOpenToHalfOpen(): void
    {
        $clock = new FrozenClock();
        $cb = new CircuitBreaker(threshold: 1, timeout: 60, clock: $clock);

        $cb->recordFailure();
        $clock->advance(120);

        $this->assertTrue($cb->isAvailable());
        $this->assertTrue($cb->isAvailable());
        $this->assertSame(CircuitState::OPEN, $cb->getState());

        $this->assertTrue($cb->acquire());
        $this->assertSame(CircuitState::HALF_OPEN, $cb->getState());
    }

    /**
     * A pure isAvailable() must not reset the half-open success counter, or a
     * poll could indefinitely delay closing the circuit (#252).
     */
    public function testIsAvailableDoesNotResetHalfOpenSuccessCount(): void
    {
        $clock = new FrozenClock();
        $cb = new CircuitBreaker(threshold: 1, timeout: 60, successThreshold: 2, clock: $clock);

        $cb->recordFailure();
        $clock->advance(61);
        $this->assertTrue($cb->acquire());
        $this->assertSame(CircuitState::HALF_OPEN, $cb->getState());

        $cb->recordSuccess();
        $this->assertTrue($cb->isAvailable());
        $this->assertSame(CircuitState::HALF_OPEN, $cb->getState());

        $cb->recordSuccess();
        $this->assertSame(CircuitState::CLOSED, $cb->getState(), 'A poll must not swallow a half-open success');
    }

    public function testAcquireReturnsFalseWhileOpenBeforeTimeout(): void
    {
        $clock = new FrozenClock();
        $cb = new CircuitBreaker(threshold: 1, timeout: 60, clock: $clock);

        $cb->recordFailure();

        $this->assertFalse($cb->acquire());
        $this->assertSame(CircuitState::OPEN, $cb->getState());

        $clock->advance(59);
        $this->assertFalse($cb->acquire());
        $this->assertSame(CircuitState::OPEN, $cb->getState());
    }

    public function testAcquireIsTrueWhenClosed(): void
    {
        $cb = new CircuitBreaker();

        $this->assertTrue($cb->acquire());
        $this->assertSame(CircuitState::CLOSED, $cb->getState());
    }

    public function testSporadicFailuresDoNotTripCircuit(): void
    {
        // A healthy service with sporadic transient failures must not open the
        // breaker: each success clears the consecutive-failure streak, so only
        // consecutive (not cumulative) failures count toward the threshold.
        $cb = new CircuitBreaker(
            threshold: 10,
            timeout: 60,
            clock: new FrozenClock(new \DateTimeImmutable('2025-01-15 10:00:00'), 0.0),
        );

        for ($i = 0; $i < 100; $i++) {
            $cb->recordFailure();
            $cb->recordSuccess();
        }

        $this->assertSame(CircuitState::CLOSED, $cb->getState());
        $this->assertTrue($cb->isAvailable());
    }

    public function testConsecutiveFailuresTripCircuit(): void
    {
        $cb = new CircuitBreaker(
            threshold: 10,
            timeout: 60,
            clock: new FrozenClock(new \DateTimeImmutable('2025-01-15 10:00:00'), 0.0),
        );

        for ($i = 0; $i < 9; $i++) {
            $cb->recordFailure();
        }
        $this->assertSame(CircuitState::CLOSED, $cb->getState());

        $cb->recordFailure();
        $this->assertSame(CircuitState::OPEN, $cb->getState());
    }

    public function testSuccessResetsConsecutiveFailureStreak(): void
    {
        $cb = new CircuitBreaker(
            threshold: 3,
            timeout: 60,
            clock: new FrozenClock(new \DateTimeImmutable('2025-01-15 10:00:00'), 0.0),
        );

        $cb->recordFailure();
        $cb->recordFailure();
        $cb->recordSuccess(); // clears the streak
        $cb->recordFailure();
        $cb->recordFailure();

        $this->assertSame(CircuitState::CLOSED, $cb->getState());
    }

    private function getSuccessThreshold(CircuitBreaker $cb): int
    {
        $reflection = new \ReflectionClass($cb);
        $prop = $reflection->getProperty('successThreshold');
        $threshold = $prop->getValue($cb);

        // A reflected value is mixed; asserting the type is also the assertion
        // that the property really holds the int the rest of the class reads.
        self::assertIsInt($threshold);

        return $threshold;
    }
}
