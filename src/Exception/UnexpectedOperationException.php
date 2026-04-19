<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Exception;

use Throwable;

class UnexpectedOperationException extends \RuntimeException
{
    public static function fromPrevious(Throwable $exception): self
    {
        return new self($exception->getMessage(), $exception->getCode(), $exception);
    }
}
