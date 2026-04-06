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
