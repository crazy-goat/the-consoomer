<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * Stamp that holds explicit message priority (0-255) for AMQP publish.
 *
 * Higher priority messages are processed before lower priority ones.
 * Requires the queue to be configured with x-max-priority argument.
 *
 * RabbitMQ supports priority values 0-255 via the x-max-priority queue argument.
 */
final readonly class AmqpPriorityStamp implements NonSendableStampInterface
{
    public function __construct(
        private int $priority,
    ) {
        if ($priority < 0 || $priority > 255) {
            throw new \InvalidArgumentException(sprintf('Priority must be between 0 and 255, got %d', $priority));
        }
    }

    public function getPriority(): int
    {
        return $this->priority;
    }
}
