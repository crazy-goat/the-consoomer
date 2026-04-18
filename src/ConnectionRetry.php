<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use CrazyGoat\TheConsoomer\Clock\SystemClock;
use Psr\Log\LoggerInterface;

class ConnectionRetry implements ConnectionRetryInterface
{
    /** Jitter variation factor - 25% of delay is used as max variation range */
    public const JITTER_VARIATION_FACTOR = 0.25;
    private ?CircuitBreaker $circuitBreaker = null;
    private readonly RetryMetrics $metrics;

    public function __construct(
        private readonly int $retryCount = 3,
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

    public function withRetry(callable $operation): mixed
    {
        if ($this->retryCircuitBreaker && $this->circuitBreaker instanceof \CrazyGoat\TheConsoomer\CircuitBreaker && !$this->circuitBreaker->isAvailable()) {
            $this->logger?->error('Circuit breaker is open, rejecting operation');
            $this->metrics->recordCircuitBreakerOpen();
            throw new \RuntimeException('Circuit breaker is open');
        }

        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->retryCount) {
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
                $lastException = $exception;
                $attempt++;

                if ($attempt >= $this->retryCount) {
                    break;
                }

                $delay = $this->calculateDelay($attempt);
                $this->logger?->error('Retry attempt failed', [
                    'attempt' => $attempt,
                    'max_attempts' => $this->retryCount,
                    'delay' => $delay,
                    'error' => $exception->getMessage(),
                ]);

                usleep($delay);
            }
        }

        $this->metrics->recordFailure();

        if ($this->retryCircuitBreaker && $this->circuitBreaker instanceof \CrazyGoat\TheConsoomer\CircuitBreaker) {
            $this->circuitBreaker->recordFailure();
        }

        $this->logger?->error('Retry failed after max attempts', [
            'max_attempts' => $this->retryCount,
            'error' => $lastException?->getMessage(),
        ]);

        throw $lastException ?? new \RuntimeException('Operation failed with no retries configured');
    }

    public function isCircuitOpen(): bool
    {
        if (!$this->circuitBreaker instanceof \CrazyGoat\TheConsoomer\CircuitBreaker) {
            return false;
        }

        return !$this->circuitBreaker->isAvailable();
    }

    public function getState(): CircuitState
    {
        if (!$this->circuitBreaker instanceof \CrazyGoat\TheConsoomer\CircuitBreaker) {
            return CircuitState::CLOSED;
        }

        return $this->circuitBreaker->getState();
    }

    public function getMetrics(): RetryMetrics
    {
        return $this->metrics;
    }

    public function reset(): void
    {
        $this->circuitBreaker?->reset();
        $this->metrics->reset();
    }

    private function calculateDelay(int $attempt): int
    {
        $delay = $this->retryDelay;

        if ($this->retryBackoff) {
            $delay = $this->retryDelay * (2 ** ($attempt - 1));
        }

        $delay = min($delay, $this->retryMaxDelay);

        if ($this->retryJitter) {
            $variation = (int) ($delay * self::JITTER_VARIATION_FACTOR);
            $delay += random_int(-$variation, $variation);
        }

        return max(0, $delay);
    }
}
