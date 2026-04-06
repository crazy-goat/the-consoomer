<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

class AmqpTransport implements TransportInterface, TransportFactoryInterface
{
    public function __construct(
        private readonly ReceiverInterface $receiver,
        private readonly SenderInterface $sender,
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

    public function supports(string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'amqp-consoomer://') || str_starts_with($dsn, 'amqps://');
    }

    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        return self::create($dsn, $options, $serializer);
    }

    public static function create(string $dsn, array $options, SerializerInterface $serializer, ?AmqpFactoryInterface $factory = null): TransportInterface
    {
        $dsnParser = new DsnParser();
        $parsedDsn = $dsnParser->parse($dsn);
        $mergedOptions = [...$options, ...$parsedDsn];

        $factory ??= new AmqpFactory();
        $connection = $factory->createConnection();
        $connection->setHost($parsedDsn['host']);
        $connection->setPort($parsedDsn['port']);
        $connection->setVhost($parsedDsn['vhost']);
        $connection->setLogin($parsedDsn['user']);
        $connection->setPassword($parsedDsn['password']);
        $connection->setReadTimeout((float) ($parsedDsn['timeout'] ?? 0.1));

        $factory->configureSsl($connection, $mergedOptions);

        $connection->connect();

        $setup = new InfrastructureSetup($factory, $connection, $mergedOptions);

        return new self(
            new Receiver($factory, $connection, $serializer, $mergedOptions, $setup),
            new Sender($factory, $connection, $serializer, $mergedOptions, $setup),
        );
    }
}
