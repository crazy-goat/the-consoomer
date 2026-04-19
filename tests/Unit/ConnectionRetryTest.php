<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\CircuitState;
use CrazyGoat\TheConsoomer\ConnectionRetry;
use CrazyGoat\TheConsoomer\Exception\CircuitBreakerOpenException;
use CrazyGoat\TheConsoomer\Exception\RetryExhaustedException;
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

        $this->expectException(RetryExhaustedException::class);
        $this->expectExceptionMessage('Operation failed with no retries configured');

        $retry->withRetry(function (): void {
            throw new \AMQPConnectionException('Connection failed');
        });
    }

    public function testRetryOnConnectionException(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(retryCount: 3, retryDelay: 1000);

        $this->expectException(RetryExhaustedException::class);

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

        $this->expectException(RetryExhaustedException::class);

        $retry->withRetry(function (): void {
            throw new \RuntimeException('Other error');
        });
    }

    public function testRetryOnChannelException(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(retryCount: 3, retryDelay: 1000);

        $this->expectException(RetryExhaustedException::class);

        $retry->withRetry(function () use (&$attempt): void {
            $attempt++;
            throw new \AMQPChannelException('Channel closed unexpectedly');
        });

        $this->assertSame(3, $attempt);
    }

    public function testRetryOnExchangeException(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(retryCount: 3, retryDelay: 1000);

        $this->expectException(RetryExhaustedException::class);

        $retry->withRetry(function () use (&$attempt): void {
            $attempt++;
            throw new \AMQPExchangeException('Exchange error after reconnect');
        });

        $this->assertSame(3, $attempt);
    }

    public function testRetryOnQueueException(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(retryCount: 3, retryDelay: 1000);

        $this->expectException(RetryExhaustedException::class);

        $retry->withRetry(function () use (&$attempt): void {
            $attempt++;
            throw new \AMQPQueueException('Queue error after reconnect');
        });

        $this->assertSame(3, $attempt);
    }

    public function testRetrySucceedsOnSecondAttemptWithChannelException(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(retryCount: 3, retryDelay: 1000);

        $result = $retry->withRetry(function () use (&$attempt): string {
            $attempt++;
            if ($attempt < 2) {
                throw new \AMQPChannelException('Channel closed');
            }
            return 'success';
        });

        $this->assertSame('success', $result);
        $this->assertSame(2, $attempt);
    }

    public function testNoRetryOnQueueNotFound(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(retryCount: 3, retryDelay: 1000);

        try {
            $retry->withRetry(function () use (&$attempt): void {
                $attempt++;
                throw new \AMQPQueueException('Queue not found', 404);
            });
            $this->fail('Expected AMQPQueueException to be thrown');
        } catch (\AMQPQueueException $e) {
            $this->assertSame(1, $attempt, 'Permanent failure should not trigger retry');
            $this->assertSame('Queue not found', $e->getMessage());
        }
    }

    public function testNoRetryOnExchangeNotFound(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(retryCount: 3, retryDelay: 1000);

        try {
            $retry->withRetry(function () use (&$attempt): void {
                $attempt++;
                throw new \AMQPExchangeException('Exchange not found', 404);
            });
            $this->fail('Expected AMQPExchangeException to be thrown');
        } catch (\AMQPExchangeException $e) {
            $this->assertSame(1, $attempt, 'Permanent failure should not trigger retry');
            $this->assertSame('Exchange not found', $e->getMessage());
        }
    }

    public function testNoRetryOnAccessDenied(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(retryCount: 3, retryDelay: 1000);

        try {
            $retry->withRetry(function () use (&$attempt): void {
                $attempt++;
                throw new \AMQPException('Access refused', 403);
            });
            $this->fail('Expected AMQPException to be thrown');
        } catch (\AMQPException $e) {
            $this->assertSame(1, $attempt, 'Permanent failure should not trigger retry');
            $this->assertSame(403, $e->getCode());
        }
    }

    public function testNoRetryOnPreconditionFailed(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(retryCount: 3, retryDelay: 1000);

        try {
            $retry->withRetry(function () use (&$attempt): void {
                $attempt++;
                throw new \AMQPException('Precondition failed', 406);
            });
            $this->fail('Expected AMQPException to be thrown');
        } catch (\AMQPException $e) {
            $this->assertSame(1, $attempt, 'Permanent failure should not trigger retry');
            $this->assertSame(406, $e->getCode());
        }
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
            } catch (RetryExhaustedException $e) {
                $this->assertInstanceOf(\AMQPConnectionException::class, $e->getPrevious());
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
        } catch (RetryExhaustedException $e) {
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
        } catch (RetryExhaustedException $e) {
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
            } catch (RetryExhaustedException $e) {
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
        } catch (RetryExhaustedException $e) {
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
        } catch (RetryExhaustedException $e) {
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
        } catch (RetryExhaustedException $e) {
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
