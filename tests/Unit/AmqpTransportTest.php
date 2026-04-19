<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\AmqpTransport;
use CrazyGoat\TheConsoomer\InfrastructureSetupInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

class AmqpTransportTest extends TestCase
{
    private ReceiverInterface&MockObject $receiver;
    private SenderInterface&MockObject $sender;
    private InfrastructureSetupInterface&MockObject $setup;

    protected function setUp(): void
    {
        $this->receiver = $this->createMock(ReceiverInterface::class);
        $this->sender = $this->createMock(SenderInterface::class);
        $this->setup = $this->createMock(InfrastructureSetupInterface::class);
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
}
