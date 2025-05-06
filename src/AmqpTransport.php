<?php

namespace CrazyGoat\TheConsoomer;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;

class AmqpTransport implements TransportInterface
{
    public function __construct(private readonly AmqpReceiver $receiver, private readonly AmqpSender $sender)
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