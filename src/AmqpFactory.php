<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

class AmqpFactory
{
    public function createConnection(): \AMQPConnection
    {
        return new \AMQPConnection();
    }

    public function createChannel(\AMQPConnection $connection): \AMQPChannel
    {
        return new \AMQPChannel($connection);
    }

    public function createQueue(\AMQPChannel $channel): \AMQPQueue
    {
        return new \AMQPQueue($channel);
    }

    public function createExchange(\AMQPChannel $channel): \AMQPExchange
    {
        return new \AMQPExchange($channel);
    }
}
