<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\E2E;

use CrazyGoat\TheConsoomer\AmqpReceivedStamp;
use CrazyGoat\TheConsoomer\AmqpStamp;
use CrazyGoat\TheConsoomer\AmqpTransportFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

class AmqpReceivedStampTest extends TestCase
{
    private const QUEUE_NAME = 'test_received_stamp_queue';
    private const EXCHANGE_NAME = 'test_received_stamp_exchange';

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

    public function testReceivedStampContainsQueueName(): void
    {
        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'Test queue name';
        $envelope = new Envelope($testMessage);

        $transport->send($envelope);

        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);

        $receivedEnvelope = $messages[0];
        $stamp = $receivedEnvelope->last(AmqpReceivedStamp::class);

        $this->assertInstanceOf(AmqpReceivedStamp::class, $stamp);
        $this->assertSame(self::QUEUE_NAME, $stamp->getQueueName());

        $transport->ack($receivedEnvelope);
    }

    public function testReceivedStampContainsMessageId(): void
    {
        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'Test message ID';
        $amqpStamp = new AmqpStamp(attributes: ['message_id' => 'msg-12345']);
        $envelope = new Envelope($testMessage, [$amqpStamp]);

        $transport->send($envelope);

        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);

        $receivedEnvelope = $messages[0];
        $stamp = $receivedEnvelope->last(AmqpReceivedStamp::class);

        $this->assertInstanceOf(AmqpReceivedStamp::class, $stamp);
        $this->assertSame('msg-12345', $stamp->getMessageId());

        $transport->ack($receivedEnvelope);
    }

    public function testReceivedStampContainsAppId(): void
    {
        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'Test app ID';
        $amqpStamp = new AmqpStamp(attributes: ['app_id' => 'my-test-app']);
        $envelope = new Envelope($testMessage, [$amqpStamp]);

        $transport->send($envelope);

        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);

        $receivedEnvelope = $messages[0];
        $stamp = $receivedEnvelope->last(AmqpReceivedStamp::class);

        $this->assertInstanceOf(AmqpReceivedStamp::class, $stamp);
        $this->assertSame('my-test-app', $stamp->getAppId());

        $transport->ack($receivedEnvelope);
    }

    public function testReceivedStampContainsCorrelationId(): void
    {
        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'Test correlation ID';
        $amqpStamp = new AmqpStamp(attributes: ['correlation_id' => 'corr-abc123']);
        $envelope = new Envelope($testMessage, [$amqpStamp]);

        $transport->send($envelope);

        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);

        $receivedEnvelope = $messages[0];
        $stamp = $receivedEnvelope->last(AmqpReceivedStamp::class);

        $this->assertInstanceOf(AmqpReceivedStamp::class, $stamp);
        $this->assertSame('corr-abc123', $stamp->getCorrelationId());

        $transport->ack($receivedEnvelope);
    }

    public function testReceivedStampContainsHeaders(): void
    {
        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'Test headers';
        $amqpStamp = new AmqpStamp(
            attributes: ['headers' => ['x-custom-header' => 'custom-value', 'x-another' => 'another-value']],
        );
        $envelope = new Envelope($testMessage, [$amqpStamp]);

        $transport->send($envelope);

        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);

        $receivedEnvelope = $messages[0];
        $stamp = $receivedEnvelope->last(AmqpReceivedStamp::class);

        $this->assertInstanceOf(AmqpReceivedStamp::class, $stamp);
        $headers = $stamp->getHeaders();
        $this->assertArrayHasKey('x-custom-header', $headers);
        $this->assertSame('custom-value', $headers['x-custom-header']);

        $transport->ack($receivedEnvelope);
    }

    public function testReceivedStampContainsContentType(): void
    {
        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'Test content type';
        $amqpStamp = new AmqpStamp(attributes: ['content_type' => 'application/json']);
        $envelope = new Envelope($testMessage, [$amqpStamp]);

        $transport->send($envelope);

        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);

        $receivedEnvelope = $messages[0];
        $stamp = $receivedEnvelope->last(AmqpReceivedStamp::class);

        $this->assertInstanceOf(AmqpReceivedStamp::class, $stamp);
        $this->assertSame('application/json', $stamp->getContentType());

        $transport->ack($receivedEnvelope);
    }

    public function testReceivedStampContainsDeliveryMode(): void
    {
        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'Test delivery mode';
        $amqpStamp = new AmqpStamp(attributes: ['delivery_mode' => 2]);
        $envelope = new Envelope($testMessage, [$amqpStamp]);

        $transport->send($envelope);

        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);

        $receivedEnvelope = $messages[0];
        $stamp = $receivedEnvelope->last(AmqpReceivedStamp::class);

        $this->assertInstanceOf(AmqpReceivedStamp::class, $stamp);
        $this->assertSame(2, $stamp->getDeliveryMode());

        $transport->ack($receivedEnvelope);
    }

    public function testReceivedStampContainsPriority(): void
    {
        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'Test priority';
        $amqpStamp = new AmqpStamp(attributes: ['priority' => 5]);
        $envelope = new Envelope($testMessage, [$amqpStamp]);

        $transport->send($envelope);

        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);

        $receivedEnvelope = $messages[0];
        $stamp = $receivedEnvelope->last(AmqpReceivedStamp::class);

        $this->assertInstanceOf(AmqpReceivedStamp::class, $stamp);
        $this->assertSame(5, $stamp->getPriority());

        $transport->ack($receivedEnvelope);
    }

    public function testReceivedStampContainsTimestamp(): void
    {
        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'Test timestamp';
        $timestamp = time();
        $amqpStamp = new AmqpStamp(attributes: ['timestamp' => $timestamp]);
        $envelope = new Envelope($testMessage, [$amqpStamp]);

        $transport->send($envelope);

        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);

        $receivedEnvelope = $messages[0];
        $stamp = $receivedEnvelope->last(AmqpReceivedStamp::class);

        $this->assertInstanceOf(AmqpReceivedStamp::class, $stamp);
        $this->assertSame($timestamp, $stamp->getTimestamp());

        $transport->ack($receivedEnvelope);
    }

    public function testReceivedStampContainsReplyTo(): void
    {
        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'Test reply to';
        $amqpStamp = new AmqpStamp(attributes: ['reply_to' => 'reply.queue']);
        $envelope = new Envelope($testMessage, [$amqpStamp]);

        $transport->send($envelope);

        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);

        $receivedEnvelope = $messages[0];
        $stamp = $receivedEnvelope->last(AmqpReceivedStamp::class);

        $this->assertInstanceOf(AmqpReceivedStamp::class, $stamp);
        $this->assertSame('reply.queue', $stamp->getReplyTo());

        $transport->ack($receivedEnvelope);
    }

    public function testReceivedStampProvidesAccessToAmqpEnvelope(): void
    {
        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'Test envelope access';
        $envelope = new Envelope($testMessage);

        $transport->send($envelope);

        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);

        $receivedEnvelope = $messages[0];
        $stamp = $receivedEnvelope->last(AmqpReceivedStamp::class);

        $this->assertInstanceOf(AmqpReceivedStamp::class, $stamp);
        $this->assertInstanceOf(\AMQPEnvelope::class, $stamp->getAmqpEnvelope());
        $this->assertStringContainsString('Test envelope access', $stamp->getAmqpEnvelope()->getBody());

        $transport->ack($receivedEnvelope);
    }
}
