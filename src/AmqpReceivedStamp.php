<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * Stamp that wraps a raw AMQP envelope for internal processing.
 *
 * Used by the receiver to track the original AMQP message
 * for acknowledgment and rejection operations.
 * Provides access to message metadata: timestamp, app_id, message_id, headers.
 *
 * The channel generation identifies which {@see Receiver} channel instance
 * delivered the message. Delivery tags are scoped per channel, so an ack/reject
 * is only valid against the channel of the same generation — after a reconnect
 * the broker re-queues unacked messages and issues fresh tags on the new
 * channel, so any ack/reject carrying a stale generation is a no-op (#220).
 */
final readonly class AmqpReceivedStamp implements NonSendableStampInterface
{
    public function __construct(
        private \AMQPEnvelope $envelope,
        private string $queueName,
        private int $channelGeneration = 0,
    ) {
    }

    public function getAmqpEnvelope(): \AMQPEnvelope
    {
        return $this->envelope;
    }

    public function getQueueName(): string
    {
        return $this->queueName;
    }

    /**
     * Returns the channel generation that delivered this message.
     *
     * Delivery tags are only valid on the channel of the same generation; a
     * mismatch against {@see Receiver}'s current generation means the tag
     * belongs to a dead channel and the ack/reject must be a no-op.
     */
    public function getChannelGeneration(): int
    {
        return $this->channelGeneration;
    }

    public function getMessageId(): ?string
    {
        return $this->envelope->getMessageId();
    }

    public function getTimestamp(): ?int
    {
        return $this->envelope->getTimestamp();
    }

    public function getAppId(): ?string
    {
        return $this->envelope->getAppId();
    }

    /**
     * @return array<string, mixed>
     */
    public function getHeaders(): array
    {
        return $this->envelope->getHeaders();
    }

    public function getCorrelationId(): ?string
    {
        return $this->envelope->getCorrelationId();
    }

    public function getReplyTo(): ?string
    {
        return $this->envelope->getReplyTo();
    }

    public function getContentType(): ?string
    {
        return $this->envelope->getContentType();
    }

    public function getDeliveryMode(): int
    {
        return $this->envelope->getDeliveryMode();
    }

    public function getPriority(): int
    {
        return $this->envelope->getPriority();
    }
}
