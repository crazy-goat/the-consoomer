<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\AmqpFactory;
use PHPUnit\Framework\TestCase;

class AmqpFactoryTest extends TestCase
{
    public function testFactoryHasCreateConnectionMethod(): void
    {
        $factory = new AmqpFactory();
        $this->assertTrue(method_exists($factory, 'createConnection'));
    }

    public function testFactoryHasCreateChannelMethod(): void
    {
        $factory = new AmqpFactory();
        $this->assertTrue(method_exists($factory, 'createChannel'));
    }

    public function testFactoryHasCreateQueueMethod(): void
    {
        $factory = new AmqpFactory();
        $this->assertTrue(method_exists($factory, 'createQueue'));
    }

    public function testFactoryHasCreateExchangeMethod(): void
    {
        $factory = new AmqpFactory();
        $this->assertTrue(method_exists($factory, 'createExchange'));
    }

    public function testFactoryHasConfigureSslMethod(): void
    {
        $factory = new AmqpFactory();
        $this->assertTrue(method_exists($factory, 'configureSsl'));
    }

    public function testCreateConnectionWithSslOptions(): void
    {
        $factory = new AmqpFactory();

        $connection = $this->createMock(\AMQPConnection::class);
        $connection->expects($this->once())
            ->method('setCert')
            ->with('/path/to/cert.pem');
        $connection->expects($this->once())
            ->method('setKey')
            ->with('/path/to/key.pem');
        $connection->expects($this->once())
            ->method('setCaCert')
            ->with('/path/to/ca.pem');
        $connection->expects($this->once())
            ->method('setVerify')
            ->with(true);

        $factory->configureSsl($connection, [
            'ssl' => true,
            'ssl_cert' => '/path/to/cert.pem',
            'ssl_key' => '/path/to/key.pem',
            'ssl_cacert' => '/path/to/ca.pem',
            'ssl_verify' => true,
        ]);
    }
}
