<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

/**
 * Tracks retry metrics and statistics.
 *
 * Records attempts, successes, failures, and circuit breaker events
 * for monitoring and debugging retry behavior.
 */
final class RetryMetrics
{
    private int $totalAttempts = 0;
    private int $successfulRetries = 0;
    private int $failedRetries = 0;
    private int $circuitBreakerOpens = 0;

    /**
     * Records a retry attempt.
     */
    public function recordAttempt(): void
    {
        $this->totalAttempts++;
    }

    /**
     * Records a successful retry.
     */
    public function recordSuccess(): void
    {
        $this->successfulRetries++;
    }

    /**
     * Records a failed retry.
     */
    public function recordFailure(): void
    {
        $this->failedRetries++;
    }

    /**
     * Records a circuit breaker open event.
     */
    public function recordCircuitBreakerOpen(): void
    {
        $this->circuitBreakerOpens++;
    }

    /**
     * Returns total number of attempts.
     */
    public function getTotalAttempts(): int
    {
        return $this->totalAttempts;
    }

    /**
     * Returns number of successful retries.
     */
    public function getSuccessfulRetries(): int
    {
        return $this->successfulRetries;
    }

    /**
     * Returns number of failed retries.
     */
    public function getFailedRetries(): int
    {
        return $this->failedRetries;
    }

    /**
     * Returns number of circuit breaker open events.
     */
    public function getCircuitBreakerOpens(): int
    {
        return $this->circuitBreakerOpens;
    }

    /**
     * Returns retry success rate as percentage.
     *
     * @return float Success rate (0-100)
     */
    public function getRetrySuccessRate(): float
    {
        if ($this->totalAttempts === 0) {
            return 0.0;
        }

        return ($this->successfulRetries / $this->totalAttempts) * 100;
    }

    /**
     * Resets all metrics to zero.
     */
    public function reset(): void
    {
        $this->totalAttempts = 0;
        $this->successfulRetries = 0;
        $this->failedRetries = 0;
        $this->circuitBreakerOpens = 0;
    }

    /**
     * Returns all metrics as an associative array.
     *
     * @return array{
     *     total_attempts: int,
     *     successful_retries: int,
     *     failed_retries: int,
     *     circuit_breaker_opens: int,
     *     retry_success_rate: float,
     * }
     */
    public function toArray(): array
    {
        return [
            'total_attempts' => $this->totalAttempts,
            'successful_retries' => $this->successfulRetries,
            'failed_retries' => $this->failedRetries,
            'circuit_breaker_opens' => $this->circuitBreakerOpens,
            'retry_success_rate' => $this->getRetrySuccessRate(),
        ];
    }
}
