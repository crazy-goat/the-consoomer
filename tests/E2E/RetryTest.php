<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\E2E;

use CrazyGoat\TheConsoomer\AmqpTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

class RetryTest extends TestCase
{
    private const QUEUE_NAME = 'test_retry_queue';
    private const EXCHANGE_NAME = 'test_retry_exchange';

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareExchange(self::EXCHANGE_NAME);
        $this->declareQueue(self::QUEUE_NAME);
        $this->bindQueue(self::QUEUE_NAME, self::EXCHANGE_NAME);
    }

    protected function tearDown(): void
    {
        $this->deleteQueue(self::QUEUE_NAME);
        $this->deleteExchange(self::EXCHANGE_NAME);

        parent::tearDown();
    }

    public function testTransportWithRetryEnabled(): void
    {
        $host = getenv('RABBITMQ_HOST') ?: 'localhost';
        $port = intval(getenv('RABBITMQ_PORT') ?: 5672);
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $password = getenv('RABBITMQ_PASSWORD') ?: 'guest';
        $vhost = getenv('RABBITMQ_VHOST') ?: '/';

        $dsn = sprintf(
            'amqp-consoomer://%s:%s@%s:%d/%s/%s?queue=%s&retry=true&retry_count=3&retry_delay=100000',
            $user,
            $password,
            $host,
            $port,
            urlencode($vhost),
            self::EXCHANGE_NAME,
            self::QUEUE_NAME,
        );

        $serializer = new PhpSerializer();
        $transport = AmqpTransport::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'Hello Retry Test';
        $envelope = new Envelope($testMessage);

        $transport->send($envelope);

        $messages = $transport->get();
        $this->assertIsIterable($messages);
        $messages = iterator_to_array($messages);

        $this->assertCount(1, $messages);

        /** @var Envelope $receivedEnvelope */
        $receivedEnvelope = $messages[0];
        $transport->ack($receivedEnvelope);
    }

    public function testTransportWithRetryAndBackoff(): void
    {
        $host = getenv('RABBITMQ_HOST') ?: 'localhost';
        $port = intval(getenv('RABBITMQ_PORT') ?: 5672);
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $password = getenv('RABBITMQ_PASSWORD') ?: 'guest';
        $vhost = getenv('RABBITMQ_VHOST') ?: '/';

        $dsn = sprintf(
            'amqp-consoomer://%s:%s@%s:%d/%s/%s?queue=%s&retry=true&retry_count=3&retry_backoff=true&retry_jitter=false&retry_max_delay=1000000',
            $user,
            $password,
            $host,
            $port,
            urlencode($vhost),
            self::EXCHANGE_NAME,
            self::QUEUE_NAME,
        );

        $serializer = new PhpSerializer();
        $transport = AmqpTransport::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'Hello Backoff Test';
        $envelope = new Envelope($testMessage);

        $transport->send($envelope);

        $messages = $transport->get();
        $messages = iterator_to_array($messages);

        $this->assertCount(1, $messages);
    }

    public function testTransportWithCircuitBreaker(): void
    {
        $host = getenv('RABBITMQ_HOST') ?: 'localhost';
        $port = intval(getenv('RABBITMQ_PORT') ?: 5672);
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $password = getenv('RABBITMQ_PASSWORD') ?: 'guest';
        $vhost = getenv('RABBITMQ_VHOST') ?: '/';

        $dsn = sprintf(
            'amqp-consoomer://%s:%s@%s:%d/%s/%s?queue=%s&retry=true&retry_count=1&retry_circuit_breaker=true&retry_circuit_breaker_threshold=5&retry_circuit_breaker_timeout=60',
            $user,
            $password,
            $host,
            $port,
            urlencode($vhost),
            self::EXCHANGE_NAME,
            self::QUEUE_NAME,
        );

        $serializer = new PhpSerializer();
        $transport = AmqpTransport::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'Hello Circuit Breaker Test';
        $envelope = new Envelope($testMessage);

        $transport->send($envelope);

        $messages = $transport->get();
        $messages = iterator_to_array($messages);

        $this->assertCount(1, $messages);
    }
}
