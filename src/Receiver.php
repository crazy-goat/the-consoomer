<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use CrazyGoat\TheConsoomer\Exception\CircuitBreakerOpenException;
use CrazyGoat\TheConsoomer\Exception\MissingStampException;
use CrazyGoat\TheConsoomer\Exception\RetryExhaustedException;
use CrazyGoat\TheConsoomer\Exception\UnexpectedOperationException;
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
    /**
     * Read timeout used for every queue after the first while draining a
     * multi-queue {@see get()} cycle.
     *
     * Only the first (rotating) queue waits the configured read_timeout for
     * work; the remaining queues are probed with this short timeout so an idle
     * get() over N queues costs ~1 × read_timeout instead of N × read_timeout
     * (#309). A queue that has a message returns immediately regardless of the
     * timeout, so fairness is unaffected.
     */
    private const NON_BLOCKING_READ_TIMEOUT = 0.01;
    /** @var array<string, int> */
    private array $unacked = [];
    /** @var array<string, list<int>> */
    private array $pendingAcks = [];
    /** @var array<Envelope> */
    private array $messages = [];
    /** @var array<string, \AMQPQueue> */
    private array $queues = [];
    /**
     * Maps broker-issued consumer tags to queue names.
     *
     * All queues share one channel, so a consume() loop started for one queue
     * can receive a delivery belonging to another queue's consumer. Resolving
     * the owning queue from the envelope's consumer tag keeps
     * {@see AmqpReceivedStamp::getQueueName()} accurate (#309).
     *
     * @var array<string, string>
     */
    private array $tagToQueue = [];
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
     *     redeclare_on_reconnect?: bool,
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

        // The broker never delivers more than the prefetch, so a batch larger
        // than max_unacked_messages can never be filled: consume() blocks until
        // read_timeout and the batch silently degrades to a partial one (#280).
        // Prefetch is divided across queues (#239), which cannot make this any
        // better, so the cross-check is on the channel-wide values.
        if ($this->batchSize > $this->maxUnackedMessages) {
            throw new \InvalidArgumentException(sprintf(
                'batch_size (%d) must not exceed max_unacked_messages (%d): the broker will not deliver more than the prefetch, so such a batch could never be filled (#280).',
                $this->batchSize,
                $this->maxUnackedMessages,
            ));
        }

        $rawMaxBodyBytes = $this->options['max_body_bytes'] ?? self::DEFAULT_MAX_BODY_BYTES;
        // Fail closed (#288): a negative or non-integer value is a config error,
        // not "no guard" — 0 disables the guard entirely, so clamping invalid
        // input there would silently remove the only size protection against
        // broker-controlled bytes. filter_var() returns false (not null) for
        // out-of-range values, so the check must be explicit.
        $maxBodyBytes = filter_var($rawMaxBodyBytes, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($maxBodyBytes === false) {
            throw new \InvalidArgumentException(sprintf('Option "max_body_bytes" must be a non-negative integer, got "%s".', get_debug_type($rawMaxBodyBytes)));
        }
        $this->maxBodyBytes = $maxBodyBytes;
    }

    /**
     * Target total for unacknowledged deliveries across the channel (#239).
     *
     * Prefetch itself is per-consumer, so {@see prefetchPerConsumer()} divides
     * this value across the configured queues; acks are buffered per queue and
     * flushed at that same divided value, keeping the two scopes consistent.
     */
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
        if ($this->options['redeclare_on_reconnect'] ?? false) {
            $this->setup->resetSetup();
        }
        $this->queues = [];
        $this->tagToQueue = [];
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
        // Prefetch is per-consumer, so it is divided evenly across the queues
        // (#239): N consumers on this channel then hold at most
        // N × prefetchPerConsumer() ≈ max_unacked_messages unacked deliveries
        // in total. (RabbitMQ does not honour the channel-global `qos(...,
        // true)` flag, so dividing is the reliable way to bound the total.)
        $channel->qos(0, $this->prefetchPerConsumer());

        foreach ($this->getQueueNames() as $queueName) {
            $queue = $this->factory->createQueue($channel);
            $queue->setName($queueName);
            $queue->consume(null, AMQP_NOPARAM);
            $this->queues[$queueName] = $queue;

            $tag = $queue->getConsumerTag();
            if (is_string($tag) && $tag !== '') {
                $this->tagToQueue[$tag] = $queueName;
            }
        }
    }

    /**
     * Resolves the queue a delivery belongs to from its consumer tag.
     *
     * Falls back to the queue whose consume() loop is currently running when
     * the envelope carries no (or an unknown) tag — e.g. a broker that omits
     * it, or a directly-injected queue in tests, where the loop queue is the
     * only sensible attribution.
     */
    private function resolveQueueName(\AMQPEnvelope $message, string $fallback): string
    {
        $tag = $message->getConsumerTag();
        if (is_string($tag) && $tag !== '' && isset($this->tagToQueue[$tag])) {
            return $this->tagToQueue[$tag];
        }

        return $fallback;
    }

    /**
     * Temporarily shortens the connection read timeout for a non-first queue.
     *
     * Returns the previous timeout so it can be restored, or null when the
     * connection exposes no timeout to shrink.
     */
    private function shrinkReadTimeout(): float
    {
        $connection = $this->connection->getConnection();
        $original = $connection->getReadTimeout();
        $connection->setReadTimeout(
            $original > 0 ? min($original, self::NON_BLOCKING_READ_TIMEOUT) : self::NON_BLOCKING_READ_TIMEOUT,
        );

        return $original;
    }

    private function restoreReadTimeout(float $original): void
    {
        $this->connection->getConnection()->setReadTimeout($original);
    }

    /**
     * Collects up to `batch_size` messages from the configured queue(s).
     *
     * Queues are polled in round-robin order; an idle call costs roughly one
     * read timeout (not one per queue). Consumer topology is declared here when
     * `auto_setup` is enabled.
     *
     * @return list<Envelope> The collected messages (possibly empty)
     * @throws RetryExhaustedException When a poison-message reject exhausts retries (retry enabled)
     * @throws UnexpectedOperationException When a poison-message reject wraps a non-AMQP failure (retry enabled)
     */
    public function get(): iterable
    {
        $this->messages = [];
        $this->ensureConnected();
        if ($this->options['auto_setup'] ?? true) {
            $this->setup->setup();
        }
        $this->connect();

        // Mark the start of the consume cycle: this is the long operation, so
        // activity is refreshed before it (and again when it returns). Without
        // this the whole loop duration counted against the staleness window
        // (#235).
        $this->connection->updateActivity();

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

        $first = true;
        foreach ($requests as [$queueName, $queue]) {
            if (count($this->messages) >= $this->batchSize) {
                break;
            }

            // Only the first queue waits the configured read_timeout for work.
            // Every later queue is probed with a short timeout so an idle get()
            // costs ~1 × read_timeout instead of one per queue (#309); a queue
            // that has a message returns immediately either way.
            $previousReadTimeout = null;
            if (!$first) {
                $previousReadTimeout = $this->shrinkReadTimeout();
            }
            $first = false;

            // Per-queue consumed count for this cycle: the stop predicate must
            // be relative to this queue, not the global buffer, otherwise the
            // first queue drains until the global budget is met and later
            // queues each contribute at most one message (#204).
            $consumed = 0;
            $callback = function (\AMQPEnvelope $message) use ($queueName, $queue, &$consumed): bool {
                // The delivery may belong to another queue's consumer on the
                // shared channel; attribute it to the queue that actually owns
                // the consumer (#309).
                $resolvedQueueName = $this->resolveQueueName($message, $queueName);
                $resolvedQueue = $this->queues[$resolvedQueueName] ?? $queue;

                $body = $message->getBody();

                try {
                    // Defense in depth (#288): broker-controlled bytes are
                    // untrusted input. The size guard runs before decode() so
                    // an oversized body never reaches the serializer (memory
                    // pressure DoS).
                    if ($this->maxBodyBytes > 0 && \strlen($body) > $this->maxBodyBytes) {
                        $this->rejectPoisonMessage($resolvedQueue, (int) $message->getDeliveryTag());
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
                        $this->rejectPoisonMessage($resolvedQueue, (int) $message->getDeliveryTag());
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
                    $resolvedQueueName,
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
            } catch (\AMQPException) {
                // Genuine connection/channel failure: flush buffered acks
                // best-effort before tearing down so the broker requeues only
                // what could not be acknowledged.
                try {
                    $this->ackPending();
                } catch (\Throwable) {
                    // Channel is dead — acks cannot be sent; broker will redeliver.
                }
                // The channel is gone and the next get() reconnects lazily when
                // it asks for a channel; that path is not heartbeat-staleness,
                // so reset the setup flag here to honour redeclare_on_reconnect
                // (#308).
                if ($this->options['redeclare_on_reconnect'] ?? false) {
                    $this->setup->resetSetup();
                }
                $this->connection->clearChannelCache();
                $this->queues = [];
                $this->tagToQueue = [];
                $this->unacked = [];
                $this->pendingAcks = [];
                // Bump the generation: the channel that issued the buffered
                // delivery tags is gone, so any in-flight envelope carrying
                // them must become a no-op for ack/reject on the next channel.
                ++$this->channelGeneration;
            } finally {
                if ($previousReadTimeout !== null) {
                    $this->restoreReadTimeout($previousReadTimeout);
                }
            }
        }

        $this->connection->updateActivity();

        return $this->messages;
    }

    /**
     * Acknowledges the given envelope on the AMQP queue it was received from.
     *
     * The ack is **deferred**: the delivery tag is buffered and the broker is
     * only told when a queue reaches `max_unacked_messages` (per-queue share),
     * or when {@see ackPending()}/{@see close()} flushes. `ack()` returning
     * therefore does not mean the broker has seen the acknowledgement.
     *
     * Does NOT reconnect on heartbeat staleness (#235): ack() runs after the
     * (possibly slow) message handler, and a slow-but-healthy handler must not
     * force a reconnect here — that would wipe in-flight delivery tags and
     * redeliver messages. Activity is refreshed instead. An envelope from a
     * channel that was already lost is detected by the generation check below
     * and becomes a no-op (#220).
     *
     * @throws MissingStampException When the envelope carries no AmqpReceivedStamp
     * @throws RetryExhaustedException When the ack flush exhausts retries (retry enabled)
     * @throws CircuitBreakerOpenException When the circuit breaker is open (retry circuit breaker enabled)
     * @throws UnexpectedOperationException When the ack flush wraps a non-AMQP failure (retry enabled)
     */
    public function ack(Envelope $envelope): void
    {
        // The handler has finished, so mark liveness before touching the
        // channel; this is what keeps the next get() from seeing false
        // staleness after a long handler (#235).
        $this->connection->updateActivity();

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

        // Bookkeeping runs exactly once; only the flush inside ackMessage() is
        // retryable. Wrapping the whole ack in withRetry would re-run the
        // non-idempotent bookkeeping on every attempt and corrupt the counters
        // (#277).
        $this->ackMessage($stamp->getAmqpEnvelope(), $stamp->getQueueName());
        $this->connection->updateActivity();
    }

    /**
     * Rejects the given envelope on the AMQP queue it was received from.
     *
     * Like {@see ack()} it does not reconnect on heartbeat staleness (#235);
     * activity is refreshed and a stale-generation envelope is a no-op (#220).
     *
     * @throws MissingStampException When the envelope carries no AmqpReceivedStamp
     * @throws RetryExhaustedException When the reject or ack flush exhausts retries (retry enabled)
     * @throws CircuitBreakerOpenException When the circuit breaker is open (retry circuit breaker enabled)
     * @throws UnexpectedOperationException When the reject wraps a non-AMQP failure (retry enabled)
     */
    public function reject(Envelope $envelope): void
    {
        $this->connection->updateActivity();

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

        // As with ack(), only the I/O inside rejectMessage() is retryable; the
        // rejection itself must not be retried as a whole (#277).
        $this->rejectMessage($stamp);
        $this->connection->updateActivity();
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

        $queue = $this->queues[$queueName];
        $deliveryTag = $stamp->getAmqpEnvelope()->getDeliveryTag();

        $operation = function () use ($queue, $deliveryTag): void {
            $queue->reject($deliveryTag);
        };

        if ($this->retry instanceof ConnectionRetryInterface) {
            $this->retry->withRetry($operation);
        } else {
            $operation();
        }
    }

    /**
     * Flushes buffered acknowledgements to the broker.
     *
     * @param string|null $queueName Flush only this queue, or all queues when null
     * @throws RetryExhaustedException When the flush exhausts retries (retry enabled)
     * @throws CircuitBreakerOpenException When the circuit breaker is open (retry circuit breaker enabled)
     * @throws UnexpectedOperationException When the flush wraps a non-AMQP failure (retry enabled)
     */
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

        // Take the tags off the buffer BEFORE any I/O (#277): a retry of the
        // flush must not re-buffer or re-count them. If the flush ultimately
        // fails, the tags are gone and the broker redelivers the messages —
        // at-least-once, never a corrupted counter or a double ack.
        $tags = $this->pendingAcks[$queueName];
        $this->pendingAcks[$queueName] = [];
        $this->unacked[$queueName] = 0;

        $operation = function () use ($queueName, $tags): void {
            $queue = $this->queues[$queueName] ?? null;
            if (!$queue instanceof \AMQPQueue) {
                // The queue map was wiped between attempts (reconnect); the
                // broker redelivers, so there is nothing left to ack.
                return;
            }

            if ($this->isMultiQueue()) {
                // Multi-queue mode: all queues share one channel, so
                // AMQP_MULTIPLE would acknowledge every message on the channel
                // up to and including the highest tag — including in-flight
                // messages belonging to other queues. Ack each tag individually
                // to stay within this queue's own delivery tags and avoid
                // silent cross-queue message loss (#202).
                foreach ($tags as $tag) {
                    $queue->ack($tag, \AMQP_NOPARAM);
                }
            } else {
                // Single-queue mode: the channel carries only this queue's tags,
                // so a single AMQP_MULTIPLE ack up to the highest tag is both
                // correct and efficient (one RTT instead of N).
                $queue->ack(end($tags), AMQP_MULTIPLE);
            }
        };

        if ($this->retry instanceof ConnectionRetryInterface) {
            $this->retry->withRetry($operation);
        } else {
            $operation();
        }
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

        // Flush at the same per-consumer prefetch that gates delivery for this
        // queue: flushing at the (larger) channel-wide value would stall the
        // consumer — the broker stops at the prefetch, unacked reaches the
        // threshold and the buffered acks are never sent (#239).
        if (($this->unacked[$queueName] ?? 0) >= $this->prefetchPerConsumer()) {
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
     * Prefetch applied to each consumer on the shared channel.
     *
     * AMQP prefetch is per-consumer and RabbitMQ does not honour the
     * channel-global `qos(..., true)` flag, so to keep the total in-flight
     * bounded by `max_unacked_messages` the value is divided evenly across the
     * configured queues (#239). With a single queue it is simply
     * `max_unacked_messages`. The same value is used as the per-queue ack-batch
     * flush threshold so buffered acks are always flushed before the consumer's
     * prefetch stalls delivery.
     */
    private function prefetchPerConsumer(): int
    {
        $queueCount = max(1, count($this->getQueueNames()));

        return $queueCount > 1
            ? max(1, intdiv($this->maxUnackedMessages, $queueCount))
            : $this->maxUnackedMessages;
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

    /**
     * Flushes all buffered acknowledgements and marks the receiver closed.
     */
    public function close(): void
    {
        $this->ackPending();
    }

    /**
     * Purges messages from a queue and returns the number removed.
     *
     * @param string|null $queueName Queue to purge. When null, **every**
     *        configured queue is purged and the counts are summed (multi-queue
     *        mode used to purge only the first queue, silently — #243).
     *
     * @throws \InvalidArgumentException When no queue is given and none is configured
     * @throws RetryExhaustedException When the purge exhausts retries (retry enabled)
     * @throws CircuitBreakerOpenException When the circuit breaker is open (retry circuit breaker enabled)
     * @throws UnexpectedOperationException When the purge wraps a non-AMQP failure (retry enabled)
     */
    public function purgeQueue(?string $queueName = null): int
    {
        $this->ensureConnected();
        if ($this->options['auto_setup'] ?? true) {
            $this->setup->setup();
        }

        $queueNames = $queueName !== null && $queueName !== ''
            ? [$queueName]
            : $this->getQueueNames();

        if ($queueNames === []) {
            throw new \InvalidArgumentException('Queue name must be provided either as argument or in receiver options.');
        }

        $channel = $this->connection->getChannel();
        $total = 0;

        foreach ($queueNames as $name) {
            $purgeQueue = $this->factory->createQueue($channel);
            $purgeQueue->setName($name);

            $purgeOperation = fn(): int => $purgeQueue->purge();

            $total += $this->retry instanceof ConnectionRetryInterface
                ? $this->retry->withRetry($purgeOperation)
                : $purgeOperation();
        }

        $this->connection->updateActivity();

        return $total;
    }

    /**
     * Returns the number of ready messages summed across all configured queues.
     *
     * Uses a passive declare per queue, so it reflects the broker's ready
     * count (messages delivered but not yet acked are not counted).
     *
     * @throws RetryExhaustedException When the passive declare exhausts retries (retry enabled)
     * @throws CircuitBreakerOpenException When the circuit breaker is open (retry circuit breaker enabled)
     * @throws UnexpectedOperationException When the declare wraps a non-AMQP failure (retry enabled)
     */
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
