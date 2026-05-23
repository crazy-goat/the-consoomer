<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

final readonly class AmqpDelayStamp implements NonSendableStampInterface
{
    /**
     * @param int $delayMs Delay in milliseconds, must be positive
     */
    public function __construct(
        private int $delayMs,
    ) {
        if ($delayMs <= 0) {
            throw new \InvalidArgumentException(sprintf('Delay must be a positive integer (ms), got %d', $delayMs));
        }
    }

    public function getDelay(): int
    {
        return $this->delayMs;
    }
}
