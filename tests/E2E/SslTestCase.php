<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\E2E;

abstract class SslTestCase extends TestCase
{
    protected function setUp(): void
    {
        $host = getenv('RABBITMQ_HOST') ?: 'localhost';
        $port = intval(getenv('RABBITMQ_SSL_PORT') ?: 5671);
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $password = getenv('RABBITMQ_PASSWORD') ?: 'guest';
        $vhost = getenv('RABBITMQ_VHOST') ?: '/';
        $caCert = getenv('RABBITMQ_SSL_CA_CERT') ?: __DIR__ . '/ssl/ca_certificate.pem';

        $this->connection = new \AMQPConnection();
        $this->connection->setHost($host);
        $this->connection->setPort($port);
        $this->connection->setLogin($user);
        $this->connection->setPassword($password);
        $this->connection->setVhost($vhost);
        $this->connection->setCaCert($caCert);
        $this->connection->setVerify(true);
        $this->connection->connect();

        $this->channel = new \AMQPChannel($this->connection);
    }
}
