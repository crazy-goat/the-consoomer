<?php

namespace CrazyGoat\TheConsoomer\Tests\E2E;

use CrazyGoat\TheConsoomer\AmqpTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

class ConsumeProduceTest extends TestCase
{
    private const QUEUE_NAME = 'test_consume_produce_queue';
    private const EXCHANGE_NAME = 'test_consume_produce_exchange';

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareExchange(self::EXCHANGE_NAME);
        $this->declareQueue(self::QUEUE_NAME);
        $this->bindQueue(self::QUEUE_NAME, self::EXCHANGE_NAME);
    }

    protected function tearDown(): void
    {
        $this->deleteQueue(self::QUEUE_NAME);
        $this->deleteExchange(self::EXCHANGE_NAME);

        parent::tearDown();
    }

    public function testProduceAndConsumeMessage(): void
    {
        $host = getenv('RABBITMQ_HOST') ?: 'localhost';
        $port = intval(getenv('RABBITMQ_PORT') ?: 5672);
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $password = getenv('RABBITMQ_PASSWORD') ?: 'guest';
        $vhost = getenv('RABBITMQ_VHOST') ?: '/';

        $dsn = sprintf(
            'amqp-consoomer://%s:%s@%s:%d/%s/%s?queue=%s',
            $user,
            $password,
            $host,
            $port,
            urlencode($vhost),
            self::EXCHANGE_NAME,
            self::QUEUE_NAME
        );

        $serializer = new PhpSerializer();
        $transport = AmqpTransport::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'Hello E2E Test';
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
        $this->assertEquals('Hello E2E Test', $receivedMessage->content);

        $transport->ack($receivedEnvelope);
    }

    public function testConsumeEmptyQueue(): void
    {
        $this->purgeQueue(self::QUEUE_NAME);

        $host = getenv('RABBITMQ_HOST') ?: 'localhost';
        $port = intval(getenv('RABBITMQ_PORT') ?: 5672);
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $password = getenv('RABBITMQ_PASSWORD') ?: 'guest';
        $vhost = getenv('RABBITMQ_VHOST') ?: '/';

        $dsn = sprintf(
            'amqp-consoomer://%s:%s@%s:%d/%s/%s?queue=%s&timeout=0.1',
            $user,
            $password,
            $host,
            $port,
            urlencode($vhost),
            self::EXCHANGE_NAME,
            self::QUEUE_NAME
        );

        $serializer = new PhpSerializer();
        $transport = AmqpTransport::create($dsn, [], $serializer);

        $messages = $transport->get();
        $messages = iterator_to_array($messages);

        $this->assertEmpty($messages);
    }

    public function testRejectMessage(): void
    {
        $host = getenv('RABBITMQ_HOST') ?: 'localhost';
        $port = intval(getenv('RABBITMQ_PORT') ?: 5672);
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $password = getenv('RABBITMQ_PASSWORD') ?: 'guest';
        $vhost = getenv('RABBITMQ_VHOST') ?: '/';

        $dsn = sprintf(
            'amqp-consoomer://%s:%s@%s:%d/%s/%s?queue=%s',
            $user,
            $password,
            $host,
            $port,
            urlencode($vhost),
            self::EXCHANGE_NAME,
            self::QUEUE_NAME
        );

        $serializer = new PhpSerializer();
        $transport = AmqpTransport::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'To Reject';
        $envelope = new Envelope($testMessage);

        $transport->send($envelope);

        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);

        $receivedEnvelope = $messages[0];
        $transport->reject($receivedEnvelope);

        $dsnWithTimeout = sprintf(
            'amqp-consoomer://%s:%s@%s:%d/%s/%s?queue=%s&timeout=0.1',
            $user,
            $password,
            $host,
            $port,
            urlencode($vhost),
            self::EXCHANGE_NAME,
            self::QUEUE_NAME
        );
        $transportWithTimeout = AmqpTransport::create($dsnWithTimeout, [], $serializer);
        $messages = iterator_to_array($transportWithTimeout->get());
        $this->assertEmpty($messages);
    }
}
