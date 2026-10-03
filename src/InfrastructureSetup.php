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

    /** @var array<string, mixed> */
    private readonly array $bindingArguments;

    /** @var array<string, array<string, mixed>> Validated by validateQueues() */
    private readonly array $queues;

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
     * accepted and falls back to `direct`.
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
     *     exchange_flags?: mixed,
     *     queue_flags?: mixed,
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

        if (isset($options['exchange_bindings'])) {
            $this->validateExchangeBindings($options['exchange_bindings']);
        }

        if (isset($options['binding_keys'])) {
            $this->validateBindingKeys($options['binding_keys']);
        }

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
        $exchange->setFlags($this->resolveFlags('exchange_flags'));

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
        $queue->setFlags($this->resolveFlags('queue_flags'));
        if (isset($this->options['queue_arguments'])) {
            $queue->setArguments($this->options['queue_arguments']);
        }
        $queue->declareQueue();

        $bindingKeys = $this->options['binding_keys'] ?? [$this->options['routing_key'] ?? ''];
        $bindingArguments = $this->bindingArguments;
        foreach ($bindingKeys as $bindingKey) {
            $queue->bind($this->exchange, $bindingKey, $bindingArguments);
        }
    }

    private function setupMultipleQueues(\AMQPChannel $channel): void
    {
        foreach ($this->queues as $queueName => $queueConfig) {
            $queue = $this->factory->createQueue($channel);
            $queue->setName($queueName);
            $queue->setFlags($this->resolveFlags('queue_flags'));

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
     * not have to re-derive the shape from the raw options array.
     *
     * @return array<string, array<string, mixed>>
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

        foreach ($queues as $name => $config) {
            if (!is_string($name) || $name === '') {
                throw new \InvalidArgumentException('Each queue name must be a non-empty string');
            }

            if (!is_array($config)) {
                throw new \InvalidArgumentException(sprintf('queues[%s] must be an array', $name));
            }

            if (isset($config['binding_keys'])) {
                if (!is_array($config['binding_keys'])) {
                    throw new \InvalidArgumentException(sprintf('queues[%s].binding_keys must be an array', $name));
                }

                foreach ($config['binding_keys'] as $keyIndex => $key) {
                    if (!is_string($key)) {
                        throw new \InvalidArgumentException(sprintf('queues[%s].binding_keys[%d] must be a string', $name, $keyIndex));
                    }
                }
            }

            if (isset($config['binding_arguments']) && !is_array($config['binding_arguments'])) {
                throw new \InvalidArgumentException(sprintf('queues[%s].binding_arguments must be an array', $name));
            }

            if (isset($config['arguments']) && !is_array($config['arguments'])) {
                throw new \InvalidArgumentException(sprintf('queues[%s].arguments must be an array', $name));
            }
        }

        return $queues;
    }

    private function setupExchangeBindings(\AMQPExchange $exchange): void
    {
        $bindings = $this->options['exchange_bindings'] ?? [];

        foreach ($bindings as $binding) {
            $target = $binding['target'];
            $routingKeys = $binding['routing_keys'] ?? [''];

            foreach ($routingKeys as $routingKey) {
                $exchange->bind($target, $routingKey);
            }
        }
    }

    private function resolveFlags(string $optionName): int
    {
        $flags = (int) ($this->options[$optionName] ?? 0);
        if ($this->options['durable'] ?? true) {
            $flags |= \AMQP_DURABLE;
        }
        return $flags;
    }
    /**
     * @throws \InvalidArgumentException
     */
    private function validateBindingKeys(mixed $bindingKeys): void
    {
        if (!is_array($bindingKeys)) {
            throw new \InvalidArgumentException('binding_keys must be an array');
        }

        if ($bindingKeys === []) {
            throw new \InvalidArgumentException('binding_keys must not be empty');
        }

        foreach ($bindingKeys as $index => $key) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException(sprintf('binding_keys[%d] must be a string', $index));
            }
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function validateExchangeBindings(mixed $bindings): void
    {
        if (!is_array($bindings)) {
            throw new \InvalidArgumentException('exchange_bindings must be an array');
        }

        foreach ($bindings as $index => $binding) {
            if (!is_array($binding)) {
                throw new \InvalidArgumentException(sprintf('exchange_bindings[%d] must be an array', $index));
            }

            if (!isset($binding['target']) || !is_string($binding['target']) || $binding['target'] === '') {
                throw new \InvalidArgumentException(sprintf('exchange_bindings[%d].target must be a non-empty string', $index));
            }

            if (isset($binding['routing_keys'])) {
                if (!is_array($binding['routing_keys'])) {
                    throw new \InvalidArgumentException(sprintf('exchange_bindings[%d].routing_keys must be an array', $index));
                }

                if ($binding['routing_keys'] === []) {
                    throw new \InvalidArgumentException(sprintf('exchange_bindings[%d].routing_keys must not be empty', $index));
                }

                foreach ($binding['routing_keys'] as $keyIndex => $key) {
                    if (!is_string($key)) {
                        throw new \InvalidArgumentException(sprintf('exchange_bindings[%d].routing_keys[%d] must be a string', $index, $keyIndex));
                    }
                }
            }
        }
    }
}
