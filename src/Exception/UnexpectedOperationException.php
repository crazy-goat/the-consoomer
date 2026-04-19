<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Exception;

/**
 * Exception thrown when an unexpected non-AMQP exception occurs during retry.
 */
class UnexpectedOperationException extends \RuntimeException
{
    /**
     * Creates exception from a previous exception.
     *
     * @param \Throwable $exception Previous exception
     */
    public static function fromPrevious(\Throwable $exception): self
    {
        return new self($exception->getMessage(), $exception->getCode(), $exception);
    }
}
