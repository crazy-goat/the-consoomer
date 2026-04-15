<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\CircuitBreaker;
use CrazyGoat\TheConsoomer\CircuitState;
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

        $cb->recordFailure();
        $this->assertSame(CircuitState::OPEN, $cb->getState());

        usleep(1100000);

        $this->assertTrue($cb->isAvailable());
        $this->assertSame(CircuitState::HALF_OPEN, $cb->getState());

        $cb->recordSuccess();
        $this->assertSame(CircuitState::HALF_OPEN, $cb->getState());

        $cb->recordSuccess();
        $this->assertSame(CircuitState::CLOSED, $cb->getState());
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

        $cb->isAvailable();
        $this->assertSame(CircuitState::HALF_OPEN, $cb->getState());

        $cb->recordSuccess();
        $this->assertSame(CircuitState::HALF_OPEN, $cb->getState());

        $cb->recordSuccess();
        $this->assertSame(CircuitState::HALF_OPEN, $cb->getState());

        $cb->recordSuccess();
        $this->assertSame(CircuitState::CLOSED, $cb->getState());
    }

    private function getSuccessThreshold(CircuitBreaker $cb): int
    {
        $reflection = new \ReflectionClass($cb);
        $prop = $reflection->getProperty('successThreshold');
        return $prop->getValue($cb);
    }
}
