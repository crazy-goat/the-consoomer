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
     * @throws \InvalidArgumentException When SSL certificate files are not found, not readable,
     *                                  or ssl_verify is not a valid boolean value
     *
     * When SSL is enabled and verification is on but no CA certificate is configured
     * (no `ssl_cacert`), the connection falls back to the system CA store. A prominent
     * warning is logged via {@see hasCaCertConfigured()}; pin a CA cert in production.
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
        if (is_string($sslVerify) && $sslVerify === '') {
            throw new \InvalidArgumentException('ssl_verify must be a boolean value, got empty string');
        }
        if (!is_bool($sslVerify)) {
            $normalized = filter_var($sslVerify, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($normalized === null) {
                throw new \InvalidArgumentException(sprintf(
                    'ssl_verify must be a boolean value, got "%s"',
                    get_debug_type($sslVerify),
                ));
            }
            $sslVerify = $normalized;
        }
        $connection->setVerify($sslVerify);
        if ($sslVerify) {
            $logger?->debug('SSL verify: enabled');
            // Guard for the "verify on but no CA pinned" case. ext-amqp/rabbitmq-c
            // then falls back to the system CA store; on builds without one the
            // handshake fails silently or verifies against an empty trust set.
            // Drive this from hasCaCertConfigured() so it is no longer dead code. See #231.
            if (!$this->hasCaCertConfigured($options)) {
                $logger?->warning(
                    'SSL peer certificate verification is enabled but no CA certificate (ssl_cacert) is configured. '
                    . 'The connection relies on the system CA store: if the system has no trusted CAs the handshake '
                    . 'will fail, or on some builds verify against an empty trust set. Set ssl_cacert explicitly to pin a CA.',
                );
            }
        } else {
            // Peer certificate validation is off — this is a security-sensitive downgrade.
            // Log at warning (not debug) so it is visible by default. See #286, #231.
            $logger?->warning('SSL peer certificate verification is disabled — the broker identity is not checked, allowing MITM / impersonation.');
        }

        $logger?->info('SSL handshake configured successfully');
    }

    /**
     * {@inheritdoc}
     *
     * Used by {@see configureSsl()} to guard the verify-on-but-no-CA-pinned case.
     *
     * @param array{ssl_cacert?: string} $options SSL configuration options
     * @return bool True if a CA certificate is configured
     */
    public function hasCaCertConfigured(array $options): bool
    {
        return !empty($options['ssl_cacert']);
    }
}
