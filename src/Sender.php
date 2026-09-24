<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * AMQP message sender for Symfony Messenger.
 *
 * Handles publishing messages to AMQP exchange with support for
 * publisher confirms, retry logic and connection recovery.
 */
final class Sender implements SenderInterface
{
    /**
     * AMQP entity names and shortstr values (including routing keys) are
     * limited to 255 bytes by the protocol.
     */
    private const MAX_AMQP_NAME_BYTES = 255;
    /**
     * Characters allowed in a routing key that is templated into a delay queue
     * name (#289). Routing keys are frequently derived from message content or
     * tenant IDs, so an unrestricted key would let a publisher shape the AMQP
     * entity name (queue name, dead-letter routing key) far beyond its
     * intended meaning. Anchored with `\z` (not `$`, which also matches before
     * a trailing newline) so a control byte cannot slip through.
     */
    private const SAFE_ROUTING_KEY_PATTERN = '/^[A-Za-z0-9._-]*\z/';
    private ?\AMQPExchange $exchange = null;
    private ?\AMQPExchange $delayExchange = null;
    private readonly float $confirmTimeout;
    /**
     * Whether publisher confirms are enabled.
     *
     * Decoupled from {@see $confirmTimeout} (#244): the timeout is a duration
     * (0 = wait indefinitely, matching ext-amqp's `waitForConfirm(0)`), not a
     * feature flag. When `publisher_confirms` is absent this defaults to true
     * iff a positive `confirm_timeout` is configured, preserving the behaviour
     * before the flag existed; set it explicitly to override either way.
     */
    private readonly bool $publisherConfirms;
    /**
     * Whether the durable topology is re-declared after a reconnect.
     *
     * Durable exchanges/queues survive client disconnects by definition, so
     * re-declaring on every reconnect (including wall-clock idle reconnects,
     * #235) is wasted work; it is only needed when an operator may have
     * deleted the topology while the worker was connected. Default: false.
     */
    private readonly bool $redeclareOnReconnect;
    /**
     * Channel already placed in confirm mode via confirmSelect().
     * confirm.select is a per-channel setting that persists for the channel
     * lifetime, so it must be sent only once per channel — not on every
     * publish (#307). Reset to null whenever the channel is lost (reconnect).
     */
    private ?\AMQPChannel $confirmedChannel = null;
    /** @var array<string, true> */
    private array $delayQueuesCreated = [];
    /**
     * Delay-exchange bindings already created, keyed by queue name then routing
     * key. The delay queue's dead-letter routing key is no longer fixed at
     * creation (#276): the queue is bound for the message's own routing key and
     * the delayed message keeps that key, so a custom `queue_name_pattern`
     * without `{queue}` cannot rewrite the routing of later messages.
     *
     * @var array<string, array<string, true>>
     */
    private array $delayQueueBindings = [];
    private readonly string $delayExchangeName;
    private readonly string $delayQueueNamePattern;

    /**
     * @param array{
     *     exchange?: string,
     *     default_publish_routing_key?: string,
     *     auto_setup?: bool,
     *     redeclare_on_reconnect?: bool,
     *     retry?: bool,
     *     publisher_confirms?: bool,
     *     confirm_timeout?: float|int,
     *     delay?: array{
     *         exchange_name?: string,
     *         queue_name_pattern?: string,
     *     },
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
        $confirmTimeout = $this->options['confirm_timeout'] ?? 0.0;
        if ($confirmTimeout < 0) {
            throw new \InvalidArgumentException('confirm_timeout must be a non-negative value');
        }
        $this->confirmTimeout = (float) $confirmTimeout;
        $this->publisherConfirms = $this->resolvePublisherConfirms();
        $this->redeclareOnReconnect = (bool) ($this->options['redeclare_on_reconnect'] ?? false);

        $this->delayExchangeName = $this->options['delay']['exchange_name']
            ?? ($this->options['exchange'] ?? '') . '_delay';
        $this->delayQueueNamePattern = $this->options['delay']['queue_name_pattern']
            ?? 'delay_{delay}_{queue}';
    }

    /**
     * Resolves whether publisher confirms are enabled (#244).
     *
     * An explicit `publisher_confirms` wins; otherwise a positive
     * `confirm_timeout` enables confirms (backwards compatibility with the
     * pre-#244 overloaded semantics), and an absent/0 timeout leaves them off.
     */
    private function resolvePublisherConfirms(): bool
    {
        $explicit = $this->options['publisher_confirms'] ?? null;

        if ($explicit === null) {
            return $this->confirmTimeout > 0.0;
        }

        $resolved = filter_var($explicit, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE);
        if ($resolved === null) {
            throw new \InvalidArgumentException(sprintf(
                'Option "publisher_confirms" must be a boolean, got "%s".',
                get_debug_type($explicit),
            ));
        }

        return $resolved;
    }

