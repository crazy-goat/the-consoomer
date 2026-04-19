<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class Sender implements SenderInterface
{
    private ?\AMQPExchange $exchange = null;

    /**
     * @param array{
     *     exchange?: string,
     *     routing_key?: string,
     *     auto_setup?: bool,
     *     retry?: bool,
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
    }

    private function connect(): void
    {
        if ($this->exchange instanceof \AMQPExchange) {
            return;
        }

        $this->exchange = $this->factory->createExchange($this->connection->getChannel());
        $this->exchange->setName($this->options['exchange'] ?? '');
    }

    private function ensureConnected(): void
    {
        if ($this->connection->checkHeartbeat()) {
            $this->connection->reconnect();
            $this->exchange = null;
            $this->connect();
        }
    }

    public function send(Envelope $envelope): Envelope
    {
        if ($this->options['auto_setup'] ?? true) {
            $this->setup->setup();
        }
        $this->ensureConnected();
        $this->connect();

        $stamp = $envelope->last(AmqpStamp::class);

        $data = $this->serializer->encode($envelope);

        $publishCallback = fn() => $this->exchange->publish(
            $data['body'],
            $stamp?->routingKey ?? $this->options['routing_key'] ?? '',
            null,
            $data['headers'] ?? [],
        );

        if ($this->retry instanceof \CrazyGoat\TheConsoomer\ConnectionRetryInterface) {
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
