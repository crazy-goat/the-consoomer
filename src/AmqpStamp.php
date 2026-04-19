<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * AMQP stamp for attaching routing information to messages.
 *
 * Used to specify custom routing keys when publishing messages.
 */
final readonly class AmqpStamp implements NonSendableStampInterface
{
    /**
     * @param string $routingKey Routing key for message
     */
    public function __construct(
        public string $routingKey = '',
    ) {
    }
}
