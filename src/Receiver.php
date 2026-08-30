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
    /**
     * Default cap for a single message body handed to the serializer (#288).
     *
     * A body larger than this limit is rejected without calling decode() at
     * all: even a "safe" serializer can be pushed into memory pressure by an
     * oversized payload, and unbounded broker-controlled input must not be
     * the thing that decides how much memory the consumer allocates.
     */
    public const DEFAULT_MAX_BODY_BYTES = 16 * 1024 * 1024;
    /** @var array<string, int> */
    private array $unacked = [];
    /** @var array<string, list<int>> */
    private array $pendingAcks = [];
    /** @var array<Envelope> */
    private array $messages = [];
    /** @var array<string, \AMQPQueue> */
    private array $queues = [];
    /**
     * Monotonically incremented on every reconnect. Delivery tags are scoped
     * per channel, so an envelope whose {@see AmqpReceivedStamp} carries an
     * older generation belongs to a dead channel and must never be acked or
     * rejected on the current one — the broker re-queues its message and
     * reissues a fresh tag on the new channel (#220).
     */
    private int $channelGeneration = 0;
    /**
     * Index of the queue that starts the next {@see get()} cycle. Rotated on
     * every call so that, in multi-queue mode with a single total batch budget,
     * no queue is permanently first in the (stable) iteration order (#204).
     */
    private int $nextQueueOffset = 0;

    /**
     * @param array{
     *     queue?: string,
     *     queues?: array<string, array{binding_keys?: list<string>}>,
     *     exchange?: string,
     *     max_unacked_messages?: int,
     *     batch_size?: int,
     *     max_body_bytes?: int,
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
        $this->maxBodyBytes = max(0, intval($this->options['max_body_bytes'] ?? self::DEFAULT_MAX_BODY_BYTES));
    }

    private readonly int $maxUnackedMessages;
    private readonly int $batchSize;
    /**
     * Upper bound on the raw body length accepted per message (#288).
     *
     * 0 disables the guard: the body of any size is handed to the serializer,
     * exactly as before the option existed.
     */
    private readonly int $maxBodyBytes;

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
        $this->pendingAcks = [];
        // Bump the generation so any in-flight envelope (carrying an old tag
        // from the dead channel) is recognised as stale by ack()/reject() and
        // becomes a no-op instead of a protocol error on the new channel (#220).
        ++$this->channelGeneration;

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

        // Iterate the queues round-robin (rotating the starting offset between
        // get() calls) and stop entering further queues once the total batch
        // budget is spent, so no single queue drains everything (#204).
        $requests = [];
        $queueNames = array_keys($this->queues);
        $count = count($queueNames);
        for ($i = 0; $i < $count; $i++) {
            $queueName = $queueNames[($this->nextQueueOffset + $i) % $count];
            $requests[] = [$queueName, $this->queues[$queueName]];
        }
        $this->nextQueueOffset = ($this->nextQueueOffset + 1) % max(1, $count);

        foreach ($requests as [$queueName, $queue]) {
            if (count($this->messages) >= $this->batchSize) {
                break;
            }

            // Per-queue consumed count for this cycle: the stop predicate must
            // be relative to this queue, not the global buffer, otherwise the
            // first queue drains until the global budget is met and later
            // queues each contribute at most one message (#204).
            $consumed = 0;
            $callback = function (\AMQPEnvelope $message) use ($queueName, $queue, &$consumed): bool {
                $body = $message->getBody();

                try {
                    // Defense in depth (#288): broker-controlled bytes are
                    // untrusted input. The size guard runs before decode() so
                    // an oversized body never reaches the serializer (memory
                    // pressure DoS).
                    if ($this->maxBodyBytes > 0 && \strlen($body) > $this->maxBodyBytes) {
                        $this->rejectPoisonMessage($queue, (int) $message->getDeliveryTag());
                        ++$consumed;

                        return $consumed < $this->perQueueBudget()
                            && count($this->messages) < $this->batchSize;
                    }

                    $envelope = $this->serializer->decode([
                        'body' => $body,
                        'headers' => $message->getHeaders(),
                    ]);
                } catch (MessageDecodingFailedException $e) {
                    try {
                        $this->rejectPoisonMessage($queue, (int) $message->getDeliveryTag());
                    } catch (\Throwable) {
                        // The poison message could not be taken off the queue
                        // (broken channel / retries exhausted) — rethrow the
                        // decode failure so the problem stays visible instead
                        // of silently looping on broker redelivery.
                        throw $e;
                    }

                    // Poison message rejected (dropped or dead-lettered per
                    // broker policy). The batch survives (#288): keep consuming
                    // instead of aborting the whole get() cycle.
                    ++$consumed;

                    return $consumed < $this->perQueueBudget()
                        && count($this->messages) < $this->batchSize;
                }

                $this->messages[] = $envelope->with(new AmqpReceivedStamp(
                    $message,
                    $queueName,
                    $this->channelGeneration,
                ));

                // Stop consuming from this queue once it has contributed its
                // share (or the global budget is spent); the outer loop then
                // moves on to the next queue.
                ++$consumed;

                return $consumed < $this->perQueueBudget()
                    && count($this->messages) < $this->batchSize;
            };

            try {
                $queue->consume($callback, AMQP_JUST_CONSUME, $queue->getConsumerTag());
            } catch (\AMQPQueueException) {
                // Idle consume timeout: the expected outcome of polling a queue
                // that has no messages ready. The channel, consumers, and all
                // buffered acks remain valid — do NOT tear them down. The old
                // behaviour wiped pending acks here on every empty poll, causing
                // redelivery of already-processed messages (#271).
                if ($this->messages !== []) {
                    break;
                }
            } catch (\AMQPException) {
                // Genuine connection/channel failure: flush buffered acks
                // best-effort before tearing down so the broker requeues only
                // what could not be acknowledged.
                try {
                    $this->ackPending();
                } catch (\Throwable) {
                    // Channel is dead — acks cannot be sent; broker will redeliver.
                }
                $this->connection->clearChannelCache();
                $this->queues = [];
                $this->unacked = [];
                $this->pendingAcks = [];
                // Bump the generation: the channel that issued the buffered
                // delivery tags is gone, so any in-flight envelope carrying
                // them must become a no-op for ack/reject on the next channel.
                ++$this->channelGeneration;

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

        // The envelope may predate a reconnect that happened between get() and
        // ack() (e.g. a later get() rebuilt the queue map on a new channel).
        // Its delivery tag belongs to the dead channel — acking it on the
        // current channel is a protocol error; the broker redelivers it (#220).
        if ($stamp->getChannelGeneration() !== $this->channelGeneration) {
            return;
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

        // The envelope may predate a reconnect that happened between get() and
        // reject() (e.g. a later get() rebuilt the queue map on a new channel).
        // Its delivery tag belongs to the dead channel — rejecting it on the
        // current channel is a protocol error; the broker redelivers it (#220).
        if ($stamp->getChannelGeneration() !== $this->channelGeneration) {
            return;
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
        if (!isset($this->pendingAcks[$queueName]) || $this->pendingAcks[$queueName] === []) {
            return;
        }

        // After a reconnect the queue map is empty — the channel that carried
        // these delivery tags is gone, so the acks cannot be sent. The broker
        // will redeliver the messages; drop the stale tracking state silently.
        if (!isset($this->queues[$queueName])) {
            $this->pendingAcks[$queueName] = [];
            $this->unacked[$queueName] = 0;

            return;
        }

        $tags = $this->pendingAcks[$queueName];
        $queue = $this->queues[$queueName];

        if ($this->isMultiQueue()) {
            // Multi-queue mode: all queues share one channel, so AMQP_MULTIPLE
            // would acknowledge every message on the channel up to and including
            // the highest tag — including in-flight messages belonging to other
            // queues. Ack each tag individually to stay within this queue's own
            // delivery tags and avoid silent cross-queue message loss (#202).
            foreach ($tags as $tag) {
                $queue->ack($tag, \AMQP_NOPARAM);
            }
        } else {
            // Single-queue mode: the channel carries only this queue's tags, so
            // a single AMQP_MULTIPLE ack up to the highest tag is both correct
            // and efficient (one RTT instead of N).
            $queue->ack(end($tags), AMQP_MULTIPLE);
        }

        $this->pendingAcks[$queueName] = [];
        $this->unacked[$queueName] = 0;
    }

    private function ackMessage(\AMQPEnvelope $message, string $queueName): void
    {
        // The queue must still be in the map — if it was wiped by a genuine
        // connection/channel failure (AMQPException) or a reconnect, the
        // channel that carried this delivery tag is gone and the broker will
        // redeliver the message on the next get(). Buffering the ack would
        // either fatal on flush (missing queue) or silently send a stale
        // delivery tag on the new channel — a protocol error (#220/#272).
        if (!isset($this->queues[$queueName])) {
            return;
        }

        $deliveryTag = (int) $message->getDeliveryTag();
        $this->pendingAcks[$queueName][] = $deliveryTag;
        $this->unacked[$queueName] = ($this->unacked[$queueName] ?? 0) + 1;

        if (($this->unacked[$queueName] ?? 0) >= $this->maxUnackedMessages) {
            $this->ackPending($queueName);
        }
    }

    /**
     * Whether more than one queue is consumed on the shared channel.
     *
     * Delivery tags are scoped per channel, so AMQP_MULTIPLE can only be used
     * safely when a single queue owns the channel; with multiple queues a
     * batched ack would acknowledge other queues' in-flight messages (#202).
     */
    private function isMultiQueue(): bool
    {
        return count($this->getQueueNames()) > 1;
    }

    /**
     * Per-queue consume budget for a {@see get()} cycle.
     *
     * The total {@see batch_size} is a single batch across all queues, so each
     * queue's callback must stop draining once the global budget is met — but
     * before then it may consume freely so a slow/drained earlier queue does
     * not starve a later one. In multi-queue mode the budget is divided evenly
     * across the configured queues; round-robin start rotation then spreads
     * any remainder so no queue is systematically short-changed (#204).
     */
    private function perQueueBudget(): int
    {
        $queueCount = count($this->getQueueNames());

        return $queueCount > 1 ? max(1, intdiv($this->batchSize, $queueCount)) : $this->batchSize;
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
