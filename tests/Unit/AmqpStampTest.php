<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\AmqpStamp;
use PHPUnit\Framework\TestCase;

class AmqpStampTest extends TestCase
{
    public function testConstructorWithRoutingKey(): void
    {
        $stamp = new AmqpStamp('my.routing.key');

        $this->assertSame('my.routing.key', $stamp->getRoutingKey());
    }

    public function testConstructorWithDefaultRoutingKey(): void
    {
        $stamp = new AmqpStamp();

        $this->assertNull($stamp->getRoutingKey());
    }

    public function testIsNonSendable(): void
    {
        $stamp = new AmqpStamp('test');

        $this->assertInstanceOf(\Symfony\Component\Messenger\Stamp\NonSendableStampInterface::class, $stamp);
    }

    public function testConstructorWithFlags(): void
    {
        $stamp = new AmqpStamp('key', \AMQP_MANDATORY);

        $this->assertSame(\AMQP_MANDATORY, $stamp->getFlags());
    }

    public function testDefaultFlagsIsNoParam(): void
    {
        $stamp = new AmqpStamp();

        $this->assertSame(\AMQP_NOPARAM, $stamp->getFlags());
    }

    public function testConstructorWithAttributes(): void
    {
        $stamp = new AmqpStamp('key', \AMQP_NOPARAM, ['content_type' => 'application/json']);

        $this->assertSame(['content_type' => 'application/json'], $stamp->getAttributes());
    }

    public function testDefaultAttributesIsEmptyArray(): void
    {
        $stamp = new AmqpStamp();

        $this->assertSame([], $stamp->getAttributes());
    }

    public function testWithRoutingKeyReturnsNewInstance(): void
    {
        $stamp = new AmqpStamp('old.key');
        $newStamp = $stamp->withRoutingKey('new.key');

        $this->assertSame('old.key', $stamp->getRoutingKey());
        $this->assertSame('new.key', $newStamp->getRoutingKey());
    }

    public function testWithFlagsReturnsNewInstance(): void
    {
        $stamp = new AmqpStamp('key', \AMQP_NOPARAM);
        $newStamp = $stamp->withFlags(\AMQP_MANDATORY);

        $this->assertSame(\AMQP_NOPARAM, $stamp->getFlags());
        $this->assertSame(\AMQP_MANDATORY, $newStamp->getFlags());
    }

    public function testWithAttributeReturnsNewInstance(): void
    {
        $stamp = new AmqpStamp('key', \AMQP_NOPARAM, ['existing' => 'value']);
        $newStamp = $stamp->withAttribute('new', 'attribute');

        $this->assertSame(['existing' => 'value'], $stamp->getAttributes());
        $this->assertSame(['existing' => 'value', 'new' => 'attribute'], $newStamp->getAttributes());
    }

    public function testWithAttributeOverwritesExistingKey(): void
    {
        $stamp = new AmqpStamp('key', \AMQP_NOPARAM, ['priority' => 5]);
        $newStamp = $stamp->withAttribute('priority', 10);

        $this->assertSame(['priority' => 10], $newStamp->getAttributes());
    }

    public function testCreateFromAmqpEnvelope(): void
    {
        $envelope = $this->createMock(\AMQPEnvelope::class);
        $envelope->method('getRoutingKey')->willReturn('test.routing.key');
        $envelope->method('getContentType')->willReturn('application/json');
        $envelope->method('getContentEncoding')->willReturn(null);
        $envelope->method('getMessageId')->willReturn('msg-123');
        $envelope->method('getDeliveryMode')->willReturn(2);
        $envelope->method('getPriority')->willReturn(5);
        $envelope->method('getTimestamp')->willReturn(1234567890);
        $envelope->method('getAppId')->willReturn('test-app');
        $envelope->method('getUserId')->willReturn(null);
        $envelope->method('getExpiration')->willReturn(null);
        $envelope->method('getType')->willReturn(null);
        $envelope->method('getReplyTo')->willReturn(null);
        $envelope->method('getCorrelationId')->willReturn('corr-456');
        $envelope->method('getHeaders')->willReturn(['x-custom' => 'value']);

        $stamp = AmqpStamp::createFromAmqpEnvelope($envelope);

        $this->assertSame('test.routing.key', $stamp->getRoutingKey());
        $this->assertSame(\AMQP_NOPARAM, $stamp->getFlags());
        $attributes = $stamp->getAttributes();
        $this->assertSame('application/json', $attributes['content_type']);
        $this->assertSame('msg-123', $attributes['message_id']);
        $this->assertSame(2, $attributes['delivery_mode']);
        $this->assertSame(5, $attributes['priority']);
        $this->assertSame(1234567890, $attributes['timestamp']);
        $this->assertSame('test-app', $attributes['app_id']);
        $this->assertSame('corr-456', $attributes['correlation_id']);
        $this->assertSame(['x-custom' => 'value'], $attributes['headers']);
        $this->assertCount(8, $attributes);
    }

