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

    private bool $setupPerformed = false;

    /**
     * @param array{
     *     exchange: string,
     *     queue?: string,
     *     queues?: array<string, array{binding_keys?: list<string>, binding_arguments?: array<string, mixed>, arguments?: array<string, mixed>}>,
     *     exchange_type?: string,
     *     routing_key?: string,
     *     binding_keys?: list<string>,
     *     binding_arguments?: array<string, mixed>,
     *     queue_arguments?: array<string, mixed>,
     *     exchange_flags?: int,
     *     queue_flags?: int,
     *     exchange_bindings?: array<array{target: string, routing_keys?: list<string>}>,
     *     retry_exchange?: string,
     *     retry_queue_arguments?: array<string, mixed>,
     *     durable?: bool,
     * } $options
     */
    public function __construct(
        private readonly AmqpFactoryInterface $factory,
        private readonly ConnectionInterface $connection,
        private readonly array $options,
    ) {
        if (!isset($options['exchange'])) {
            throw new \InvalidArgumentException('exchange option is required');
        }

        if (!isset($options['queue']) && !isset($options['queues'])) {
            throw new \InvalidArgumentException('either queue or queues option is required');
        }

        if (isset($options['queues'])) {
            $this->validateQueues($options['queues']);
        }

        if (isset($options['exchange_bindings'])) {
            $this->validateExchangeBindings($options['exchange_bindings']);
        }

        if (isset($options['binding_keys'])) {
            $this->validateBindingKeys($options['binding_keys']);
        }

        if (isset($options['binding_arguments']) && !is_array($options['binding_arguments'])) {
            throw new \InvalidArgumentException('binding_arguments must be an array');
        }

        foreach (self::ALLOWED_OPTION_KEYS as $key) {
            if (isset($options[$key]) && is_int($options[$key]) && ($options[$key] & self::FORBIDDEN_FLAGS) !== 0) {
                throw new \InvalidArgumentException(sprintf(
                    '%s must not contain AMQP_EXCLUSIVE or AMQP_AUTODELETE flags (got %d)',
                    $key,
                    $options[$key],
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
        if ($this->setupPerformed) {
            return;
        }

        $channel = $this->connection->getChannel();

        $exchange = $this->factory->createExchange($channel);
        $exchange->setName($this->options['exchange']);
        $type = match (ExchangeType::tryFrom((string) ($this->options['exchange_type'] ?? 'direct'))) {
            ExchangeType::FANOUT => \AMQP_EX_TYPE_FANOUT,
            ExchangeType::TOPIC => \AMQP_EX_TYPE_TOPIC,
            ExchangeType::HEADERS => \AMQP_EX_TYPE_HEADERS,
            default => \AMQP_EX_TYPE_DIRECT,
        };
        $exchange->setType($type);
        $exchange->setFlags($this->resolveFlags('exchange_flags'));
        $exchange->declareExchange();

        $this->setupQueues($channel, $exchange);
        $this->setupExchangeBindings($exchange);
        $this->setupRetryQueue();

        $this->setupPerformed = true;
    }

    public function resetSetup(): void
    {
        $this->setupPerformed = false;
    }

    /**
     * Creates and binds queues based on configuration.
     *
     * Supports both single queue (via 'queue' option) and multiple queues
     * (via 'queues' option). When 'queues' is provided, each queue can have
     * its own binding_keys, binding_arguments, and arguments.
     */
    private function setupQueues(\AMQPChannel $channel, \AMQPExchange $exchange): void
    {
        if (isset($this->options['queues'])) {
            $this->setupMultipleQueues($channel, $exchange);
        } else {
            $this->setupSingleQueue($channel, $exchange);
        }
    }

    private function setupSingleQueue(\AMQPChannel $channel, \AMQPExchange $exchange): void
    {
        $queue = $this->factory->createQueue($channel);
        $queue->setName($this->options['queue']);
        $queue->setFlags($this->resolveFlags('queue_flags'));
        if (isset($this->options['queue_arguments'])) {
            $queue->setArguments($this->options['queue_arguments']);
        }
        $queue->declareQueue();

        $bindingKeys = $this->options['binding_keys'] ?? [$this->options['routing_key'] ?? ''];
        $bindingArguments = $this->options['binding_arguments'] ?? [];
        foreach ($bindingKeys as $bindingKey) {
            $queue->bind($exchange->getName(), $bindingKey, $bindingArguments);
        }
    }

    private function setupMultipleQueues(\AMQPChannel $channel, \AMQPExchange $exchange): void
    {
        foreach ($this->options['queues'] as $queueName => $queueConfig) {
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
                $queue->bind($exchange->getName(), $bindingKey, $bindingArguments);
            }
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function validateQueues(mixed $queues): void
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

    private function setupRetryQueue(): void
    {
        if (isset($this->options['queue'])) {
            $queues = [$this->options['queue'] => ['binding_keys' => [$this->options['routing_key'] ?? '']]];
        } elseif (isset($this->options['queues'])) {
            $queues = $this->options['queues'];
        } else {
            return;
        }

        $retryExchangeName = $this->options['retry_exchange'] ?? $this->options['exchange'] . '_retry';
        $retryExchange = $this->factory->createExchange($this->connection->getChannel());
        $retryExchange->setName($retryExchangeName);
        $retryExchange->setType(\AMQP_EX_TYPE_DIRECT);
        $retryExchange->setFlags(\AMQP_DURABLE);
        $retryExchange->declareExchange();

        foreach ($queues as $queueName => $queueConfig) {
            $bindingKeys = $queueConfig['binding_keys'] ?? [''];
            $routingKey = $bindingKeys[0] ?? '';
            $retryQueueName = $queueName . '_retry';

            $retryQueue = $this->factory->createQueue($this->connection->getChannel());
            $retryQueue->setName($retryQueueName);
            $retryQueue->setFlags(\AMQP_DURABLE);
            $retryQueue->setArguments($this->options['retry_queue_arguments'] ?? [
                'x-dead-letter-exchange' => $this->options['exchange'],
                'x-dead-letter-routing-key' => $routingKey,
            ]);
            $retryQueue->declareQueue();
            $retryQueue->bind($retryExchangeName, $routingKey . '_retry');
        }
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
