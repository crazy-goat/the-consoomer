<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class Receiver implements ReceiverInterface
{
    private int $unacked = 0;
    private int $maxUnackedMessages = 100;
    private ?\AMQPEnvelope $lastUnacked = null;
    private ?Envelope $message = null;
    private ?\AMQPQueue $queue = null;
    private \Closure $callback;

    public function __construct(
        private readonly AmqpFactoryInterface $factory,
        private readonly \AMQPConnection $connection,
        private readonly SerializerInterface $serializer,
        private readonly array $options,
        private readonly InfrastructureSetup $setup,
        private readonly ?ConnectionRetryInterface $retry = null,
    ) {
        $this->maxUnackedMessages = max(1, intval($this->options['max_unacked_messages'] ?? $this->maxUnackedMessages));
    }

    private function connect(): void
    {
        if ($this->queue instanceof \AMQPQueue) {
            return;
        }

        $this->callback = function (\AMQPEnvelope $message): false {
            $envelope = $this->serializer->decode(['body' => $message->getBody()]);
            $this->message = $envelope->with(new RawMessageStamp($message));

            return false;
        };

        $channel = $this->factory->createChannel($this->connection);
        $channel->qos(0, $this->maxUnackedMessages);
        $this->queue = $this->factory->createQueue($channel);
        $this->queue->setName($this->options['queue'] ?? '');
        // setup consumer, consume happens in get() function
        $this->queue->consume();
    }

    public function get(): iterable
    {
        if ($this->options['auto_setup'] ?? true) {
            $this->setup->setup();
        }
        $this->connect();

        try {
            $this->queue->consume($this->callback, AMQP_JUST_CONSUME, $this->queue->getConsumerTag());
        } catch (\AMQPException $exception) {
            if ('Consumer timeout exceed' !== $exception->getMessage()) {
                throw $exception;
            }
        }

        return $this->message instanceof Envelope ? [$this->message] : [];
    }

    public function ack(Envelope $envelope): void
    {
        $stamp = $envelope->last(RawMessageStamp::class);
        if (!$stamp instanceof RawMessageStamp) {
            throw new \RuntimeException('No raw message stamp');
        }

        if ($this->retry instanceof \CrazyGoat\TheConsoomer\ConnectionRetryInterface) {
            $this->retry->withRetry(fn() => $this->ackMessage($stamp->amqpMessage));
        } else {
            $this->ackMessage($stamp->amqpMessage);
        }
    }

    public function reject(Envelope $envelope): void
    {
        $stamp = $envelope->last(RawMessageStamp::class);
        if (!$stamp instanceof RawMessageStamp) {
            throw new \RuntimeException('No raw message stamp');
        }

        $this->ackPending();

        if ($this->retry instanceof \CrazyGoat\TheConsoomer\ConnectionRetryInterface) {
            $this->retry->withRetry(fn() => $this->queue->reject($stamp->amqpMessage->getDeliveryTag()));
        } else {
            $this->queue->reject($stamp->amqpMessage->getDeliveryTag());
        }
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
