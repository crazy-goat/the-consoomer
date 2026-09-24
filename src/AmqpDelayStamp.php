<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * Delays a message by publishing it to a per-delay queue whose TTL expires it
 * back onto the main exchange.
 *
 * The delay is expressed in **milliseconds** (applied as `x-message-ttl`),
 * unlike the connection/retry options which use seconds.
 */
final readonly class AmqpDelayStamp implements NonSendableStampInterface
{
    /**
     * @param int $delayMs Delay in milliseconds, must be positive
     * @throws \InvalidArgumentException When the delay is not positive
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
