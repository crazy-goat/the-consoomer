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
    private int $successfulOperations = 0;
    private int $failedOperations = 0;

    /**
     * Records a single retry attempt (one invocation of the operation,
     * regardless of whether it succeeds or fails).
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
     * Records a successful operation (whole withRetry() outcome).
     *
     * Unlike recordSuccess()/recordFailure(), which count retry attempts,
     * this counts whole operation outcomes, so first-attempt successes are
     * included.
     */
    public function recordSuccessfulOperation(): void
    {
        $this->successfulOperations++;
    }

    /**
     * Records a failed operation (whole withRetry() outcome).
     *
     * Unlike recordSuccess()/recordFailure(), which count retry attempts,
     * this counts whole operation outcomes.
     */
    public function recordFailedOperation(): void
    {
        $this->failedOperations++;
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
     * Returns number of successful operations (whole withRetry() outcomes).
     */
    public function getSuccessfulOperations(): int
    {
        return $this->successfulOperations;
    }

    /**
     * Returns number of failed operations (whole withRetry() outcomes).
     */
    public function getFailedOperations(): int
    {
        return $this->failedOperations;
    }

    /**
     * Returns operation success rate as percentage of completed operations
     * (whole withRetry() outcomes, not attempts), so first-attempt successes
     * are included. Returns 0.0 when no operations have completed.
     *
     * @return float Success rate (0-100)
     */
    public function getOperationSuccessRate(): float
    {
        $completed = $this->successfulOperations + $this->failedOperations;

        if ($completed === 0) {
            return 0.0;
        }

        return ($this->successfulOperations / $completed) * 100;
    }

    /**
     * Returns retry success rate as percentage of total attempts.
     *
     * The denominator is the total number of operation attempts (both
     * successful and failed), so the rate reflects the true proportion of
     * attempts that were successful retries. Returns 0.0 when no attempts
     * have been recorded.
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
        $this->successfulOperations = 0;
        $this->failedOperations = 0;
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
     *     successful_operations: int,
     *     failed_operations: int,
     *     operation_success_rate: float,
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
            'successful_operations' => $this->successfulOperations,
            'failed_operations' => $this->failedOperations,
            'operation_success_rate' => $this->getOperationSuccessRate(),
        ];
    }
}
