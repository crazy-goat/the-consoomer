<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\E2E;

use CrazyGoat\TheConsoomer\AmqpTransportFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * E2E regression tests for #288: broker-controlled bytes must not be able to
 * wedge the consumer.
 *
 * Pins two guards against a real broker, using a real PhpSerializer (the
 * documented, supported serializer that runs unserialize() on the body):
 *
 *  1. An oversized body is rejected (dead-lettered/requeued per broker policy)
 *     without ever being handed to decode(), and the queue is left clean — the
 *     poison message cannot clog the queue.
 *  2. A body PhpSerializer rejects (unserializable bytes) is rejected on the
 *     spot and get() survives (returns an empty batch) instead of bubbling
 *     MessageDecodingFailedException out of the consume loop.
 *  3. A valid message published after a poison one is consumed in the same
 *     cycle once the poison message has been rejected.
 */
class PoisonPillTest extends TestCase
{
    private const QUEUE_NAME = 'test_poison_pill_queue';
    private const EXCHANGE_NAME = 'test_poison_pill_exchange';

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

    public function testOversizedMessageIsRejectedWithoutDecoding(): void
    {
        $this->purgeQueue(self::QUEUE_NAME);

        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME, [
            'auto_setup' => false,
            'max_body_bytes' => 1024,
        ]);

        // Real PhpSerializer: if the oversized body ever reached decode(), it
        // would be unserialize()d — the guard must stop it before that.
        $transport = AmqpTransportFactory::create($dsn, [], new PhpSerializer());

        $this->publishMessage(self::EXCHANGE_NAME, str_repeat('x', 64 * 1024));

        // The oversized message is rejected, not delivered.
        $this->assertSame([], iterator_to_array($transport->get()));

        // ...and nothing is left on the queue for the next cycle.
        $this->assertSame(0, $this->getQueueLength());
    }

    public function testMalformedMessageIsRejectedAndBatchSurvives(): void
    {
        $this->purgeQueue(self::QUEUE_NAME);

        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME, [
            'auto_setup' => false,
            'max_body_bytes' => 0, // size guard off — this test exercises the decode() catch path
        ]);

        $transport = AmqpTransportFactory::create($dsn, [], new PhpSerializer());

        $this->publishMessage(self::EXCHANGE_NAME, 'definitely-not-a-serialized-payload');

        $this->assertSame([], iterator_to_array($transport->get()));
        $this->assertSame(0, $this->getQueueLength());
    }

    public function testValidMessageIsConsumedAfterPoisonOneWasRejected(): void
    {
        $this->purgeQueue(self::QUEUE_NAME);

        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME, [
            'auto_setup' => false,
            'max_body_bytes' => 1024,
            'batch_size' => 2, // poison reject counts toward the batch budget, like any delivery
        ]);

        $transport = AmqpTransportFactory::create($dsn, [], new PhpSerializer());

        $this->publishMessage(self::EXCHANGE_NAME, str_repeat('x', 64 * 1024));

        // A real, encodable message — published through the transport itself
        // so the body is exactly what PhpSerializer::decode() expects.
        $transport->send(new Envelope((object) ['content' => 'hello!']));

        $messages = iterator_to_array($transport->get());

        $this->assertCount(1, $messages);
        $message = $messages[0]->getMessage();
        $this->assertInstanceOf(\stdClass::class, $message);
        $this->assertSame('hello!', $message->content);
        $this->assertSame(0, $this->getQueueLength());
    }

    private function getQueueLength(): int
    {
        $queue = new \AMQPQueue($this->channel);
        $queue->setName(self::QUEUE_NAME);
        $flags = $queue->getFlags();
        $queue->setFlags($flags | AMQP_PASSIVE);
        try {
            return $queue->declareQueue();
        } finally {
            $queue->setFlags($flags);
        }
    }
}
