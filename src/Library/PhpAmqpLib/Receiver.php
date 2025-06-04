<?php

namespace CrazyGoat\TheConsoomer\Library\PhpAmqpLib;

use Bunny\Channel;
use Bunny\ChannelInterface;
use Bunny\Client;
use Bunny\Message;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class Receiver implements ReceiverInterface
{
    private int $unacked = 0;
    private int $maxUnackedMessages = 100;
    private ?AMQPMessage $lastUnacked = null;
    private ?Envelope $message = null;
    private ?AMQPChannel $channel = null;
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly Client              $connection,
        private readonly SerializerInterface $serializer,
        private readonly array               $options,
        ?LoggerInterface                     $logger = null,
    )
    {
        $this->logger = $logger ?? new NullLogger();
        $this->maxUnackedMessages = max(1, intval($this->options['max_unacked_messages'] ?? $this->maxUnackedMessages));
    }

    private function connect(): void
    {
        if ($this->channel instanceof Channel) {
            return;
        }

        $this->channel = $this->connection->connect()->channel();
        $this->channel->qos(0, $this->prefetchCount);

        $this->channel->basic_qos(0, $this->maxUnackedMessages, null);
        $this->channel->basic_consume(
            queue: $this->options['queue'] ?? throw new \RuntimeException('Queue name not defined'),
            callback: function (AMQPMessage $message): void {
                $envelope = $this->serializer->decode(['body' => $message->getBody()]);
                $this->message = $envelope->with(new RawMessageStamp($message));
            },
            $this->options['queue'] ?? throw new \RuntimeException('Queue name not defined'),
        );
    }

    public function get(): iterable
    {
        $timeout = $this->options['timeout'] ?? 1.0;
        $this->connect();
        $this->messages = [];

        $this->connection->run($timeout);
        yield from $this->messages;
    }

    public function ack(Envelope $envelope): void
    {
        $stamp = $envelope->last(RawMessageStamp::class);
        if (!$stamp instanceof RawMessageStamp) {
            throw new \RuntimeException('No raw message stamp');
        }

        $this->ackMessage($stamp->amqpMessage);
    }

    public function reject(Envelope $envelope): void
    {
        $stamp = $envelope->last(RawMessageStamp::class);
        if (!$stamp instanceof RawMessageStamp) {
            throw new \RuntimeException('No raw message stamp');
        }

        $this->ackPending();
        $this->channel->nack($stamp->amqpMessage, true);
    }

    public function ackPending(): void
    {
        if ($this->lastUnacked === null) {
            return;
        }
        $this->channel->ack($this->lastUnacked, true);
        $this->lastUnacked = null;
        $this->unacked = 0;
    }

    private function ackMessage(Message $message): void
    {
        $this->lastUnacked = $message;
        ++$this->unacked;

        if (0 === $this->unacked % $this->maxUnackedMessages) {
            $this->ackPending();
        }
    }
}
