<?php

namespace CrazyGoat\TheConsoomer;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class AmqpSender implements SenderInterface
{
    private ?AMQPChannel $channel = null;

    public function __construct(private readonly AMQPStreamConnection $connection, private readonly SerializerInterface $serializer, private readonly array $options)
    {
    }

    private function connect(): void
    {
        if ($this->channel instanceof AMQPChannel) {
            return;
        }

        $this->channel = $this->connection->channel();
    }

    public function send(Envelope $envelope): Envelope
    {
        $this->connect();

        $stamp = $envelope->last(AmqpStamp::class);

        $data = $this->serializer->encode($envelope);

        $this->channel->basic_publish(
            new AMQPMessage($data['body'], $data['headers'] ?? []),
            $this->options['exchange'] ?? '',
            $this->options['routing_key'] ?? $stamp?->routingKey ?? '',
        );

        return $envelope;
    }
}
