<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * Stamp that holds explicit message priority (0-9) for AMQP publish.
 *
 * Higher priority messages are processed before lower priority ones.
 * Requires the queue to be configured with x-max-priority argument.
 */
final readonly class AmqpPriorityStamp implements NonSendableStampInterface
{
    public function __construct(
        private int $priority,
    ) {
        if ($priority < 0 || $priority > 9) {
            throw new \InvalidArgumentException(sprintf('Priority must be between 0 and 9, got %d', $priority));
        }
    }

    public function getPriority(): int
    {
        return $this->priority;
    }
}
