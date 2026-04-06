<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class Sender implements SenderInterface
{
    private ?\AMQPChannel $channel = null;
    private ?\AMQPExchange $exchange = null;

    public function __construct(
        private readonly AmqpFactoryInterface $factory,
        private readonly \AMQPConnection $connection,
        private readonly SerializerInterface $serializer,
        private readonly array $options,
        private readonly InfrastructureSetup $setup,
    ) {
    }

    private function connect(): void
    {
        if ($this->exchange instanceof \AMQPExchange) {
            return;
        }

        $this->channel = $this->factory->createChannel($this->connection);
        $this->exchange = $this->factory->createExchange($this->channel);
        $this->exchange->setName($this->options['exchange'] ?? '');
    }

    public function send(Envelope $envelope): Envelope
    {
        $this->setup->setup();
        $this->connect();

        $stamp = $envelope->last(AmqpStamp::class);

        $data = $this->serializer->encode($envelope);

        $this->exchange->publish(
            $data['body'],
            $this->options['routing_key'] ?? $stamp?->routingKey ?? '',
            null,
            $data['headers'] ?? [],
        );

        return $envelope;
    }
}
