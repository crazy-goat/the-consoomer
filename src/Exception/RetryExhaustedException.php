<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Exception;

use Throwable;

class RetryExhaustedException extends \RuntimeException
{
    public function __construct(
        string $message = 'Operation failed with no retries configured',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function fromPrevious(Throwable $exception): self
    {
        return new self($exception->getMessage(), $exception->getCode(), $exception);
    }
}
