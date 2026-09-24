<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\E2E;

use CrazyGoat\TheConsoomer\AmqpReceivedStamp;
use CrazyGoat\TheConsoomer\AmqpStamp;
use CrazyGoat\TheConsoomer\AmqpTransportFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Verifies the multi-queue behaviour of {@see \CrazyGoat\TheConsoomer\Receiver}
 * against a live broker: get() runs one consume() loop per queue in rotating
 * order, only the first queue waits the configured read_timeout for work, and
 * deliveries are attributed to the queue that owns the consumer (#309, #204).
 */
final class MultiQueueGetTest extends TestCase
{
    private const EXCHANGE_NAME = 'test_multi_queue_get_exchange';
    private const READ_TIMEOUT = 1.0;

    /** @var list<string> */
    private array $queueNames = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareExchange(self::EXCHANGE_NAME);

        for ($i = 1; $i <= 4; $i++) {
            $name = sprintf('test_multi_queue_get_q%d_%s', $i, uniqid());
            $this->declareQueue($name);
            $this->bindQueue($name, self::EXCHANGE_NAME, 'key' . $i);
            $this->queueNames[] = $name;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->queueNames as $name) {
            $this->deleteQueue($name);
        }
        $this->deleteExchange(self::EXCHANGE_NAME);

        parent::tearDown();
    }

    /**
     * @return array<string, array{binding_keys: list<string>}>
     */
    private function queueOptions(): array
    {
        $queues = [];
        foreach ($this->queueNames as $index => $name) {
            $queues[$name] = ['binding_keys' => ['key' . ($index + 1)]];
        }

        return $queues;
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function createTransport(array $extra = []): TransportInterface
    {
        $dsn = $this->buildDsn(self::EXCHANGE_NAME, $this->queueNames[0], ['timeout' => self::READ_TIMEOUT]);

        return AmqpTransportFactory::create(
            $dsn,
            ['queues' => $this->queueOptions(), ...$extra],
            new PhpSerializer(),
        );
    }

    private function sendTo(string $transportKey, TransportInterface $transport, string $body): void
    {
        $message = new \stdClass();
        $message->content = $body;
        $transport->send(new Envelope($message, [new AmqpStamp($transportKey)]));
    }

    public function testMessageOnLastQueueIsDeliveredWithoutWaitingForEarlierQueues(): void
    {
        $transport = $this->createTransport();

        // Publish to the last of the four queues' binding key, so only that
        // queue receives the message.
        $this->sendTo('key4', $transport, 'hello-last-queue');

        $start = microtime(true);
        $messages = iterator_to_array($transport->get());
        $elapsed = microtime(true) - $start;

        $this->assertCount(1, $messages);
        $stamp = $messages[0]->last(AmqpReceivedStamp::class);
        $this->assertSame($this->queueNames[3], $stamp?->getQueueName());

        // The message is pushed to its consumer during the first queue's wait,
        // and attributed to the queue that owns the consumer — well within a
        // single read_timeout.
        $this->assertLessThan(
            self::READ_TIMEOUT,
            $elapsed,
            sprintf('get() took %.3fs; empty queues must not block the poll', $elapsed),
        );

        $transport->ack($messages[0]);
    }

    public function testIdleGetWaitsOnceNotPerQueue(): void
    {
        $transport = $this->createTransport();

        $start = microtime(true);
        $messages = iterator_to_array($transport->get());
        $elapsed = microtime(true) - $start;

        $this->assertSame([], $messages);

        // Only the first queue waits the configured read_timeout; the other
        // three are probed with a short timeout. The old per-queue polling
        // waited four full timeouts (~4s) on this configuration.
        $this->assertGreaterThanOrEqual(
            self::READ_TIMEOUT * 0.5,
            $elapsed,
            sprintf('idle get() returned after %.3fs; the first queue should still wait for work', $elapsed),
        );
        $this->assertLessThan(
            self::READ_TIMEOUT * 1.5,
            $elapsed,
            sprintf('idle get() took %.3fs; expected ~one read_timeout, not one per queue', $elapsed),
        );
    }
}
