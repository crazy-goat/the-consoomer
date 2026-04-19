<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Exception;

/**
 * Exception thrown when circuit breaker is open and rejecting operations.
 */
class CircuitBreakerOpenException extends \RuntimeException
{
}
