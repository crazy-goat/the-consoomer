<?php

namespace CrazyGoat\TheConsoomer;

use Psr\Log\NullLogger;
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
        return str_starts_with($dsn, 'amqp-consoomer://');
    }

    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        return self::create($dsn, $options, $serializer);
    }

    public static function create(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $info = parse_url($dsn);
        $query = [];
        parse_str($info['query'] ?? '', $query);
        $mergedOptions = [...$options, ...self::parsePath($info['path'] ?? ''), ...$query];

        $connection = new \AMQPConnection();
        $connection->setHost($info['host']);
        $connection->setPort($info['port']);
        $connection->setVhost($mergedOptions['vhost']);
        $connection->setLogin($info['user']);
        $connection->setPassword($info['pass']);
        $connection->setReadTimeout($mergedOptions['timeout'] ?? 0.1);
        $connection->connect();

        $logger = new NullLogger();

        return new self(
            new Receiver($connection, $serializer, $mergedOptions, $logger),
            new Sender($connection, $serializer, $mergedOptions),
        );
    }

    private static function parsePath(mixed $path): array
    {
        $items = explode('/', trim((string) $path, " \n\r\t\v\0/"));

        return ['vhost' => urldecode($items[0] ?? '/'), 'exchange' => urldecode($items[1] ?? '')];
    }
}
