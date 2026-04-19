<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Psr\Log\LoggerInterface;

/**
 * Factory for creating AMQP resources (connections, channels, queues, exchanges).
 *
 * Provides SSL/TLS configuration support and centralized resource creation.
 */
class AmqpFactory implements AmqpFactoryInterface
{
    /**
     * {@inheritdoc}
     *
     * @param array{heartbeat?: int} $options Connection options
     */
    public function createConnection(array $options = []): \AMQPConnection
    {
        $connectionOptions = [];
        if (isset($options['heartbeat'])) {
            $connectionOptions['heartbeat'] = $options['heartbeat'];
        }

        return new \AMQPConnection($connectionOptions);
    }

    /**
     * {@inheritdoc}
     *
     * @param \AMQPConnection $connection AMQP connection
     */
    public function createChannel(\AMQPConnection $connection): \AMQPChannel
    {
        return new \AMQPChannel($connection);
    }

    /**
     * {@inheritdoc}
     *
     * @param \AMQPChannel $channel AMQP channel
     */
    public function createQueue(\AMQPChannel $channel): \AMQPQueue
    {
        return new \AMQPQueue($channel);
    }

    /**
     * {@inheritdoc}
     *
     * @param \AMQPChannel $channel AMQP channel
     */
    public function createExchange(\AMQPChannel $channel): \AMQPExchange
    {
        return new \AMQPExchange($channel);
    }

    /**
     * {@inheritdoc}
     *
     * Configures SSL/TLS settings on the AMQP connection.
     *
     * @param \AMQPConnection      $connection AMQP connection to configure
     * @param array{
     *     ssl?: bool,
     *     ssl_cert?: string,
     *     ssl_key?: string,
     *     ssl_cacert?: string,
     *     ssl_verify?: bool,
     * } $options SSL configuration options
     * @param LoggerInterface|null $logger    Logger instance
     * @throws \InvalidArgumentException When SSL certificate files are not found or not readable
     */
    public function configureSsl(\AMQPConnection $connection, array $options, ?LoggerInterface $logger = null): void
    {
        if (empty($options['ssl'])) {
            return;
        }

        $logger?->info('SSL/TLS enabled for connection');

        $certFiles = [
            'ssl_cert' => $options['ssl_cert'] ?? '',
            'ssl_key' => $options['ssl_key'] ?? '',
            'ssl_cacert' => $options['ssl_cacert'] ?? '',
        ];

        foreach ($certFiles as $type => $path) {
            if ($path !== '' && !file_exists($path)) {
                throw new \InvalidArgumentException("SSL {$type} file not found: {$path}");
            }
            if ($path !== '' && !is_readable($path)) {
                throw new \InvalidArgumentException("SSL {$type} file not readable: {$path}");
            }
        }

        if (!empty($options['ssl_cert'])) {
            $connection->setCert($options['ssl_cert']);
            $logger?->debug('Using SSL certificate: {cert}', ['cert' => $options['ssl_cert']]);
        }
        if (!empty($options['ssl_key'])) {
            $connection->setKey($options['ssl_key']);
            $logger?->debug('Using SSL key: {key}', ['key' => $options['ssl_key']]);
        }
        if (!empty($options['ssl_cacert'])) {
            $connection->setCaCert($options['ssl_cacert']);
            $logger?->debug('Using SSL CA certificate: {cacert}', ['cacert' => $options['ssl_cacert']]);
        }

        $sslVerify = $options['ssl_verify'] ?? true;
        $connection->setVerify($sslVerify);
        $logger?->debug('SSL verify: {verify}', ['verify' => $sslVerify ? 'enabled' : 'disabled']);

        $logger?->info('SSL handshake configured successfully');
    }

    /**
     * {@inheritdoc}
     *
     * @param array{ssl_cacert?: string} $options SSL configuration options
     * @return bool True if CA certificate is configured
     */
    public function hasCaCertConfigured(array $options): bool
    {
        return !empty($options['ssl_cacert']);
    }
}
