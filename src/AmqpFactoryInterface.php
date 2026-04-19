<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Psr\Log\LoggerInterface;

interface AmqpFactoryInterface
{
    /**
     * @param array{heartbeat?: int} $options
     */
    public function createConnection(array $options = []): \AMQPConnection;

    public function createChannel(\AMQPConnection $connection): \AMQPChannel;

    public function createQueue(\AMQPChannel $channel): \AMQPQueue;

    public function createExchange(\AMQPChannel $channel): \AMQPExchange;

    /**
     * @param array{
     *     ssl?: bool,
     *     ssl_cert?: string,
     *     ssl_key?: string,
     *     ssl_cacert?: string,
     *     ssl_verify?: bool,
     * } $options
     */
    public function configureSsl(\AMQPConnection $connection, array $options, ?LoggerInterface $logger = null): void;

    /**
     * @param array{ssl_cacert?: string} $options
     */
    public function hasCaCertConfigured(array $options): bool;
}
