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
