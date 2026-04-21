<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\AmqpReceivedStamp;
use PHPUnit\Framework\TestCase;

class AmqpReceivedStampTest extends TestCase
{
    public function testConstructorStoresMessageAndQueueName(): void
    {
        $envelope = $this->createMock(\AMQPEnvelope::class);
        $stamp = new AmqpReceivedStamp($envelope, 'test_queue');

        $this->assertSame($envelope, $stamp->amqpMessage);
        $this->assertSame('test_queue', $stamp->queueName);
    }

    public function testIsNonSendable(): void
    {
        $envelope = $this->createMock(\AMQPEnvelope::class);
        $stamp = new AmqpReceivedStamp($envelope, 'test_queue');

        $this->assertInstanceOf(\Symfony\Component\Messenger\Stamp\NonSendableStampInterface::class, $stamp);
    }

    public function testGetAmqpEnvelopeReturnsEnvelope(): void
    {
        $envelope = $this->createMock(\AMQPEnvelope::class);
        $stamp = new AmqpReceivedStamp($envelope, 'test_queue');

        $this->assertSame($envelope, $stamp->getAmqpEnvelope());
    }

    public function testGetQueueNameReturnsQueueName(): void
    {
        $envelope = $this->createMock(\AMQPEnvelope::class);
        $stamp = new AmqpReceivedStamp($envelope, 'my_queue');

        $this->assertSame('my_queue', $stamp->getQueueName());
    }

    public function testGetMessageIdReturnsMessageId(): void
    {
        $envelope = $this->createMock(\AMQPEnvelope::class);
        $envelope->method('getMessageId')->willReturn('msg-123');
        $stamp = new AmqpReceivedStamp($envelope, 'test_queue');

        $this->assertSame('msg-123', $stamp->getMessageId());
    }

    public function testGetMessageIdReturnsNullWhenNotSet(): void
    {
        $envelope = $this->createMock(\AMQPEnvelope::class);
        $envelope->method('getMessageId')->willReturn(null);
        $stamp = new AmqpReceivedStamp($envelope, 'test_queue');

        $this->assertNull($stamp->getMessageId());
    }

    public function testGetTimestampReturnsTimestamp(): void
    {
        $envelope = $this->createMock(\AMQPEnvelope::class);
        $envelope->method('getTimestamp')->willReturn(1234567890);
        $stamp = new AmqpReceivedStamp($envelope, 'test_queue');

        $this->assertSame(1234567890, $stamp->getTimestamp());
    }

    public function testGetTimestampReturnsNullWhenNotSet(): void
    {
        $envelope = $this->createMock(\AMQPEnvelope::class);
        $envelope->method('getTimestamp')->willReturn(null);
        $stamp = new AmqpReceivedStamp($envelope, 'test_queue');

        $this->assertNull($stamp->getTimestamp());
    }

    public function testGetAppIdReturnsAppId(): void
    {
        $envelope = $this->createMock(\AMQPEnvelope::class);
        $envelope->method('getAppId')->willReturn('my-app');
        $stamp = new AmqpReceivedStamp($envelope, 'test_queue');

        $this->assertSame('my-app', $stamp->getAppId());
    }

    public function testGetAppIdReturnsNullWhenNotSet(): void
    {
        $envelope = $this->createMock(\AMQPEnvelope::class);
        $envelope->method('getAppId')->willReturn(null);
        $stamp = new AmqpReceivedStamp($envelope, 'test_queue');

        $this->assertNull($stamp->getAppId());
    }

    public function testGetHeadersReturnsHeaders(): void
    {
        $envelope = $this->createMock(\AMQPEnvelope::class);
        $envelope->method('getHeaders')->willReturn(['x-custom' => 'value']);
        $stamp = new AmqpReceivedStamp($envelope, 'test_queue');

        $this->assertSame(['x-custom' => 'value'], $stamp->getHeaders());
    }

    public function testGetHeadersReturnsEmptyArrayWhenNotSet(): void
    {
        $envelope = $this->createMock(\AMQPEnvelope::class);
        $envelope->method('getHeaders')->willReturn([]);
        $stamp = new AmqpReceivedStamp($envelope, 'test_queue');

        $this->assertSame([], $stamp->getHeaders());
    }

    public function testGetCorrelationIdReturnsCorrelationId(): void
    {
        $envelope = $this->createMock(\AMQPEnvelope::class);
        $envelope->method('getCorrelationId')->willReturn('corr-123');
        $stamp = new AmqpReceivedStamp($envelope, 'test_queue');

        $this->assertSame('corr-123', $stamp->getCorrelationId());
    }

    public function testGetCorrelationIdReturnsNullWhenNotSet(): void
    {
        $envelope = $this->createMock(\AMQPEnvelope::class);
        $envelope->method('getCorrelationId')->willReturn(null);
        $stamp = new AmqpReceivedStamp($envelope, 'test_queue');

        $this->assertNull($stamp->getCorrelationId());
    }

    public function testGetReplyToReturnsReplyTo(): void
    {
        $envelope = $this->createMock(\AMQPEnvelope::class);
        $envelope->method('getReplyTo')->willReturn('reply.queue');
        $stamp = new AmqpReceivedStamp($envelope, 'test_queue');

        $this->assertSame('reply.queue', $stamp->getReplyTo());
    }

    public function testGetReplyToReturnsNullWhenNotSet(): void
    {
        $envelope = $this->createMock(\AMQPEnvelope::class);
        $envelope->method('getReplyTo')->willReturn(null);
        $stamp = new AmqpReceivedStamp($envelope, 'test_queue');

        $this->assertNull($stamp->getReplyTo());
    }

    public function testGetContentTypeReturnsContentType(): void
    {
        $envelope = $this->createMock(\AMQPEnvelope::class);
        $envelope->method('getContentType')->willReturn('application/json');
        $stamp = new AmqpReceivedStamp($envelope, 'test_queue');

        $this->assertSame('application/json', $stamp->getContentType());
    }

    public function testGetContentTypeReturnsNullWhenNotSet(): void
    {
        $envelope = $this->createMock(\AMQPEnvelope::class);
        $envelope->method('getContentType')->willReturn(null);
        $stamp = new AmqpReceivedStamp($envelope, 'test_queue');

        $this->assertNull($stamp->getContentType());
    }

    public function testGetDeliveryModeReturnsDeliveryMode(): void
    {
        $envelope = $this->createMock(\AMQPEnvelope::class);
        $envelope->method('getDeliveryMode')->willReturn(2);
        $stamp = new AmqpReceivedStamp($envelope, 'test_queue');

        $this->assertSame(2, $stamp->getDeliveryMode());
    }

    public function testGetPriorityReturnsPriority(): void
    {
        $envelope = $this->createMock(\AMQPEnvelope::class);
        $envelope->method('getPriority')->willReturn(5);
        $stamp = new AmqpReceivedStamp($envelope, 'test_queue');

        $this->assertSame(5, $stamp->getPriority());
    }
}
