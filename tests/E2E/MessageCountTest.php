<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\E2E;

use CrazyGoat\TheConsoomer\AmqpTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

class MessageCountTest extends TestCase
{
    private const QUEUE_NAME = 'test_message_count_queue';
    private const EXCHANGE_NAME = 'test_message_count_exchange';

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

    public function testGetMessageCountReturnsZeroForEmptyQueue(): void
    {
        $transport = $this->createTransport();

        $count = $transport->getMessageCount();

        $this->assertSame(0, $count);
    }

    public function testGetMessageCountReturnsCorrectNumberAfterSendingMessages(): void
    {
        $transport = $this->createTransport();
        $serializer = new PhpSerializer();

        // Send 3 messages (auto_setup will create queue if needed)
        for ($i = 1; $i <= 3; ++$i) {
            $message = new \stdClass();
            $message->id = $i;
            $envelope = new Envelope($message);
            $transport->send($envelope);
        }

        // Small delay to allow RabbitMQ to process all messages
        usleep(100000); // 100ms

        // Should have 3 messages (queue created by auto_setup during first getMessageCount)
        $this->assertSame(3, $transport->getMessageCount());

        // Consume and ack one message
        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);
        $transport->ack($messages[0]);

        // Should have 2 messages remaining
        $this->assertSame(2, $transport->getMessageCount());

        // Consume and ack second message
        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);
        $transport->ack($messages[0]);

        // Should have 1 message remaining
        $this->assertSame(1, $transport->getMessageCount());

        // Consume and ack last message
        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);
        $transport->ack($messages[0]);

        // Should be empty again
        $this->assertSame(0, $transport->getMessageCount());
    }

    public function testGetMessageCountWithAutoSetupCreatesQueue(): void
    {
        $this->deleteQueue(self::QUEUE_NAME);

        $transport = $this->createTransport();

        // Queue doesn't exist but auto_setup should create it via setup()
        // After setup creates the queue, AMQP_PASSIVE declareQueue returns 0 (empty new queue)
        $count = $transport->getMessageCount();

        $this->assertSame(0, $count);
    }

    private function createTransport(): AmqpTransport
    {
        $host = getenv('RABBITMQ_HOST') ?: 'localhost';
        $port = intval(getenv('RABBITMQ_PORT') ?: 5672);
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $password = getenv('RABBITMQ_PASSWORD') ?: 'guest';
        $vhost = getenv('RABBITMQ_VHOST') ?: '/';

        $dsn = sprintf(
            'amqp-consoomer://%s:%s@%s:%d/%s/%s?queue=%s',
            $user,
            $password,
            $host,
            $port,
            urlencode($vhost),
            self::EXCHANGE_NAME,
            self::QUEUE_NAME,
        );

        $serializer = new PhpSerializer();

        return AmqpTransport::create($dsn, [], $serializer);
    }
}
