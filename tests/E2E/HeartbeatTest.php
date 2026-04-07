<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\E2E;

use CrazyGoat\TheConsoomer\AmqpTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

class HeartbeatTest extends TestCase
{
    private const QUEUE_NAME = 'test_heartbeat_queue';
    private const EXCHANGE_NAME = 'test_heartbeat_exchange';

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

    private function createDsn(int $heartbeat = 0): string
    {
        $host = getenv('RABBITMQ_HOST') ?: 'localhost';
        $port = intval(getenv('RABBITMQ_PORT') ?: 5672);
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $password = getenv('RABBITMQ_PASSWORD') ?: 'guest';
        $vhost = getenv('RABBITMQ_VHOST') ?: '/';

        return sprintf(
            'amqp-consoomer://%s:%s@%s:%d/%s/%s?queue=%s&heartbeat=%d',
            $user,
            $password,
            $host,
            $port,
            urlencode($vhost),
            self::EXCHANGE_NAME,
            self::QUEUE_NAME,
            $heartbeat,
        );
    }

    public function testHeartbeatEnabledSendsAndReceivesMessage(): void
    {
        $dsn = $this->createDsn(heartbeat: 60);
        $serializer = new PhpSerializer();
        $transport = AmqpTransport::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'Heartbeat test message';
        $envelope = new Envelope($testMessage);

        $transport->send($envelope);

        $messages = iterator_to_array($transport->get());

        $this->assertCount(1, $messages);
        $this->assertEquals('Heartbeat test message', $messages[0]->getMessage()->content);

        $transport->ack($messages[0]);
    }

    public function testHeartbeatDisabledWorks(): void
    {
        $dsn = $this->createDsn(heartbeat: 0);
        $serializer = new PhpSerializer();
        $transport = AmqpTransport::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'No heartbeat test';
        $envelope = new Envelope($testMessage);

        $transport->send($envelope);

        $messages = iterator_to_array($transport->get());

        $this->assertCount(1, $messages);
        $this->assertEquals('No heartbeat test', $messages[0]->getMessage()->content);

        $transport->ack($messages[0]);
    }

    public function testMultipleMessagesWithHeartbeat(): void
    {
        $dsn = $this->createDsn(heartbeat: 30);
        $serializer = new PhpSerializer();
        $transport = AmqpTransport::create($dsn, [], $serializer);

        for ($i = 0; $i < 5; $i++) {
            $testMessage = new \stdClass();
            $testMessage->content = "Message $i";
            $envelope = new Envelope($testMessage);

            $transport->send($envelope);
        }

        for ($i = 0; $i < 5; $i++) {
            $messages = iterator_to_array($transport->get());
            $this->assertCount(1, $messages);
            $transport->ack($messages[0]);
        }
    }

    public function testSendReceiveAckCycleWithHeartbeat(): void
    {
        $dsn = $this->createDsn(heartbeat: 60);
        $serializer = new PhpSerializer();
        $transport = AmqpTransport::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'Send-Receive-Ack cycle';
        $envelope = new Envelope($testMessage);

        $transport->send($envelope);

        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);

        $transport->ack($messages[0]);

        $this->assertTrue(true);
    }
}
