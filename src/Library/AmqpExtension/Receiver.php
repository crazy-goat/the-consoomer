<?php

namespace CrazyGoat\TheConsoomer\Library\AmqpExtension;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class Receiver implements ReceiverInterface
{
    private int $unacked = 0;
    private int $maxUnackedMessages = 100;
    private ?\AMQPEnvelope $lastUnacked = null;
    private ?Envelope $message = null;
    private readonly LoggerInterface $logger;
    private ?\AMQPQueue $queue = null;
    private \Closure $callback;

    public function __construct(
        private readonly \AMQPConnection $connection,
        private readonly SerializerInterface $serializer,
        private readonly array $options,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->maxUnackedMessages = max(1, intval($this->options['max_unacked_messages'] ?? $this->maxUnackedMessages));
    }

    private function connect(): void
    {
        if ($this->queue instanceof \AMQPQueue) {
            return;
        }

        $this->callback = function (\AMQPEnvelope $message) {
            $envelope = $this->serializer->decode(['body' => $message->getBody()]);
            $this->message = $envelope->with(new RawMessageStamp($message));
            return false;
        };

        $cahnnel = new \AMQPChannel($this->connection);
        $cahnnel->qos(0, $this->maxUnackedMessages);
        $this->queue = new \AMQPQueue($cahnnel);
        $this->queue->setName($this->options['queue']);
        //setup consumer, consume happens in get() function
        $this->queue->consume();
    }

    public function get(): iterable
    {
        $this->connect();

        try {
            $this->queue->consume($this->callback, AMQP_JUST_CONSUME, $this->queue->getConsumerTag());
        } catch (\AMQPQueueException $exception) {
            if ($exception->getMessage() !== 'Consumer timeout exceed') {
                throw $e;
            }
        }

        return $this->message !== null ? [$this->message] : [];
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
        $this->queue->reject($stamp->amqpMessage->getDeliveryTag());
    }

    public function ackPending(): void
    {
        if ($this->lastUnacked instanceof \AMQPEnvelope) {
            $this->queue->ack($this->lastUnacked->getDeliveryTag(), AMQP_MULTIPLE);
        }
        $this->lastUnacked = null;
        $this->unacked = 0;
    }

    private function ackMessage(\AMQPEnvelope $message): void
    {
        $this->lastUnacked = $message;
        ++$this->unacked;

        if (0 === $this->unacked % $this->maxUnackedMessages) {
            $this->ackPending();
        }
    }
}
