<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\AmqpFactoryInterface;
use CrazyGoat\TheConsoomer\AmqpTransport;
use CrazyGoat\TheConsoomer\InfrastructureSetup;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class AmqpTransportTest extends TestCase
{
    private ReceiverInterface&MockObject $receiver;
    private SenderInterface&MockObject $sender;
    private SerializerInterface&MockObject $serializer;
    private InfrastructureSetup&MockObject $setup;

    protected function setUp(): void
    {
        $this->receiver = $this->createMock(ReceiverInterface::class);
        $this->sender = $this->createMock(SenderInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->setup = $this->createMock(InfrastructureSetup::class);
    }

    public function testSupportsReturnsTrueForAmqpConsoomerDsn(): void
    {
        $transport = new AmqpTransport($this->receiver, $this->sender, $this->setup);

        $this->assertTrue($transport->supports('amqp-consoomer://localhost', []));
    }

    public function testSupportsReturnsTrueForAmqpConsoomerDsnWithHost(): void
    {
        $transport = new AmqpTransport($this->receiver, $this->sender, $this->setup);

        $this->assertTrue($transport->supports('amqp-consoomer://rabbitmq.example.com', []));
    }

    public function testSupportsReturnsFalseForOtherDsn(): void
    {
        $transport = new AmqpTransport($this->receiver, $this->sender, $this->setup);

        $this->assertFalse($transport->supports('amqp://localhost', []));
    }

    public function testSupportsReturnsFalseForAmqpDsnWithoutConsoomer(): void
    {
        $transport = new AmqpTransport($this->receiver, $this->sender, $this->setup);

        $this->assertFalse($transport->supports('amqp://localhost', []));
    }

    public function testSupportsReturnsFalseForRabbitMqDsn(): void
    {
        $transport = new AmqpTransport($this->receiver, $this->sender, $this->setup);

        $this->assertFalse($transport->supports('rabbitmq://localhost', []));
    }

    public function testSupportsAmqpsConsoomerScheme(): void
    {
        $transport = new AmqpTransport(
            $this->createMock(ReceiverInterface::class),
            $this->createMock(SenderInterface::class),
            $this->setup,
        );

        $this->assertTrue($transport->supports('amqps-consoomer://localhost/%2f/exchange', []));
    }

    public function testSupportsReturnsFalseForGenericAmqpsScheme(): void
    {
        $transport = new AmqpTransport(
            $this->createMock(ReceiverInterface::class),
            $this->createMock(SenderInterface::class),
            $this->setup,
        );

        $this->assertFalse($transport->supports('amqps://localhost/%2f/exchange', []));
    }

    public function testGetDelegatesToReceiver(): void
    {
        $envelope = new Envelope(new \stdClass());

        $this->receiver
            ->expects($this->once())
            ->method('get')
            ->willReturn([$envelope]);

        $transport = new AmqpTransport($this->receiver, $this->sender, $this->setup);

        $result = iterator_to_array($transport->get());

        $this->assertSame([$envelope], $result);
    }

    public function testGetReturnsEmptyIterableWhenReceiverReturnsEmpty(): void
    {
        $this->receiver
            ->expects($this->once())
            ->method('get')
            ->willReturn([]);

        $transport = new AmqpTransport($this->receiver, $this->sender, $this->setup);

        $result = iterator_to_array($transport->get());

        $this->assertSame([], $result);
    }

    public function testAckDelegatesToReceiver(): void
    {
        $envelope = new Envelope(new \stdClass());

        $this->receiver
            ->expects($this->once())
            ->method('ack')
            ->with($envelope);

        $transport = new AmqpTransport($this->receiver, $this->sender, $this->setup);

        $transport->ack($envelope);
    }

    public function testRejectDelegatesToReceiver(): void
    {
        $envelope = new Envelope(new \stdClass());

        $this->receiver
            ->expects($this->once())
            ->method('reject')
            ->with($envelope);

        $transport = new AmqpTransport($this->receiver, $this->sender, $this->setup);

        $transport->reject($envelope);
    }

    public function testSendDelegatesToSender(): void
    {
        $envelope = new Envelope(new \stdClass());
        $returnedEnvelope = new Envelope(new \stdClass());

        $this->sender
            ->expects($this->once())
            ->method('send')
            ->with($envelope)
            ->willReturn($returnedEnvelope);

        $transport = new AmqpTransport($this->receiver, $this->sender, $this->setup);

        $result = $transport->send($envelope);

        $this->assertSame($returnedEnvelope, $result);
    }

    public function testSendReturnsSameEnvelopeFromSender(): void
    {
        $envelope = new Envelope(new \stdClass());

        $this->sender
            ->expects($this->once())
            ->method('send')
            ->with($envelope)
            ->willReturn($envelope);

        $transport = new AmqpTransport($this->receiver, $this->sender, $this->setup);

        $result = $transport->send($envelope);

        $this->assertSame($envelope, $result);
    }

    public function testCreatePassesInfrastructureSetupToReceiverAndSender(): void
    {
        $factory = $this->createMock(AmqpFactoryInterface::class);
        $connection = $this->createMock(\AMQPConnection::class);
        $serializer = $this->createMock(SerializerInterface::class);

        $factory
            ->expects($this->once())
            ->method('createConnection')
            ->willReturn($connection);

        $connection
            ->expects($this->once())
            ->method('connect');

        $transport = AmqpTransport::create(
            'amqp-consoomer://guest:guest@localhost:5672/vhost',
            ['queue' => 'test-queue', 'exchange' => 'test-exchange'],
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

    public function testCreateWithAmqpsScheme(): void
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

        $transport = AmqpTransport::create(
            'amqps-consoomer://guest:guest@localhost/%2f/my_exchange',
            ['exchange' => 'my_exchange', 'queue' => 'my_queue'],
            $this->serializer,
            $factory,
        );

        $this->assertInstanceOf(AmqpTransport::class, $transport);
    }

    public function testGetMessageCountDelegatesToReceiver(): void
    {
        $receiver = new class ($this->createMock(ReceiverInterface::class)) implements ReceiverInterface, MessageCountAwareInterface {
            public function __construct(private readonly ReceiverInterface $inner)
            {
            }
            public function get(): iterable
            {
                return $this->inner->get();
            }
            public function ack(Envelope $envelope): void
            {
                $this->inner->ack($envelope);
            }
            public function reject(Envelope $envelope): void
            {
                $this->inner->reject($envelope);
            }
            public function getMessageCount(): int
            {
                return 42;
            }
        };

        $transport = new AmqpTransport($receiver, $this->sender, $this->setup);

        $this->assertSame(42, $transport->getMessageCount());
    }

    public function testGetMessageCountReturnsZeroWhenReceiverNotMessageCountAware(): void
    {
        $receiver = $this->createMock(ReceiverInterface::class);

        $transport = new AmqpTransport($receiver, $this->sender, $this->setup);

        $this->assertSame(0, $transport->getMessageCount());
    }

    public function testSetupDelegatesToInfrastructureSetup(): void
    {
        $this->setup
            ->expects($this->once())
            ->method('setup');

        $transport = new AmqpTransport($this->receiver, $this->sender, $this->setup);

        $transport->setup();
    }

    public function testSetupIsIdempotent(): void
    {
        $this->setup
            ->expects($this->exactly(2))
            ->method('setup');

        $transport = new AmqpTransport($this->receiver, $this->sender, $this->setup);

        $transport->setup();
        $transport->setup();
    }
}
