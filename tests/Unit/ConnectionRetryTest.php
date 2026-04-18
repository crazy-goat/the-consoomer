<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\CircuitState;
use CrazyGoat\TheConsoomer\ConnectionRetry;
use CrazyGoat\TheConsoomer\Tests\Unit\Clock\FrozenClock;
use PHPUnit\Framework\TestCase;

class ConnectionRetryTest extends TestCase
{
    public function testJitterVariationFactorConstant(): void
    {
        $this->assertSame(0.25, ConnectionRetry::JITTER_VARIATION_FACTOR);
    }

    public function testSuccessfulOperationNoRetry(): void
    {
        $retry = new ConnectionRetry(retryCount: 3, retryDelay: 1000);

        $result = $retry->withRetry(fn(): string => 'success');

        $this->assertSame('success', $result);
    }

    public function testRetryCountZeroThrowsRuntimeException(): void
    {
        $retry = new ConnectionRetry(retryCount: 0, retryDelay: 1000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Operation failed with no retries configured');

        $retry->withRetry(function (): void {
            throw new \AMQPConnectionException('Connection failed');
        });
    }

    public function testRetryOnConnectionException(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(retryCount: 3, retryDelay: 1000);

        $this->expectException(\AMQPConnectionException::class);

        $retry->withRetry(function () use (&$attempt): void {
            $attempt++;
            throw new \AMQPConnectionException('Connection failed');
        });

        $this->assertSame(3, $attempt);
    }

    public function testRetrySucceedsOnSecondAttempt(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(retryCount: 3, retryDelay: 1000);

        $result = $retry->withRetry(function () use (&$attempt): string {
            $attempt++;
            if ($attempt < 2) {
                throw new \AMQPConnectionException('Connection failed');
            }
            return 'success';
        });

        $this->assertSame('success', $result);
        $this->assertSame(2, $attempt);
    }

    public function testNoRetryOnOtherException(): void
    {
        $retry = new ConnectionRetry(retryCount: 3, retryDelay: 1000);

        $this->expectException(\RuntimeException::class);

        $retry->withRetry(function (): void {
            throw new \RuntimeException('Other error');
        });
    }

    public function testCircuitBreakerOpensAfterThreshold(): void
    {
        $retry = new ConnectionRetry(
            retryCount: 3,
            retryDelay: 1000,
            retryCircuitBreaker: true,
            retryCircuitBreakerThreshold: 2,
        );

        for ($i = 0; $i < 2; $i++) {
            try {
                $retry->withRetry(function (): void {
                    throw new \AMQPConnectionException('Connection failed');
                });
            } catch (\AMQPConnectionException) {
            }
        }

        $this->assertTrue($retry->isCircuitOpen());
        $this->assertSame(CircuitState::OPEN, $retry->getState());
    }

    public function testCircuitBreakerAllowsRequestWhenHalfOpen(): void
    {
        $clock = new FrozenClock();

        $retry = new ConnectionRetry(
            retryCount: 1,
            retryDelay: 1000,
            retryCircuitBreaker: true,
            retryCircuitBreakerThreshold: 1,
            retryCircuitBreakerTimeout: 2,
            clock: $clock,
        );

        try {
            $retry->withRetry(function (): void {
                throw new \AMQPConnectionException('Connection failed');
            });
        } catch (\AMQPConnectionException) {
        }

        $this->assertTrue($retry->isCircuitOpen());

        $clock->advance(3);

        $result = $retry->withRetry(fn(): string => 'success');

        $this->assertSame('success', $result);
    }

    public function testExponentialBackoff(): void
    {
        $retry = new ConnectionRetry(
            retryCount: 3,
            retryDelay: 100000,
            retryBackoff: true,
            retryJitter: false,
        );

        $startTime = microtime(true);

        try {
            $retry->withRetry(function (): void {
                throw new \AMQPConnectionException('Connection failed');
            });
        } catch (\AMQPConnectionException) {
        }

        $totalTime = (microtime(true) - $startTime) * 1000000;

        $expectedBaseDelay = 100000;
        $this->assertGreaterThan($expectedBaseDelay * 1.5, $totalTime);
    }

    public function testJitterAddsRandomVariation(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $retry = new ConnectionRetry(
                retryCount: 2,
                retryDelay: 100000,
                retryBackoff: false,
                retryJitter: true,
            );

            $attempt = 0;
            try {
                $retry->withRetry(function () use (&$attempt): void {
                    $attempt++;
                    throw new \AMQPConnectionException('Connection failed');
                });
            } catch (\AMQPConnectionException) {
            }
        }

        $this->assertTrue(true);
    }

