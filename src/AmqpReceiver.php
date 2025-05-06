<?php

namespace CrazyGoat\TheConsoomer;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPNoDataException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class AmqpReceiver implements ReceiverInterface
{
    private int $unacked = 0;
    private int $maxUnackedMessages = 1;
    private int $prefetchCount = 1;
    private ?AMQPMessage $lastUnacked = null;
    private ?Envelope $message = null;
    private ?AMQPChannel $channel = null;
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly AMQPStreamConnection $connection,
        private readonly SerializerInterface $serializer,
        private readonly array $options,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->maxUnackedMessages = max(1, intval($this->options['max_unacked_messages'] ?? $this->maxUnackedMessages));
    }

    private function connect(): void
    {
        if ($this->channel instanceof AMQPChannel) {
            return;
        }

        $this->channel = $this->connection->channel();

        $this->channel->basic_qos(null, $this->prefetchCount, null);
        $this->channel->basic_consume(
            queue: $this->options['queue'] ?? throw new \RuntimeException('Queue name not defined'),
            callback: function (AMQPMessage $message): void {
                $envelope = $this->serializer->decode(['body' => $message->getBody()]);
                $this->message = $envelope->with(new RawMessageStamp($message));
            },
        );
    }

    public function get(): iterable
    {
        $timeout = $this->options['timeout'] ?? 1.0;
        $this->connect();

        while ($this->channel->is_consuming()) {
            try {
                $this->channel->wait(null, false, $timeout);
                yield $this->message ?? throw new AMQPNoDataException();
            } catch (AMQPTimeoutException|AMQPNoDataException) {
                $this->logger->debug(
                    sprintf('Waited %ss to receive message. No message received. Sending heartbeat.', $timeout)
                );
                $this->ackPending();

                $this->channel->getConnection()->checkHeartBeat();

                return [];
            }
        }

        $this->ackPending();

        return [];
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
        $stamp->amqpMessage->nack();
    }

    public function ackPending(): void
    {
        $this->lastUnacked?->ack(true);
        $this->lastUnacked = null;
        $this->unacked = 0;
    }

    private function ackMessage(AMQPMessage $message): void
    {
        $this->lastUnacked = $message;
        ++$this->unacked;

        if (0 === $this->unacked % $this->maxUnackedMessages) {
            $this->ackPending();
        }
    }
}