    /**
     * Initializes AMQP exchange connection.
     * Idempotent - safe to call multiple times.
     */
    private function connect(): void
    {
        if ($this->exchange instanceof \AMQPExchange) {
            return;
        }

        $this->exchange = $this->factory->createExchange($this->connection->getChannel());
        $this->exchange->setName($this->options['exchange'] ?? '');
    }

    /**
     * Checks connection heartbeat and reconnects if stale.
     *
     * On reconnect the channel is lost, so the exchange object, confirm-mode
     * cache, and delay-queue cache are reset. The setup flag is only reset when
     * the `redeclare_on_reconnect` option is enabled (default false): durable
     * topology survives a disconnect, so re-declaring it on every reconnect is
     * unnecessary work. Enable the option when an operator may delete the
     * topology while the worker is connected (#308, #273).
     */
    private function ensureConnected(): void
    {
        if ($this->connection->checkHeartbeat()) {
            $this->connection->reconnect();
            if ($this->redeclareOnReconnect) {
                $this->setup->resetSetup();
            }
            $this->exchange = null;
            $this->delayExchange = null;
            $this->delayQueuesCreated = [];
            $this->delayQueueBindings = [];
            $this->confirmedChannel = null;
            $this->connect();
        }
    }

    /**
     * Returns the current channel in publisher-confirm mode.
     *
     * `confirm.select` is a per-channel setting that persists for the channel
     * lifetime, so it is sent only once per channel — not on every publish
     * (#307). On reconnect `ensureConnected()` resets `$confirmedChannel`, so
     * the next call re-enables confirms on the fresh channel.
     *
     * @return \AMQPChannel|null The channel in confirm mode, or null when
     *                           confirms are disabled (`publisher_confirms` off)
     */
    private function confirmChannel(): ?\AMQPChannel
    {
        if (!$this->publisherConfirms) {
            return null;
        }

        $channel = $this->connection->getChannel();
        if ($this->confirmedChannel !== $channel) {
            $channel->confirmSelect();
            $this->confirmedChannel = $channel;
        }

        return $channel;
    }

    /**
     * Resolves the routing key for a message.
     *
     * Priority: Stamp routing key > default_publish_routing_key option > Empty string
     */
    private function getRoutingKeyForMessage(?AmqpStamp $stamp): string
    {
        $routingKey = $stamp?->getRoutingKey();
        if ($routingKey !== null) {
            return $routingKey;
        }

        return $this->options['default_publish_routing_key'] ?? '';
    }

    /**
     * Builds the AMQP message attributes for publish.
     *
     * Serializer headers are not AMQP basic-property names (ext-amqp only
     * understands `content_type`, `delivery_mode`, …, `headers`), so they are
     * nested under the AMQP `headers` table to survive transport intact and be
     * handed back to `decode()` on the consumer (see #274), mirroring
     * symfony/amqp-messenger. The serializer's `Content-Type` header is mapped
     * to the AMQP `content_type` basic property (unless the stamp already sets
     * one); stamp headers win over serializer headers on key collision.
     *
     * @param array{body: string, headers?: array<string, mixed>} $data
     * @return array<string, mixed>
     */
    private function buildAttributes(?AmqpStamp $stamp, array $data): array
    {
        $attributes = $stamp?->getAttributes() ?? [];
        $serializerHeaders = $data['headers'] ?? [];

        $headers = ($attributes['headers'] ?? []) + $serializerHeaders;
        unset($attributes['headers']);

        if (!isset($attributes['content_type']) && isset($headers['Content-Type'])) {
            $attributes['content_type'] = $headers['Content-Type'];
        }
        unset($headers['Content-Type']);

        if ($headers !== []) {
            $attributes['headers'] = $headers;
        }

        return $attributes;
    }

