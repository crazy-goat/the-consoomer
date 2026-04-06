<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\E2E;

use CrazyGoat\TheConsoomer\AmqpTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

class DsnParserOptionsTest extends TestCase
{
    private const EXCHANGE_NAME = 'test_dsn_options_exchange';
    private const QUEUE_NAME = 'test_dsn_options_queue';

    protected function setUp(): void
    {
        parent::setUp();
        $this->declareExchange(self::EXCHANGE_NAME, 'direct');
        $this->declareQueue(self::QUEUE_NAME);
        $this->bindQueue(self::QUEUE_NAME, self::EXCHANGE_NAME);
    }

    protected function tearDown(): void
    {
        $this->purgeQueue(self::QUEUE_NAME);
        $this->deleteQueue(self::QUEUE_NAME);
        $this->deleteExchange(self::EXCHANGE_NAME);
        parent::tearDown();
    }

    private function buildDsn(array $extraParams = []): string
    {
        $host = getenv('RABBITMQ_HOST') ?: 'localhost';
        $port = intval(getenv('RABBITMQ_PORT') ?: 5672);
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $password = getenv('RABBITMQ_PASSWORD') ?: 'guest';
        $vhost = getenv('RABBITMQ_VHOST') ?: '/';

        $params = array_merge(['queue' => self::QUEUE_NAME], $extraParams);
        $query = http_build_query($params);

        return sprintf(
            'amqp-consoomer://%s:%s@%s:%d/%s/%s?%s',
            $user,
            $password,
            $host,
            $port,
            urlencode($vhost),
            self::EXCHANGE_NAME,
            $query,
        );
    }

    public function testHeartbeatOption(): void
    {
        $dsn = $this->buildDsn(['heartbeat' => 60]);
        $serializer = new PhpSerializer();
        $transport = AmqpTransport::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'Heartbeat test';
        $envelope = new Envelope($testMessage);

        $transport->send($envelope);

        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);

        $transport->ack($messages[0]);
    }

    public function testTimeoutOptions(): void
    {
        $dsn = $this->buildDsn([
            'timeout' => 0.5,
            'read_timeout' => 1.0,
            'write_timeout' => 1.0,
        ]);
        $serializer = new PhpSerializer();
        $transport = AmqpTransport::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'Timeout test';
        $envelope = new Envelope($testMessage);

        $transport->send($envelope);

        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);

        $transport->ack($messages[0]);
    }

    public function testRoutingKeyOption(): void
    {
        $routingKeyQueue = 'test_routing_key_queue';
        $this->declareQueue($routingKeyQueue);
        $this->bindQueue($routingKeyQueue, self::EXCHANGE_NAME, 'custom.routing.key');

        try {
            $host = getenv('RABBITMQ_HOST') ?: 'localhost';
            $port = intval(getenv('RABBITMQ_PORT') ?: 5672);
            $user = getenv('RABBITMQ_USER') ?: 'guest';
            $password = getenv('RABBITMQ_PASSWORD') ?: 'guest';
            $vhost = getenv('RABBITMQ_VHOST') ?: '/';

            $dsn = sprintf(
                'amqp-consoomer://%s:%s@%s:%d/%s/%s?queue=%s&routing_key=custom.routing.key',
                $user,
                $password,
                $host,
                $port,
                urlencode($vhost),
                self::EXCHANGE_NAME,
                $routingKeyQueue,
            );

            $serializer = new PhpSerializer();
            $transport = AmqpTransport::create($dsn, [], $serializer);

            $testMessage = new \stdClass();
            $testMessage->content = 'Routing key test';
            $envelope = new Envelope($testMessage);

            $transport->send($envelope);

            $messages = iterator_to_array($transport->get());
            $this->assertCount(1, $messages);

            $transport->ack($messages[0]);
        } finally {
            $this->deleteQueue($routingKeyQueue);
        }
    }

    public function testMultipleOptionsCombined(): void
    {
        $dsn = $this->buildDsn([
            'heartbeat' => 30,
            'timeout' => 1.0,
            'max_unacked_messages' => 10,
        ]);
        $serializer = new PhpSerializer();
        $transport = AmqpTransport::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'Combined options test';
        $envelope = new Envelope($testMessage);

        $transport->send($envelope);

        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);

        $transport->ack($messages[0]);
    }

    public function testQueueArgumentsParsed(): void
    {
        $host = getenv('RABBITMQ_HOST') ?: 'localhost';
        $port = intval(getenv('RABBITMQ_PORT') ?: 5672);
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $password = getenv('RABBITMQ_PASSWORD') ?: 'guest';
        $vhost = getenv('RABBITMQ_VHOST') ?: '/';

        $dsn = sprintf(
            'amqp-consoomer://%s:%s@%s:%d/%s/%s?queue=%s&queue_arguments[x-max-priority]=10',
            $user,
            $password,
            $host,
            $port,
            urlencode($vhost),
            self::EXCHANGE_NAME,
            self::QUEUE_NAME,
        );

        $parser = new \CrazyGoat\TheConsoomer\DsnParser();
        $parsed = $parser->parse($dsn);

        $this->assertArrayHasKey('queue_arguments', $parsed);
        $this->assertEquals(10, $parsed['queue_arguments']['x-max-priority']);
    }
}
