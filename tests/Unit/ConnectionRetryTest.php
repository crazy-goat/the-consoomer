<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\CircuitBreaker;
use CrazyGoat\TheConsoomer\CircuitState;
use CrazyGoat\TheConsoomer\ConnectionRetry;
use CrazyGoat\TheConsoomer\Exception\CircuitBreakerOpenException;
use CrazyGoat\TheConsoomer\Exception\RetryExhaustedException;
use CrazyGoat\TheConsoomer\Exception\UnexpectedOperationException;
use CrazyGoat\TheConsoomer\Tests\Unit\Clock\FrozenClock;
use PHPUnit\Framework\TestCase;

class ConnectionRetryTest extends TestCase
{
    /**
     * Asserts that a guarded try/catch caught the expected exception.
     *
     * The parameter is `mixed` on purpose: it is `null` when nothing was
     * thrown, and that case has to surface as a normal assertion failure
     * rather than as a fatal error on `null->getMessage()`. It also keeps the
     * check a genuine run-time one — the test verifies what the retry actually
     * threw instead of restating what the analyser can already infer from the
     * closure that always throws.
     *
     * @param class-string $expectedClass
     */
    private function assertCaught(mixed $caught, string $expectedClass, string $message): void
    {
        self::assertInstanceOf($expectedClass, $caught, $message);
    }

    public function testJitterVariationFactorConstant(): void
    {
        // Read through reflection on purpose: the analyser knows every constant
        // value already, so an inline comparison is a tautology for it. The
        // run-time check is the point - it catches a retuned constant.
        $factor = (new \ReflectionClass(ConnectionRetry::class))->getConstant('JITTER_VARIATION_FACTOR');

        $this->assertIsFloat($factor);
        $this->assertSame(0.25, $factor);
        // It scales the base delay, so it has to stay a usable fraction.
        $this->assertGreaterThan(0.0, $factor);
        $this->assertLessThanOrEqual(1.0, $factor);
    }

    public function testSuccessfulOperationNoRetry(): void
    {
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        $payload = new \stdClass();
        $result = $retry->withRetry(fn(): \stdClass => $payload);

        // Identity rather than a string: withRetry() has to hand back the very
        // object the operation returned, which also proves nothing was retried
        // or wrapped on the way through.
        $this->assertSame($payload, $result);
    }

    public function testMaxAttemptsZeroThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxAttempts must be at least 1');

