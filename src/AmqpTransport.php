<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Psr\Log\LoggerInterface;
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

    public static function create(string $dsn, array $options, SerializerInterface $serializer, ?AmqpFactoryInterface $factory = null, ?LoggerInterface $logger = null): TransportInterface
    {
        $dsnParser = new DsnParser();
        $parsedDsn = $dsnParser->parse($dsn);
        $mergedOptions = [...$options, ...$parsedDsn];

        $factory ??= new AmqpFactory();

        // Native AMQP heartbeat - negotiated with broker at protocol level
        // Set via constructor to ensure RabbitMQ sees the heartbeat value
        $connection = $factory->createConnection($mergedOptions);

        $connection->setHost($parsedDsn['host']);
        $connection->setPort($parsedDsn['port']);
        $connection->setVhost($parsedDsn['vhost']);
        $connection->setLogin($parsedDsn['user']);
        $connection->setPassword($parsedDsn['password']);
        $connection->setReadTimeout((float) ($parsedDsn['timeout'] ?? 0.1));

        $factory->configureSsl($connection, $mergedOptions, $logger);

        // Client-side heartbeat tracking for auto-reconnect detection
        $amqpConnection = new Connection($factory, $connection);
        if (isset($mergedOptions['heartbeat'])) {
            $amqpConnection->setHeartbeat((int) $mergedOptions['heartbeat']);
        }
        if ($logger !== null) {
            $amqpConnection->setLogger($logger);
        }

        $connection->connect();
        $amqpConnection->updateActivity();

        $setup = new InfrastructureSetup($factory, $amqpConnection, $mergedOptions);

        $retry = self::createRetry($mergedOptions, $logger);

        return new self(
            new Receiver($factory, $amqpConnection, $serializer, $mergedOptions, $setup, $retry),
            new Sender($factory, $amqpConnection, $serializer, $mergedOptions, $setup, $retry),
        );
    }

    private static function createRetry(array $options, ?LoggerInterface $logger = null): ?ConnectionRetryInterface
    {
        if ($options['retry'] ?? false) {
            return new ConnectionRetry(
                retryCount: (int) ($options['retry_count'] ?? 3),
                retryDelay: (int) ($options['retry_delay'] ?? 100000),
                retryBackoff: (bool) ($options['retry_backoff'] ?? false),
                retryMaxDelay: (int) ($options['retry_max_delay'] ?? 30000000),
                retryJitter: (bool) ($options['retry_jitter'] ?? true),
                retryCircuitBreaker: (bool) ($options['retry_circuit_breaker'] ?? false),
                retryCircuitBreakerThreshold: (int) ($options['retry_circuit_breaker_threshold'] ?? 10),
                retryCircuitBreakerTimeout: (int) ($options['retry_circuit_breaker_timeout'] ?? 60),
                logger: $logger,
            );
        }

        return null;
    }
}
