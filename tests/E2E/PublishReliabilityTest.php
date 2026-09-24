<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\E2E;

use CrazyGoat\TheConsoomer\AmqpTransportFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * E2E tests for publish reliability after topology loss / reconnect (#273),
 * adapted to the producer/consumer topology split of #308.
 *
 * A producer declares only its exchange; queues and bindings are consumer-side
 * topology. So after an operator deletes the exchange, it is a receiver cycle
 * with `redeclare_on_reconnect=true` that restores the exchange, queue and
 * binding; the producer then publishes successfully again.
 */
class PublishReliabilityTest extends TestCase
{
    private const EXCHANGE_NAME = 'test_pub_reliability_exchange';
    private const QUEUE_NAME = 'test_pub_reliability_queue';

    protected function tearDown(): void
    {
        $this->deleteQueue(self::QUEUE_NAME);
        $this->deleteExchange(self::EXCHANGE_NAME);

        parent::tearDown();
    }

    /**
     * After a heartbeat-stale reconnect, a receiver with
     * `redeclare_on_reconnect=true` re-declares the exchange, queue and binding
     * that an operator deleted, so a separate producer can publish again.
     */
    public function testReceiverReDeclaresDeletedTopologyAfterReconnect(): void
    {
        $serializer = new PhpSerializer();

        $consumer = AmqpTransportFactory::create($this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME, [
            'auto_setup' => true,
            'redeclare_on_reconnect' => true,
            'heartbeat' => 1,
            'max_unacked_messages' => 1,
        ]), [], $serializer);

        // Separate producer connection, as a real producer process would have.
        $producer = AmqpTransportFactory::create(
            $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME, ['auto_setup' => true, 'timeout' => 0.1]),
            [],
            $serializer,
        );

        // Establish consumer-side topology (exchange, queue, binding).
        iterator_to_array($consumer->get());

        $msg1 = new \stdClass();
        $msg1->content = 'before reconnect';
        $producer->send(new Envelope($msg1));

        $messages = iterator_to_array($consumer->get());
        $this->assertCount(1, $messages);
        $consumer->ack($messages[0]);

        // Wait for the consumer heartbeat to go stale.
        sleep(3);

        // Delete the exchange broker-side, simulating operator deletion.
        $this->deleteExchange(self::EXCHANGE_NAME);

        // A receiver cycle after the reconnect re-declares the topology
        // (resetSetup + auto_setup).
        iterator_to_array($consumer->get());

        $msg2 = new \stdClass();
        $msg2->content = 'after reconnect with exchange deleted';
        $producer->send(new Envelope($msg2));

        $received = [];
        $deadline = microtime(true) + 10;
        while ($received === [] && microtime(true) < $deadline) {
            foreach ($consumer->get() as $envelope) {
                $received[] = $envelope;
                $consumer->ack($envelope);
            }
        }

        $this->assertCount(1, $received, 'Message lost: the receiver did not re-declare topology after reconnect');
        $this->assertSame('after reconnect with exchange deleted', $received[0]->getMessage()->content);
    }

    /**
     * With retry enabled and publisher confirms, a send() against a missing
     * exchange must surface the error (via waitForConfirm) instead of silently
     * succeeding. This is the "exchange missing" scenario from #273, guarded
     * by confirm_timeout — the documented reliability mechanism.
     */
    public function testSendWithConfirmsAgainstMissingExchangeThrows(): void
    {
        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME, [
            'auto_setup' => false,
            'confirm_timeout' => 5,
            'timeout' => 0.1,
        ]);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        // The exchange does not exist (auto_setup=false, and we never declared
        // it). Publisher confirms should surface the broker's 404 channel-close
        // as an exception.
        $msg = new \stdClass();
        $msg->content = 'to missing exchange';

        $this->expectException(\AMQPException::class);
        $transport->send(new Envelope($msg));
    }

    /**
     * A receiver reconnect with redeclare_on_reconnect=true combined with a
     * retrying producer delivers the message after the topology was deleted.
     */
    public function testRetryProducerDeliversAfterReceiverReDeclaresTopology(): void
    {
        $serializer = new PhpSerializer();

        $consumer = AmqpTransportFactory::create($this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME, [
            'auto_setup' => true,
            'redeclare_on_reconnect' => true,
            'heartbeat' => 1,
            'max_unacked_messages' => 1,
        ]), [], $serializer);

        $producer = AmqpTransportFactory::create($this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME, [
            'auto_setup' => true,
            'retry' => 'true',
            'retry_count' => '3',
            'retry_delay' => '100000',
            'timeout' => 0.1,
        ]), [], $serializer);

        iterator_to_array($consumer->get());

        $msg1 = new \stdClass();
        $msg1->content = 'initial';
        $producer->send(new Envelope($msg1));

        $messages = iterator_to_array($consumer->get());
        $this->assertCount(1, $messages);
        $consumer->ack($messages[0]);

        sleep(3);
        $this->deleteExchange(self::EXCHANGE_NAME);

        // Receiver reconnect restores topology.
        iterator_to_array($consumer->get());

        $msg2 = new \stdClass();
        $msg2->content = 'after reconnect with retry';
        $producer->send(new Envelope($msg2));

        $received = [];
        $deadline = microtime(true) + 10;
        while ($received === [] && microtime(true) < $deadline) {
            foreach ($consumer->get() as $envelope) {
                $received[] = $envelope;
                $consumer->ack($envelope);
            }
        }

        $this->assertCount(1, $received, 'Message lost after reconnect with retry and auto_setup');
        $this->assertSame('after reconnect with retry', $received[0]->getMessage()->content);
    }
}
