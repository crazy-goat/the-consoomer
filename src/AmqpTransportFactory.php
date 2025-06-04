<?php

namespace CrazyGoat\TheConsoomer;

use Bunny\Client;
use CrazyGoat\TheConsoomer\Library\AmqpExtension\Receiver as AmqpExtensionReceiver;
use CrazyGoat\TheConsoomer\Library\Bunny\Receiver as BunnyReceiver;
use CrazyGoat\TheConsoomer\Library\Bunny\Sender as BunnySender;
use CrazyGoat\TheConsoomer\Library\PhpAmqpLib\Receiver as PhpAmqpLibReceiver;
use CrazyGoat\TheConsoomer\Library\AmqpExtension\Sender as AmqpExtensionSender;
use CrazyGoat\TheConsoomer\Library\PhpAmqpLib\Sender as PhpAmqpLibSender;
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

        return new AmqpTransport(...$this->createConnection($info, $options, $serializer));
    }

    private function createConnection(array $info, array $options, SerializerInterface $serializer): array
    {
        if (extension_loaded('amqp')) {
            $this->logger->info('Using amqp extension');
            return $this->createAmqpExtensionConnection($info, $options, $serializer);
        } elseif (class_exists(Client::class)) {
            $this->logger->info('Using bunny library');
            return $this->createBunnyConnection($info, $options, $serializer);
        } elseif (class_exists(AMQPStreamConnection::class)) {
            $this->logger->info('Using php-amqp library');
            return $this->createPhpAmqpLibConnection($info, $options, $serializer);
        };

        throw new \RuntimeException('Missing amqp extension or PhpAmqpLib');
    }

    public function supports(string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'amqp-consoomer://');
    }

    private function parsePath(mixed $oath): array
    {
        $items = explode('/', trim((string)$oath, " \n\r\t\v\0/"));

        return ['vhost' => urldecode($items[0] ?? '/'), 'exchange' => urldecode($items[1] ?? '')];
    }

    private function createPhpAmqpLibConnection(array $info, array $options, SerializerInterface $serializer): array
    {
        $connection = new AMQPStreamConnection($info['host'], $info['port'], $info['user'], $info['pass'], $options['vhost']);
        return [new PhpAmqpLibReceiver($connection, $serializer, $options, $this->logger), new PhpAmqpLibSender($connection, $serializer, $options)];
    }

    private function createAmqpExtensionConnection(array $info, array $options, SerializerInterface $serializer): array
    {
        $connection = new \AMQPConnection();
        $connection->setHost($info['host']);
        $connection->setPort($info['port']);
        $connection->setVhost($options['vhost']);
        $connection->setLogin($info['user']);
        $connection->setPassword($info['pass']);
        $connection->setReadTimeout($options['timeout'] ?? 0.1);
        $connection->connect();

        return [
            new AmqpExtensionReceiver($connection, $serializer, $options, $this->logger),
            new AmqpExtensionSender($connection,$serializer, $options),
        ];
    }

    private function createBunnyConnection(array $info, array $options, SerializerInterface $serializer): array
    {
        $bunny = new Client([
            'host' => $info['host'],
            'port' => $info['port'],
            'vhost' => $options['vhost'],
            'user' => $info['user'],
            'password' =>  $info['pass'],
        ]);
        return [new BunnyReceiver($bunny, $serializer, $options, $this->logger), new BunnySender($bunny, $serializer, $options)];
    }
}
