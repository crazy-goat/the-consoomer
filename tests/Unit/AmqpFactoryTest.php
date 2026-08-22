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
            'allow_insecure_verify' => true,
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

    public function testConfigureSslVerifyDefaultsToTrueWhenNotSet(): void
    {
        $factory = new AmqpFactory();

        $connection = $this->createMock(\AMQPConnection::class);
        $connection->expects($this->once())
            ->method('setVerify')
            ->with(true);

        $factory->configureSsl($connection, [
            'ssl' => true,
        ]);
    }

    public function testConfigureSslWithVerifyStringTrue(): void
    {
        $factory = new AmqpFactory();

        $connection = $this->createMock(\AMQPConnection::class);
        $connection->expects($this->once())
            ->method('setVerify')
            ->with(true);

        $factory->configureSsl($connection, [
            'ssl' => true,
            'ssl_verify' => 'true',
        ]);
    }

    public function testConfigureSslWithVerifyStringFalse(): void
    {
        $factory = new AmqpFactory();

        $connection = $this->createMock(\AMQPConnection::class);
        $connection->expects($this->once())
            ->method('setVerify')
            ->with(false);

        $factory->configureSsl($connection, [
            'ssl' => true,
            'ssl_verify' => 'false',
            'allow_insecure_verify' => true,
        ]);
    }

    public function testConfigureSslWithVerifyIntOne(): void
    {
        $factory = new AmqpFactory();

        $connection = $this->createMock(\AMQPConnection::class);
        $connection->expects($this->once())
            ->method('setVerify')
            ->with(true);

        $factory->configureSsl($connection, [
            'ssl' => true,
            'ssl_verify' => 1,
        ]);
    }

    public function testConfigureSslWithVerifyIntZero(): void
    {
        $factory = new AmqpFactory();

        $connection = $this->createMock(\AMQPConnection::class);
        $connection->expects($this->once())
            ->method('setVerify')
            ->with(false);

        $factory->configureSsl($connection, [
            'ssl' => true,
            'ssl_verify' => 0,
            'allow_insecure_verify' => true,
        ]);
    }

    public function testConfigureSslWithVerifyEmptyStringThrows(): void
    {
        $factory = new AmqpFactory();
        $connection = $this->createMock(\AMQPConnection::class);
        $connection->expects($this->never())
            ->method('setVerify');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ssl_verify must be a boolean');

        $factory->configureSsl($connection, [
            'ssl' => true,
            'ssl_verify' => '',
        ]);
    }

    public function testConfigureSslWithVerifyInvalidStringThrows(): void
    {
        $factory = new AmqpFactory();
        $connection = $this->createMock(\AMQPConnection::class);
        $connection->expects($this->never())
            ->method('setVerify');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ssl_verify must be a boolean');

        $factory->configureSsl($connection, [
            'ssl' => true,
            'ssl_verify' => 'maybe',
        ]);
    }

    public function testConfigureSslWithVerifyNumericStringOne(): void
    {
        $factory = new AmqpFactory();

        $connection = $this->createMock(\AMQPConnection::class);
        $connection->expects($this->once())
            ->method('setVerify')
            ->with(true);

        $factory->configureSsl($connection, [
            'ssl' => true,
            'ssl_verify' => '1',
        ]);
    }

    public function testConfigureSslWithVerifyNumericStringZero(): void
    {
        $factory = new AmqpFactory();

        $connection = $this->createMock(\AMQPConnection::class);
        $connection->expects($this->once())
            ->method('setVerify')
            ->with(false);

        $factory->configureSsl($connection, [
            'ssl' => true,
            'ssl_verify' => '0',
            'allow_insecure_verify' => true,
        ]);
    }

    public function testConfigureSslWithVerifyStringOneTruthyValues(): void
    {
        $factory = new AmqpFactory();

        foreach (['on', 'yes'] as $value) {
            $connection = $this->createMock(\AMQPConnection::class);
            $connection->expects($this->once())
                ->method('setVerify')
                ->with(true);

            $factory->configureSsl($connection, [
                'ssl' => true,
                'ssl_verify' => $value,
            ]);
        }
    }

    public function testConfigureSslWithVerifyStringZeroFalsyValues(): void
    {
        $factory = new AmqpFactory();

        foreach (['off', 'no'] as $value) {
            $connection = $this->createMock(\AMQPConnection::class);
            $connection->expects($this->once())
                ->method('setVerify')
                ->with(false);

            $factory->configureSsl($connection, [
                'ssl' => true,
                'ssl_verify' => $value,
                'allow_insecure_verify' => true,
            ]);
        }
    }

    public function testConfigureSslLogsWarningWhenVerifyDisabled(): void
    {
        $factory = new AmqpFactory();

        $connection = $this->createMock(\AMQPConnection::class);
        $connection->expects($this->once())
            ->method('setVerify')
            ->with(false);

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('verification is disabled'));
        // No debug line for the verify state anymore — it's warning/ok.
        $logger->expects($this->never())
            ->method('debug')
            ->with($this->anything());

        $factory->configureSsl($connection, [
            'ssl' => true,
            'ssl_verify' => false,
            'allow_insecure_verify' => true,
        ], $logger);
    }

    public function testConfigureSslLogsDebugEnabledWhenVerifyEnabled(): void
    {
        $factory = new AmqpFactory();

        $tempDir = sys_get_temp_dir();
        $caFile = tempnam($tempDir, 'ca');

        try {
            $connection = $this->createMock(\AMQPConnection::class);
            $connection->expects($this->once())
                ->method('setCaCert')
                ->with($caFile);
            $connection->expects($this->once())
                ->method('setVerify')
                ->with(true);

            // With a CA cert pinned there are two debug calls (cacert + verify enabled)
            // and no warning. Assert on the absence of warning; the debug "SSL verify:
            // enabled" line is covered by testConfigureSslLogsWarningWhenVerifyEnabledButNoCaCert.
            $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
            $logger->expects($this->atLeastOnce())
                ->method('debug');
            $logger->expects($this->never())
                ->method('warning');

            $factory->configureSsl($connection, [
                'ssl' => true,
                'ssl_verify' => true,
                'ssl_cacert' => $caFile,
            ], $logger);
        } finally {
            @unlink($caFile);
        }
    }

    public function testConfigureSslLogsWarningWhenVerifyEnabledButNoCaCert(): void
    {
        $factory = new AmqpFactory();

        $connection = $this->createMock(\AMQPConnection::class);
        $connection->expects($this->once())
            ->method('setVerify')
            ->with(true);

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('no CA certificate'));
        $logger->expects($this->once())
            ->method('debug')
            ->with('SSL verify: enabled');

        $factory->configureSsl($connection, [
            'ssl' => true,
            'ssl_verify' => true,
        ], $logger);
    }

    public function testConfigureSslVerifyDefaultNoCaCertLogsWarning(): void
    {
        // ssl_verify defaults to true when unset; with no CA cert the guard fires.
        $factory = new AmqpFactory();

        $connection = $this->createMock(\AMQPConnection::class);
        $connection->expects($this->once())
            ->method('setVerify')
            ->with(true);

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('no CA certificate'));

        $factory->configureSsl($connection, [
            'ssl' => true,
        ], $logger);
    }

    public function testConfigureSslRefusesVerifyFalseWithoutOptIn(): void
    {
        // #361: ssl_verify=false must not silently disable peer verification. Without
        // the explicit allow_insecure_verify opt-in, configureSsl() must throw BEFORE
        // mutating the connection (setVerify is never called) — even when a logger is
        // injected (the old warning-only path was a no-op without one).
        $factory = new AmqpFactory();

        $connection = $this->createMock(\AMQPConnection::class);
        $connection->expects($this->never())
            ->method('setVerify');

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Refusing ssl_verify=false without an explicit opt-in');

        $factory->configureSsl($connection, [
            'ssl' => true,
            'ssl_verify' => false,
        ], $logger);
    }

    public function testConfigureSslRefusesVerifyFalseWithoutOptInAndNoLogger(): void
    {
        // #351 + #361: without a logger the old warning was a silent no-op. Now the
        // throw fires regardless of logger presence.
        $factory = new AmqpFactory();

        $connection = $this->createMock(\AMQPConnection::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Refusing ssl_verify=false without an explicit opt-in');

        $factory->configureSsl($connection, [
            'ssl' => true,
            'ssl_verify' => false,
        ]);
    }

    public function testConfigureSslAcceptsVerifyFalseWithOptIn(): void
    {
        // Explicit programmatic opt-in acknowledges the risk and allows ssl_verify=false.
        $factory = new AmqpFactory();

        $connection = $this->createMock(\AMQPConnection::class);
        $connection->expects($this->once())
            ->method('setVerify')
            ->with(false);

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('allow_insecure_verify opt-in'));

        $factory->configureSsl($connection, [
            'ssl' => true,
            'ssl_verify' => false,
            'allow_insecure_verify' => true,
        ], $logger);
    }

    public function testConfigureSslRefusesVerifyFalseWithOptInFalse(): void
    {
        // allow_insecure_verify=false (explicit) must not grant permission.
        $factory = new AmqpFactory();

        $connection = $this->createMock(\AMQPConnection::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Refusing ssl_verify=false without an explicit opt-in');

        $factory->configureSsl($connection, [
            'ssl' => true,
            'ssl_verify' => false,
            'allow_insecure_verify' => false,
        ]);
    }

    public function testConfigureSslAcceptsVerifyFalseWithStringOptInTrue(): void
    {
        // String "true" normalizes via FILTER_VALIDATE_BOOL, matching the ssl_verify pattern.
        $factory = new AmqpFactory();

        $connection = $this->createMock(\AMQPConnection::class);
        $connection->expects($this->once())
            ->method('setVerify')
            ->with(false);

        $factory->configureSsl($connection, [
            'ssl' => true,
            'ssl_verify' => false,
            'allow_insecure_verify' => 'true',
        ]);
    }
}