    /**
     * {@inheritdoc}
     *
     * @param Envelope $envelope The envelope to send
     * @return Envelope The sent envelope
     * @throws \AMQPException When connection or publish fails
     */
    public function send(Envelope $envelope): Envelope
    {
        $this->ensureConnected();
        $this->connect();

        $stamp = $envelope->last(AmqpStamp::class);

        $data = $this->serializer->encode($envelope);

        $routingKey = $this->getRoutingKeyForMessage($stamp);
        $flags = $stamp?->getFlags() ?? \AMQP_NOPARAM;
        $attributes = $this->buildAttributes($stamp, $data);

        $priorityStamp = $envelope->last(AmqpPriorityStamp::class);
        if ($priorityStamp instanceof AmqpPriorityStamp) {
            $attributes['priority'] = $priorityStamp->getPriority();
        }

        $delayStamp = $envelope->last(AmqpDelayStamp::class);
        if ($delayStamp instanceof AmqpDelayStamp) {
            // Validate before the retry wrapper: a routing key that cannot be
            // turned into a safe entity name is a caller error, not a transient
            // failure, and must not be retried or re-typed (#289).
            $delayQueueName = $this->prepareDelayQueueName($routingKey, $delayStamp->getDelay());

            $publishCallback = function () use ($data, $routingKey, $flags, $attributes, $delayStamp, $delayQueueName): void {
                $this->sendWithDelay($data, $routingKey, $flags, $attributes, $delayStamp, $delayQueueName);
            };
        } else {
            $publishCallback = function () use ($data, $routingKey, $flags, $attributes): void {
                $channel = $this->confirmChannel();

                $this->exchange->publish(
                    $data['body'],
                    $routingKey,
                    $flags,
                    $attributes,
                );

                $channel?->waitForConfirm($this->confirmTimeout);
            };
        }

        if ($this->retry instanceof ConnectionRetryInterface) {
            $this->retry->withRetry(function () use ($publishCallback): void {
                // auto_setup runs inside the retried operation so a transient
                // declaration failure is retried instead of escaping send()
                // before the retry wrapper (#281). A producer only needs its
                // exchange (#308).
                if ($this->options['auto_setup'] ?? true) {
                    $this->setup->setupExchange();
                }

                // Without publisher confirms, AMQPExchange::publish() is
                // fire-and-forget: it writes to the socket buffer and returns
                // immediately, even when the broker is down or the exchange is
                // missing. The retry wrapper would never see an exception and
                // the message would silently evaporate. Guard the send path
                // with an explicit connection check so retry has an error
                // signal to act on (#273). Confirms (confirm_timeout > 0)
                // surface broker-side rejects via waitForConfirm(), but the
                // connection check still catches total broker outages that
                // would otherwise be swallowed before the confirm wait.
                if (!$this->connection->isConnected()) {
                    $this->connection->reconnect();
                    if ($this->redeclareOnReconnect) {
                        $this->setup->resetSetup();
                    }
                    $this->exchange = null;
                    $this->delayExchange = null;
                    $this->delayQueuesCreated = [];
                    $this->delayQueueBindings = [];
                    $this->confirmedChannel = null;
                    $this->connect();

                    // The pre-retry setupExchange() ran before this reconnect,
                    // and the reconnect may have cleared the setup flag, so the
                    // exchange must be (re-)declared for this attempt — not only
                    // on the next send() (#308).
                    if ($this->options['auto_setup'] ?? true) {
                        $this->setup->setupExchange();
                    }
                }

                $publishCallback();
                $this->connection->updateActivity();
            });
        } else {
            if ($this->options['auto_setup'] ?? true) {
                $this->setup->setupExchange();
            }
            $publishCallback();
            $this->connection->updateActivity();
        }

        return $envelope;
    }

    private function ensureDelayExchange(): void
    {
        if ($this->delayExchange instanceof \AMQPExchange) {
            return;
        }

        $this->delayExchange = $this->factory->createExchange($this->connection->getChannel());
        $this->delayExchange->setName($this->delayExchangeName);
        $this->delayExchange->setType(\AMQP_EX_TYPE_DIRECT);
        $this->delayExchange->setFlags(\AMQP_DURABLE);
        $this->delayExchange->declareExchange();
    }

