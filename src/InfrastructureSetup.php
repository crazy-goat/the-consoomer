<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

class InfrastructureSetup
{
    private bool $setupPerformed = false;

    public function __construct(
        private readonly AmqpFactoryInterface $factory,
        private readonly \AMQPConnection $connection,
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

        $channel = $this->factory->createChannel($this->connection);

        $exchange = $this->factory->createExchange($channel);
        $exchange->setName($this->options['exchange']);
        $type = match ($this->options['exchange_type'] ?? 'direct') {
            'fanout' => AMQP_EX_TYPE_FANOUT,
            'topic' => AMQP_EX_TYPE_TOPIC,
            'headers' => AMQP_EX_TYPE_HEADERS,
            default => AMQP_EX_TYPE_DIRECT,
        };
        $exchange->setType($type);
        $exchange->setFlags(AMQP_DURABLE);
        $exchange->declareExchange();

        $queue = $this->factory->createQueue($channel);
        $queue->setName($this->options['queue']);
        $queue->setFlags(AMQP_DURABLE);
        $queue->declareQueue();

        $routingKey = $this->options['routing_key'] ?? '';
        $queue->bind($exchange->getName(), $routingKey);

        $this->setupPerformed = true;
    }
}
