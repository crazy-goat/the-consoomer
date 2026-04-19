<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Exception;

use Throwable;

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
     * @param \Throwable $exception Previous exception
     * @return self
     */
    public static function fromPrevious(\Throwable $exception): self
    {
        return new self($exception->getMessage(), $exception->getCode(), $exception);
    }
}
