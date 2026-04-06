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
            ->method('setCert');
        $connection->expects($this->once())
            ->method('setKey');
        $connection->expects($this->once())
            ->method('setCaCert');
        $connection->expects($this->once())
            ->method('setVerify');

        $tempDir = sys_get_temp_dir();
        $certFile = tempnam($tempDir, 'cert');
        $keyFile = tempnam($tempDir, 'key');
        $caFile = tempnam($tempDir, 'ca');

        try {
            $factory->configureSsl($connection, [
                'ssl' => true,
                'ssl_cert' => $certFile,
                'ssl_key' => $keyFile,
                'ssl_cacert' => $caFile,
                'ssl_verify' => true,
            ]);
        } finally {
            unlink($certFile);
            unlink($keyFile);
            unlink($caFile);
        }
    }

    public function testConfigureSslThrowsForMissingCertFile(): void
    {
        $factory = new AmqpFactory();
        $connection = new \AMQPConnection();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SSL ssl_cert file not found');

        $factory->configureSsl($connection, [
            'ssl' => true,
            'ssl_cert' => '/nonexistent/cert.pem',
        ]);
    }
}
