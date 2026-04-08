<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class Receiver implements ReceiverInterface, MessageCountAwareInterface
{
    public const DEFAULT_MAX_UNACKED_MESSAGES = 100;
    private int $unacked = 0;
    private int $maxUnackedMessages = self::DEFAULT_MAX_UNACKED_MESSAGES;
    private ?\AMQPEnvelope $lastUnacked = null;
    private ?Envelope $message = null;
    private ?\AMQPQueue $queue = null;
    private \Closure $callback;

    public function __construct(
        private readonly AmqpFactoryInterface $factory,
        private readonly Connection $connection,
        private readonly SerializerInterface $serializer,
        private readonly array $options,
        private readonly InfrastructureSetup $setup,
        private readonly ?ConnectionRetryInterface $retry = null,
    ) {
        $this->maxUnackedMessages = max(1, intval($this->options['max_unacked_messages'] ?? $this->maxUnackedMessages));
    }

    /**
     * Checks if connection needs reconnection due to heartbeat timeout.
     * Resets internal state when reconnection occurs.
     * This handles stale connections detected via heartbeat mechanism.
     */
    private function ensureConnected(): void
    {
        if ($this->connection->checkHeartbeat()) {
            $this->connection->reconnect();
            $this->queue = null;
            $this->unacked = 0;
            $this->lastUnacked = null;
        }
    }

    /**
     * Establishes AMQP connection and sets up queue if not already connected.
     * Creates channel, configures QoS, and initializes queue consumer.
     * This is idempotent - safe to call multiple times.
     */
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

        $channel = $this->connection->getChannel();
        $channel->qos(0, $this->maxUnackedMessages);
        $this->queue = $this->factory->createQueue($channel);
        $this->queue->setName($this->options['queue'] ?? '');
        $this->queue->consume();
    }

    public function get(): iterable
    {
        $this->message = null;
        if ($this->options['auto_setup'] ?? true) {
            $this->setup->setup();
        }
        $this->ensureConnected();
        $this->connect();

        try {
            $this->queue->consume($this->callback, AMQP_JUST_CONSUME, $this->queue->getConsumerTag());
        } catch (\AMQPException $exception) {
            // Use substring match instead of exact string comparison to handle
            // variations in the ext-amqp extension's error message wording
            // (e.g., "exceed" vs "exceeded"). This is more robust against
            // upstream changes in the C extension.
            if (!str_contains($exception->getMessage(), 'Consumer timeout')) {
                throw $exception;
            }
        }

        $this->connection->updateActivity();

        return $this->message instanceof Envelope ? [$this->message] : [];
    }

    public function ack(Envelope $envelope): void
    {
        $this->ensureConnected();

        $stamp = $envelope->last(RawMessageStamp::class);
        if (!$stamp instanceof RawMessageStamp) {
            throw new \RuntimeException('No raw message stamp');
        }

        if ($this->retry instanceof \CrazyGoat\TheConsoomer\ConnectionRetryInterface) {
            $this->retry->withRetry(function () use ($stamp): void {
                $this->ackMessage($stamp->amqpMessage);
                $this->connection->updateActivity();
            });
        } else {
            $this->ackMessage($stamp->amqpMessage);
            $this->connection->updateActivity();
        }
    }

    public function reject(Envelope $envelope): void
    {
        $this->ensureConnected();

        $stamp = $envelope->last(RawMessageStamp::class);
        if (!$stamp instanceof RawMessageStamp) {
            throw new \RuntimeException('No raw message stamp');
        }

        if ($this->retry instanceof \CrazyGoat\TheConsoomer\ConnectionRetryInterface) {
            $this->retry->withRetry(function () use ($stamp): void {
                $this->ackPending();
                $this->queue->reject($stamp->amqpMessage->getDeliveryTag());
                $this->connection->updateActivity();
            });
        } else {
            $this->ackPending();
            $this->queue->reject($stamp->amqpMessage->getDeliveryTag());
            $this->connection->updateActivity();
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

    /**
     * Acknowledges a message and tracks batched acknowledgments.
     *
     * Uses AMQP_MULTIPLE flag to ack all messages up to the delivery tag,
     * which is more efficient than ack'ing one by one. The ackPending()
     * resets internal state after each batch.
     */
    private function ackMessage(\AMQPEnvelope $message): void
    {
        $this->lastUnacked = $message;
        ++$this->unacked;

        if ($this->unacked >= $this->maxUnackedMessages) {
            $this->ackPending();
        }
    }

    /**
     * Returns the number of messages in the queue.
     *
     * Uses AMQP_PASSIVE flag to safely query queue depth without re-declaring.
     * If auto_setup is enabled, will first ensure the queue exists.
     *
     * @throws \AMQPException When connection fails
     * @throws \AMQPQueueException When queue does not exist (with auto_setup disabled)
     */
    public function getMessageCount(): int
    {
        // Follow same pattern as get(): setup first, then ensure connection
        if ($this->options['auto_setup'] ?? true) {
            $this->setup->setup();
        }
        $this->ensureConnected();
        $this->connect();

        $getMessageCountOperation = function (): int {
            // Use passive flag to safely query queue depth without re-declaring
            $flags = $this->queue->getFlags();
            $this->queue->setFlags($flags | \AMQP_PASSIVE);

            try {
                return $this->queue->declareQueue();
            } finally {
                // Restore original flags
                $this->queue->setFlags($flags);
            }
        };

        $result = $this->retry instanceof ConnectionRetryInterface
            ? $this->retry->withRetry($getMessageCountOperation)
            : $getMessageCountOperation();

        $this->connection->updateActivity();

        return $result;
    }
}
