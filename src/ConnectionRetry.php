<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use CrazyGoat\TheConsoomer\Clock\SystemClock;
use CrazyGoat\TheConsoomer\Exception\CircuitBreakerOpenException;
use CrazyGoat\TheConsoomer\Exception\RetryExhaustedException;
use CrazyGoat\TheConsoomer\Exception\UnexpectedOperationException;
use Psr\Log\LoggerInterface;

/**
 * Retry mechanism with exponential backoff, jitter, and circuit breaker support.
 *
 * Provides resilient retry logic for AMQP operations with configurable:
 * - Retry count and delay
 * - Exponential backoff
 * - Random jitter to prevent thundering herd
 * - Circuit breaker pattern for failure isolation
 */
final class ConnectionRetry implements ConnectionRetryInterface
{
    /** Jitter variation factor - 25% of delay is used as max variation range */
    public const JITTER_VARIATION_FACTOR = 0.25;

    /** AMQP error codes that indicate permanent failures - should not retry */
    private const PERMANENT_FAILURE_CODES = [403, 404, 406];

    /**
     * Determines if an AMQP exception represents a permanent (non-retryable) failure.
     *
     * Connection-level exceptions (AMQPConnectionException, AMQPChannelException)
     * are considered transient — a reconnect may resolve them.
     * Resource-level exceptions (AMQPQueueException, AMQPExchangeException)
     * are considered permanent — the referenced queue/exchange will not appear
     * on retry.
     *
     * For generic AMQPException the getCode() is used as a fallback, but note
     * that ext-amqp does NOT reliably surface AMQP reply codes there —
     * getCode() is frequently 0 or a librabbitmq errno.
     */
    private function isPermanentFailure(\AMQPException $exception): bool
    {
        if ($exception instanceof \AMQPQueueException || $exception instanceof \AMQPExchangeException) {
            return true;
        }

        if ($exception instanceof \AMQPConnectionException || $exception instanceof \AMQPChannelException) {
            return false;
        }

        return in_array($exception->getCode(), self::PERMANENT_FAILURE_CODES, true);
    }

    private ?CircuitBreaker $circuitBreaker = null;
    private readonly RetryMetrics $metrics;

    /**
     * @param int                    $maxAttempts                       Maximum number of execution attempts (including the first, minimum: 1)
     * @param int                    $retryDelay                        Base delay between retries in microseconds
     * @param bool                   $retryBackoff                      Enable exponential backoff
     * @param int                    $retryMaxDelay                     Maximum delay cap in microseconds
     * @param bool                   $retryJitter                       Enable random jitter
     * @param bool                   $retryCircuitBreaker               Enable circuit breaker
     * @param int                    $retryCircuitBreakerThreshold      Failures before circuit opens
     * @param int                    $retryCircuitBreakerTimeout        Seconds circuit stays open
     * @param int                    $retryCircuitBreakerSuccessThreshold Successes to close circuit
     * @param LoggerInterface|null   $logger                            Logger instance
     * @param ClockInterface|null    $clock                             Clock for time tracking
     */
    public function __construct(
        private readonly int $maxAttempts = 3,
        private readonly int $retryDelay = 100000,
        private readonly bool $retryBackoff = false,
        private readonly int $retryMaxDelay = 30000000,
        private readonly bool $retryJitter = true,
        private readonly bool $retryCircuitBreaker = false,
        private readonly int $retryCircuitBreakerThreshold = 10,
        private readonly int $retryCircuitBreakerTimeout = 60,
        private readonly int $retryCircuitBreakerSuccessThreshold = 2,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?ClockInterface $clock = new SystemClock(),
    ) {
        if ($this->maxAttempts < 1) {
            throw new \InvalidArgumentException(sprintf(
                'maxAttempts must be at least 1, %d given',
                $this->maxAttempts,
            ));
        }

        $this->metrics = new RetryMetrics();

        if ($this->retryCircuitBreaker) {
            $this->circuitBreaker = new CircuitBreaker(
                $this->retryCircuitBreakerThreshold,
                $this->retryCircuitBreakerTimeout,
                $this->retryCircuitBreakerSuccessThreshold,
                $this->logger,
                $this->clock,
            );
        }
    }

