<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

/**
 * Interface for retry mechanism with circuit breaker support.
 */
interface ConnectionRetryInterface
{
    /**
     * Executes an operation with retry logic.
     *
     * @template T
     * @param callable(): T $operation Operation to execute
     * @return T
     * @throws \CrazyGoat\TheConsoomer\Exception\CircuitBreakerOpenException  When circuit breaker is open
     * @throws \CrazyGoat\TheConsoomer\Exception\RetryExhaustedException      When all retry attempts fail
     * @throws \CrazyGoat\TheConsoomer\Exception\UnexpectedOperationException When non-AMQP exception occurs
     */
    public function withRetry(callable $operation): mixed;

    /**
     * Checks if circuit breaker is open.
     */
    public function isCircuitOpen(): bool;

    /**
     * Returns current circuit state.
     */
    public function getState(): CircuitState;

    /**
     * Returns retry metrics.
     */
    public function getMetrics(): RetryMetrics;

    /**
     * Resets circuit breaker and metrics.
     */
    public function reset(): void;
}
