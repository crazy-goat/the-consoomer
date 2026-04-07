<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\AmqpFactoryInterface;
use CrazyGoat\TheConsoomer\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConnectionTest extends TestCase
{
    private AmqpFactoryInterface&MockObject $factory;
    private \AMQPConnection&MockObject $amqpConnection;

    protected function setUp(): void
    {
        $this->factory = $this->createMock(AmqpFactoryInterface::class);
        $this->amqpConnection = $this->createMock(\AMQPConnection::class);
    }

    public function testCheckHeartbeatReturnsFalseWhenHeartbeatDisabled(): void
    {
        $connection = new Connection($this->factory, $this->amqpConnection);
        $connection->setHeartbeat(0);

        $this->assertFalse($connection->checkHeartbeat());
    }

    public function testCheckHeartbeatReturnsFalseWhenConnectionIsFresh(): void
    {
        $connection = new Connection($this->factory, $this->amqpConnection);
        $connection->setHeartbeat(60);

        $this->assertFalse($connection->checkHeartbeat());
    }

    public function testCheckHeartbeatReturnsTrueWhenConnectionIsStale(): void
    {
        $connection = new Connection($this->factory, $this->amqpConnection);
        $connection->setHeartbeat(1);

        $reflection = new \ReflectionClass(Connection::class);
        $lastActivityProperty = $reflection->getProperty('lastActivityTime');
        $lastActivityProperty->setValue($connection, time() - 120);

        $this->assertTrue($connection->checkHeartbeat());
    }

    public function testCheckHeartbeatReturnsFalseWhenElapsedEqualsThreshold(): void
    {
        $connection = new Connection($this->factory, $this->amqpConnection);
        $connection->setHeartbeat(10);

        $threshold = 2 * 10;
        $reflection = new \ReflectionClass(Connection::class);
        $lastActivityProperty = $reflection->getProperty('lastActivityTime');
        $lastActivityProperty->setValue($connection, time() - $threshold);

        $this->assertFalse($connection->checkHeartbeat());
    }

    public function testCheckHeartbeatReturnsTrueWhenElapsedExceedsThreshold(): void
    {
        $connection = new Connection($this->factory, $this->amqpConnection);
        $connection->setHeartbeat(10);

        $threshold = 2 * 10;
        $reflection = new \ReflectionClass(Connection::class);
        $lastActivityProperty = $reflection->getProperty('lastActivityTime');
        $lastActivityProperty->setValue($connection, time() - $threshold - 1);

        $this->assertTrue($connection->checkHeartbeat());
    }

    public function testReconnectCallsReconnectWhenAlreadyConnected(): void
    {
        $connection = new Connection($this->factory, $this->amqpConnection);

        $this->amqpConnection
            ->expects($this->once())
            ->method('isConnected')
            ->willReturn(true);

        $this->amqpConnection
            ->expects($this->once())
            ->method('reconnect');

        $connection->reconnect();
    }

    public function testReconnectCallsConnectWhenNotConnected(): void
    {
        $connection = new Connection($this->factory, $this->amqpConnection);

        $this->amqpConnection
            ->expects($this->once())
            ->method('isConnected')
            ->willReturn(false);

        $this->amqpConnection
            ->expects($this->once())
            ->method('connect');

        $connection->reconnect();
    }

    public function testReconnectThrowsOnFailure(): void
    {
        $connection = new Connection($this->factory, $this->amqpConnection);

        $this->amqpConnection
            ->expects($this->once())
            ->method('isConnected')
            ->willReturn(false);

        $this->amqpConnection
            ->expects($this->once())
            ->method('connect')
            ->willThrowException(new \AMQPConnectionException('Connection failed'));

        $this->expectException(\AMQPConnectionException::class);
        $this->expectExceptionMessage('Connection failed');

        $connection->reconnect();
    }

    public function testUpdateActivityUpdatesLastActivityTime(): void
    {
        $connection = new Connection($this->factory, $this->amqpConnection);

        $before = time();
        $connection->updateActivity();
        $after = time();

        $reflection = new \ReflectionClass(Connection::class);
        $lastActivityProperty = $reflection->getProperty('lastActivityTime');
        $lastActivity = $lastActivityProperty->getValue($connection);

        $this->assertGreaterThanOrEqual($before, $lastActivity);
        $this->assertLessThanOrEqual($after, $lastActivity);
    }

    public function testGetChannelCreatesChannelFromFactory(): void
    {
        $channel = $this->createMock(\AMQPChannel::class);

        $this->factory
            ->expects($this->once())
            ->method('createChannel')
            ->with($this->amqpConnection)
            ->willReturn($channel);

        $connection = new Connection($this->factory, $this->amqpConnection);
        $result = $connection->getChannel();

        $this->assertSame($channel, $result);
    }

    public function testGetConnectionReturnsAmqpConnection(): void
    {
        $connection = new Connection($this->factory, $this->amqpConnection);
        $result = $connection->getConnection();

        $this->assertSame($this->amqpConnection, $result);
    }

    public function testIsConnectedDelegatesToAmqpConnection(): void
    {
        $connection = new Connection($this->factory, $this->amqpConnection);

        $this->amqpConnection
            ->expects($this->once())
            ->method('isConnected')
            ->willReturn(true);

        $this->assertTrue($connection->isConnected());
    }
}
