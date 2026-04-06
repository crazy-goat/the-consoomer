<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Psr\Log\LoggerInterface;

class AmqpFactory implements AmqpFactoryInterface
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
        if (isset($options['ssl_verify'])) {
            $connection->setVerify($options['ssl_verify']);
            $logger?->debug('SSL verify: {verify}', ['verify' => $options['ssl_verify'] ? 'enabled' : 'disabled']);
        }

        $logger?->info('SSL handshake configured successfully');
    }

    public function hasCaCertConfigured(array $options): bool
    {
        return !empty($options['ssl_cacert']);
    }
}
