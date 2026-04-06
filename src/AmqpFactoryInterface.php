<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

interface AmqpFactoryInterface
{
    public function createConnection(): \AMQPConnection;

    public function createChannel(\AMQPConnection $connection): \AMQPChannel;

    public function createQueue(\AMQPChannel $channel): \AMQPQueue;

    public function createExchange(\AMQPChannel $channel): \AMQPExchange;
}
