<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

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

    public function configureSsl(\AMQPConnection $connection, array $options): void
    {
        if (empty($options['ssl'])) {
            return;
        }

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
        }
        if (!empty($options['ssl_key'])) {
            $connection->setKey($options['ssl_key']);
        }
        if (!empty($options['ssl_cacert'])) {
            $connection->setCaCert($options['ssl_cacert']);
        }
        if (isset($options['ssl_verify'])) {
            $connection->setVerify($options['ssl_verify']);
        }
    }
}
