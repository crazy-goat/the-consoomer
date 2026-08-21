<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use CrazyGoat\TheConsoomer\Exception\MissingStampException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class Receiver implements ReceiverInterface, MessageCountAwareInterface
{
    public const DEFAULT_MAX_UNACKED_MESSAGES = 100;
    public const DEFAULT_BATCH_SIZE = 1;
    /** @var array<string, int> */
    private array $unacked = [];
    /** @var array<string, ?\AMQPEnvelope> */
    private array $lastUnacked = [];
    /** @var array<Envelope> */
    private array $messages = [];
    /** @var array<string, \AMQPQueue> */
    private array $queues = [];

    /**
     * @param array{
     *     queue?: string,
     *     queues?: array<string, array{binding_keys?: list<string>}>,
     *     exchange?: string,
     *     max_unacked_messages?: int,
     *     batch_size?: int,
     *     auto_setup?: bool,
     *     routing_key?: string,
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
        $this->maxUnackedMessages = max(1, intval($this->options['max_unacked_messages'] ?? self::DEFAULT_MAX_UNACKED_MESSAGES));
        $this->batchSize = max(1, intval($this->options['batch_size'] ?? self::DEFAULT_BATCH_SIZE));
    }

    private readonly int $maxUnackedMessages;
    private readonly int $batchSize;

    /**
     * @return list<string>
     */
    private function getQueueNames(): array
    {
        if (isset($this->options['queues']) && $this->options['queues'] !== []) {
            return array_keys($this->options['queues']);
        }

        $queue = $this->options['queue'] ?? '';
        if ($queue !== '') {
            return [$queue];
        }

        return [];
    }

    /**
     * Ensures the connection is alive, reconnecting when the heartbeat is stale.
     *
     * On reconnect the channel is lost, so all queue/consumer state and buffered
     * ack tracking are reset — the broker will redeliver any unacked messages on
     * the new channel. Returns true when a reconnect happened, so callers that
     * hold stale delivery tags (ack/reject) can treat the operation as a no-op
     * instead of indexing into the wiped queue map.
     *
     * @return bool True when a reconnect was performed, false otherwise
     */
    private function ensureConnected(): bool
    {
        if (!$this->connection->checkHeartbeat()) {
            return false;
        }

        $this->connection->reconnect();
        $this->setup->resetSetup();
        $this->queues = [];
        $this->unacked = [];
        $this->lastUnacked = [];

        return true;
    }

    private function connect(): void
    {
        if ($this->queues !== []) {
            return;
        }

        $channel = $this->connection->getChannel();
        $channel->qos(0, $this->maxUnackedMessages);

        foreach ($this->getQueueNames() as $queueName) {
            $queue = $this->factory->createQueue($channel);
            $queue->setName($queueName);
            $queue->consume(null, AMQP_NOPARAM);
            $this->queues[$queueName] = $queue;
        }
    }

    public function get(): iterable
    {
        $this->messages = [];
        $this->ensureConnected();
        if ($this->options['auto_setup'] ?? true) {
            $this->setup->setup();
        }
        $this->connect();

        foreach ($this->queues as $queueName => $queue) {
            $callback = function (\AMQPEnvelope $message) use ($queueName, $queue): bool {
                try {
                    $envelope = $this->serializer->decode([
                        'body' => $message->getBody(),
                        'headers' => $message->getHeaders(),
                    ]);
                } catch (MessageDecodingFailedException $e) {
                    try {
                        $this->rejectPoisonMessage($queue, (int) $message->getDeliveryTag());
                    } catch (\Throwable) {
                        // Best-effort reject: the decode exception must still propagate.
                    }
                    throw $e;
                }
                $this->messages[] = $envelope->with(new AmqpReceivedStamp($message, $queueName));

                return count($this->messages) < $this->batchSize;
            };

            try {
                $queue->consume($callback, AMQP_JUST_CONSUME, $queue->getConsumerTag());
            } catch (\AMQPException) {
                $this->connection->clearChannelCache();
                $this->queues = [];
                $this->unacked = [];
                $this->lastUnacked = [];

                if ($this->messages !== []) {
                    break;
                }
            }
        }

        $this->connection->updateActivity();

        return $this->messages;
    }

    /**
     * Acknowledges the given envelope on the AMQP queue it was received from.
     *
     * If the connection was stale and a reconnect happened inside this call,
     * the operation is a no-op: the old channel (and its delivery tag) is gone,
     * so the ack cannot be sent and the broker will redeliver the message.
     *
     * @throws MissingStampException When the envelope carries no AmqpReceivedStamp
     */
    public function ack(Envelope $envelope): void
    {
        if ($this->ensureConnected()) {
            // A reconnect wiped the channel — the delivery tag is dead and the
            // broker will redeliver the message on the next get(). Acking it on
            // the new channel would be a no-op at best or a protocol error.
            return;
        }

        $stamp = $envelope->last(AmqpReceivedStamp::class);
        if (!$stamp instanceof AmqpReceivedStamp) {
            throw new MissingStampException('No AMQP received stamp');
        }

        $operation = function () use ($stamp): void {
            $this->ackMessage($stamp->getAmqpEnvelope(), $stamp->getQueueName());
            $this->connection->updateActivity();
        };

        if ($this->retry instanceof ConnectionRetryInterface) {
            $this->retry->withRetry($operation);
        } else {
            $operation();
        }
    }

    /**
     * Rejects the given envelope on the AMQP queue it was received from.
     *
     * If the connection was stale and a reconnect happened inside this call,
     * the operation is a no-op: the old channel (and its delivery tag) is gone,
     * so the reject cannot be sent and the broker will redeliver the message.
     *
     * @throws MissingStampException When the envelope carries no AmqpReceivedStamp
     */
    public function reject(Envelope $envelope): void
    {
        if ($this->ensureConnected()) {
            // A reconnect wiped the channel — the delivery tag is dead and the
            // broker will redeliver the message on the next get(). Rejecting it
            // on the new channel would be a no-op at best or a protocol error.
            return;
        }

        $stamp = $envelope->last(AmqpReceivedStamp::class);
        if (!$stamp instanceof AmqpReceivedStamp) {
            throw new MissingStampException('No AMQP received stamp');
        }

        $operation = function () use ($stamp): void {
            $this->rejectMessage($stamp);
            $this->connection->updateActivity();
        };

        if ($this->retry instanceof ConnectionRetryInterface) {
            $this->retry->withRetry($operation);
        } else {
            $operation();
        }
    }

    /**
     * Rejects a poison message (one whose body could not be decoded) using the
     * configured retry wrapper when available, consistent with {@see reject()}.
     * Intended for best-effort use inside the consume callback: callers should
     * always rethrow the original MessageDecodingFailedException afterwards.
     */
    private function rejectPoisonMessage(\AMQPQueue $queue, int $deliveryTag): void
    {
        $operation = function () use ($queue, $deliveryTag): void {
            $queue->reject($deliveryTag);
            $this->connection->updateActivity();
        };

        if ($this->retry instanceof ConnectionRetryInterface) {
            $this->retry->withRetry($operation);
        } else {
            $operation();
        }
    }

    private function rejectMessage(AmqpReceivedStamp $stamp): void
    {
        $queueName = $stamp->getQueueName();
        if (!isset($this->queues[$queueName])) {
            throw new \InvalidArgumentException(sprintf('Unknown queue "%s" in received message', $queueName));
        }

        $this->ackPending($queueName);

        $this->queues[$queueName]->reject($stamp->getAmqpEnvelope()->getDeliveryTag());
    }

    public function ackPending(?string $queueName = null): void
    {
        if ($queueName !== null) {
            $this->ackPendingForQueue($queueName);
        } else {
            foreach (array_keys($this->unacked) as $name) {
                $this->ackPendingForQueue($name);
            }
        }
    }

    private function ackPendingForQueue(string $queueName): void
    {
        if (!isset($this->lastUnacked[$queueName])) {
            return;
        }

        // After a reconnect the queue map is empty — the channel that carried
        // these delivery tags is gone, so the acks cannot be sent. The broker
        // will redeliver the messages; drop the stale tracking state silently.
        if (isset($this->queues[$queueName])) {
            $this->queues[$queueName]->ack($this->lastUnacked[$queueName]->getDeliveryTag(), AMQP_MULTIPLE);
        }
        $this->lastUnacked[$queueName] = null;
        $this->unacked[$queueName] = 0;
    }

    private function ackMessage(\AMQPEnvelope $message, string $queueName): void
    {
        $this->lastUnacked[$queueName] = $message;
        $this->unacked[$queueName] = ($this->unacked[$queueName] ?? 0) + 1;

        if (($this->unacked[$queueName] ?? 0) >= $this->maxUnackedMessages) {
            $this->ackPending($queueName);
        }
    }

    public function close(): void
    {
        $this->ackPending();
    }

    public function purgeQueue(?string $queueName = null): int
    {
        $this->ensureConnected();
        if ($this->options['auto_setup'] ?? true) {
            $this->setup->setup();
        }

        $queueName ??= $this->options['queue'] ?? '';
        if ($queueName === '' && !isset($this->options['queues'])) {
            throw new \InvalidArgumentException('Queue name must be provided either as argument or in receiver options.');
        }

        if ($queueName === '' && isset($this->options['queues'])) {
            $queueName = $this->getQueueNames()[0] ?? '';
            if ($queueName === '') {
                throw new \InvalidArgumentException('No queues configured for purge.');
            }
        }

        $channel = $this->connection->getChannel();
        $purgeQueue = $this->factory->createQueue($channel);
        $purgeQueue->setName($queueName);

        $purgeOperation = fn(): int => $purgeQueue->purge();

        $result = $this->retry instanceof ConnectionRetryInterface
            ? $this->retry->withRetry($purgeOperation)
            : $purgeOperation();

        $this->connection->updateActivity();

        return $result;
    }

    public function getMessageCount(): int
    {
        $this->ensureConnected();
        if ($this->options['auto_setup'] ?? true) {
            $this->setup->setup();
        }

        $channel = $this->connection->getChannel();
        $total = 0;

        foreach ($this->getQueueNames() as $queueName) {
            $queue = $this->factory->createQueue($channel);
            $queue->setName($queueName);

            $getCount = function () use ($queue): int {
                $flags = $queue->getFlags();
                $queue->setFlags($flags | \AMQP_PASSIVE);

                try {
                    return $queue->declareQueue();
                } finally {
                    $queue->setFlags($flags);
                }
            };

            $total += $this->retry instanceof ConnectionRetryInterface
                ? $this->retry->withRetry($getCount)
                : $getCount();
        }

        $this->connection->updateActivity();

        return $total;
    }
}
