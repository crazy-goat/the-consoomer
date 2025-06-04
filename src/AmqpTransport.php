<?php

namespace CrazyGoat\TheConsoomer;

use CrazyGoat\TheConsoomer\Library\AmqpExtension\Receiver;
use CrazyGoat\TheConsoomer\Library\AmqpExtension\Sender;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

class AmqpTransport implements TransportInterface
{
    public function __construct(private readonly ReceiverInterface $receiver, private readonly SenderInterface $sender)
    {
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
}
