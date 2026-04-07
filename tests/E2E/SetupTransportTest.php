<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\E2E;

use CrazyGoat\TheConsoomer\AmqpTransport;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

class SetupTransportTest extends TestCase
{
    private const EXCHANGE_NAME = 'test_setup_exchange';
    private const ROUTING_KEY = 'test_setup_key';
    private string $queueName;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queueName = 'test_setup_queue_' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->deleteQueue($this->queueName);
        $this->deleteExchange(self::EXCHANGE_NAME);
        parent::tearDown();
    }

    public function testSetupMethodCreatesExchangeAndQueue(): void
    {
        $host = getenv('RABBITMQ_HOST') ?: 'localhost';
        $port = intval(getenv('RABBITMQ_PORT') ?: 5672);
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $password = getenv('RABBITMQ_PASSWORD') ?: 'guest';
        $vhost = getenv('RABBITMQ_VHOST') ?: '/';

        $dsn = sprintf(
            'amqp-consoomer://%s:%s@%s:%d/%s/%s?queue=%s&routing_key=%s',
            $user,
            $password,
            $host,
            $port,
            urlencode($vhost),
            self::EXCHANGE_NAME,
            $this->queueName,
            self::ROUTING_KEY,
        );

        $serializer = new PhpSerializer();
        $transport = AmqpTransport::create($dsn, [], $serializer);

        $transport->setup();

        $this->assertExchangeAndQueueExist();
    }

    private function assertExchangeAndQueueExist(): void
    {
        $exchange = new \AMQPExchange($this->channel);
        $exchange->setName(self::EXCHANGE_NAME);
        $exchange->setType('direct');
        $exchange->setFlags(\AMQP_DURABLE);
        $exchange->declareExchange();

        $queue = new \AMQPQueue($this->channel);
        $queue->setName($this->queueName);
        $queue->setFlags(\AMQP_DURABLE);
        $queue->declareQueue();

        $this->addToAssertionCount(2);
    }
}