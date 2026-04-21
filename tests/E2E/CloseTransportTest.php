<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\E2E;

use CrazyGoat\TheConsoomer\AmqpTransportFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

class CloseTransportTest extends TestCase
{
    private const EXCHANGE_NAME = 'test_close_exchange';
    private const ROUTING_KEY = 'test_close_key';
    private string $queueName;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queueName = 'test_close_queue_' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->deleteQueue($this->queueName);
        $this->deleteExchange(self::EXCHANGE_NAME);
        parent::tearDown();
    }

    public function testCloseDisconnectsConnection(): void
    {
        $dsn = $this->buildDsn(self::EXCHANGE_NAME, $this->queueName, [
            'routing_key' => self::ROUTING_KEY,
        ]);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        $transport->setup();

        $connection = $this->extractConnection($transport);
        $this->assertTrue($connection->isConnected(), 'Connection should be active after setup');

        $transport->close();

        $this->assertFalse($connection->isConnected(), 'Connection should be closed after close()');
    }

    public function testCloseIsIdempotent(): void
    {
        $dsn = $this->buildDsn(self::EXCHANGE_NAME, $this->queueName, [
            'routing_key' => self::ROUTING_KEY,
        ]);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        $transport->setup();
        $transport->close();
        $transport->close();

        // Should not throw
        $this->assertTrue(true);
    }

    public function testSendAfterCloseThrowsException(): void
    {
        $dsn = $this->buildDsn(self::EXCHANGE_NAME, $this->queueName, [
            'routing_key' => self::ROUTING_KEY,
        ]);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        $transport->setup();
        $transport->close();

        $this->expectException(\AMQPException::class);

        $envelope = new Envelope(new \stdClass());
        $transport->send($envelope);
    }

    /**
     * Extracts the connection from AmqpTransport via reflection.
     */
    private function extractConnection(object $transport): \CrazyGoat\TheConsoomer\ConnectionInterface
    {
        $reflection = new \ReflectionClass($transport);
        $property = $reflection->getProperty('connection');

        $connection = $property->getValue($transport);
        $this->assertInstanceOf(\CrazyGoat\TheConsoomer\ConnectionInterface::class, $connection);

        return $connection;
    }
}
