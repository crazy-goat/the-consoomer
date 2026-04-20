<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\AmqpFactory;
use PHPUnit\Framework\TestCase;

class AmqpFactoryTest extends TestCase
{
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

    public function testConfigureSslThrowsForUnreadableCertFile(): void
    {
        $factory = new AmqpFactory();
        $connection = new \AMQPConnection();

        $tempDir = sys_get_temp_dir();
        $certFile = tempnam($tempDir, 'cert');
        chmod($certFile, 0000);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('SSL ssl_cert file not readable');

            $factory->configureSsl($connection, [
                'ssl' => true,
                'ssl_cert' => $certFile,
            ]);
        } finally {
            chmod($certFile, 0644);
            @unlink($certFile);
        }
    }

    public function testHasCaCertConfiguredReturnsTrueWhenCaCertSet(): void
    {
        $factory = new AmqpFactory();
        $this->assertTrue($factory->hasCaCertConfigured([
            'ssl_cacert' => '/path/to/ca.pem',
        ]));
    }

    public function testHasCaCertConfiguredReturnsFalseWhenCaCertNotSet(): void
    {
        $factory = new AmqpFactory();
        $this->assertFalse($factory->hasCaCertConfigured([
            'ssl_cert' => '/path/to/cert.pem',
            'ssl_key' => '/path/to/key.pem',
        ]));
    }

    public function testConfigureSslWithVerifyFalse(): void
    {
        $factory = new AmqpFactory();

        $connection = $this->createMock(\AMQPConnection::class);
        $connection->expects($this->once())
            ->method('setVerify')
            ->with(false);

        $factory->configureSsl($connection, [
            'ssl' => true,
            'ssl_verify' => false,
        ]);
    }

    public function testConfigureSslDoesNothingWhenSslDisabled(): void
    {
        $factory = new AmqpFactory();

        $connection = $this->createMock(\AMQPConnection::class);
        $connection->expects($this->never())
            ->method('setCert');
        $connection->expects($this->never())
            ->method('setKey');
        $connection->expects($this->never())
            ->method('setCaCert');
        $connection->expects($this->never())
            ->method('setVerify');

        $factory->configureSsl($connection, [
            'ssl' => false,
            'ssl_cert' => '/path/to/cert.pem',
        ]);
    }
}
