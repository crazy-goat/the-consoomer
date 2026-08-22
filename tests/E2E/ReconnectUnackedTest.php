<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\E2E;

use CrazyGoat\TheConsoomer\AmqpReceivedStamp;
use CrazyGoat\TheConsoomer\AmqpTransportFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Regression tests for #220: a heartbeat-stale reconnect drops in-flight
 * unacked tracking. Delivery tags are scoped per channel, so acking/rejecting
 * an envelope from the dead channel on the new one is a protocol error
 * (PRECONDITION_FAILED / unknown-delivery-tag) that closes the channel and
 * crashes the worker, or silently loses the ack. The receiver must treat
 * stale delivery tags as no-ops and let the broker redeliver on the next get().
 *
 * These tests use a short heartbeat and a real sleep to drive the
 * heartbeat-stale reconnect path (the same path as
 * {@see HeartbeatTest::testReconnectsAfterHeartbeatTimeout}), with no
 * reflection hacking of internal state.
 */
class ReconnectUnackedTest extends TestCase
{
    private const QUEUE_NAME = 'test_reconnect_unacked_queue';
    private const EXCHANGE_NAME = 'test_reconnect_unacked_exchange';

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
     * Consume a message, let the connection go stale past the heartbeat window
     * (so the next operation reconnects onto a fresh channel), then attempt to
     * ack the stale envelope. The ack must be a no-op: no exception, and the
     * broker must still hold the message for redelivery on the next get().
     */
    public function testAckOnStaleEnvelopeAfterReconnectIsNoOpAndRedelivers(): void
    {
        $this->purgeQueue(self::QUEUE_NAME);

        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME, [
            'heartbeat' => 1,
            'timeout' => 0.1,
            'auto_setup' => false,
        ]);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        // Publish one message.
        $testMessage = new \stdClass();
        $testMessage->content = 'Survives reconnect';
        $transport->send(new Envelope($testMessage));

        // Consume it — the envelope carries a delivery tag from this channel,
        // stamped with channel generation 0.
        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);

        $stamp = $messages[0]->last(AmqpReceivedStamp::class);
        $this->assertInstanceOf(AmqpReceivedStamp::class, $stamp);
        $this->assertSame(0, $stamp->getChannelGeneration());

        // Let the connection go stale past the heartbeat window (threshold is
        // 2 * heartbeat). The next operation triggers ensureConnected(), which
        // reconnects onto a fresh channel and bumps the channel generation.
        sleep(3);

        // Acking the stale envelope must NOT throw and must NOT send the stale
        // delivery tag to the new channel (no protocol error, no silent loss).
        $transport->ack($messages[0]);

        // The message must still be ready for redelivery — no ack reached the
        // broker on the new channel.
        $this->assertSame(1, $this->getQueueDepth(self::QUEUE_NAME));

        // A fresh get() on the new channel must redeliver the message.
        $redelivered = iterator_to_array($transport->get());
        $this->assertCount(1, $redelivered);
        $this->assertSame('Survives reconnect', $redelivered[0]->getMessage()->content);

        // The redelivered envelope is stamped with the new generation (> 0).
        $newStamp = $redelivered[0]->last(AmqpReceivedStamp::class);
        $this->assertInstanceOf(AmqpReceivedStamp::class, $newStamp);
        $this->assertGreaterThan(0, $newStamp->getChannelGeneration());

        // Acking the fresh envelope works normally.
        $transport->ack($redelivered[0]);
        $transport->close();

        $this->assertSame(0, $this->getQueueDepth(self::QUEUE_NAME));
    }

    /**
     * Reject on a stale envelope after a reconnect must likewise be a no-op
     * (no protocol error, no silent loss — the broker redelivers).
     */
    public function testRejectOnStaleEnvelopeAfterReconnectIsNoOp(): void
    {
        $this->purgeQueue(self::QUEUE_NAME);

        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME, [
            'heartbeat' => 1,
            'timeout' => 0.1,
            'auto_setup' => false,
        ]);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'Reject survives reconnect';
        $transport->send(new Envelope($testMessage));

        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);

        // Stale the connection past the heartbeat window → next op reconnects.
        sleep(3);

        // Rejecting the stale envelope must NOT throw a protocol error.
        $transport->reject($messages[0]);

        // Message is still queued for redelivery (no reject reached the broker).
        $this->assertSame(1, $this->getQueueDepth(self::QUEUE_NAME));

        // Clean up: consume and ack the redelivered message.
        $redelivered = iterator_to_array($transport->get());
        $this->assertCount(1, $redelivered);
        $transport->ack($redelivered[0]);
        $transport->close();

        $this->assertSame(0, $this->getQueueDepth(self::QUEUE_NAME));
    }

    /**
     * After a reconnect, a fresh envelope delivered on the new channel must be
     * ackable normally (the generation guard must not suppress legitimate acks).
     */
    public function testFreshEnvelopeAfterReconnectIsAckable(): void
    {
        $this->purgeQueue(self::QUEUE_NAME);

        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME, [
            'heartbeat' => 1,
            'timeout' => 0.1,
            'auto_setup' => false,
        ]);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'Fresh after reconnect';
        $transport->send(new Envelope($testMessage));

        // Stale the connection, then get() — this reconnects and delivers on
        // the new channel.
        sleep(3);

        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);
        $this->assertSame('Fresh after reconnect', $messages[0]->getMessage()->content);

        $stamp = $messages[0]->last(AmqpReceivedStamp::class);
        $this->assertInstanceOf(AmqpReceivedStamp::class, $stamp);
        $this->assertGreaterThan(0, $stamp->getChannelGeneration());

        // Acking the fresh envelope must succeed and clear the queue.
        $transport->ack($messages[0]);
        $transport->close();

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
