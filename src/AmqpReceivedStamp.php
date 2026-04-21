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
        public \AMQPEnvelope $amqpMessage,
        public string $queueName,
    ) {
    }

    public function getAmqpEnvelope(): \AMQPEnvelope
    {
        return $this->amqpMessage;
    }

    public function getQueueName(): string
    {
        return $this->queueName;
    }

    public function getMessageId(): ?string
    {
        return $this->amqpMessage->getMessageId();
    }

    public function getTimestamp(): ?int
    {
        return $this->amqpMessage->getTimestamp();
    }

    public function getAppId(): ?string
    {
        return $this->amqpMessage->getAppId();
    }

    public function getHeaders(): array
    {
        return $this->amqpMessage->getHeaders();
    }

    public function getCorrelationId(): ?string
    {
        return $this->amqpMessage->getCorrelationId();
    }

    public function getReplyTo(): ?string
    {
        return $this->amqpMessage->getReplyTo();
    }

    public function getContentType(): ?string
    {
        return $this->amqpMessage->getContentType();
    }

    public function getDeliveryMode(): int
    {
        return $this->amqpMessage->getDeliveryMode();
    }

    public function getPriority(): int
    {
        return $this->amqpMessage->getPriority();
    }
}