    /**
     * {@inheritdoc}
     *
     * @template T
     * @param callable(): T $operation Operation to execute with retry
     * @return T
     * @throws CircuitBreakerOpenException    When circuit breaker is open
     * @throws RetryExhaustedException        When all retry attempts fail
     * @throws UnexpectedOperationException   When non-AMQP exception occurs
     * @throws \AMQPException                 When permanent AMQP failure occurs
     */
    public function withRetry(callable $operation): mixed
    {
        if ($this->retryCircuitBreaker && $this->circuitBreaker instanceof \CrazyGoat\TheConsoomer\CircuitBreaker) {
            if (!$this->circuitBreaker->isAvailable()) {
                $this->logger?->error('Circuit breaker is open, rejecting operation');
                $this->metrics->recordCircuitBreakerOpen();
                throw new CircuitBreakerOpenException('Circuit breaker is open');
            }

            if ($this->circuitBreaker->getState() === CircuitState::HALF_OPEN) {
                return $this->executeHalfOpenProbe($operation);
            }
        }

        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxAttempts) {
            try {
                $result = $operation();

                if ($this->retryCircuitBreaker && $this->circuitBreaker instanceof \CrazyGoat\TheConsoomer\CircuitBreaker) {
                    $this->circuitBreaker->recordSuccess();
                }

                if ($attempt > 0) {
                    $this->metrics->recordSuccess();
                }
                $this->metrics->recordAttempt();

                return $result;
            } catch (\AMQPException $exception) {
                if ($this->isPermanentFailure($exception)) {
                    $this->logger?->warning('Permanent AMQP failure, not retrying', [
                        'code' => $exception->getCode(),
                        'type' => $exception::class,
                        'error' => $exception->getMessage(),
                    ]);
                    throw $exception;
                }

                $lastException = $exception;
                $attempt++;

                if ($attempt >= $this->maxAttempts) {
                    break;
                }

                $delay = $this->calculateDelay($attempt);
                $this->logger?->error('Retry attempt failed', [
                    'attempt' => $attempt,
                    'max_attempts' => $this->maxAttempts,
                    'delay' => $delay,
                    'error' => $exception->getMessage(),
                ]);

                usleep($delay);
            } catch (\Throwable $exception) {
                $this->logger?->warning('Non-AMQP exception during retry', [
                    'error' => $exception->getMessage(),
                ]);
                throw UnexpectedOperationException::fromPrevious($exception);
            }
        }

        $this->metrics->recordFailure();

        if ($this->retryCircuitBreaker && $this->circuitBreaker instanceof \CrazyGoat\TheConsoomer\CircuitBreaker) {
            $this->circuitBreaker->recordFailure();
        }

        $this->logger?->error('Retry failed after max attempts', [
            'max_attempts' => $this->maxAttempts,
            'error' => $lastException?->getMessage(),
        ]);

        throw $lastException instanceof \AMQPException
            ? RetryExhaustedException::fromPrevious($lastException)
            : new RetryExhaustedException();
    }

    /**
     * {@inheritdoc}
     */
    public function isCircuitOpen(): bool
    {
        if (!$this->circuitBreaker instanceof \CrazyGoat\TheConsoomer\CircuitBreaker) {
            return false;
        }

        return !$this->circuitBreaker->isAvailable();
    }

    /**
     * {@inheritdoc}
     */
    public function getState(): CircuitState
    {
        if (!$this->circuitBreaker instanceof \CrazyGoat\TheConsoomer\CircuitBreaker) {
            return CircuitState::CLOSED;
        }

        return $this->circuitBreaker->getState();
    }

    /**
     * {@inheritdoc}
     */
    public function getMetrics(): RetryMetrics
    {
        return $this->metrics;
    }

    /**
     * {@inheritdoc}
     */
    public function reset(): void
    {
        $this->circuitBreaker?->reset();
        $this->metrics->reset();
    }

    /**
     * Executes operation exactly once as a half-open probe.
     *
     * In half-open state, no retry loop is used — exactly one attempt is made
     * and the result is recorded directly to the circuit breaker.
     *
     * @template T
     * @param callable(): T $operation Operation to execute
     * @return T
     * @throws \AMQPException When AMQP operation fails (breaker is re-opened)
     * @throws UnexpectedOperationException When non-AMQP exception occurs
     */
    private function executeHalfOpenProbe(callable $operation): mixed
    {
        try {
            $result = $operation();

            $this->circuitBreaker?->recordSuccess();
            $this->metrics->recordAttempt();

            return $result;
        } catch (\AMQPException $exception) {
            $this->circuitBreaker?->recordFailure();
            $this->metrics->recordFailure();

            $this->logger?->warning('Half-open probe failed, circuit re-opening', [
                'code' => $exception->getCode(),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        } catch (\Throwable $exception) {
            $this->logger?->warning('Non-AMQP exception during half-open probe', [
                'error' => $exception->getMessage(),
            ]);

            throw UnexpectedOperationException::fromPrevious($exception);
        }
    }

    /**
     * Calculates delay between retry attempts with optional backoff and jitter.
     *
     * @param int $attempt Current attempt number (1-based)
     * @return int Delay in microseconds
     */
    private function calculateDelay(int $attempt): int
    {
        $delay = $this->retryDelay;

        if ($this->retryBackoff) {
            $delay = $this->retryDelay * (2 ** ($attempt - 1));
        }

        if ($this->retryJitter) {
            $variation = (int) ($delay * self::JITTER_VARIATION_FACTOR);
            $delay += random_int(-$variation, $variation);
        }

        return max(0, min($delay, $this->retryMaxDelay));
    }
}
