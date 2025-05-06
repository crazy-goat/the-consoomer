<?php

namespace CrazyGoat\TheConsoomer;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

class AmqpTransportFactory implements TransportFactoryInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $info = parse_url($dsn);
        $query = [];
        parse_str($info['query'] ?? '', $query);
        $options = [...$options, ...$this->parsePath($info['path'] ?? ''), ...$query];

        $connection = new AMQPStreamConnection($info['host'], $info['port'], $info['user'], $info['pass'], $options['vhost']);

        return new AmqpTransport(new AmqpReceiver($connection, $serializer, $options, $this->logger), new AmqpSender($connection, $serializer, $options));
    }

    public function supports(string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'amqp-consoomer://');
    }

    private function parsePath(mixed $oath): array
    {
        $items = explode('/', trim((string) $oath, " \n\r\t\v\0/"));

        return ['vhost' => urldecode($items[0] ?? '/'), 'exchange' => urldecode($items[1] ?? '')];
    }
}
