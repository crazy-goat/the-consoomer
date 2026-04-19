<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Psr\Log\LoggerInterface;

/**
 * Factory interface for creating AMQP resources.
 */
interface AmqpFactoryInterface
{
    /**
     * Creates an AMQP connection.
     *
     * @param array{heartbeat?: int} $options Connection options
     */
    public function createConnection(array $options = []): \AMQPConnection;

    /**
     * Creates an AMQP channel on the given connection.
     *
     * @param \AMQPConnection $connection AMQP connection
     */
    public function createChannel(\AMQPConnection $connection): \AMQPChannel;

    /**
     * Creates an AMQP queue on the given channel.
     *
     * @param \AMQPChannel $channel AMQP channel
     */
    public function createQueue(\AMQPChannel $channel): \AMQPQueue;

    /**
     * Creates an AMQP exchange on the given channel.
     *
     * @param \AMQPChannel $channel AMQP channel
     */
    public function createExchange(\AMQPChannel $channel): \AMQPExchange;

    /**
     * Configures SSL/TLS settings on the connection.
     *
     * @param \AMQPConnection      $connection AMQP connection
     * @param array{
     *     ssl?: bool,
     *     ssl_cert?: string,
     *     ssl_key?: string,
     *     ssl_cacert?: string,
     *     ssl_verify?: bool,
     * } $options SSL configuration options
     * @param LoggerInterface|null $logger Logger instance
     * @throws \InvalidArgumentException When SSL certificate files are not found or not readable
     */
    public function configureSsl(\AMQPConnection $connection, array $options, ?LoggerInterface $logger = null): void;

    /**
     * Checks if CA certificate is configured.
     *
     * @param array{ssl_cacert?: string} $options SSL configuration options
     * @return bool True if CA certificate is configured
     */
    public function hasCaCertConfigured(array $options): bool;
}
