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
 * Verifies #309 against a live broker: all queues of a multi-queue transport
 * share one channel, so a single consume loop must serve every consumer.
 *
 * Before the fix get() called consume() once per queue; an idle queue blocked
 * the full read_timeout, so a get() over N idle queues took N × read_timeout
 * and a message published to a later queue was delayed until every earlier
 * consumer had timed out.
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

    private function createTransport(): TransportInterface
    {
        $dsn = $this->buildDsn(self::EXCHANGE_NAME, $this->queueNames[0], ['timeout' => self::READ_TIMEOUT]);

        return AmqpTransportFactory::create($dsn, ['queues' => $this->queueOptions()], new PhpSerializer());
    }

    public function testMessageOnLastQueueIsDeliveredWithoutWaitingForEarlierQueues(): void
    {
        $transport = $this->createTransport();

        // Publish to the last of the four queues' binding key, so only its
        // consumer can receive the message. Sending through the transport
        // reuses its channel, so the message is enqueued before get() runs.
        $message = new \stdClass();
        $message->content = 'hello-last-queue';
        $transport->send(new Envelope($message, [new AmqpStamp('key4')]));

        $start = microtime(true);
        $messages = iterator_to_array($transport->get());
        $elapsed = microtime(true) - $start;

        $this->assertCount(1, $messages);
        $stamp = $messages[0]->last(AmqpReceivedStamp::class);
        $this->assertSame($this->queueNames[3], $stamp?->getQueueName());

        // Old behaviour: three earlier idle queues each blocked read_timeout
        // (~3s) before the fourth was polled. The single loop picks the
        // delivery up during its first wait.
        $this->assertLessThan(
            self::READ_TIMEOUT * 2,
            $elapsed,
            sprintf('get() took %.3fs; expected a single consume loop rather than one wait per queue', $elapsed),
        );

        $transport->ack($messages[0]);
    }

    public function testIdleGetWaitsOnceNotOncePerQueue(): void
    {
        $transport = $this->createTransport();

        $start = microtime(true);
        $messages = iterator_to_array($transport->get());
        $elapsed = microtime(true) - $start;

        $this->assertSame([], $messages);

        // The single loop waits one read_timeout for the whole channel. The
        // old per-queue polling took four (~4s) on this configuration.
        $this->assertGreaterThanOrEqual(
            self::READ_TIMEOUT * 0.5,
            $elapsed,
            sprintf('get() returned after %.3fs; expected the idle timeout to be awaited', $elapsed),
        );
        $this->assertLessThan(
            self::READ_TIMEOUT * 2,
            $elapsed,
            sprintf('get() took %.3fs; expected one read_timeout, not one per queue', $elapsed),
        );
    }
}