    private function sendWithDelay(
        array $data,
        string $routingKey,
        int $flags,
        array $attributes,
        AmqpDelayStamp $delayStamp,
        string $delayQueueName,
    ): void {
        $this->ensureDelayExchange();

        if (!isset($this->delayQueuesCreated[$delayQueueName])) {
            $this->createDelayQueue($delayQueueName, $delayStamp->getDelay(), $routingKey);
            $this->delayQueuesCreated[$delayQueueName] = true;
            $this->delayQueueBindings[$delayQueueName][$routingKey] = true;
        } else {
            $this->ensureDelayBinding($delayQueueName, $routingKey);
        }

        $channel = $this->confirmChannel();

        // Publish with the message's own routing key (#276). The delay queue has
        // only `x-dead-letter-exchange` set, so on TTL expiry the broker
        // dead-letters with this same key — no per-queue routing key to freeze.
        $this->delayExchange->publish(
            $data['body'],
            $routingKey,
            $flags,
            $attributes,
        );

        $channel?->waitForConfirm($this->confirmTimeout);
    }

    /**
     * Validates the publisher-controlled inputs and builds the delay queue name.
     *
     * Called from {@see send()} *before* the retry wrapper so a validation
     * failure keeps its {@see \InvalidArgumentException} type instead of being
     * re-wrapped as {@see UnexpectedOperationException} by the retry path, and
     * before any declaration side effect (#289).
     *
     * @throws \InvalidArgumentException When the routing key is too long or
     *                                   unsafe, or the queue name is too long
     */
    private function prepareDelayQueueName(string $routingKey, int $delay): string
    {
        $this->assertSafeEntityRoutingKey($routingKey);

        $delayQueueName = $this->getDelayQueueName($routingKey, $delay);

        if (\strlen($delayQueueName) > self::MAX_AMQP_NAME_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'Delay queue name "%s" exceeds the AMQP %d-byte limit; shorten the delay queue_name_pattern or the routing key.',
                $delayQueueName,
                self::MAX_AMQP_NAME_BYTES,
            ));
        }

        return $delayQueueName;
    }

    private function getDelayQueueName(string $routingKey, int $delay): string
    {
        return str_replace(
            ['{delay}', '{queue}'],
            [(string) $delay, $routingKey],
            $this->delayQueueNamePattern,
        );
    }

    /**
     * Validates a routing key that will be templated into delay entity names.
     *
     * @throws \InvalidArgumentException When the key is too long or contains
     *                                   characters unsafe for AMQP entity names
     */
    private function assertSafeEntityRoutingKey(string $routingKey): void
    {
        if (\strlen($routingKey) > self::MAX_AMQP_NAME_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'Routing key exceeds the AMQP %d-byte limit (%d bytes).',
                self::MAX_AMQP_NAME_BYTES,
                \strlen($routingKey),
            ));
        }

        if (preg_match(self::SAFE_ROUTING_KEY_PATTERN, $routingKey) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Routing key "%s" contains characters not allowed in a delay entity name; only A-Z, a-z, 0-9, ".", "_" and "-" are permitted.',
                $routingKey,
            ));
        }
    }

    private function createDelayQueue(string $queueName, int $delay, string $routingKey): void
    {
        $queue = $this->factory->createQueue($this->connection->getChannel());
        $queue->setName($queueName);
        $queue->setFlags(\AMQP_DURABLE);
        $queue->setArgument('x-message-ttl', $delay);
        // No x-dead-letter-routing-key: the dead-lettered message keeps its own
        // routing key, so the queue's properties do not depend on which routing
        // key happened to create it (#276).
        $queue->setArgument('x-dead-letter-exchange', $this->options['exchange'] ?? '');
        $queue->declareQueue();
        $queue->bind($this->delayExchangeName, $routingKey);
    }

    /**
     * Binds the delay queue to the delay exchange for one routing key.
     *
     * A custom `queue_name_pattern` without `{queue}` maps several routing keys
     * to one queue; each key needs its own binding, and the cached queue
     * declaration alone would otherwise leave later keys unroutable (#276).
     */
    private function ensureDelayBinding(string $queueName, string $routingKey): void
    {
        if (isset($this->delayQueueBindings[$queueName][$routingKey])) {
            return;
        }

        $queue = $this->factory->createQueue($this->connection->getChannel());
        $queue->setName($queueName);
        $queue->setFlags(\AMQP_DURABLE);
        $queue->bind($this->delayExchangeName, $routingKey);

        $this->delayQueueBindings[$queueName][$routingKey] = true;
    }
}
