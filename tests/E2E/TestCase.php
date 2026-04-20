<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\E2E;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected ?\AMQPConnection $connection = null;
    protected ?\AMQPChannel $channel = null;

    protected function setUp(): void
    {
        $params = $this->getDsnParams();

        $this->connection = new \AMQPConnection();
        $this->connection->setHost($params['host']);
        $this->connection->setPort($params['port']);
        $this->connection->setLogin($params['user']);
        $this->connection->setPassword($params['password']);
        $this->connection->setVhost($params['vhost']);
        $this->connection->connect();

        $this->channel = new \AMQPChannel($this->connection);
    }

    protected function tearDown(): void
    {
        if ($this->connection instanceof \AMQPConnection && $this->connection->isConnected()) {
            $this->connection->disconnect();
        }

        $this->channel = null;
        $this->connection = null;
    }

    protected function declareQueue(string $name, bool $durable = true): void
    {
        $queue = new \AMQPQueue($this->channel);
        $queue->setName($name);
        $queue->setFlags($durable ? AMQP_DURABLE : AMQP_AUTODELETE);
        $queue->declareQueue();
    }

    protected function declareExchange(string $name, string $type = 'direct'): void
    {
        $exchange = new \AMQPExchange($this->channel);
        $exchange->setName($name);
        $exchange->setType($type);
        $exchange->setFlags(AMQP_DURABLE);
        $exchange->declareExchange();
    }

    protected function bindQueue(string $queueName, string $exchangeName, string $routingKey = ''): void
    {
        $queue = new \AMQPQueue($this->channel);
        $queue->setName($queueName);
        $queue->bind($exchangeName, $routingKey);
    }

    protected function purgeQueue(string $name): void
    {
        $queue = new \AMQPQueue($this->channel);
        $queue->setName($name);
        $queue->purge();
    }

    protected function deleteQueue(string $name): void
    {
        $queue = new \AMQPQueue($this->channel);
        $queue->setName($name);
        $queue->delete();
    }

    protected function deleteExchange(string $name): void
    {
        $exchange = new \AMQPExchange($this->channel);
        $exchange->setName($name);
        $exchange->delete();
    }

    protected function publishMessage(string $exchangeName, string $body, string $routingKey = ''): void
    {
        $exchange = new \AMQPExchange($this->channel);
        $exchange->setName($exchangeName);
        $exchange->publish($body, $routingKey);
    }

    /**
     * @return array{host: string, port: int, user: string, password: string, vhost: string}
     */
    protected function getDsnParams(): array
    {
        return [
            'host' => getenv('RABBITMQ_HOST') ?: 'localhost',
            'port' => intval(getenv('RABBITMQ_PORT') ?: 5672),
            'user' => getenv('RABBITMQ_USER') ?: 'guest',
            'password' => getenv('RABBITMQ_PASSWORD') ?: 'guest',
            'vhost' => getenv('RABBITMQ_VHOST') ?: '/',
        ];
    }

    /**
     * @param array<string, string|int|float> $extra
     */
    protected function buildDsn(string $exchange, string $queue, array $extra = []): string
    {
        $params = $this->getDsnParams();

        $queryParams = array_merge(['queue' => $queue], $extra);
        $query = http_build_query($queryParams);

        return sprintf(
            'amqp-consoomer://%s:%s@%s:%d/%s/%s?%s',
            $params['user'],
            $params['password'],
            $params['host'],
            $params['port'],
            urlencode($params['vhost']),
            $exchange,
            $query,
        );
    }
}
