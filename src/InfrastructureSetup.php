<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use CrazyGoat\TheConsoomer\Enum\ExchangeType;

/**
 * Handles AMQP infrastructure setup (exchanges, queues, bindings).
 *
 * Declares durable exchanges and queues with configurable options.
 * Idempotent - safe to call multiple times (setup runs only once).
 */
final class InfrastructureSetup implements InfrastructureSetupInterface
{
    private const FORBIDDEN_FLAGS = \AMQP_EXCLUSIVE | \AMQP_AUTODELETE;
    private const ALLOWED_OPTION_KEYS = ['exchange_flags', 'queue_flags'];

    private bool $exchangeSetupPerformed = false;
    private bool $queuesSetupPerformed = false;

    /**
     * The validated values, kept as properties so the rest of the class does
     * not have to re-check the raw option array.
     */
    private readonly string $exchange;

    /**
     * AMQP argument table shared by every single-queue binding.
     *
     * `is_array()` is the only check {@see validateQueues()} applies to a
     * `binding_arguments` value, so the key type is deliberately not promised
     * here: ext-amqp accepts the table and drops an entry whose key is not a
     * string ("Ignoring non-string header field"), and narrowing this property
     * to `array<string, mixed>` would describe a promise the constructor does
     * not keep.
     *
     * @var array<array-key, mixed>
     */
    private readonly array $bindingArguments;

    /** @var array<array-key, string> Validated by validateBindingKeys() */
    private readonly array $bindingKeys;

    /**
     * Per-queue configuration in the shape validateQueues() proved: every
     * present key has been checked and narrowed.
     *
     * @var array<string, array{
     *     binding_keys?: array<array-key, string>,
     *     binding_arguments?: array<array-key, mixed>,
     *     arguments?: array<array-key, mixed>,
     * }>
     */
    private readonly array $queues;

    /** @var list<array{target: string, routing_keys: array<array-key, string>}> Validated by validateExchangeBindings() */
    private readonly array $exchangeBindings;