    public function testCreateFromAmqpEnvelopeFiltersEmptyValues(): void
    {
        $envelope = $this->createMock(\AMQPEnvelope::class);
        $envelope->method('getRoutingKey')->willReturn('');
        $envelope->method('getContentType')->willReturn('');
        $envelope->method('getContentEncoding')->willReturn('');
        $envelope->method('getMessageId')->willReturn('');
        $envelope->method('getDeliveryMode')->willReturn(0);
        $envelope->method('getPriority')->willReturn(0);
        $envelope->method('getTimestamp')->willReturn(0);
        $envelope->method('getAppId')->willReturn('');
        $envelope->method('getUserId')->willReturn('');
        $envelope->method('getExpiration')->willReturn('');
        $envelope->method('getType')->willReturn('');
        $envelope->method('getReplyTo')->willReturn('');
        $envelope->method('getCorrelationId')->willReturn('');
        $envelope->method('getHeaders')->willReturn([]);

        $stamp = AmqpStamp::createFromAmqpEnvelope($envelope);

        $this->assertSame('', $stamp->getRoutingKey());
        $this->assertSame([], $stamp->getAttributes());
    }

    public function testCreateFromAmqpEnvelopeFiltersNumericZeroValues(): void
    {
        $envelope = $this->createMock(\AMQPEnvelope::class);
        $envelope->method('getRoutingKey')->willReturn('test.key');
        $envelope->method('getContentType')->willReturn(null);
        $envelope->method('getContentEncoding')->willReturn(null);
        $envelope->method('getMessageId')->willReturn(null);
        $envelope->method('getDeliveryMode')->willReturn(0);
        $envelope->method('getPriority')->willReturn(0);
        $envelope->method('getTimestamp')->willReturn(0);
        $envelope->method('getAppId')->willReturn(null);
        $envelope->method('getUserId')->willReturn(null);
        $envelope->method('getExpiration')->willReturn(null);
        $envelope->method('getType')->willReturn(null);
        $envelope->method('getReplyTo')->willReturn(null);
        $envelope->method('getCorrelationId')->willReturn(null);
        $envelope->method('getHeaders')->willReturn([]);

        $stamp = AmqpStamp::createFromAmqpEnvelope($envelope);

        $this->assertSame('test.key', $stamp->getRoutingKey());
        $this->assertSame([], $stamp->getAttributes());
    }

    public function testCreateFromAmqpEnvelopeIncludesNumericValuesWhenPositive(): void
    {
        $envelope = $this->createMock(\AMQPEnvelope::class);
        $envelope->method('getRoutingKey')->willReturn('');
        $envelope->method('getContentType')->willReturn(null);
        $envelope->method('getContentEncoding')->willReturn(null);
        $envelope->method('getMessageId')->willReturn(null);
        $envelope->method('getDeliveryMode')->willReturn(2);
        $envelope->method('getPriority')->willReturn(5);
        $envelope->method('getTimestamp')->willReturn(1234567890);
        $envelope->method('getAppId')->willReturn(null);
        $envelope->method('getUserId')->willReturn(null);
        $envelope->method('getExpiration')->willReturn(null);
        $envelope->method('getType')->willReturn(null);
        $envelope->method('getReplyTo')->willReturn(null);
        $envelope->method('getCorrelationId')->willReturn(null);
        $envelope->method('getHeaders')->willReturn([]);

        $stamp = AmqpStamp::createFromAmqpEnvelope($envelope);

        $this->assertSame([
            'delivery_mode' => 2,
            'priority' => 5,
            'timestamp' => 1234567890,
        ], $stamp->getAttributes());
    }

    public function testCreateWithAttributes(): void
    {
        $stamp = AmqpStamp::createWithAttributes(['content_type' => 'text/plain']);

        $this->assertNull($stamp->getRoutingKey());
        $this->assertSame(\AMQP_NOPARAM, $stamp->getFlags());
        $this->assertSame(['content_type' => 'text/plain'], $stamp->getAttributes());
    }

    public function testCreateWithAttributesPreservesStampValues(): void
    {
        $original = new AmqpStamp('original.key', \AMQP_MANDATORY, ['old' => 'value']);
        $stamp = AmqpStamp::createWithAttributes(['new' => 'attribute'], $original);

        $this->assertSame('original.key', $stamp->getRoutingKey());
        $this->assertSame(\AMQP_MANDATORY, $stamp->getFlags());
        $this->assertSame(['new' => 'attribute'], $stamp->getAttributes());
    }
}
