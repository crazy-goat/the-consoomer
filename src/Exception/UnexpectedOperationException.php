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
     * The previous exception's `getCode()` is deliberately **not** propagated
     * (#251): the wrappers would otherwise carry whatever code the wrapped
     * failure happened to use (frequently `0`), making `getCode()` mean
     * different things depending on the wrapping path. The wrapper always
     * reports code `0`; reach the original code via `getPrevious()->getCode()`.
     *
     * @param \Throwable $exception Previous exception
     */
    public static function fromPrevious(\Throwable $exception): self
    {
        return new self($exception->getMessage(), 0, $exception);
    }
}
