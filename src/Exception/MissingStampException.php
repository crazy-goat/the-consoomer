<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Exception;

class MissingStampException extends \RuntimeException
{
    public function __construct(
        string $message = 'Missing required stamp',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
