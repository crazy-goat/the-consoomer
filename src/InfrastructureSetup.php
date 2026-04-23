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
    private bool $setupPerformed = false;

    /**
     * @param array{
     *     exchange: string,
     *     queue: string,
     *     exchange_type?: string,
     *     routing_key?: string,
     *     queue_arguments?: array<string, mixed>,
     *     exchange_flags?: int,
     *     queue_flags?: int,
     *     exchange_bindings?: array<array{target: string, routing_keys?: list<string>}>,
     * } $options
     */
    public function __construct(
        private readonly AmqpFactoryInterface $factory,
        private readonly ConnectionInterface $connection,
        private readonly array $options,
    ) {
        if (!isset($options['exchange']) || !isset($options['queue'])) {
            throw new \InvalidArgumentException('exchange and queue options are required');
        }

        if (isset($options['exchange_bindings'])) {
            $this->validateExchangeBindings($options['exchange_bindings']);
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
        $exchange->setFlags(\AMQP_DURABLE | ($this->options['exchange_flags'] ?? 0));
        $exchange->declareExchange();

        $queue = $this->factory->createQueue($channel);
        $queue->setName($this->options['queue']);
        $queue->setFlags(\AMQP_DURABLE | ($this->options['queue_flags'] ?? 0));
        if (isset($this->options['queue_arguments'])) {
            $queue->setArguments($this->options['queue_arguments']);
        }
        $queue->declareQueue();

        $routingKey = $this->options['routing_key'] ?? '';
        $queue->bind($exchange->getName(), $routingKey);

        $this->setupExchangeBindings($exchange);

        $this->setupPerformed = true;
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
