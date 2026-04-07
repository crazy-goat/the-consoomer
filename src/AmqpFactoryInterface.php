<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Psr\Log\LoggerInterface;

interface AmqpFactoryInterface
{
    public function createConnection(array $options = []): \AMQPConnection;

    public function createChannel(\AMQPConnection $connection): \AMQPChannel;

    public function createQueue(\AMQPChannel $channel): \AMQPQueue;

    public function createExchange(\AMQPChannel $channel): \AMQPExchange;

    public function configureSsl(\AMQPConnection $connection, array $options, ?LoggerInterface $logger = null): void;

    public function hasCaCertConfigured(array $options): bool;
}
