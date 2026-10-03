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
     * @param array<string, mixed> $attributes Any attribute bag. The well-known
     *        AMQP attribute names are `content_type` (string), `content_encoding`
     *        (string), `message_id` (string), `delivery_mode` (int), `priority`
     *        (int), `timestamp` (int), `app_id` (string), `user_id` (string),
     *        `expiration` (string), `type` (string), `reply_to` (string),
     *        `correlation_id` (string) and `headers` (array<string, mixed>), but
     *        the bag is deliberately open: {@see withAttribute()} takes any key
     *        and any value, so the shape is not restricted to that list.
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
     * @return array<string, mixed> The attribute bag, with the same open key set
     *         as the {@see __construct()} parameter.
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

    /**
     * Creates a new stamp with the given attributes, replacing any existing ones.
     *
     * Routing key and flags from the original stamp are preserved.
     *
     * @param array<string, mixed> $attributes Any attribute bag; the same open
     *        key set as {@see __construct()}.
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
