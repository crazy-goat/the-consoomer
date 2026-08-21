<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\E2E;

use CrazyGoat\TheConsoomer\AmqpTransportFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Regression test for #271: an idle consume-timeout (the normal outcome of
 * polling an empty queue) must not discard buffered acks or tear down the
 * channel — otherwise already-processed messages are redelivered.
 */
class IdleTimeoutAckTest extends TestCase
{
    private const QUEUE_NAME = 'test_idle_ack_queue';
    private const EXCHANGE_NAME = 'test_idle_ack_exchange';

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

    /**
     * Send 1 message, consume + ack it, then poll an empty queue (idle
     * timeout), then close. After close the broker queue depth must be 0 —
     * the acked message must not have been redelivered.
     */
    public function testAckedMessageNotRedeliveredAfterIdleTimeout(): void
    {
        $this->purgeQueue(self::QUEUE_NAME);

        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME, [
            'timeout' => 0.1,
            'auto_setup' => false,
        ]);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        // Publish one message.
        $testMessage = new \stdClass();
        $testMessage->content = 'Ack survives idle timeout';
        $transport->send(new Envelope($testMessage));

        // Consume and ack it.
        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);
        $transport->ack($messages[0]);

        // Poll the now-empty queue — triggers the idle consume timeout.
        $empty = iterator_to_array($transport->get());
        $this->assertEmpty($empty);

        // Close flushes any buffered acks.
        $transport->close();

        // The acked message must not have been redelivered.
        $this->assertSame(0, $this->getQueueDepth(self::QUEUE_NAME));
    }

    private function getQueueDepth(string $name): int
    {
        $queue = new \AMQPQueue($this->channel);
        $queue->setName($name);

        $flags = $queue->getFlags();
        $queue->setFlags($flags | \AMQP_PASSIVE);
        try {
            return $queue->declareQueue();
        } finally {
            $queue->setFlags($flags);
        }
    }
}
