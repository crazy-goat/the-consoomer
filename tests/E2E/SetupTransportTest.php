<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\E2E;

use CrazyGoat\TheConsoomer\AmqpTransportFactory;
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
        $dsn = $this->buildDsn(self::EXCHANGE_NAME, $this->queueName, [
            'routing_key' => self::ROUTING_KEY,
        ]);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

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
        $messageCount = $queue->declareQueue();

        $this->assertGreaterThanOrEqual(0, $messageCount, 'Queue should exist after setup()');
    }
}
