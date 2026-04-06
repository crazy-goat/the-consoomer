<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\AmqpFactoryInterface;
use CrazyGoat\TheConsoomer\AmqpTransport;
use CrazyGoat\TheConsoomer\InfrastructureSetup;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class AmqpTransportTest extends TestCase
{
    private ReceiverInterface&MockObject $receiver;
    private SenderInterface&MockObject $sender;

    protected function setUp(): void
    {
        $this->receiver = $this->createMock(ReceiverInterface::class);
        $this->sender = $this->createMock(SenderInterface::class);
    }

    public function testSupportsReturnsTrueForAmqpConsoomerDsn(): void
    {
        $transport = new AmqpTransport($this->receiver, $this->sender);

        $this->assertTrue($transport->supports('amqp-consoomer://localhost', []));
    }

    public function testSupportsReturnsTrueForAmqpConsoomerDsnWithHost(): void
    {
        $transport = new AmqpTransport($this->receiver, $this->sender);

        $this->assertTrue($transport->supports('amqp-consoomer://rabbitmq.example.com', []));
    }

    public function testSupportsReturnsFalseForOtherDsn(): void
    {
        $transport = new AmqpTransport($this->receiver, $this->sender);

        $this->assertFalse($transport->supports('amqp://localhost', []));
    }

    public function testSupportsReturnsFalseForAmqpDsnWithoutConsoomer(): void
    {
        $transport = new AmqpTransport($this->receiver, $this->sender);

        $this->assertFalse($transport->supports('amqp://localhost', []));
    }

    public function testSupportsReturnsFalseForRabbitMqDsn(): void
    {
        $transport = new AmqpTransport($this->receiver, $this->sender);

        $this->assertFalse($transport->supports('rabbitmq://localhost', []));
    }

    public function testGetDelegatesToReceiver(): void
    {
        $envelope = new Envelope(new \stdClass());

        $this->receiver
            ->expects($this->once())
            ->method('get')
            ->willReturn([$envelope]);

        $transport = new AmqpTransport($this->receiver, $this->sender);

        $result = iterator_to_array($transport->get());

        $this->assertSame([$envelope], $result);
    }

    public function testGetReturnsEmptyIterableWhenReceiverReturnsEmpty(): void
    {
        $this->receiver
            ->expects($this->once())
            ->method('get')
            ->willReturn([]);

        $transport = new AmqpTransport($this->receiver, $this->sender);

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

        $transport = new AmqpTransport($this->receiver, $this->sender);

        $transport->ack($envelope);
    }

    public function testRejectDelegatesToReceiver(): void
    {
        $envelope = new Envelope(new \stdClass());

        $this->receiver
            ->expects($this->once())
            ->method('reject')
            ->with($envelope);

        $transport = new AmqpTransport($this->receiver, $this->sender);

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

        $transport = new AmqpTransport($this->receiver, $this->sender);

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

        $transport = new AmqpTransport($this->receiver, $this->sender);

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
}
