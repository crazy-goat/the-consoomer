<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

class AmqpTransport implements TransportInterface, MessageCountAwareInterface, SetupableTransportInterface
{
    public function __construct(
        private readonly ReceiverInterface $receiver,
        private readonly SenderInterface $sender,
        private readonly InfrastructureSetup $setup,
    ) {
    }

    public function get(): iterable
    {
        yield from $this->receiver->get();
    }

    public function ack(Envelope $envelope): void
    {
        $this->receiver->ack($envelope);
    }

    public function reject(Envelope $envelope): void
    {
        $this->receiver->reject($envelope);
    }

    public function send(Envelope $envelope): Envelope
    {
        return $this->sender->send($envelope);
    }

    public function getMessageCount(): int
    {
        if ($this->receiver instanceof MessageCountAwareInterface) {
            return $this->receiver->getMessageCount();
        }

        return 0;
    }

    public function setup(): void
    {
        $this->setup->setup();
    }
}
