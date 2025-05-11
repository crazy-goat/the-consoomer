<?php

namespace CrazyGoat\TheConsoomer;

use Bunny\Channel;
use Bunny\Client;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class AmqpSender implements SenderInterface
{
    private ?Channel $channel = null;

    public function __construct(private readonly Client $connection, private readonly SerializerInterface $serializer, private readonly array $options)
    {
    }

    private function connect(): void
    {
        if ($this->channel instanceof Channel) {
            return;
        }

        $this->channel = $this->connection->connect()->channel();
    }

    public function send(Envelope $envelope): Envelope
    {
        $this->connect();

        $stamp = $envelope->last(AmqpStamp::class);

        $data = $this->serializer->encode($envelope);

        $this->channel->publish(
            $data['body'],
            $data['headers'] ?? [],
            $this->options['exchange'] ?? '',
            $this->options['routing_key'] ?? $stamp?->routingKey ?? '',
        );

        return $envelope;
    }
}
