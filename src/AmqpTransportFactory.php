<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

class AmqpTransportFactory implements TransportFactoryInterface
{
    public function supports(string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'amqp-consoomer://') || str_starts_with($dsn, 'amqps-consoomer://');
    }

    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        throw new \RuntimeException('Not implemented yet');
    }

    public static function createRetry(array $options, ?LoggerInterface $logger = null): ?ConnectionRetryInterface
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