    public function testResetsCircuitBreaker(): void
    {
        $retry = new ConnectionRetry(
            retryCount: 1,
            retryDelay: 1000,
            retryCircuitBreaker: true,
            retryCircuitBreakerThreshold: 1,
        );

        try {
            $retry->withRetry(function (): void {
                throw new \AMQPConnectionException('Connection failed');
            });
        } catch (\AMQPConnectionException) {
        }

        $this->assertTrue($retry->isCircuitOpen());

        $retry->reset();

        $this->assertFalse($retry->isCircuitOpen());
        $this->assertSame(CircuitState::CLOSED, $retry->getState());
    }

    /**
     * Regression test for issue #60: CircuitBreaker should not transition based on
     * construction time - timeout should only start after first actual failure.
     */
    public function testCircuitBreakerDoesNotTransitionWithoutFailure(): void
    {
        $clock = new FrozenClock();

        $retry = new ConnectionRetry(
            retryCount: 1,
            retryDelay: 1000,
            retryCircuitBreaker: true,
            retryCircuitBreakerThreshold: 1,
            retryCircuitBreakerTimeout: 1,
            clock: $clock,
        );

        $clock->advance(2);

        $this->assertFalse($retry->isCircuitOpen());
        $this->assertSame(CircuitState::CLOSED, $retry->getState());
    }

    public function testCircuitBreakerSuccessThresholdDefaultIsTwo(): void
    {
        $clock = new FrozenClock();

        $retry = new ConnectionRetry(
            retryCount: 1,
            retryDelay: 1000,
            retryCircuitBreaker: true,
            retryCircuitBreakerThreshold: 1,
            retryCircuitBreakerTimeout: 2,
            clock: $clock,
        );

        try {
            $retry->withRetry(function (): void {
                throw new \AMQPConnectionException('Connection failed');
            });
        } catch (\AMQPConnectionException) {
        }

        $this->assertTrue($retry->isCircuitOpen());

        $clock->advance(3);

        $retry->isCircuitOpen();
        $this->assertSame(CircuitState::HALF_OPEN, $retry->getState());

        $retry->withRetry(fn(): string => 'success');
        $this->assertSame(CircuitState::HALF_OPEN, $retry->getState());

        $retry->withRetry(fn(): string => 'success');
        $this->assertSame(CircuitState::CLOSED, $retry->getState());
    }

    public function testCircuitBreakerCustomSuccessThreshold(): void
    {
        $clock = new FrozenClock();

        $retry = new ConnectionRetry(
            retryCount: 1,
            retryDelay: 1000,
            retryCircuitBreaker: true,
            retryCircuitBreakerThreshold: 1,
            retryCircuitBreakerTimeout: 2,
            retryCircuitBreakerSuccessThreshold: 3,
            clock: $clock,
        );

        try {
            $retry->withRetry(function (): void {
                throw new \AMQPConnectionException('Connection failed');
            });
        } catch (\AMQPConnectionException) {
        }

        $clock->advance(3);

        $retry->isCircuitOpen();
        $this->assertSame(CircuitState::HALF_OPEN, $retry->getState());

        $retry->withRetry(fn(): string => 'success');
        $this->assertSame(CircuitState::HALF_OPEN, $retry->getState());

        $retry->withRetry(fn(): string => 'success');
        $this->assertSame(CircuitState::HALF_OPEN, $retry->getState());

        $retry->withRetry(fn(): string => 'success');
        $this->assertSame(CircuitState::CLOSED, $retry->getState());
    }

    public function testCircuitBreakerSuccessThresholdValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('successThreshold must be at least 2');

        new ConnectionRetry(
            retryCount: 1,
            retryDelay: 1000,
            retryCircuitBreaker: true,
            retryCircuitBreakerThreshold: 1,
            retryCircuitBreakerTimeout: 2,
            retryCircuitBreakerSuccessThreshold: 1,
        );
    }
}
