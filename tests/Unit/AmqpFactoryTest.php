<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\AmqpFactory;
use PHPUnit\Framework\TestCase;

class AmqpFactoryTest extends TestCase
{
    private AmqpFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new AmqpFactory();
    }

    public function testFactoryHasCreateConnectionMethod(): void
    {
        $this->assertTrue(method_exists($this->factory, 'createConnection'));
    }

    public function testFactoryHasCreateChannelMethod(): void
    {
        $this->assertTrue(method_exists($this->factory, 'createChannel'));
    }

    public function testFactoryHasCreateQueueMethod(): void
    {
        $this->assertTrue(method_exists($this->factory, 'createQueue'));
    }

    public function testFactoryHasCreateExchangeMethod(): void
    {
        $this->assertTrue(method_exists($this->factory, 'createExchange'));
    }

    public function testCreateConnectionReturnsAmqpConnection(): void
    {
        $this->markTestSkipped('Requires running RabbitMQ - run integration tests instead');
    }

    public function testCreateChannelReturnsAmqpChannel(): void
    {
        $this->markTestSkipped('Requires running RabbitMQ - run integration tests instead');
    }

    public function testCreateQueueReturnsAmqpQueue(): void
    {
        $this->markTestSkipped('Requires running RabbitMQ - run integration tests instead');
    }

    public function testCreateExchangeReturnsAmqpExchange(): void
    {
        $this->markTestSkipped('Requires running RabbitMQ - run integration tests instead');
    }

    public function testCreateConnectionReturnsNewInstanceEachTime(): void
    {
        $this->markTestSkipped('Requires running RabbitMQ - run integration tests instead');
    }

    public function testCreateChannelReturnsNewInstanceEachTime(): void
    {
        $this->markTestSkipped('Requires running RabbitMQ - run integration tests instead');
    }

    public function testCreateQueueReturnsNewInstanceEachTime(): void
    {
        $this->markTestSkipped('Requires running RabbitMQ - run integration tests instead');
    }

    public function testCreateExchangeReturnsNewInstanceEachTime(): void
    {
        $this->markTestSkipped('Requires running RabbitMQ - run integration tests instead');
    }
}
