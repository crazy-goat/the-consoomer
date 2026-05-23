<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * AMQP message sender for Symfony Messenger.
 *
 * Handles publishing messages to AMQP exchange with support for
 * publisher confirms, retry logic and connection recovery.
 */
final class Sender implements SenderInterface
{
    private ?\AMQPExchange $exchange = null;
    private float $confirmTimeout = 0.0;

    /**
     * @param array{
     *     exchange?: string,
     *     default_publish_routing_key?: string,
     *     auto_setup?: bool,
     *     retry?: bool,
     *     confirm_timeout?: float|int,
     * } $options
     */
    public function __construct(
        private readonly AmqpFactoryInterface $factory,
        private readonly ConnectionInterface $connection,
        private readonly SerializerInterface $serializer,
        private readonly array $options,
        private readonly InfrastructureSetupInterface $setup,
        private readonly ?ConnectionRetryInterface $retry = null,
    ) {
        $confirmTimeout = $this->options['confirm_timeout'] ?? 0.0;
        if ($confirmTimeout < 0) {
            throw new \InvalidArgumentException('confirm_timeout must be a non-negative value');
        }
        $this->confirmTimeout = (float) $confirmTimeout;
    }

    /**
     * Initializes AMQP exchange connection.
     * Idempotent - safe to call multiple times.
     */
    private function connect(): void
    {
        if ($this->exchange instanceof \AMQPExchange) {
            return;
        }

        $this->exchange = $this->factory->createExchange($this->connection->getChannel());
        $this->exchange->setName($this->options['exchange'] ?? '');
    }

    /**
     * Checks connection heartbeat and reconnects if stale.
     */
    private function ensureConnected(): void
    {
        if ($this->connection->checkHeartbeat()) {
            $this->connection->reconnect();
            $this->exchange = null;
            $this->connect();
        }
    }

    /**
     * Resolves the routing key for a message.
     *
     * Priority: Stamp routing key > default_publish_routing_key option > Empty string
     */
    private function getRoutingKeyForMessage(?AmqpStamp $stamp): string
    {
        $routingKey = $stamp?->getRoutingKey();
        if ($routingKey !== null) {
            return $routingKey;
        }

        return $this->options['default_publish_routing_key'] ?? '';
    }

    /**
     * {@inheritdoc}
     *
     * @param Envelope $envelope The envelope to send
     * @return Envelope The sent envelope
     * @throws \AMQPException When connection or publish fails
     */
    public function send(Envelope $envelope): Envelope
    {
        if ($this->options['auto_setup'] ?? true) {
            $this->setup->setup();
        }
        $this->ensureConnected();
        $this->connect();

        $stamp = $envelope->last(AmqpStamp::class);

        $data = $this->serializer->encode($envelope);

        $routingKey = $this->getRoutingKeyForMessage($stamp);
        $flags = $stamp?->getFlags() ?? \AMQP_NOPARAM;
        $attributes = array_merge($data['headers'] ?? [], $stamp?->getAttributes() ?? []);

        $priorityStamp = $envelope->last(AmqpPriorityStamp::class);
        if ($priorityStamp instanceof AmqpPriorityStamp) {
            $attributes['priority'] = $priorityStamp->getPriority();
        }

        $publishCallback = function () use ($data, $routingKey, $flags, $attributes): void {
            if ($this->confirmTimeout > 0.0) {
                $channel = $this->connection->getChannel();
                $channel->confirmSelect();
            }

            $this->exchange->publish(
                $data['body'],
                $routingKey,
                $flags,
                $attributes,
            );

            if (isset($channel)) {
                $channel->waitForConfirm($this->confirmTimeout);
            }
        };

        if ($this->retry instanceof ConnectionRetryInterface) {
            $this->retry->withRetry(function () use ($publishCallback): void {
                $publishCallback();
                $this->connection->updateActivity();
            });
        } else {
            $publishCallback();
            $this->connection->updateActivity();
        }

        return $envelope;
    }
}
