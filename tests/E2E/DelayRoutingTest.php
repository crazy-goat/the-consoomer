<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\E2E;

use CrazyGoat\TheConsoomer\AmqpDelayStamp;
use CrazyGoat\TheConsoomer\AmqpReceivedStamp;
use CrazyGoat\TheConsoomer\AmqpStamp;
use CrazyGoat\TheConsoomer\AmqpTransportFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Regression test for #276: a delay queue whose name pattern omits {queue}
 * maps several routing keys to one queue. The dead-letter routing key must not
 * be frozen to the key that created the queue — each message must return with
 * its own routing key.
 */
final class DelayRoutingTest extends TestCase
{
    private const EXCHANGE_NAME = 'test_delay_routing_exchange';
    private const DELAY_MS = 300;

    /** @var array<string, string> routing key => queue name */
    private array $queueNames = [];
    private string $delayQueuePattern = '';
    private string $delayQueueName = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareExchange(self::EXCHANGE_NAME);

        foreach (['rk1', 'rk2'] as $routingKey) {
            $name = sprintf('test_delay_routing_%s_%s', $routingKey, uniqid());
            $this->declareQueue($name);
            $this->bindQueue($name, self::EXCHANGE_NAME, $routingKey);
            $this->queueNames[$routingKey] = $name;
        }

        // A pattern without {queue}: both routing keys share this delay queue.
        $this->delayQueuePattern = 'test_delay_routing_dl_' . uniqid() . '_{delay}';
        $this->delayQueueName = str_replace('{delay}', (string) self::DELAY_MS, $this->delayQueuePattern);
    }

    protected function tearDown(): void
    {
        foreach ($this->queueNames as $name) {
            $this->deleteQueue($name);
        }
        $this->deleteQueue($this->delayQueueName);
        $this->deleteExchange(self::EXCHANGE_NAME);

        parent::tearDown();
    }

    public function testDelayQueueWithoutQueuePlaceholderPreservesEachRoutingKey(): void
    {
        $dsn = $this->buildDsn(self::EXCHANGE_NAME, $this->queueNames['rk1'], ['timeout' => 0.1]);

        $transport = AmqpTransportFactory::create($dsn, [
            'auto_setup' => true,
            'queues' => [
                $this->queueNames['rk1'] => ['binding_keys' => ['rk1']],
                $this->queueNames['rk2'] => ['binding_keys' => ['rk2']],
            ],
            'delay' => ['queue_name_pattern' => $this->delayQueuePattern],
        ], new PhpSerializer());

        foreach (['rk1' => 'message-for-rk1', 'rk2' => 'message-for-rk2'] as $routingKey => $content) {
            $message = new \stdClass();
            $message->content = $content;
            $transport->send(new Envelope($message, [
                new AmqpStamp($routingKey),
                new AmqpDelayStamp(self::DELAY_MS),
            ]));
        }

        // After the TTL the messages dead-letter back onto their own routing key.
        $received = [];
        $deadline = microtime(true) + 10;
        while (count($received) < 2 && microtime(true) < $deadline) {
            foreach ($transport->get() as $envelope) {
                $queueName = $envelope->last(AmqpReceivedStamp::class)?->getQueueName();
                if ($queueName !== null) {
                    $received[$queueName][] = $envelope->getMessage()->content;
                }
                $transport->ack($envelope);
            }
            if (count($received) < 2) {
                usleep(50_000);
            }
        }

        $this->assertSame(
            ['message-for-rk1'],
            $received[$this->queueNames['rk1']] ?? [],
            'the rk1 message must be dead-lettered with rk1, not the first routing key',
        );
        $this->assertSame(
            ['message-for-rk2'],
            $received[$this->queueNames['rk2']] ?? [],
            'the rk2 message must keep its own routing key through the shared delay queue',
        );
    }
}
