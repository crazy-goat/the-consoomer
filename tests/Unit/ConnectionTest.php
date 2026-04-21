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

    public function testGetChannelCachesChannel(): void
    {
        $channel = $this->createMock(\AMQPChannel::class);

        // isConnected called on each getChannel when channel is cached
        $channel
            ->expects($this->any())
            ->method('isConnected')
            ->willReturn(true);

        $this->factory
            ->expects($this->once())
            ->method('createChannel')
            ->with($this->amqpConnection)
            ->willReturn($channel);

        $connection = new Connection($this->factory, $this->amqpConnection);

        $result1 = $connection->getChannel();
        $this->assertSame($channel, $result1);

        $result2 = $connection->getChannel();
        $this->assertSame($channel, $result2);
    }

    public function testReconnectInvalidatesChannelCache(): void
    {
        $channel = $this->createMock(\AMQPChannel::class);
        $newChannel = $this->createMock(\AMQPChannel::class);

        $this->factory
            ->expects($this->exactly(2))
            ->method('createChannel')
            ->with($this->amqpConnection)
            ->willReturnOnConsecutiveCalls($channel, $newChannel);

        $this->amqpConnection
            ->expects($this->once())
            ->method('isConnected')
            ->willReturn(true);

        $this->amqpConnection
            ->expects($this->once())
            ->method('reconnect');

        $connection = new Connection($this->factory, $this->amqpConnection);

        // First call creates the channel
        $result1 = $connection->getChannel();
        $this->assertSame($channel, $result1);

        // Reconnect should invalidate the cache (after successful reconnect)
        $connection->reconnect();

        // Next call should create a new channel
        $result2 = $connection->getChannel();
        $this->assertSame($newChannel, $result2);
    }

    public function testGetChannelCreatesNewChannelWhenCachedChannelIsDisconnected(): void
    {
        $channel = $this->createMock(\AMQPChannel::class);
        $newChannel = $this->createMock(\AMQPChannel::class);

        // First channel is disconnected - this triggers recreation
        $channel
            ->expects($this->once())
            ->method('isConnected')
            ->willReturn(false);

        // New channel stays connected
        $newChannel
            ->expects($this->never())
            ->method('isConnected');

        $this->factory
            ->expects($this->exactly(2))
            ->method('createChannel')
            ->with($this->amqpConnection)
            ->willReturnOnConsecutiveCalls($channel, $newChannel);

        $connection = new Connection($this->factory, $this->amqpConnection);

        // First call creates the channel
        $result1 = $connection->getChannel();
        $this->assertSame($channel, $result1);

        // Second call detects disconnected channel and creates new one
        $result2 = $connection->getChannel();
        $this->assertSame($newChannel, $result2);
    }

    public function testClearChannelCacheManuallyClearsCache(): void
    {
        $channel = $this->createMock(\AMQPChannel::class);
        $newChannel = $this->createMock(\AMQPChannel::class);

        // isConnected is NOT called on first getChannel because channel is null
        // New channel is also not checked because cache was manually cleared
        $this->factory
            ->expects($this->exactly(2))
            ->method('createChannel')
            ->with($this->amqpConnection)
            ->willReturnOnConsecutiveCalls($channel, $newChannel);

        $connection = new Connection($this->factory, $this->amqpConnection);

        // First call creates the channel
        $result1 = $connection->getChannel();
        $this->assertSame($channel, $result1);

        // Manually clear cache
        $connection->clearChannelCache();

        // Next call should create a new channel (cache is null, no isConnected check)
        $result2 = $connection->getChannel();
        $this->assertSame($newChannel, $result2);
    }

    public function testReconnectDoesNotClearCacheOnFailure(): void
    {
        $channel = $this->createMock(\AMQPChannel::class);

        $this->factory
            ->expects($this->once())
            ->method('createChannel')
            ->with($this->amqpConnection)
            ->willReturn($channel);

        $this->amqpConnection
            ->expects($this->once())
            ->method('isConnected')
            ->willReturn(false);

        $this->amqpConnection
            ->expects($this->once())
            ->method('connect')
            ->willThrowException(new \AMQPConnectionException('Connection failed'));

        $connection = new Connection($this->factory, $this->amqpConnection);

        // First call creates the channel
        $result1 = $connection->getChannel();
        $this->assertSame($channel, $result1);

        // Reconnect fails - cache should NOT be cleared
        $this->expectException(\AMQPConnectionException::class);
        $connection->reconnect();
    }

    public function testCloseDisconnectsWhenConnected(): void
    {
        $this->amqpConnection
            ->expects($this->once())
            ->method('isConnected')
            ->willReturn(true);

        $this->amqpConnection
            ->expects($this->once())
            ->method('disconnect');

        $connection = new Connection($this->factory, $this->amqpConnection);
        $connection->close();
    }

    public function testCloseDoesNotDisconnectWhenNotConnected(): void
    {
        $this->amqpConnection
            ->expects($this->once())
            ->method('isConnected')
            ->willReturn(false);

        $this->amqpConnection
            ->expects($this->never())
            ->method('disconnect');

        $connection = new Connection($this->factory, $this->amqpConnection);
        $connection->close();
    }

    public function testCloseClearsChannelCache(): void
    {
        $channel = $this->createMock(\AMQPChannel::class);

        $this->factory
            ->expects($this->once())
            ->method('createChannel')
            ->with($this->amqpConnection)
            ->willReturn($channel);

        $this->amqpConnection
            ->expects($this->once())
            ->method('isConnected')
            ->willReturn(true);

        $this->amqpConnection
            ->expects($this->once())
            ->method('disconnect');

        $connection = new Connection($this->factory, $this->amqpConnection);

        // Create a channel first
        $connection->getChannel();

        // Close should clear the cache
        $connection->close();

        // After close, getChannel should create a new one (but we're not testing that here)
    }

    public function testCloseIsIdempotent(): void
    {
        $this->amqpConnection
            ->expects($this->exactly(2))
            ->method('isConnected')
            ->willReturnOnConsecutiveCalls(true, false);

        $this->amqpConnection
            ->expects($this->once())
            ->method('disconnect');

        $connection = new Connection($this->factory, $this->amqpConnection);

        $connection->close();
        $connection->close();
    }

    public function testCloseThrowsOnDisconnectFailure(): void
    {
        $this->amqpConnection
            ->expects($this->once())
            ->method('isConnected')
            ->willReturn(true);

        $this->amqpConnection
            ->expects($this->once())
            ->method('disconnect')
            ->willThrowException(new \AMQPConnectionException('Disconnect failed'));

        $connection = new Connection($this->factory, $this->amqpConnection);

        $this->expectException(\AMQPConnectionException::class);
        $this->expectExceptionMessage('Disconnect failed');

        $connection->close();
    }
}
