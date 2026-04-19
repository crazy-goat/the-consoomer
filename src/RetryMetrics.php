<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

class RetryMetrics
{
    private int $totalAttempts = 0;
    private int $successfulRetries = 0;
    private int $failedRetries = 0;
    private int $circuitBreakerOpens = 0;

    public function recordAttempt(): void
    {
        $this->totalAttempts++;
    }

    public function recordSuccess(): void
    {
        $this->successfulRetries++;
    }

    public function recordFailure(): void
    {
        $this->failedRetries++;
    }

    public function recordCircuitBreakerOpen(): void
    {
        $this->circuitBreakerOpens++;
    }

    public function getTotalAttempts(): int
    {
        return $this->totalAttempts;
    }

    public function getSuccessfulRetries(): int
    {
        return $this->successfulRetries;
    }

    public function getFailedRetries(): int
    {
        return $this->failedRetries;
    }

    public function getCircuitBreakerOpens(): int
    {
        return $this->circuitBreakerOpens;
    }

    public function getRetrySuccessRate(): float
    {
        if ($this->totalAttempts === 0) {
            return 0.0;
        }

        return ($this->successfulRetries / $this->totalAttempts) * 100;
    }

    public function reset(): void
    {
        $this->totalAttempts = 0;
        $this->successfulRetries = 0;
        $this->failedRetries = 0;
        $this->circuitBreakerOpens = 0;
    }

    /**
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