    /**
     * The option array comes straight from the transport factory, i.e. from
     * DSN query parameters merged with programmatic options. Those are untyped
     * input, and validating them is this constructor's job: every option listed
     * as `mixed` below is checked here (or by validateQueues() /
     * validateExchangeBindings() / validateBindingKeys()) and is stored in a
     * typed property once it passes. Declaring them any narrower would only
     * describe the happy path and hide the guards that produce the readable
     * InvalidArgumentException instead of a TypeError further down.
     *
     * `exchange_type` is not validated here, so it stays typed; `null` is
     * accepted and falls back to `direct`. The two `*_flags` options are read
     * with an `(int)` cast ({@see resolveFlags()}), so they accept an int or a
     * numeric string but promise nothing beyond that.
     *
     * @param array{
     *     exchange?: mixed,
     *     queue?: string,
     *     queues?: mixed,
     *     exchange_type?: string|null,
     *     routing_key?: string,
     *     binding_keys?: mixed,
     *     binding_arguments?: mixed,
     *     queue_arguments?: array<string, mixed>,
     *     exchange_flags?: int|string,
     *     queue_flags?: int|string,
     *     exchange_bindings?: mixed,
     *     durable?: bool|int,
     * } $options
     * @throws \InvalidArgumentException When exchange is missing or an option has the wrong type
     */
    public function __construct(
        private readonly AmqpFactoryInterface $factory,
        private readonly ConnectionInterface $connection,
        private readonly array $options,
    ) {
        if (!isset($options['exchange'])) {
            throw new \InvalidArgumentException('exchange option is required');
        }
        // Both properties are readonly and assigned exactly once, so the
        // is_array()/is_string() refinements below are what the rest of the
        // class relies on.
        $exchange = $options['exchange'];
        if (!is_string($exchange)) {
            throw new \InvalidArgumentException('exchange must be a string');
        }
        $this->exchange = $exchange;

        $bindingArguments = $options['binding_arguments'] ?? [];
        if (!is_array($bindingArguments)) {
            throw new \InvalidArgumentException('binding_arguments must be an array');
        }
        $this->bindingArguments = $bindingArguments;

        // A queue is only needed to declare consumer topology, so it is required
        // by declareQueues()/setup() rather than the constructor. A send-only
        // transport (auto_setup=false, never consumes) can be created without a
        // dummy queue (#279).

        $this->queues = isset($options['queues'])
            ? $this->validateQueues($options['queues'])
            : [];

        $this->exchangeBindings = isset($options['exchange_bindings'])
            ? $this->validateExchangeBindings($options['exchange_bindings'])
            : [];

        $this->bindingKeys = isset($options['binding_keys'])
            ? $this->validateBindingKeys($options['binding_keys'])
            : [($options['routing_key'] ?? '')];

        foreach (self::ALLOWED_OPTION_KEYS as $key) {
            // Read the flag once: the guard below has to look at the same value
            // three times, and re-reading the offset after is_int() makes the
            // narrowing impossible to follow.
            $flags = $options[$key] ?? null;
            if (is_int($flags) && ($flags & self::FORBIDDEN_FLAGS) !== 0) {
                throw new \InvalidArgumentException(sprintf(
                    '%s must not contain AMQP_EXCLUSIVE or AMQP_AUTODELETE flags (got %d)',
                    $key,
                    $flags,
                ));
            }
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws \InvalidArgumentException When exchange or queue is not configured
     * @throws \AMQPException When AMQP declaration fails
     */
    public function setup(): void
    {
        if ($this->exchangeSetupPerformed && $this->queuesSetupPerformed) {
            return;
        }

        $channel = $this->connection->getChannel();
        $exchange = $this->exchangeSetupPerformed
            ? $this->createExchange($channel)
            : $this->declareExchange($channel);
        $this->exchangeSetupPerformed = true;

        if (!$this->queuesSetupPerformed) {
            $this->declareQueues($channel);
            $this->setupExchangeBindings($exchange);
            $this->queuesSetupPerformed = true;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setupExchange(): void
    {
        if ($this->exchangeSetupPerformed) {
            return;
        }

        $this->declareExchange($this->connection->getChannel());
        $this->exchangeSetupPerformed = true;
    }

    /**
     * {@inheritdoc}
     */
    public function setupQueues(): void
    {
        if ($this->queuesSetupPerformed) {
            return;
        }

        $channel = $this->connection->getChannel();
        $exchange = $this->exchangeSetupPerformed
            ? $this->createExchange($channel)
            : $this->declareExchange($channel);
        $this->exchangeSetupPerformed = true;

        $this->declareQueues($channel);
        $this->setupExchangeBindings($exchange);
        $this->queuesSetupPerformed = true;
    }

    public function resetSetup(): void
    {
        $this->exchangeSetupPerformed = false;
        $this->queuesSetupPerformed = false;
    }

    /**
     * Builds (but does not declare) the configured exchange.
     */
    private function createExchange(\AMQPChannel $channel): \AMQPExchange
    {
        $exchange = $this->factory->createExchange($channel);
        $exchange->setName($this->exchange);
        $exchange->setType(match (ExchangeType::tryFrom((string) ($this->options['exchange_type'] ?? 'direct'))) {
            ExchangeType::FANOUT => \AMQP_EX_TYPE_FANOUT,
            ExchangeType::TOPIC => \AMQP_EX_TYPE_TOPIC,
            ExchangeType::HEADERS => \AMQP_EX_TYPE_HEADERS,
            default => \AMQP_EX_TYPE_DIRECT,
        });
        $exchange->setFlags($this->resolveFlags($this->options['exchange_flags'] ?? 0));

        return $exchange;
    }

    /**
     * Declares the configured exchange (and nothing else).
     */
    private function declareExchange(\AMQPChannel $channel): \AMQPExchange
    {
        $exchange = $this->createExchange($channel);
        $exchange->declareExchange();

        return $exchange;
    }

    /**
     * Creates and binds queues based on configuration.
     *
     * Supports both single queue (via 'queue' option) and multiple queues
     * (via 'queues' option). When 'queues' is provided, each queue can have
     * its own binding_keys, binding_arguments, and arguments.
     */
    private function declareQueues(\AMQPChannel $channel): void
    {
        // The queue name is resolved here and handed to setupSingleQueue(),
        // rather than re-read from the raw options there: the guarantee that it
        // exists is established right here, so it should travel with the value.
        if ($this->queues !== []) {
            $this->setupMultipleQueues($channel);

            return;
        }

        $queueName = $this->options['queue'] ?? null;
        if ($queueName === null) {
            throw new \InvalidArgumentException('either queue or queues option is required to declare consumer topology');
        }

        $this->setupSingleQueue($channel, $queueName);
    }

    private function setupSingleQueue(\AMQPChannel $channel, string $queueName): void
    {
        $queue = $this->factory->createQueue($channel);
        $queue->setName($queueName);
        $queue->setFlags($this->resolveFlags($this->options['queue_flags'] ?? 0));
        if (isset($this->options['queue_arguments'])) {
            $queue->setArguments($this->options['queue_arguments']);
        }
        $queue->declareQueue();

        foreach ($this->bindingKeys as $bindingKey) {
            $queue->bind($this->exchange, $bindingKey, $this->bindingArguments);
        }
    }

    private function setupMultipleQueues(\AMQPChannel $channel): void
    {
        foreach ($this->queues as $queueName => $queueConfig) {
            $queue = $this->factory->createQueue($channel);
            $queue->setName($queueName);
            $queue->setFlags($this->resolveFlags($this->options['queue_flags'] ?? 0));

            $queueArgs = $queueConfig['arguments'] ?? $this->options['queue_arguments'] ?? null;
            if ($queueArgs !== null) {
                $queue->setArguments($queueArgs);
            }
            $queue->declareQueue();

            $bindingKeys = $queueConfig['binding_keys'] ?? [''];
            $bindingArguments = $queueConfig['binding_arguments'] ?? [];
            foreach ($bindingKeys as $bindingKey) {
                $queue->bind($this->exchange, $bindingKey, $bindingArguments);
            }
        }
    }

    /**
     * Validates the `queues` option and returns it in its known-good shape.
     *
     * Returning the validated value (instead of only asserting on it) lets the
     * constructor store it in a typed property, so setupMultipleQueues() does
     * not have to re-derive the shape from the raw options array. The narrowed
     * array is rebuilt rather than returned as-is because the checks narrow the
     * individual values, not the array's value types.
     *
     * @return array<string, array{
     *     binding_keys?: array<array-key, string>,
     *     binding_arguments?: array<array-key, mixed>,
     *     arguments?: array<array-key, mixed>,
     * }>
     * @throws \InvalidArgumentException
     */
    private function validateQueues(mixed $queues): array
    {
        if (!is_array($queues)) {
            throw new \InvalidArgumentException('queues option must be an array');
        }

        if ($queues === []) {
            throw new \InvalidArgumentException('queues option must not be empty');
        }

        $validated = [];
        foreach ($queues as $name => $config) {
            if (!is_string($name) || $name === '') {
                throw new \InvalidArgumentException('Each queue name must be a non-empty string');
            }

            if (!is_array($config)) {
                throw new \InvalidArgumentException(sprintf('queues[%s] must be an array', $name));
            }

            $queue = [];

            if (isset($config['binding_keys'])) {
                $bindingKeys = $config['binding_keys'];
                if (!is_array($bindingKeys)) {
                    throw new \InvalidArgumentException(sprintf('queues[%s].binding_keys must be an array', $name));
                }

                $keys = [];
                foreach ($bindingKeys as $keyIndex => $key) {
                    if (!is_string($key)) {
                        throw new \InvalidArgumentException(sprintf('queues[%s].binding_keys[%d] must be a string', $name, $keyIndex));
                    }
                    $keys[$keyIndex] = $key;
                }
                $queue['binding_keys'] = $keys;
            }

            if (isset($config['binding_arguments'])) {
                $bindingArguments = $config['binding_arguments'];
                if (!is_array($bindingArguments)) {
                    throw new \InvalidArgumentException(sprintf('queues[%s].binding_arguments must be an array', $name));
                }
                $queue['binding_arguments'] = $bindingArguments;
            }

            if (isset($config['arguments'])) {
                $arguments = $config['arguments'];
                if (!is_array($arguments)) {
                    throw new \InvalidArgumentException(sprintf('queues[%s].arguments must be an array', $name));
                }
                $queue['arguments'] = $arguments;
            }

            $validated[$name] = $queue;
        }

        return $validated;
    }

    private function setupExchangeBindings(\AMQPExchange $exchange): void
    {
        foreach ($this->exchangeBindings as $binding) {
            foreach ($binding['routing_keys'] as $routingKey) {
                $exchange->bind($binding['target'], $routingKey);
            }
        }
    }

    /**
     * Flags for the exchange or the queue: the configured bitmask, plus
     * AMQP_DURABLE unless `durable` is turned off.
     *
     * Takes the value rather than the option name so the read happens against a
     * literal key — that is what gives the `(int)` cast a typed operand instead
     * of an unchecked `mixed`.
     */
    private function resolveFlags(int|string $flags): int
    {
        $resolved = (int) $flags;
        if ($this->options['durable'] ?? true) {
            $resolved |= \AMQP_DURABLE;
        }
        return $resolved;
    }

    /**
     * @return array<array-key, string>
     * @throws \InvalidArgumentException
     */
    private function validateBindingKeys(mixed $bindingKeys): array
    {
        if (!is_array($bindingKeys)) {
            throw new \InvalidArgumentException('binding_keys must be an array');
        }

        if ($bindingKeys === []) {
            throw new \InvalidArgumentException('binding_keys must not be empty');
        }

        $validated = [];
        foreach ($bindingKeys as $index => $key) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException(sprintf('binding_keys[%d] must be a string', $index));
            }
            $validated[$index] = $key;
        }

        return $validated;
    }

    /**
     * Validates the `exchange_bindings` option and returns it with every field
     * narrowed, so setupExchangeBindings() reads typed values instead of
     * re-deriving them from the raw options array.
     *
     * @return list<array{target: string, routing_keys: array<array-key, string>}>
     * @throws \InvalidArgumentException
     */
    private function validateExchangeBindings(mixed $bindings): array
    {
        if (!is_array($bindings)) {
            throw new \InvalidArgumentException('exchange_bindings must be an array');
        }

        $validated = [];
        foreach ($bindings as $index => $binding) {
            if (!is_array($binding)) {
                throw new \InvalidArgumentException(sprintf('exchange_bindings[%d] must be an array', $index));
            }

            $target = $binding['target'] ?? null;
            if (!is_string($target) || $target === '') {
                throw new \InvalidArgumentException(sprintf('exchange_bindings[%d].target must be a non-empty string', $index));
            }

            $routingKeys = $binding['routing_keys'] ?? null;
            if ($routingKeys === null) {
                $routingKeys = [''];
            } else {
                if (!is_array($routingKeys)) {
                    throw new \InvalidArgumentException(sprintf('exchange_bindings[%d].routing_keys must be an array', $index));
                }

                if ($routingKeys === []) {
                    throw new \InvalidArgumentException(sprintf('exchange_bindings[%d].routing_keys must not be empty', $index));
                }

                $keys = [];
                foreach ($routingKeys as $keyIndex => $key) {
                    if (!is_string($key)) {
                        throw new \InvalidArgumentException(sprintf('exchange_bindings[%d].routing_keys[%d] must be a string', $index, $keyIndex));
                    }
                    $keys[$keyIndex] = $key;
                }
                $routingKeys = $keys;
            }

            $validated[] = ['target' => $target, 'routing_keys' => $routingKeys];
        }

        return $validated;
    }
}
