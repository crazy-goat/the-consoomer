<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Factory for creating AMQP transport instances.
 *
 * Supports both Symfony Messenger transport factory interface and
 * direct instantiation via static create() method.
 */
class AmqpTransportFactory implements TransportFactoryInterface
{
    private const DEFAULT_READ_TIMEOUT = 0.1;
    private const DEFAULT_RETRY_COUNT = 3;
    private const DEFAULT_RETRY_DELAY = 100_000;
    private const DEFAULT_RETRY_MAX_DELAY = 30_000_000;

    private static ?DsnParser $dsnParser = null;

    /**
     * {@inheritdoc}
     *
     * @param string $dsn     DSN string
     * @param array  $options Additional options
     * @return bool True if DSN is supported (amqp-consoomer:// or amqps-consoomer://)
     */
    public function supports(string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'amqp-consoomer://') || str_starts_with($dsn, 'amqps-consoomer://');
    }

    /**
     * {@inheritdoc}
     *
     * @param string              $dsn        DSN string
     * @param array               $options    Additional options
     * @param SerializerInterface $serializer Message serializer
     */
    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        return self::create($dsn, $options, $serializer);
    }

    /**
     * Convenience method for direct instantiation outside Symfony DI.
     * Use createTransport() when integrating with Symfony Messenger transport factory system.
     *
     * @param string                 $dsn        DSN string
     * @param array{
     *     host?: string,
     *     port?: int,
     *     user?: string,
     *     password?: string,
     *     vhost?: string,
     *     exchange?: string,
     *     queue?: string,
     *     routing_key?: string,
     *     timeout?: float|int,
     *     exchange_type?: string,
     *     queue_arguments?: array<string, mixed>,
     *     max_unacked_messages?: int,
     *     auto_setup?: bool,
     *     retry?: bool,
     *     retry_count?: int,
     *     retry_delay?: int,
     *     retry_backoff?: bool,
     *     retry_max_delay?: int,
     *     retry_jitter?: bool,
     *     retry_circuit_breaker?: bool,
     *     retry_circuit_breaker_threshold?: int,
     *     retry_circuit_breaker_timeout?: int,
     *     retry_circuit_breaker_success_threshold?: int,
     *     heartbeat?: int,
     *     ssl?: bool,
     *     ssl_cert?: string,
     *     ssl_key?: string,
     *     ssl_cacert?: string,
     *     ssl_verify?: bool,
     *     exchange_flags?: int,
     *     queue_flags?: int,
     * } $options
     * @param SerializerInterface  $serializer Message serializer
     * @param AmqpFactoryInterface|null $factory    AMQP factory (optional)
     * @param LoggerInterface|null      $logger     Logger (optional)
     * @throws \InvalidArgumentException When DSN is invalid
     */
    public static function create(
        string $dsn,
        array $options,
        SerializerInterface $serializer,
        ?AmqpFactoryInterface $factory = null,
        ?LoggerInterface $logger = null,
    ): TransportInterface {
        self::$dsnParser ??= new DsnParser();
        $parsedDsn = self::$dsnParser->parse($dsn);
        $mergedOptions = [...$parsedDsn, ...$options];

        $factory ??= new AmqpFactory();

        // Native AMQP heartbeat - negotiated with broker at protocol level
        // Set via constructor to ensure RabbitMQ sees the heartbeat value
        $connection = $factory->createConnection($mergedOptions);

        // Connection parameters (host, port, vhost, user, password, timeout) are always
        // taken from $parsedDsn, not from $mergedOptions. These are part of the DSN
        // authority/path and cannot be overridden by programmatic $options.
        $connection->setHost($parsedDsn['host']);
        $connection->setPort($parsedDsn['port']);
        $connection->setVhost($parsedDsn['vhost']);
        $connection->setLogin($parsedDsn['user']);
        $connection->setPassword($parsedDsn['password']);
        $connection->setReadTimeout((float) ($parsedDsn['timeout'] ?? self::DEFAULT_READ_TIMEOUT));

        $factory->configureSsl($connection, $mergedOptions, $logger);

        // Client-side heartbeat tracking for auto-reconnect detection
        $amqpConnection = new Connection($factory, $connection);
        if (isset($mergedOptions['heartbeat'])) {
            $amqpConnection->setHeartbeat($mergedOptions['heartbeat']);
        }
        if ($logger instanceof LoggerInterface) {
            $amqpConnection->setLogger($logger);
        }

        $connection->connect();
        $amqpConnection->updateActivity();

        $setup = new InfrastructureSetup($factory, $amqpConnection, $mergedOptions);

        $retry = self::createRetry($mergedOptions, $logger);

        return new AmqpTransport(
            new Receiver($factory, $amqpConnection, $serializer, $mergedOptions, $setup, $retry),
            new Sender($factory, $amqpConnection, $serializer, $mergedOptions, $setup, $retry),
            $setup,
        );
    }

    /**
     * Creates retry configuration based on options.
     *
     * @param array{
     *     retry?: bool,
     *     retry_count?: int,
     *     retry_delay?: int,
     *     retry_backoff?: bool,
     *     retry_max_delay?: int,
     *     retry_jitter?: bool,
     *     retry_circuit_breaker?: bool,
     *     retry_circuit_breaker_threshold?: int,
     *     retry_circuit_breaker_timeout?: int,
     *     retry_circuit_breaker_success_threshold?: int,
     * } $options Retry configuration options
     * @param LoggerInterface|null $logger Logger instance
     */
    private static function createRetry(array $options, ?LoggerInterface $logger = null): ?ConnectionRetryInterface
    {
        if ($options['retry'] ?? false) {
            return new ConnectionRetry(
                retryCount: (int) ($options['retry_count'] ?? self::DEFAULT_RETRY_COUNT),
                retryDelay: (int) ($options['retry_delay'] ?? self::DEFAULT_RETRY_DELAY),
                retryBackoff: (bool) ($options['retry_backoff'] ?? false),
                retryMaxDelay: (int) ($options['retry_max_delay'] ?? self::DEFAULT_RETRY_MAX_DELAY),
                retryJitter: (bool) ($options['retry_jitter'] ?? true),
                retryCircuitBreaker: (bool) ($options['retry_circuit_breaker'] ?? false),
                retryCircuitBreakerThreshold: (int) ($options['retry_circuit_breaker_threshold'] ?? 10),
                retryCircuitBreakerTimeout: (int) ($options['retry_circuit_breaker_timeout'] ?? 60),
                retryCircuitBreakerSuccessThreshold: (int) ($options['retry_circuit_breaker_success_threshold'] ?? 2),
                logger: $logger,
            );
        }

        return null;
    }
}
