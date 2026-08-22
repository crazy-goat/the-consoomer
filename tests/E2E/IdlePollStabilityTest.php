<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\E2E;

use CrazyGoat\TheConsoomer\AmqpFactory;
use CrazyGoat\TheConsoomer\AmqpFactoryInterface;
use CrazyGoat\TheConsoomer\AmqpTransportFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * E2E regression tests for #306: idle consume-timeout polls must NOT tear
 * down the channel, consumers, or buffered acks.
 *
 * The fix (commit 19187ad, #271) already distinguishes \AMQPQueueException
 * (the normal idle-poll timeout) from genuine connection/channel failures.
 * These tests pin the measurable guarantees the issue asks for:
 *
 *  1. A spying AmqpFactoryInterface sees createChannel/createQueue called
 *     exactly once across many idle polls — no per-cycle channel/consumer
 *     churn (today, without the fix: 50 channels created).
 *  2. Already-processed messages buffered under max_unacked_messages are
 *     not redelivered when the queue is polled empty multiple times and the
 *     transport is then closed and re-opened — queue depth stays 0.
 */
class IdlePollStabilityTest extends TestCase
{
    private const QUEUE_NAME = 'test_idle_poll_stability_queue';
    private const EXCHANGE_NAME = 'test_idle_poll_stability_exchange';

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

    public function testFactoryCreatesChannelAndQueueOnceAcrossManyIdlePolls(): void
    {
        $this->purgeQueue(self::QUEUE_NAME);

        $spy = new SpyAmqpFactory();

        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME, [
            'timeout' => 0.1,
            'auto_setup' => false,
        ]);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer, $spy);

        // Poll an empty queue 50 times — each get() hits the idle consume
        // timeout. Before the fix each cycle tore down the channel and
        // recreated consumers (50 channel.open + 50 basic.consume RTTs).
        for ($i = 0; $i < 50; $i++) {
            $messages = iterator_to_array($transport->get());
            $this->assertSame([], $messages, \sprintf('Idle poll #%d must return no messages', $i));
        }

        $transport->close();

        // The channel and the consume queue must have been created exactly
        // once — the idle timeouts must not have triggered teardown.
        $this->assertSame(1, $spy->createChannelCalls, 'createChannel() must be called exactly once across 50 idle polls');
        $this->assertSame(1, $spy->createQueueCalls, 'createQueue() must be called exactly once (one consumer) across 50 idle polls');
    }

    public function testBufferedAcksNotRedeliveredAcrossMultipleIdlePollsAndReopen(): void
    {
        $this->purgeQueue(self::QUEUE_NAME);

        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME, [
            'timeout' => 0.1,
            'auto_setup' => false,
            // Keep all 10 acks buffered — default 100 threshold is never hit.
            'max_unacked_messages' => 100,
        ]);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        // Publish 10 messages.
        for ($i = 0; $i < 10; $i++) {
            $msg = new \stdClass();
            $msg->content = \sprintf('No-redelivery #%d', $i);
            $transport->send(new Envelope($msg));
        }

        // Consume and ack all 10 — acks stay buffered (max_unacked=100).
        for ($i = 0; $i < 10; $i++) {
            $messages = iterator_to_array($transport->get());
            $this->assertCount(1, $messages, \sprintf('Poll #%d must return the 1 expected message', $i));
            $transport->ack($messages[0]);
        }

        // Poll the now-empty queue twice — each triggers an idle timeout that
        // must NOT wipe the 10 buffered acks.
        for ($i = 0; $i < 2; $i++) {
            $empty = iterator_to_array($transport->get());
            $this->assertSame([], $empty, \sprintf('Idle poll #%d must return no messages', $i));
        }

        // Close flushes the buffered acks, then disconnect.
        $transport->close();

        // Re-open a fresh transport and confirm the queue is empty — the 10
        // acked messages must not have been redelivered.
        $reopenTransport = AmqpTransportFactory::create($dsn, [], $serializer);
        $leftover = iterator_to_array($reopenTransport->get());
        $this->assertSame([], $leftover, 'The 10 acked messages must not have been redelivered after idle polls + close + reopen');
        $reopenTransport->close();

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

/**
 * AmqpFactory decorator that counts createChannel()/createQueue() calls so
 * a test can assert the receiver did not tear down and recreate AMQP
 * resources across idle polls. Delegates every real operation to the
 * inner {@see AmqpFactory}.
 */
final class SpyAmqpFactory implements AmqpFactoryInterface
{
    public int $createChannelCalls = 0;
    public int $createQueueCalls = 0;

    private readonly AmqpFactory $inner;

    public function __construct()
    {
        $this->inner = new AmqpFactory();
    }

    public function createConnection(array $options = []): \AMQPConnection
    {
        return $this->inner->createConnection($options);
    }

    public function createChannel(\AMQPConnection $connection): \AMQPChannel
    {
        $this->createChannelCalls++;

        return $this->inner->createChannel($connection);
    }

    public function createQueue(\AMQPChannel $channel): \AMQPQueue
    {
        $this->createQueueCalls++;

        return $this->inner->createQueue($channel);
    }

    public function createExchange(\AMQPChannel $channel): \AMQPExchange
    {
        return $this->inner->createExchange($channel);
    }

    public function configureSsl(\AMQPConnection $connection, array $options, ?LoggerInterface $logger = null): void
    {
        $this->inner->configureSsl($connection, $options, $logger);
    }

    public function hasCaCertConfigured(array $options): bool
    {
        return $this->inner->hasCaCertConfigured($options);
    }
}
