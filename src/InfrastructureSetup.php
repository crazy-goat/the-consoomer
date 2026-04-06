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
    ) {}

    public function setup(): void
    {
        if ($this->setupPerformed) {
            return;
        }

        $channel = $this->factory->createChannel($this->connection);

        $exchange = $this->factory->createExchange($channel);
        $exchange->setName($this->options['exchange'] ?? '');
        $exchange->setType(AMQP_EX_TYPE_DIRECT);
        $exchange->declareExchange();

        $queue = $this->factory->createQueue($channel);
        $queue->setName($this->options['queue'] ?? '');
        $queue->declareQueue();

        $routingKey = $this->options['routing_key'] ?? '';
        $queue->bind($exchange->getName(), $routingKey);

        $this->setupPerformed = true;
    }
}
