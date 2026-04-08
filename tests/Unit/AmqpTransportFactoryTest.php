<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\AmqpFactoryInterface;
use CrazyGoat\TheConsoomer\AmqpTransport;
use CrazyGoat\TheConsoomer\AmqpTransportFactory;
use CrazyGoat\TheConsoomer\InfrastructureSetup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class AmqpTransportFactoryTest extends TestCase
{
    public function testSupportsReturnsTrueForAmqpConsoomerDsn(): void
    {
        $factory = new AmqpTransportFactory();

        $result = $factory->supports('amqp-consoomer://localhost:5672/%2f/messages', []);

        $this->assertTrue($result);
    }

    public function testSupportsReturnsTrueForAmqpsConsoomerScheme(): void
    {
        $factory = new AmqpTransportFactory();

        $result = $factory->supports('amqps-consoomer://localhost:5672/%2f/messages', []);

        $this->assertTrue($result);
    }

    public function testSupportsReturnsFalseForOtherDsn(): void
    {
        $factory = new AmqpTransportFactory();

        $result = $factory->supports('doctrine://default', []);

        $this->assertFalse($result);
    }

    private function createMockFactoryAndConnection(): array
    {
        $factory = $this->createMock(AmqpFactoryInterface::class);
        $connection = $this->createMock(\AMQPConnection::class);
        
        $factory
            ->expects($this->once())
            ->method('createConnection')
            ->willReturn($connection);
        
        $connection
            ->expects($this->once())
            ->method('connect');
        
        return [$factory, $connection];
    }

    public function testCreateTransportCreatesAmqpTransport(): void
    {
        [$factory, $connection] = $this->createMockFactoryAndConnection();
        $serializer = $this->createMock(SerializerInterface::class);

        $transport = AmqpTransportFactory::create(
            'amqp-consoomer://guest:guest@localhost:5672/vhost/test-exchange',
            ['queue' => 'test-queue'],
            $serializer,
            $factory,
        );

        $this->assertInstanceOf(AmqpTransport::class, $transport);
    }

    public function testCreateTransportWithAmqpsScheme(): void
    {
        $factory = $this->createMock(AmqpFactoryInterface::class);
        $connection = $this->createMock(\AMQPConnection::class);

        $factory
            ->expects($this->once())
            ->method('createConnection')
            ->willReturn($connection);

        $factory
            ->expects($this->once())
            ->method('configureSsl')
            ->with(
                $connection,
                $this->callback(function (array $options): true {
                    $this->assertTrue($options['ssl'] ?? false);
                    $this->assertSame(5671, $options['port']);
                    return true;
                }),
            );

        $connection
            ->expects($this->once())
            ->method('setHost')
            ->with('localhost');

        $connection
            ->expects($this->once())
            ->method('setPort')
            ->with(5671);

        $connection
            ->expects($this->once())
            ->method('connect');

        $transport = AmqpTransportFactory::create(
            'amqps-consoomer://guest:guest@localhost/%2f/my_exchange',
            ['exchange' => 'my_exchange', 'queue' => 'my_queue'],
            $this->createMock(SerializerInterface::class),
            $factory,
        );

        $this->assertInstanceOf(AmqpTransport::class, $transport);
    }

    public function testCreateTransportMergesOptionsWithProgrammaticOptionsTakingPrecedence(): void
    {
        $factory = $this->createMock(AmqpFactoryInterface::class);
        $connection = $this->createMock(\AMQPConnection::class);
        $serializer = $this->createMock(SerializerInterface::class);

        $factory
            ->expects($this->once())
            ->method('createConnection')
            ->with(
                $this->callback(function (array $options): true {
                    // Programmatic options (retry_count=5) should override DSN options (retry_count=3)
                    $this->assertSame(5, $options['retry_count']);
                    // DSN options should still be present if not overridden
                    $this->assertSame(100000, $options['retry_delay']);
                    return true;
                }),
            )
            ->willReturn($connection);

        $connection
            ->expects($this->once())
            ->method('connect');

        AmqpTransportFactory::create(
            'amqp-consoomer://guest:guest@localhost:5672/%2f/exchange?retry_count=3&retry_delay=100000',
            ['exchange' => 'test-exchange', 'queue' => 'test-queue', 'retry_count' => 5],
            $serializer,
            $factory,
        );
    }

    public function testCreateTransportPassesInfrastructureSetupToReceiverAndSender(): void
    {
        [$factory, $connection] = $this->createMockFactoryAndConnection();
        $serializer = $this->createMock(SerializerInterface::class);

        $transport = AmqpTransportFactory::create(
            'amqp-consoomer://guest:guest@localhost:5672/vhost/test-exchange',
            ['queue' => 'test-queue'],
            $serializer,
            $factory,
        );

        $reflection = new \ReflectionClass($transport);
        $receiverProperty = $reflection->getProperty('receiver');
        $senderProperty = $reflection->getProperty('sender');

        $receiver = $receiverProperty->getValue($transport);
        $sender = $senderProperty->getValue($transport);

        $receiverReflection = new \ReflectionClass($receiver);
        $senderReflection = new \ReflectionClass($sender);

        $receiverSetupProperty = $receiverReflection->getProperty('setup');
        $senderSetupProperty = $senderReflection->getProperty('setup');

        $receiverSetup = $receiverSetupProperty->getValue($receiver);
        $senderSetup = $senderSetupProperty->getValue($sender);

        $this->assertInstanceOf(InfrastructureSetup::class, $receiverSetup);
        $this->assertInstanceOf(InfrastructureSetup::class, $senderSetup);
        $this->assertSame($receiverSetup, $senderSetup);
    }
}
