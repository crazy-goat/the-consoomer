<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\E2E;

use CrazyGoat\TheConsoomer\AmqpTransportFactory;
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
        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

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
        $this->assertSame('Hello E2E Test', $receivedMessage->content);

        $transport->ack($receivedEnvelope);
    }

    public function testConsumeEmptyQueue(): void
    {
        $this->purgeQueue(self::QUEUE_NAME);

        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME, ['timeout' => 0.1]);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        $messages = $transport->get();
        $messages = iterator_to_array($messages);

        $this->assertEmpty($messages);
    }

    public function testRejectMessage(): void
    {
        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'To Reject';
        $envelope = new Envelope($testMessage);

        $transport->send($envelope);

        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);

        $receivedEnvelope = $messages[0];
        $transport->reject($receivedEnvelope);

        $dsnWithTimeout = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME, ['timeout' => 0.1]);
        $transportWithTimeout = AmqpTransportFactory::create($dsnWithTimeout, [], $serializer);
        $messages = iterator_to_array($transportWithTimeout->get());
        $this->assertEmpty($messages);
    }
}
