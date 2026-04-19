<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * AMQP transport implementation for Symfony Messenger.
 *
 * Combines receiver, sender and setup components into a single transport
 * that implements the Symfony Messenger TransportInterface.
 */
final readonly class AmqpTransport implements TransportInterface, MessageCountAwareInterface, SetupableTransportInterface
{
    /**
     * @param ReceiverInterface $receiver Receiver for consuming messages
     * @param SenderInterface   $sender   Sender for publishing messages
     * @param InfrastructureSetupInterface $setup Setup handler for AMQP infrastructure
     */
    public function __construct(
        private ReceiverInterface $receiver,
        private SenderInterface $sender,
        private InfrastructureSetupInterface $setup,
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * @return iterable<Envelope>
     */
    public function get(): iterable
    {
        yield from $this->receiver->get();
    }

    /**
     * {@inheritdoc}
     */
    public function ack(Envelope $envelope): void
    {
        $this->receiver->ack($envelope);
    }

    /**
     * {@inheritdoc}
     */
    public function reject(Envelope $envelope): void
    {
        $this->receiver->reject($envelope);
    }

    /**
     * {@inheritdoc}
     *
     * @return Envelope
     */
    public function send(Envelope $envelope): Envelope
    {
        return $this->sender->send($envelope);
    }

    /**
     * {@inheritdoc}
     *
     * @return int
     */
    public function getMessageCount(): int
    {
        if ($this->receiver instanceof MessageCountAwareInterface) {
            return $this->receiver->getMessageCount();
        }

        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function setup(): void
    {
        $this->setup->setup();
    }
}
