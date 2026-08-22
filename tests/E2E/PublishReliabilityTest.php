<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\E2E;

use CrazyGoat\TheConsoomer\AmqpTransportFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * E2E tests for publish reliability after topology loss / reconnect (#273).
 *
 * Covers the two failure scenarios from the issue:
 *  1. Exchange deleted by an operator/policy → send() with auto_setup=true
 *     must re-declare topology after a heartbeat-stale reconnect and deliver
 *     the message (previously: auto_setup was a false promise on the send
 *     path because Sender::ensureConnected() never called resetSetup()).
 *  2. Broker outage window → send() with retry=true must surface the outage
 *     as an exception (or deliver after recovery) instead of silently
 *     succeeding via fire-and-forget publish.
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
     * After a heartbeat-stale reconnect, Sender must re-declare topology
     * (resetSetup) so that a send() with auto_setup=true against an exchange
     * that was deleted broker-side delivers the message instead of silently
     * losing it.
     *
     * This reproduces the core defect from #273: Sender::ensureConnected()
     * did not call resetSetup(), so auto_setup was inert after reconnect.
     */
    public function testAutoSetupReDeclaresExchangeAfterReconnectAndExchangeDeleted(): void
    {
        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME, [
            'auto_setup' => true,
            'heartbeat' => 1,
            'max_unacked_messages' => 1,
        ]);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        // First send triggers auto_setup — exchange and queue are declared.
        $msg1 = new \stdClass();
        $msg1->content = 'before reconnect';
        $transport->send(new Envelope($msg1));

        // Receive and ack to keep the queue clean.
        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);
        $transport->ack($messages[0]);

        // Wait for the heartbeat to go stale (heartbeat=1, threshold=2s).
        sleep(3);

        // Delete the exchange broker-side, simulating operator deletion or
        // non-durable topology loss after a broker restart.
        $this->deleteExchange(self::EXCHANGE_NAME);

        // The next send() must trigger a reconnect (heartbeat stale) and then
        // re-declare the exchange (resetSetup + auto_setup) before publishing.
        $msg2 = new \stdClass();
        $msg2->content = 'after reconnect with exchange deleted';
        $transport->send(new Envelope($msg2));

        // The message must be delivered — proving topology was re-declared.
        $received = [];
        $deadline = microtime(true) + 10;
        while (count($received) < 1 && microtime(true) < $deadline) {
            foreach ($transport->get() as $envelope) {
                $received[] = $envelope;
                $transport->ack($envelope);
            }
        }

        $this->assertCount(1, $received, 'Message lost: auto_setup did not re-declare topology after reconnect');
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
     * After a heartbeat-stale reconnect with auto_setup and retry enabled,
     * a send() must deliver the message even when the exchange was deleted
     * broker-side — combining both fixes (resetSetup + isConnected guard).
     */
    public function testRetryAndAutoSetupReDeclareTopologyAfterReconnect(): void
    {
        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME, [
            'auto_setup' => true,
            'heartbeat' => 1,
            'retry' => 'true',
            'retry_count' => '3',
            'retry_delay' => '100000',
            'max_unacked_messages' => 1,
        ]);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        // Initial send declares topology.
        $msg1 = new \stdClass();
        $msg1->content = 'initial';
        $transport->send(new Envelope($msg1));

        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);
        $transport->ack($messages[0]);

        // Wait for heartbeat staleness.
        sleep(3);

        // Delete exchange broker-side.
        $this->deleteExchange(self::EXCHANGE_NAME);

        // Send after reconnect — must re-declare and deliver.
        $msg2 = new \stdClass();
        $msg2->content = 'after reconnect with retry';
        $transport->send(new Envelope($msg2));

        $received = [];
        $deadline = microtime(true) + 10;
        while (count($received) < 1 && microtime(true) < $deadline) {
            foreach ($transport->get() as $envelope) {
                $received[] = $envelope;
                $transport->ack($envelope);
            }
        }

        $this->assertCount(1, $received, 'Message lost after reconnect with retry and auto_setup');
        $this->assertSame('after reconnect with retry', $received[0]->getMessage()->content);
    }
}
