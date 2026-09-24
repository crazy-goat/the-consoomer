<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\E2E;

use CrazyGoat\TheConsoomer\AmqpStamp;
use CrazyGoat\TheConsoomer\AmqpTransportFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Regression test for #279: a publish-only transport (no queue/queues) must be
 * creatable and able to send when auto_setup is off.
 */
final class SendOnlyTransportTest extends TestCase
{
    private const EXCHANGE_NAME = 'test_send_only_exchange';
    private const QUEUE_NAME = 'test_send_only_queue';
    private const ROUTING_KEY = 'send_only';

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareExchange(self::EXCHANGE_NAME);
        $this->declareQueue(self::QUEUE_NAME);
        $this->bindQueue(self::QUEUE_NAME, self::EXCHANGE_NAME, self::ROUTING_KEY);
    }

    protected function tearDown(): void
    {
        $this->deleteQueue(self::QUEUE_NAME);
        $this->deleteExchange(self::EXCHANGE_NAME);

        parent::tearDown();
    }

    public function testSendOnlyTransportPublishesWithoutQueueOption(): void
    {
        $serializer = new PhpSerializer();

        // No queue/queues in the DSN and no auto_setup: pure producer.
        $dsn = sprintf(
            'amqp-consoomer://guest:guest@localhost:5672/%%2f/%s?auto_setup=false',
            self::EXCHANGE_NAME,
        );
        $producer = AmqpTransportFactory::create($dsn, [], $serializer);

        $message = new \stdClass();
        $message->content = 'from send-only transport';
        $producer->send(new Envelope($message, [new AmqpStamp(self::ROUTING_KEY)]));

        $consumer = AmqpTransportFactory::create(
            $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME, ['auto_setup' => false, 'timeout' => 0.1]),
            [],
            $serializer,
        );

        $received = [];
        $deadline = microtime(true) + 10;
        while ($received === [] && microtime(true) < $deadline) {
            foreach ($consumer->get() as $envelope) {
                $received[] = $envelope;
                $consumer->ack($envelope);
            }
        }

        $this->assertCount(1, $received);
        $this->assertSame('from send-only transport', $received[0]->getMessage()->content);
    }
}
