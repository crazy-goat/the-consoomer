<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * AMQP stamp for attaching routing information and message attributes.
 *
 * Provides fine-grained control over AMQP message behavior including
 * routing key, flags, and all AMQP message attributes.
 */
final readonly class AmqpStamp implements NonSendableStampInterface
{
    /**
     * @param array{
     *     content_type?: string,
     *     content_encoding?: string,
     *     message_id?: string,
     *     delivery_mode?: int,
     *     priority?: int,
     *     timestamp?: int,
     *     app_id?: string,
     *     user_id?: string,
     *     expiration?: string,
     *     type?: string,
     *     reply_to?: string,
     *     correlation_id?: string,
     *     headers?: array<string, mixed>,
     * } $attributes
     */
    public function __construct(
        private ?string $routingKey = null,
        private int $flags = \AMQP_NOPARAM,
        private array $attributes = [],
    ) {
    }

    public function getRoutingKey(): ?string
    {
        return $this->routingKey;
    }

    public function getFlags(): int
    {
        return $this->flags;
    }

    /**
     * @return array{
     *     content_type?: string,
     *     content_encoding?: string,
     *     message_id?: string,
     *     delivery_mode?: int,
     *     priority?: int,
     *     timestamp?: int,
     *     app_id?: string,
     *     user_id?: string,
     *     expiration?: string,
     *     type?: string,
     *     reply_to?: string,
     *     correlation_id?: string,
     *     headers?: array<string, mixed>,
     * }
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function withRoutingKey(?string $routingKey): self
    {
        return new self($routingKey, $this->flags, $this->attributes);
    }

    public function withFlags(int $flags): self
    {
        return new self($this->routingKey, $flags, $this->attributes);
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $attributes = $this->attributes;
        $attributes[$key] = $value;

        return new self($this->routingKey, $this->flags, $attributes);
    }

    public static function createFromAmqpEnvelope(\AMQPEnvelope $envelope): self
    {
        $attributes = [];
        $attributeMap = [
            'content_type' => $envelope->getContentType(),
            'content_encoding' => $envelope->getContentEncoding(),
            'message_id' => $envelope->getMessageId(),
            'app_id' => $envelope->getAppId(),
            'user_id' => $envelope->getUserId(),
            'expiration' => $envelope->getExpiration(),
            'type' => $envelope->getType(),
            'reply_to' => $envelope->getReplyTo(),
            'correlation_id' => $envelope->getCorrelationId(),
            'headers' => $envelope->getHeaders(),
        ];

        foreach ($attributeMap as $key => $value) {
            if (null === $value || [] === $value || '' === $value || false === $value) {
                continue;
            }

            $attributes[$key] = $value;
        }

        if ($envelope->getDeliveryMode() > 0) {
            $attributes['delivery_mode'] = $envelope->getDeliveryMode();
        }
        if ($envelope->getPriority() > 0) {
            $attributes['priority'] = $envelope->getPriority();
        }
        if ($envelope->getTimestamp() > 0) {
            $attributes['timestamp'] = $envelope->getTimestamp();
        }

        return new self($envelope->getRoutingKey(), \AMQP_NOPARAM, $attributes);
    }

    /**
     * Creates a new stamp with the given attributes, replacing any existing ones.
     *
     * Routing key and flags from the original stamp are preserved.
     *
     * @param array{
     *     content_type?: string,
     *     content_encoding?: string,
     *     message_id?: string,
     *     delivery_mode?: int,
     *     priority?: int,
     *     timestamp?: int,
     *     app_id?: string,
     *     user_id?: string,
     *     expiration?: string,
     *     type?: string,
     *     reply_to?: string,
     *     correlation_id?: string,
     *     headers?: array<string, mixed>,
     * } $attributes
     */
    public static function createWithAttributes(array $attributes, ?self $stamp = null): self
    {
        return new self(
            $stamp?->getRoutingKey(),
            $stamp?->getFlags() ?? \AMQP_NOPARAM,
            $attributes,
        );
    }
}