        new ConnectionRetry(maxAttempts: 0, retryDelay: 1000);
    }

    public function testRetryOnConnectionException(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        $caught = null;
        try {
            $retry->withRetry(function () use (&$attempt): void {
                $attempt++;
                throw new \AMQPConnectionException('Connection failed');
            });
        } catch (RetryExhaustedException $e) {
            $caught = $e;
            $this->assertSame(3, $attempt);
        }

        $this->assertCaught($caught, RetryExhaustedException::class, 'Expected RetryExhaustedException to be thrown');
    }

    public function testRetrySucceedsOnSecondAttempt(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        $payload = new \stdClass();
        $result = $retry->withRetry(function () use (&$attempt, $payload): \stdClass {
            $attempt++;
            if ($attempt < 2) {
                throw new \AMQPConnectionException('Connection failed');
            }
            return $payload;
        });

        $this->assertSame($payload, $result);
        $this->assertSame(2, $attempt);
    }

    public function testMaxAttemptsOneExecutesExactlyOneAttempt(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(maxAttempts: 1, retryDelay: 1000);

        $caught = null;
        try {
            $retry->withRetry(function () use (&$attempt): void {
                $attempt++;
                throw new \AMQPConnectionException('Connection failed');
            });
        } catch (RetryExhaustedException $e) {
            $caught = $e;
            $this->assertSame(1, $attempt);
        }

        $this->assertCaught($caught, RetryExhaustedException::class, 'Expected RetryExhaustedException to be thrown');
    }

    public function testMaxAttemptsTwoExecutesExactlyTwoAttempts(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(maxAttempts: 2, retryDelay: 1000);

        $caught = null;
        try {
            $retry->withRetry(function () use (&$attempt): void {
                $attempt++;
                throw new \AMQPConnectionException('Connection failed');
            });
        } catch (RetryExhaustedException $e) {
            $caught = $e;
            $this->assertSame(2, $attempt);
        }

        $this->assertCaught($caught, RetryExhaustedException::class, 'Expected RetryExhaustedException to be thrown');
    }

    public function testNoRetryOnOtherException(): void
    {
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        $this->expectException(UnexpectedOperationException::class);

        $retry->withRetry(function (): void {
            throw new \RuntimeException('Other error');
        });
    }

    public function testRetryOnChannelException(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        $caught = null;
        try {
            $retry->withRetry(function () use (&$attempt): void {
                $attempt++;
                throw new \AMQPChannelException('Channel closed unexpectedly');
            });
        } catch (RetryExhaustedException $e) {
            $caught = $e;
            $this->assertSame(3, $attempt);
        }

        $this->assertCaught($caught, RetryExhaustedException::class, 'Expected RetryExhaustedException to be thrown');
    }

    public function testRetryOnExchangeExceptionWithoutReplyCode(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        $caught = null;
        try {
            $retry->withRetry(function () use (&$attempt): void {
                $attempt++;
                throw new \AMQPExchangeException('Exchange error');
            });
        } catch (RetryExhaustedException $e) {
            $caught = $e;
            $this->assertSame(3, $attempt, 'Exchange exception without a reply code is transient and must be retried (#285)');
            $this->assertInstanceOf(\AMQPExchangeException::class, $e->getPrevious());
        }

        $this->assertCaught($caught, RetryExhaustedException::class, 'Expected RetryExhaustedException to be thrown');
    }

    /**
     * ext-amqp raises AMQPQueueException for a plain read timeout (verified
     * against the live broker), which is transient and must be retried (#285).
     */
    public function testRetryOnQueueExceptionTimeoutMessage(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        $caught = null;
        try {
            $retry->withRetry(function () use (&$attempt): void {
                $attempt++;
                throw new \AMQPQueueException('Consumer timeout exceed');
            });
        } catch (RetryExhaustedException $e) {
            $caught = $e;
            $this->assertSame(3, $attempt, 'A queue read timeout must be retried, not treated as permanent (#285)');
            $this->assertInstanceOf(\AMQPQueueException::class, $e->getPrevious());
        }

        $this->assertCaught($caught, RetryExhaustedException::class, 'Expected RetryExhaustedException to be thrown');
    }

    public function testRetryOnQueueExceptionWithoutReplyCode(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        $caught = null;
        try {
            $retry->withRetry(function () use (&$attempt): void {
                $attempt++;
                throw new \AMQPQueueException('Queue error');
            });
        } catch (RetryExhaustedException $e) {
            $caught = $e;
            $this->assertSame(3, $attempt, 'Queue exception without a reply code is transient and must be retried (#285)');
            $this->assertInstanceOf(\AMQPQueueException::class, $e->getPrevious());
        }

        $this->assertCaught($caught, RetryExhaustedException::class, 'Expected RetryExhaustedException to be thrown');
    }

    public function testRetryOnConnectionExceptionWithPermanentCode(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        $caught = null;
        try {
            $retry->withRetry(function () use (&$attempt): void {
                $attempt++;
                throw new \AMQPConnectionException('Connection lost', 404);
            });
        } catch (RetryExhaustedException $e) {
            $caught = $e;
            $this->assertSame(3, $attempt, 'Connection exception with code 404 should be transient, not permanent');
        }

        $this->assertCaught($caught, RetryExhaustedException::class, 'Expected RetryExhaustedException to be thrown');
    }

    public function testRetryOnChannelExceptionWithPermanentCode(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        $caught = null;
        try {
            $retry->withRetry(function () use (&$attempt): void {
                $attempt++;
                throw new \AMQPChannelException('Channel error', 404);
            });
        } catch (RetryExhaustedException $e) {
            $caught = $e;
            $this->assertSame(3, $attempt, 'Channel exception with code 404 should be transient, not permanent');
        }

        $this->assertCaught($caught, RetryExhaustedException::class, 'Expected RetryExhaustedException to be thrown');
    }

    public function testRetryOnQueueExceptionWithZeroCode(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        $caught = null;
        try {
            $retry->withRetry(function () use (&$attempt): void {
                $attempt++;
                throw new \AMQPQueueException('Queue not found', 0);
            });
        } catch (RetryExhaustedException $e) {
            $caught = $e;
            $this->assertSame(3, $attempt, 'Queue exception with code 0 carries no proof of permanence and must be retried (#285)');
        }

        $this->assertCaught($caught, RetryExhaustedException::class, 'Expected RetryExhaustedException to be thrown');
    }

    public function testRetryOnExchangeExceptionWithZeroCode(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        $caught = null;
        try {
            $retry->withRetry(function () use (&$attempt): void {
                $attempt++;
                throw new \AMQPExchangeException('Exchange not found', 0);
            });
        } catch (RetryExhaustedException $e) {
            $caught = $e;
            $this->assertSame(3, $attempt, 'Exchange exception with code 0 carries no proof of permanence and must be retried (#285)');
        }

        $this->assertCaught($caught, RetryExhaustedException::class, 'Expected RetryExhaustedException to be thrown');
    }

    /**
     * When getCode() is unreliable (0) the symbolic reply code in the broker
     * message still identifies a permanent failure (#285).
     */
    public function testNoRetryOnQueueExceptionWithNotFoundKeyword(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        $caught = null;
        try {
            $retry->withRetry(function () use (&$attempt): void {
                $attempt++;
                throw new \AMQPQueueException("NOT_FOUND - no queue 'foo' in vhost '/'", 0);
            });
        } catch (\AMQPQueueException $e) {
            $caught = $e;
            $this->assertSame(1, $attempt, 'A NOT_FOUND reply code must remain permanent (#285)');
            $this->assertStringContainsString('NOT_FOUND', $e->getMessage());
        }

        $this->assertCaught($caught, \AMQPQueueException::class, 'Expected AMQPQueueException to be thrown');
    }

    public function testNoRetryOnExchangeExceptionWithPreconditionKeyword(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        $caught = null;
        try {
            $retry->withRetry(function () use (&$attempt): void {
                $attempt++;
                throw new \AMQPExchangeException('PRECONDITION_FAILED - inequivalent exchange', 0);
            });
        } catch (\AMQPExchangeException $e) {
            $caught = $e;
            $this->assertSame(1, $attempt, 'A PRECONDITION_FAILED reply code must remain permanent (#285)');
        }

        $this->assertCaught($caught, \AMQPExchangeException::class, 'Expected AMQPExchangeException to be thrown');
    }

    public function testRetryOnGenericAmqpExceptionWithZeroCode(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        $caught = null;
        try {
            $retry->withRetry(function () use (&$attempt): void {
                $attempt++;
                throw new \AMQPException('Generic error', 0);
            });
        } catch (RetryExhaustedException $e) {
            $caught = $e;
            $this->assertSame(3, $attempt, 'Generic AMQPException with code 0 should be transient');
        }

        $this->assertCaught($caught, RetryExhaustedException::class, 'Expected RetryExhaustedException to be thrown');
    }

    public function testRetrySucceedsOnSecondAttemptWithChannelException(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        $payload = new \stdClass();
        $result = $retry->withRetry(function () use (&$attempt, $payload): \stdClass {
            $attempt++;
            if ($attempt < 2) {
                throw new \AMQPChannelException('Channel closed');
            }
            return $payload;
        });

        $this->assertSame($payload, $result);
        $this->assertSame(2, $attempt);
    }

    public function testNoRetryOnQueueNotFound(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        $caught = null;
        try {
            $retry->withRetry(function () use (&$attempt): void {
                $attempt++;
                throw new \AMQPQueueException('Queue not found', 404);
            });
        } catch (\AMQPQueueException $e) {
            $caught = $e;
            $this->assertSame(1, $attempt, 'Permanent failure should not trigger retry');
            $this->assertSame('Queue not found', $e->getMessage());
        }

        $this->assertCaught($caught, \AMQPQueueException::class, 'Expected AMQPQueueException to be thrown');
    }

    public function testNoRetryOnExchangeNotFound(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        $caught = null;
        try {
            $retry->withRetry(function () use (&$attempt): void {
                $attempt++;
                throw new \AMQPExchangeException('Exchange not found', 404);
            });
        } catch (\AMQPExchangeException $e) {
            $caught = $e;
            $this->assertSame(1, $attempt, 'Permanent failure should not trigger retry');
            $this->assertSame('Exchange not found', $e->getMessage());
        }

        $this->assertCaught($caught, \AMQPExchangeException::class, 'Expected AMQPExchangeException to be thrown');
    }

    public function testNoRetryOnAccessDenied(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        $caught = null;
        try {
            $retry->withRetry(function () use (&$attempt): void {
                $attempt++;
                throw new \AMQPException('Access refused', 403);
            });
        } catch (\AMQPException $e) {
            $caught = $e;
            $this->assertSame(1, $attempt, 'Permanent failure should not trigger retry');
            $this->assertSame(403, $e->getCode());
        }

        $this->assertCaught($caught, \AMQPException::class, 'Expected AMQPException to be thrown');
    }

    public function testNoRetryOnPreconditionFailed(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        $caught = null;
        try {
            $retry->withRetry(function () use (&$attempt): void {
                $attempt++;
                throw new \AMQPException('Precondition failed', 406);
            });
        } catch (\AMQPException $e) {
            $caught = $e;
            $this->assertSame(1, $attempt, 'Permanent failure should not trigger retry');
            $this->assertSame(406, $e->getCode());
        }

        $this->assertCaught($caught, \AMQPException::class, 'Expected AMQPException to be thrown');
    }

    public function testCircuitBreakerOpensAfterThreshold(): void
    {
        $retry = new ConnectionRetry(
            maxAttempts: 3,
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

    /**
     * `isCircuitOpen()` is a pure read (#252): polling it after the breaker
     * timeout must not flip OPEN→HALF_OPEN (which would also reset the
     * half-open success counter on the next acquisition).
     */
    public function testIsCircuitOpenDoesNotMutateState(): void
    {
        $clock = new FrozenClock();

        $retry = new ConnectionRetry(
            maxAttempts: 1,
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
        } catch (RetryExhaustedException) {
        }

        $this->assertSame(CircuitState::OPEN, $retry->getState());

        $clock->advance(3);

        $this->assertFalse($retry->isCircuitOpen());
        $this->assertFalse($retry->isCircuitOpen());
        $this->assertSame(CircuitState::OPEN, $retry->getState(), 'isCircuitOpen() must not transition to HALF_OPEN');
    }

    public function testCircuitBreakerAllowsRequestWhenHalfOpen(): void
    {
        $clock = new FrozenClock();

        $retry = new ConnectionRetry(
            maxAttempts: 1,
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
        } catch (RetryExhaustedException) {
        }

        $this->assertTrue($retry->isCircuitOpen());

        $clock->advance(3);

        $payload = new \stdClass();
        $result = $retry->withRetry(fn(): \stdClass => $payload);

        $this->assertSame($payload, $result);
    }

    public function testJitterNeverExceedsMaxDelay(): void
    {
        $retry = new ConnectionRetry(
            maxAttempts: 1,
            retryDelay: 100000,
            retryBackoff: false,
            retryMaxDelay: 100000,
            retryJitter: true,
        );

        $calculateDelay = (new \ReflectionMethod($retry, 'calculateDelay'))->getClosure($retry);

        for ($i = 0; $i < 1000; $i++) {
            $delay = $calculateDelay(1);
            $this->assertLessThanOrEqual(100000, $delay, 'Jittered delay must not exceed retryMaxDelay');
            $this->assertGreaterThanOrEqual(0, $delay, 'Delay must be non-negative');
        }
    }

    public function testJitterWithBackoffNeverExceedsMaxDelay(): void
    {
        $retry = new ConnectionRetry(
            maxAttempts: 1,
            retryDelay: 1000,
            retryBackoff: true,
            retryMaxDelay: 100000,
            retryJitter: true,
        );

        $calculateDelay = (new \ReflectionMethod($retry, 'calculateDelay'))->getClosure($retry);

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            for ($i = 0; $i < 100; $i++) {
                $delay = $calculateDelay($attempt);
                $this->assertLessThanOrEqual(100000, $delay, 'Backoff jittered delay must not exceed retryMaxDelay');
            }
        }
    }

    /**
     * An aggressive retry_delay/count used to overflow to float before the cap,
     * leaking garbage into jitter and throwing ValueError from random_int()
     * (#209). The delay must stay a bounded, valid int.
     */
    public function testAggressiveBackoffWithJitterDoesNotOverflow(): void
    {
        $retry = new ConnectionRetry(
            maxAttempts: 1,
            retryDelay: intdiv(\PHP_INT_MAX, 1024),
            retryBackoff: true,
            retryMaxDelay: 30000000,
            retryJitter: true,
        );

        $calculateDelay = (new \ReflectionMethod($retry, 'calculateDelay'))->getClosure($retry);

        for ($attempt = 1; $attempt <= 40; $attempt++) {
            $delay = $calculateDelay($attempt);
            $this->assertGreaterThanOrEqual(0, $delay);
            $this->assertLessThanOrEqual(30000000, $delay);
        }
    }

    public function testConstructorRejectsNegativeRetryDelay(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('retryDelay must not be negative');

        new ConnectionRetry(maxAttempts: 2, retryDelay: -100, retryJitter: true);
    }

    public function testConstructorRejectsNegativeRetryMaxDelay(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('retryMaxDelay must not be negative');

        new ConnectionRetry(maxAttempts: 2, retryMaxDelay: -1);
    }

    public function testExponentialBackoff(): void
    {
        $retry = new ConnectionRetry(
            maxAttempts: 3,
            retryDelay: 100000,
            retryBackoff: true,
            retryJitter: false,
        );

        $startTime = microtime(true);

        try {
            $retry->withRetry(function (): void {
                throw new \AMQPConnectionException('Connection failed');
            });
        } catch (RetryExhaustedException) {
        }

        $totalTime = (microtime(true) - $startTime) * 1000000;

        $expectedBaseDelay = 100000;
        $this->assertGreaterThan($expectedBaseDelay * 1.5, $totalTime);
    }

    public function testJitterAddsRandomVariation(): void
    {
        $elapsed = [];

        for ($i = 0; $i < 10; $i++) {
            $retry = new ConnectionRetry(
                maxAttempts: 2,
                retryDelay: 100000,
                retryBackoff: false,
                retryJitter: true,
            );

            $attempts = new AttemptCounter();
            $caught = null;
            $start = microtime(true);
            try {
                $retry->withRetry(function () use ($attempts): void {
                    $attempts->bump();
                    throw new \AMQPConnectionException('Connection failed');
                });
            } catch (RetryExhaustedException $e) {
                $caught = $e;
            }
            $elapsed[] = microtime(true) - $start;

            $this->assertCaught($caught, RetryExhaustedException::class, 'Expected RetryExhaustedException to be thrown');
            $this->assertSame(2, $attempts->count());
        }

        // A retry sleeps for the base delay scaled by a random factor, so ten
        // The sleep is the base delay scaled by a random factor, so the ten
        // durations must actually spread. Measured over ten runs on this
        // machine: with jitter the spread is ~46ms, without it ~4ms (plain
        // scheduler noise). A 20ms threshold sits well clear of that noise and
        // well inside the jittered spread.
        //
        // This assertion used to be assertTrue(true), which verified nothing at
        // all, and an earlier version only compared the durations for
        // inequality - which passes even with jitter removed, because two real
        // sleeps practically never take the identical number of microseconds.
        $spread = (max($elapsed) - min($elapsed)) * 1_000_000;

        $this->assertGreaterThan(
            20_000,
            $spread,
            sprintf(
                'Jittered sleeps must differ far more than scheduler noise; measured spread %.0fus over %s',
                $spread,
                implode(', ', array_map(static fn(float $s): int => (int) ($s * 1_000_000), $elapsed)),
            ),
        );
    }

    public function testResetsCircuitBreaker(): void
    {
        $retry = new ConnectionRetry(
            maxAttempts: 1,
            retryDelay: 1000,
            retryCircuitBreaker: true,
            retryCircuitBreakerThreshold: 1,
        );

        try {
            $retry->withRetry(function (): void {
                throw new \AMQPConnectionException('Connection failed');
            });
        } catch (RetryExhaustedException) {
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
            maxAttempts: 1,
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
            maxAttempts: 1,
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
        } catch (RetryExhaustedException) {
        }

        $this->assertTrue($retry->isCircuitOpen());

        $clock->advance(3);

        $this->flipCircuitToHalfOpen($retry);
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
            maxAttempts: 1,
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
        } catch (RetryExhaustedException) {
        }

        $clock->advance(3);

        $this->flipCircuitToHalfOpen($retry);
        $this->assertSame(CircuitState::HALF_OPEN, $retry->getState());

        $retry->withRetry(fn(): string => 'success');
        $this->assertSame(CircuitState::HALF_OPEN, $retry->getState());

        $retry->withRetry(fn(): string => 'success');
        $this->assertSame(CircuitState::HALF_OPEN, $retry->getState());

        $retry->withRetry(fn(): string => 'success');
        $this->assertSame(CircuitState::CLOSED, $retry->getState());
    }

    public function testHalfOpenExecutesExactlyOneAttemptWithMaxAttemptsGreaterThanOne(): void
    {
        $clock = new FrozenClock();

        $retry = new ConnectionRetry(
            maxAttempts: 3,
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
        } catch (RetryExhaustedException) {
        }

        $this->assertTrue($retry->isCircuitOpen());

        $clock->advance(3);

        $attempt = 0;
        try {
            $retry->withRetry(function () use (&$attempt): void {
                $attempt++;
                throw new \AMQPConnectionException('Connection failed');
            });
        } catch (\AMQPConnectionException) {
        }

        $this->assertSame(1, $attempt, 'Half-open probe must execute exactly once regardless of maxAttempts');
    }

    public function testHalfOpenProbeFailureReopensCircuit(): void
    {
        $clock = new FrozenClock();

        $retry = new ConnectionRetry(
            maxAttempts: 3,
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
        } catch (RetryExhaustedException) {
        }

        $clock->advance(3);

        $this->flipCircuitToHalfOpen($retry);
        $this->assertSame(CircuitState::HALF_OPEN, $retry->getState());

        try {
            $retry->withRetry(function (): void {
                throw new \AMQPConnectionException('Probe failed');
            });
        } catch (\AMQPConnectionException) {
        }

        $this->assertSame(CircuitState::OPEN, $retry->getState());
    }

    public function testHalfOpenProbePermanentFailureCodeRecordsToBreaker(): void
    {
        $clock = new FrozenClock();

        $retry = new ConnectionRetry(
            maxAttempts: 3,
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
        } catch (RetryExhaustedException) {
        }

        $clock->advance(3);

        $this->flipCircuitToHalfOpen($retry);
        $this->assertSame(CircuitState::HALF_OPEN, $retry->getState());

        $attempt = 0;
        try {
            $retry->withRetry(function () use (&$attempt): void {
                $attempt++;
                throw new \AMQPQueueException('Queue not found', 404);
            });
        } catch (\AMQPQueueException) {
        }

        $this->assertSame(1, $attempt, 'Permanent failure code in HALF_OPEN must execute exactly once');
        $this->assertSame(CircuitState::HALF_OPEN, $retry->getState(), 'Permanent failure must not re-open the circuit (#355)');
    }

    public function testHalfOpenPermanentProbeFailurePausesProbingForTimeout(): void
    {
        $clock = new FrozenClock();

        $retry = new ConnectionRetry(
            maxAttempts: 3,
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
        } catch (RetryExhaustedException) {
        }

        $clock->advance(3);

        $attempts = new AttemptCounter();
        $permanent = function () use ($attempts): void {
            $attempts->bump();
            throw new \AMQPQueueException('Queue not found', 404);
        };

        $caught = null;
        try {
            $retry->withRetry($permanent);
        } catch (\AMQPQueueException $e) {
            $caught = $e;
        }

        $this->assertCaught($caught, \AMQPQueueException::class, 'Expected AMQPQueueException');
        $this->assertSame(1, $attempts->count());
        $this->assertSame(CircuitState::HALF_OPEN, $retry->getState());

        // Within the timeout the next call is rejected without touching the broker.
        $clock->advance(1);
        $caught = null;
        try {
            $retry->withRetry($permanent);
        } catch (CircuitBreakerOpenException $e) {
            $caught = $e;
        }

        $this->assertCaught($caught, CircuitBreakerOpenException::class, 'Expected CircuitBreakerOpenException');
        $this->assertSame(1, $attempts->count(), 'No probe may run during the cool-down (#357)');

        // After the timeout a new probe is allowed.
        $clock->advance(3);
        $caught = null;
        try {
            $retry->withRetry($permanent);
        } catch (\AMQPQueueException $e) {
            $caught = $e;
        }

        $this->assertCaught($caught, \AMQPQueueException::class, 'Expected AMQPQueueException');
        $this->assertSame(2, $attempts->count());
        $this->assertSame(CircuitState::HALF_OPEN, $retry->getState());
    }

    public function testHalfOpenProbeSuccessAdvancesToClosedAfterThreshold(): void
    {
        $clock = new FrozenClock();

        $retry = new ConnectionRetry(
            maxAttempts: 3,
            retryDelay: 1000,
            retryCircuitBreaker: true,
            retryCircuitBreakerThreshold: 1,
            retryCircuitBreakerTimeout: 2,
            retryCircuitBreakerSuccessThreshold: 2,
            clock: $clock,
        );

        try {
            $retry->withRetry(function (): void {
                throw new \AMQPConnectionException('Connection failed');
            });
        } catch (RetryExhaustedException) {
        }

        $clock->advance(3);

        $this->flipCircuitToHalfOpen($retry);
        $this->assertSame(CircuitState::HALF_OPEN, $retry->getState());

        $retry->withRetry(fn(): string => 'first success');
        $this->assertSame(CircuitState::HALF_OPEN, $retry->getState(), 'One success under threshold should stay HALF_OPEN');

        $retry->withRetry(fn(): string => 'second success');
        $this->assertSame(CircuitState::CLOSED, $retry->getState(), 'Second success reaches threshold, should close');
    }

    public function testHalfOpenProbeNonAmqpExceptionThrowsUnexpected(): void
    {
        $clock = new FrozenClock();

        $retry = new ConnectionRetry(
            maxAttempts: 3,
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
        } catch (RetryExhaustedException) {
        }

        $clock->advance(3);

        $this->flipCircuitToHalfOpen($retry);
        $this->assertSame(CircuitState::HALF_OPEN, $retry->getState());

        $this->expectException(UnexpectedOperationException::class);

        $retry->withRetry(function (): void {
            throw new \RuntimeException('Unexpected error');
        });
    }

    public function testCircuitBreakerSuccessThresholdValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('successThreshold must be at least 2');

        new ConnectionRetry(
            maxAttempts: 1,
            retryDelay: 1000,
            retryCircuitBreaker: true,
            retryCircuitBreakerThreshold: 1,
            retryCircuitBreakerTimeout: 2,
            retryCircuitBreakerSuccessThreshold: 1,
        );
    }

    /**
     * Regression test for issue #206: recordAttempt() must fire on every
     * loop iteration, so totalAttempts counts real attempts including
     * failures, not only successful operations.
     */
    public function testMetricsRecordAttemptCountsWithFailures(): void
    {
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        try {
            $retry->withRetry(function (): void {
                throw new \AMQPConnectionException('Connection failed');
            });
        } catch (RetryExhaustedException) {
        }

        $metrics = $retry->getMetrics();

        $this->assertSame(3, $metrics->getTotalAttempts(), 'totalAttempts must count every attempt, including failures');
        $this->assertSame(0, $metrics->getSuccessfulRetries());
        $this->assertSame(1, $metrics->getFailedRetries());
    }

    /**
     * Regression test for issue #206: a single success must record exactly
     * one attempt and zero retries.
     */
    public function testMetricsSuccessRecordsOneAttemptNoRetry(): void
    {
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        $retry->withRetry(fn(): string => 'success');

        $metrics = $retry->getMetrics();

        $this->assertSame(1, $metrics->getTotalAttempts());
        $this->assertSame(0, $metrics->getSuccessfulRetries(), 'First-try success is not a retry');
        $this->assertSame(0, $metrics->getFailedRetries());
    }

    /**
     * Regression test for issue #206: a retry that eventually succeeds
     * must record each intermediate attempt plus the final one.
     */
    public function testMetricsRetrySucceedsRecordsAllAttempts(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        $retry->withRetry(function () use (&$attempt): string {
            $attempt++;
            if ($attempt < 2) {
                throw new \AMQPConnectionException('Connection failed');
            }
            return 'success';
        });

        $metrics = $retry->getMetrics();

        $this->assertSame(2, $metrics->getTotalAttempts(), 'Two real attempts were made');
        $this->assertSame(1, $metrics->getSuccessfulRetries(), 'One retry succeeded');
        $this->assertSame(0, $metrics->getFailedRetries());
    }

    /**
     * Regression test for issue #206: the success-rate denominator must
     * include attempts from fully-failed operations. Before the fix a
     * failed operation contributed zero to totalAttempts; now it must
     * contribute one attempt per loop iteration.
     */
    public function testMetricsRateDenominatorIncludesFailedOperations(): void
    {
        $retry = new ConnectionRetry(maxAttempts: 2, retryDelay: 1000);

        // Operation 1: succeeds on the 2nd attempt (one retry).
        $attempt = 0;
        $retry->withRetry(function () use (&$attempt): string {
            $attempt++;
            if ($attempt < 2) {
                throw new \AMQPConnectionException('Connection failed');
            }
            return 'success';
        });

        // Operation 2: fails both attempts.
        try {
            $retry->withRetry(function (): void {
                throw new \AMQPConnectionException('Connection failed');
            });
        } catch (RetryExhaustedException) {
        }

        $metrics = $retry->getMetrics();

        $this->assertSame(4, $metrics->getTotalAttempts(), '2 attempts for the retried success + 2 for the failure');
        $this->assertSame(1, $metrics->getSuccessfulRetries());
        $this->assertSame(1, $metrics->getFailedRetries());
        $this->assertSame(25.0, $metrics->getRetrySuccessRate(), '1 successful retry / 4 total attempts');
    }

    /**
     * Regression test for issue #339: a permanent failure rethrown from the
     * retry loop must still count as a failed retry. recordAttempt() already
     * fired at the loop top, so without recordFailure() totalAttempts would
     * grow while neither successfulRetries nor failedRetries accounts for it.
     */
    public function testMetricsPermanentFailureRecordsFailedRetry(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        $caught = null;
        try {
            $retry->withRetry(function () use (&$attempt): void {
                $attempt++;
                throw new \AMQPQueueException('Queue not found', 404);
            });
        } catch (\AMQPQueueException $e) {
            $caught = $e;
            $this->assertSame(1, $attempt, 'Permanent failure should not trigger retry');
        }

        $this->assertCaught($caught, \AMQPQueueException::class, 'Expected AMQPQueueException to be thrown');

        $metrics = $retry->getMetrics();

        $this->assertSame(1, $metrics->getTotalAttempts());
        $this->assertSame(0, $metrics->getSuccessfulRetries());
        $this->assertSame(1, $metrics->getFailedRetries(), 'Permanent failure must count as a failed retry');
    }

    /**
     * Regression test for issue #339: the same permanent failure must count
     * as a failed retry on both code paths — closed (retry loop) and half-open
     * (probe). The half-open path always recorded it; this asserts the closed
     * path now matches after the first operation opened the circuit.
     * Half-open is reached the way existing tests in this file do: FrozenClock
     * injection plus advance() past the breaker timeout, then the execution
     * path acquires the circuit (see {@see flipCircuitToHalfOpen()}).
     */
    public function testMetricsHalfOpenPermanentFailureMatchesClosedPath(): void
    {
        $clock = new FrozenClock();

        $retry = new ConnectionRetry(
            maxAttempts: 2,
            retryDelay: 1000,
            retryCircuitBreaker: true,
            retryCircuitBreakerThreshold: 1,
            retryCircuitBreakerTimeout: 60,
            retryCircuitBreakerSuccessThreshold: 2,
            clock: $clock,
        );

        // Transient failure exhausts retries and opens the circuit:
        // 2 attempts recorded, 1 failed retry.
        try {
            $retry->withRetry(function (): void {
                throw new \AMQPConnectionException('Connection failed');
            });
        } catch (RetryExhaustedException) {
        }

        $this->assertTrue($retry->isCircuitOpen());

        $clock->advance(61);

        $this->flipCircuitToHalfOpen($retry);
        $this->assertSame(CircuitState::HALF_OPEN, $retry->getState());

        // Half-open probe hits a transient failure: 1 attempt, 1 failed retry.
        try {
            $retry->withRetry(function (): void {
                throw new \AMQPConnectionException('Connection failed again');
            });
        } catch (\AMQPConnectionException) {
        }

        $metrics = $retry->getMetrics();

        $this->assertSame(CircuitState::OPEN, $retry->getState(), 'Transient probe failure must re-open the circuit');
        $this->assertSame(3, $metrics->getTotalAttempts(), '2 attempts on exhaustion + 1 half-open probe attempt');
        $this->assertSame(0, $metrics->getSuccessfulRetries());
        $this->assertSame(2, $metrics->getFailedRetries(), 'One failure from exhaustion + one from half-open transient failure');
    }

    /**
     * Regression test for issue #355: a permanent failure during a half-open
     * probe must NOT re-open the circuit. The closed path never counts
     * permanent failures toward the breaker (a missing queue is an
     * application error, not broker unhealthiness); the half-open probe now
     * matches: the exception propagates and metrics record a failed retry,
     * but the circuit state is left unchanged (HALF_OPEN).
     */
    public function testHalfOpenPermanentFailureLeavesCircuitInHalfOpen(): void
    {
        $clock = new FrozenClock();

        $retry = new ConnectionRetry(
            maxAttempts: 2,
            retryDelay: 1000,
            retryCircuitBreaker: true,
            retryCircuitBreakerThreshold: 1,
            retryCircuitBreakerTimeout: 60,
            retryCircuitBreakerSuccessThreshold: 2,
            clock: $clock,
        );

        // Transient failure exhausts retries and opens the circuit.
        try {
            $retry->withRetry(function (): void {
                throw new \AMQPConnectionException('Connection failed');
            });
        } catch (RetryExhaustedException) {
        }

        $this->assertTrue($retry->isCircuitOpen());

        $clock->advance(61);

        $this->flipCircuitToHalfOpen($retry);
        $this->assertSame(CircuitState::HALF_OPEN, $retry->getState());

        // Half-open probe hits a permanent failure.
        try {
            $retry->withRetry(function (): void {
                throw new \AMQPQueueException('Queue not found', 404);
            });
        } catch (\AMQPQueueException) {
        }

        // State unchanged: still HALF_OPEN, not bounced back to OPEN.
        $this->assertSame(CircuitState::HALF_OPEN, $retry->getState(), 'Permanent probe failure must leave the circuit in HALF_OPEN');

        // Issue #339 semantics hold on the half-open path too: the permanent
        // failure counted as one attempt + one failed retry.
        $metrics = $retry->getMetrics();
        $this->assertSame(3, $metrics->getTotalAttempts(), '2 attempts on exhaustion + 1 half-open probe attempt');
        $this->assertSame(2, $metrics->getFailedRetries(), 'One failure from exhaustion + one from the permanent probe failure');

        // After the probe cool-down (#357) the next operation probes again;
        // success closes it after threshold.
        $clock->advance(61);
        $payload = new \stdClass();
        $this->assertSame($payload, $retry->withRetry(fn(): \stdClass => $payload));
        $this->assertSame(CircuitState::HALF_OPEN, $retry->getState(), 'One success below successThreshold keeps HALF_OPEN');

        $this->assertSame($payload, $retry->withRetry(fn(): \stdClass => $payload));
        $this->assertSame(CircuitState::CLOSED, $retry->getState(), 'Reaching successThreshold closes the circuit');
    }

    /**
     * Issue #354: a first-try success must count as a successful operation,
     * so a healthy workload (everything succeeding on the first try) reports
     * 100% operation success rate instead of the misleading 0%.
     */
    public function testMetricsOperationCounterFirstTrySuccess(): void
    {
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        $retry->withRetry(fn(): string => 'success');

        $metrics = $retry->getMetrics();

        $this->assertSame(1, $metrics->getSuccessfulOperations());
        $this->assertSame(0, $metrics->getFailedOperations());
        $this->assertSame(100.0, $metrics->getOperationSuccessRate());
    }

    /**
     * Issue #354: a permanent failure must count as a failed operation.
     */
    public function testMetricsOperationCounterPermanentFailure(): void
    {
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        $attempt = 0;
        $caught = null;
        try {
            $retry->withRetry(function () use (&$attempt): void {
                $attempt++;
                throw new \AMQPQueueException('Queue not found', 404);
            });
        } catch (\AMQPQueueException $e) {
            $caught = $e;
            $this->assertSame(1, $attempt, 'Permanent failure should not trigger retry');
        }

        $this->assertCaught($caught, \AMQPQueueException::class, 'Expected AMQPQueueException to be thrown');

        $metrics = $retry->getMetrics();

        $this->assertSame(0, $metrics->getSuccessfulOperations());
        $this->assertSame(1, $metrics->getFailedOperations());
        $this->assertSame(0.0, $metrics->getOperationSuccessRate());
    }

    /**
     * Issue #354: a retry that eventually succeeds is exactly one successful
     * operation (not two), so the operation rate is 100%.
     */
    public function testMetricsOperationCounterRetryThenSuccess(): void
    {
        $attempt = 0;
        $retry = new ConnectionRetry(maxAttempts: 3, retryDelay: 1000);

        $retry->withRetry(function () use (&$attempt): string {
            $attempt++;
            if ($attempt < 2) {
                throw new \AMQPConnectionException('Connection failed');
            }
            return 'success';
        });

        $metrics = $retry->getMetrics();

        $this->assertSame(1, $metrics->getSuccessfulOperations());
        $this->assertSame(0, $metrics->getFailedOperations());
        $this->assertSame(100.0, $metrics->getOperationSuccessRate());
        $this->assertSame(1, $metrics->getSuccessfulRetries(), 'retry-attempt counters unchanged');
        $this->assertSame(0, $metrics->getFailedRetries(), 'retry-attempt counters unchanged');
    }

    /**
     * Issue #354: exhausted retries count as exactly one failed operation.
     */
    public function testMetricsOperationCounterExhaustedRetries(): void
    {
        $retry = new ConnectionRetry(maxAttempts: 2, retryDelay: 1000);

        $caught = null;
        try {
            $retry->withRetry(function (): void {
                throw new \AMQPConnectionException('Connection failed');
            });
        } catch (RetryExhaustedException $e) {
            $caught = $e;
        }

        $this->assertCaught($caught, RetryExhaustedException::class, 'Expected RetryExhaustedException to be thrown');

        $metrics = $retry->getMetrics();

        $this->assertSame(0, $metrics->getSuccessfulOperations());
        $this->assertSame(1, $metrics->getFailedOperations());
        $this->assertSame(0.0, $metrics->getOperationSuccessRate());
    }

    /**
     * Drives the breaker OPEN→HALF_OPEN through the execution path.
     *
     * `isCircuitOpen()` is a pure read since #252 and can no longer be used to
     * flip the state: the transition happens when `withRetry()` calls
     * `CircuitBreaker::acquire()`. Tests that want to set up HALF_OPEN without
     * running a probe therefore reach the internal breaker directly.
     */
    private function flipCircuitToHalfOpen(ConnectionRetry $retry): void
    {
        $breaker = (new \ReflectionProperty(ConnectionRetry::class, 'circuitBreaker'))->getValue($retry);
        \assert($breaker instanceof CircuitBreaker);

        $this->assertTrue($breaker->acquire());
    }
}
