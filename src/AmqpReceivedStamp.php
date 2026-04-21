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
 */
final readonly class AmqpReceivedStamp implements NonSendableStampInterface
{
    public function __construct(
        private \AMQPEnvelope $envelope,
        private string $queueName,
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
