<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\E2E;

abstract class SslTestCase extends TestCase
{
    protected function setUp(): void
    {
        $params = $this->getSslDsnParams();

        $this->connection = new \AMQPConnection();
        $this->connection->setHost($params['host']);
        $this->connection->setPort($params['port']);
        $this->connection->setLogin($params['user']);
        $this->connection->setPassword($params['password']);
        $this->connection->setVhost($params['vhost']);
        $this->connection->setCaCert($params['cacert']);
        $this->connection->setVerify(true);
        $this->connection->connect();

        $this->channel = new \AMQPChannel($this->connection);
    }

    /**
     * @return array{host: string, port: int, user: string, password: string, vhost: string, cacert: string}
     */
    protected function getSslDsnParams(): array
    {
        return [
            'host' => getenv('RABBITMQ_HOST') ?: 'localhost',
            'port' => intval(getenv('RABBITMQ_SSL_PORT') ?: 5671),
            'user' => getenv('RABBITMQ_USER') ?: 'guest',
            'password' => getenv('RABBITMQ_PASSWORD') ?: 'guest',
            'vhost' => getenv('RABBITMQ_VHOST') ?: '/',
            'cacert' => getenv('RABBITMQ_SSL_CA_CERT') ?: __DIR__ . '/ssl/ca_certificate.pem',
        ];
    }

    /**
     * @param array<string, string|int|float> $extra
     */
    protected function buildSslDsn(string $exchange, string $queue, array $extra = []): string
    {
        $params = $this->getSslDsnParams();

        $queryParams = array_merge(['queue' => $queue, 'ssl_cacert' => $params['cacert']], $extra);
        $query = http_build_query($queryParams);

        return sprintf(
            'amqps-consoomer://%s:%s@%s:%d/%s/%s?%s',
            $params['user'],
            $params['password'],
            $params['host'],
            $params['port'],
            urlencode($params['vhost']),
            $exchange,
            $query,
        );
    }

    /**
     * @param array<string, string|int|float> $extra
     */
    protected function buildSslDsnWithOptions(string $exchange, string $queue, array $extra = []): string
    {
        $params = $this->getSslDsnParams();

        $queryParams = array_merge(['ssl_cacert' => $params['cacert']], $extra);
        $query = http_build_query($queryParams);

        return sprintf(
            'amqp-consoomer://%s:%s@%s:%d/%s/%s?%s',
            $params['user'],
            $params['password'],
            $params['host'],
            $params['port'],
            urlencode($params['vhost']),
            $exchange,
            $query,
        );
    }

    /**
     * Build DSN with amqps scheme and no queue in DSN (queue passed to factory separately).
     *
     * @param array<string, string|int|float> $extra
     */
    protected function buildAmqpsDsn(string $exchange, array $extra = []): string
    {
        $params = $this->getSslDsnParams();

        $queryParams = array_merge(['ssl_cacert' => $params['cacert']], $extra);
        $query = http_build_query($queryParams);

        return sprintf(
            'amqps-consoomer://%s:%s@%s:%d/%s/%s?%s',
            $params['user'],
            $params['password'],
            $params['host'],
            $params['port'],
            urlencode($params['vhost']),
            $exchange,
            $query,
        );
    }
}
