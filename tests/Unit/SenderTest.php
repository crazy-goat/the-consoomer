<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\AmqpFactory;
use CrazyGoat\TheConsoomer\AmqpStamp;
use CrazyGoat\TheConsoomer\Connection;
use CrazyGoat\TheConsoomer\InfrastructureSetup;
use CrazyGoat\TheConsoomer\Sender;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class SenderTest extends TestCase
{
    private AmqpFactory&MockObject $factory;
    private Connection&MockObject $connection;
    private SerializerInterface&MockObject $serializer;
    private \AMQPExchange&MockObject $exchange;
    private InfrastructureSetup&MockObject $setup;

    protected function setUp(): void
    {
        $this->factory = $this->createMock(AmqpFactory::class);
        $this->connection = $this->createMock(Connection::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->exchange = $this->createMock(\AMQPExchange::class);
        $this->setup = $this->createMock(InfrastructureSetup::class);
    }

    public function testSendPublishesToExchange(): void
    {
        $options = ['exchange' => 'test_exchange'];

        $envelope = new Envelope(new \stdClass());

        $this->serializer
            ->expects($this->once())
            ->method('encode')
            ->with($envelope)
            ->willReturn([
                'body' => '{"message":"test"}',
                'headers' => ['content-type' => 'application/json'],
            ]);

        $this->exchange
            ->expects($this->once())
            ->method('publish')
            ->with(
                '{"message":"test"}',
                '',
                null,
                ['content-type' => 'application/json'],
            );

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $sender = $this->createSender($options);
        $result = $sender->send($envelope);

        $this->assertSame($envelope, $result);
    }

    public function testSendUsesRoutingKeyFromStamp(): void
    {
        $options = ['exchange' => 'test_exchange'];
        $routingKey = 'my.routing.key';
        $stamp = new AmqpStamp($routingKey);

        $envelope = new Envelope(new \stdClass(), [$stamp]);

        $this->serializer
            ->expects($this->once())
            ->method('encode')
            ->with($envelope)
            ->willReturn([
                'body' => '{"message":"test"}',
                'headers' => [],
            ]);

        $this->exchange
            ->expects($this->once())
            ->method('publish')
            ->with(
                '{"message":"test"}',
                $routingKey,
                null,
                [],
            );

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $sender = $this->createSender($options);
        $result = $sender->send($envelope);

        $this->assertSame($envelope, $result);
    }

    public function testSendUsesRoutingKeyFromOptionsOverStamp(): void
    {
        $options = [
            'exchange' => 'test_exchange',
            'routing_key' => 'options.routing.key',
        ];
        $stamp = new AmqpStamp('stamp.routing.key');

        $envelope = new Envelope(new \stdClass(), [$stamp]);

        $this->serializer
            ->expects($this->once())
            ->method('encode')
            ->willReturn([
                'body' => '{"message":"test"}',
                'headers' => [],
            ]);

        $this->exchange
            ->expects($this->once())
            ->method('publish')
            ->with(
                '{"message":"test"}',
                'options.routing.key',
                null,
                [],
            );

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $sender = $this->createSender($options);
        $sender->send($envelope);
    }

    public function testSendUsesEmptyRoutingKeyWhenNotProvided(): void
    {
        $options = ['exchange' => 'test_exchange'];

        $envelope = new Envelope(new \stdClass());

        $this->serializer
            ->method('encode')
            ->willReturn([
                'body' => '{"message":"test"}',
                'headers' => [],
            ]);

        $this->exchange
            ->expects($this->once())
            ->method('publish')
            ->with(
                '{"message":"test"}',
                '',
                null,
                [],
            );

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $sender = $this->createSender($options);
        $sender->send($envelope);
    }

    public function testConnectIsLazyAndExchangeIsReused(): void
    {
        $options = ['exchange' => 'test_exchange'];

        $this->serializer
            ->method('encode')
            ->willReturn(['body' => 'test', 'headers' => []]);

        $this->exchange
            ->expects($this->exactly(2))
            ->method('publish');

        $this->connection
            ->expects($this->exactly(2))
            ->method('checkHeartbeat')
            ->willReturn(false);

        $sender = $this->createSender($options);

        $envelope1 = new Envelope(new \stdClass());
        $envelope2 = new Envelope(new \stdClass());

        $sender->send($envelope1);
        $sender->send($envelope2);
    }

    public function testSendUsesFactoryToCreateChannelAndExchange(): void
    {
        $options = ['exchange' => 'test_exchange'];

        $channel = $this->createMock(\AMQPChannel::class);

        $this->connection
            ->expects($this->once())
            ->method('getChannel')
            ->willReturn($channel);

        $this->factory
            ->expects($this->once())
            ->method('createExchange')
            ->with($channel)
            ->willReturn($this->exchange);

        $this->exchange
            ->expects($this->once())
            ->method('setName')
            ->with('test_exchange');

        $this->serializer
            ->method('encode')
            ->willReturn(['body' => 'test', 'headers' => []]);

        $this->exchange
            ->expects($this->once())
            ->method('publish');

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $sender = new Sender($this->factory, $this->connection, $this->serializer, $options, $this->setup);
        $sender->send(new Envelope(new \stdClass()));
    }

    public function testSendCallsSetupFirst(): void
    {
        $setup = $this->createMock(InfrastructureSetup::class);
        $setup->expects($this->once())->method('setup');

        $channel = $this->createMock(\AMQPChannel::class);

        $this->connection
            ->method('getChannel')
            ->willReturn($channel);

        $this->factory
            ->method('createExchange')
            ->willReturn($this->exchange);

        $options = ['exchange' => 'test_exchange', 'routing_key' => 'test_key'];

        $sender = new Sender($this->factory, $this->connection, $this->serializer, $options, $setup);

        $envelope = new Envelope(new \stdClass());
        $this->serializer->method('encode')->willReturn(['body' => '{}', 'headers' => []]);
        $this->exchange->method('publish');

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $sender->send($envelope);
    }

    private function createSender(array $options): Sender
    {
        $sender = new Sender($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $reflection = new \ReflectionClass(Sender::class);
        $exchangeProperty = $reflection->getProperty('exchange');
        $exchangeProperty->setValue($sender, $this->exchange);

        return $sender;
    }
}
