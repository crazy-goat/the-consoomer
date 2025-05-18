<?php

namespace CrazyGoat\TheConsoomer\Library\AmqpExtension;

use CrazyGoat\TheConsoomer\AmqpStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class Sender implements SenderInterface
{
    private ?\AMQPExchange $exchange = null;

    public function __construct(private readonly \AMQPConnection $connection, private readonly SerializerInterface $serializer, private readonly array $options)
    {
    }

    private function connect(): void
    {
        if ($this->exchange instanceof \AMQPExchange) {
            return;
        }

        $this->exchange = new \AMQPExchange(new \AMQPChannel($this->connection));
        $this->exchange->setName($this->options['exchange'] ?? '');
    }

    public function send(Envelope $envelope): Envelope
    {
        $this->connect();

        $stamp = $envelope->last(AmqpStamp::class);

        $data = $this->serializer->encode($envelope);

        $this->exchange->publish(
            $data['body'],
            $this->options['routing_key'] ?? $stamp?->routingKey ?? '',
            null,
            $data['headers'] ?? []
        );

        return $envelope;
    }
}
