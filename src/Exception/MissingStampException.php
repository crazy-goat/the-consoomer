<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Exception;

/**
 * Exception thrown when a required stamp is missing from an envelope.
 */
class MissingStampException extends \RuntimeException
{
    /**
     * @param string          $message  Exception message
     * @param int             $code     Exception code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message = 'Missing required stamp',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
