<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

interface ConnectionRetryInterface
{
    public function withRetry(callable $operation): mixed;

    public function isCircuitOpen(): bool;

    public function getState(): CircuitState;

    public function getMetrics(): RetryMetrics;

    public function reset(): void;
}
