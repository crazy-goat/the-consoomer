<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Exception;

/**
 * Exception thrown when all retry attempts have been exhausted.
 */
class RetryExhaustedException extends \RuntimeException
{
    /**
     * @param string          $message  Exception message
     * @param int             $code     Exception code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message = 'Operation failed with no retries configured',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Creates exception from a previous exception.
     *
     * The previous exception's `getCode()` is deliberately **not** propagated
     * (#251): when wrapping an {@see \AMQPException} the code is either `0` or a
     * librabbitmq errno, never an AMQP reply code, so copying it would attach a
     * meaningless value to the wrapper. The wrapper therefore always reports
     * code `0`; reach the original code via `getPrevious()->getCode()`.
     *
     * @param \Throwable $exception Previous exception
     */
    public static function fromPrevious(\Throwable $exception): self
    {
        return new self($exception->getMessage(), 0, $exception);
    }
}
