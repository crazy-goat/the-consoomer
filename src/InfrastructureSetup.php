<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use CrazyGoat\TheConsoomer\Enum\ExchangeType;

class InfrastructureSetup
{
    private bool $setupPerformed = false;

    public function __construct(
        private readonly AmqpFactoryInterface $factory,
        private readonly Connection $connection,
        private readonly array $options,
    ) {
        if (!isset($options['exchange']) || !isset($options['queue'])) {
            throw new \InvalidArgumentException('exchange and queue options are required');
        }
    }

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
        $queue->declareQueue();

        $routingKey = $this->options['routing_key'] ?? '';
        $queue->bind($exchange->getName(), $routingKey);

        $this->setupPerformed = true;
    }
}
