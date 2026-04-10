<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\E2E;

use CrazyGoat\TheConsoomer\AmqpTransportFactory;
use CrazyGoat\TheConsoomer\AmqpTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

class AutoSetupTest extends TestCase
{
    private const EXCHANGE_NAME = 'test_auto_setup_exchange';
    private const ROUTING_KEY = 'test_auto_setup_key';
    private string $queueName;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queueName = 'test_auto_setup_queue_' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->deleteQueue($this->queueName);
        $this->deleteExchange(self::EXCHANGE_NAME);
        parent::tearDown();
    }

    public function testAutoSetupCreatesExchangeAndQueue(): void
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
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'Hello Auto Setup Test';
        $envelope = new Envelope($testMessage);

        $transport->send($envelope);

        $messages = $transport->get();
        $this->assertIsIterable($messages);
        $messages = iterator_to_array($messages);

        $this->assertCount(1, $messages);

        /** @var Envelope $receivedEnvelope */
        $receivedEnvelope = $messages[0];
        $receivedMessage = $receivedEnvelope->getMessage();

        $this->assertInstanceOf(\stdClass::class, $receivedMessage);
        $this->assertSame('Hello Auto Setup Test', $receivedMessage->content);

        $transport->ack($receivedEnvelope);
    }
}
