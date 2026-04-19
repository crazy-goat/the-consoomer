<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * Stamp that wraps a raw AMQP envelope for internal processing.
 *
 * Used by the receiver to track the original AMQP message
 * for acknowledgment and rejection operations.
 */
final readonly class RawMessageStamp implements NonSendableStampInterface
{
    /**
     * @param \AMQPEnvelope $amqpMessage Raw AMQP envelope
     */
    public function __construct(public \AMQPEnvelope $amqpMessage)
    {
    }
}
