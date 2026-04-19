<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\AmqpFactory;
use CrazyGoat\TheConsoomer\Connection;
use CrazyGoat\TheConsoomer\Exception\MissingStampException;
use CrazyGoat\TheConsoomer\InfrastructureSetup;
use CrazyGoat\TheConsoomer\RawMessageStamp;
use CrazyGoat\TheConsoomer\Receiver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class ReceiverTest extends TestCase
{
    private AmqpFactory&MockObject $factory;
    private Connection&MockObject $connection;
    private SerializerInterface&MockObject $serializer;
    private \AMQPQueue&MockObject $queue;
    private InfrastructureSetup&MockObject $setup;

    protected function setUp(): void
    {
        $this->factory = $this->createMock(AmqpFactory::class);
        $this->connection = $this->createMock(Connection::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->queue = $this->createMock(\AMQPQueue::class);
        $this->setup = $this->createMock(InfrastructureSetup::class);
    }

    public function testGetCallsSetupFirst(): void
    {
        $setup = $this->createMock(InfrastructureSetup::class);
        $setup->expects($this->once())->method('setup');

        $channel = $this->createMock(\AMQPChannel::class);
        $this->connection->method('getChannel')->willReturn($channel);
        $this->factory->method('createQueue')->willReturn($this->queue);
        $this->queue->method('getConsumerTag')->willReturn('consumer_tag');

        $options = ['queue' => 'test_queue'];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $setup);

        try {
            $receiver->get();
        } catch (\AMQPException) {
            // Expected - queue not fully mocked, but setup was called
        }
    }

    public function testGetReturnsEmptyArrayWhenNoMessage(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = $this->createReceiverWithQueue($options);

        $this->queue
            ->expects($this->once())
            ->method('consume')
            ->willThrowException(new \AMQPException('Consumer timeout exceed'));

        $this->queue
            ->method('getConsumerTag')
            ->willReturn('test_tag');

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $result = $receiver->get();

        $this->assertSame([], $result);
    }

    /**
     * @dataProvider timeoutMessageVariationProvider
     */
    public function testGetReturnsEmptyArrayForTimeoutMessageVariation(string $message): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = $this->createReceiverWithQueue($options);

        $this->queue
            ->expects($this->once())
            ->method('consume')
            ->willThrowException(new \AMQPException($message));

        $this->queue
            ->method('getConsumerTag')
            ->willReturn('test_tag');

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $result = $receiver->get();

        $this->assertSame([], $result, "Failed for message: {$message}");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function timeoutMessageVariationProvider(): array
    {
        return [
            'original message' => ['Consumer timeout exceed'],
            'grammatically correct' => ['Consumer timeout exceeded'],
            'with verb has been' => ['Consumer timeout has been exceeded'],
            'with colon' => ['Consumer timeout: exceeded'],
        ];
    }

    public function testGetReturnsMessageWhenAvailable(): void
    {
        $options = ['queue' => 'test_queue'];

        $amqpEnvelope = $this->createMock(\AMQPEnvelope::class);
        $amqpEnvelope
            ->method('getBody')
            ->willReturn('{"data":"test"}');

        $messageEnvelope = new Envelope(new \stdClass());

        $this->serializer
            ->expects($this->once())
            ->method('decode')
            ->with(['body' => '{"data":"test"}'])
            ->willReturn($messageEnvelope);

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $reflection = new \ReflectionClass(Receiver::class);
        $queueProperty = $reflection->getProperty('queue');
        $queueProperty->setValue($receiver, $this->queue);

        $callbackProperty = $reflection->getProperty('callback');
        $callbackProperty->setValue($receiver, function (\AMQPEnvelope $message) use ($receiver, $reflection): false {
            $serializer = $reflection->getProperty('serializer');

            $envelope = $serializer->getValue($receiver)->decode(['body' => $message->getBody()]);
            $messageProperty = $reflection->getProperty('message');
            $messageProperty->setValue($receiver, $envelope->with(new RawMessageStamp($message)));

            return false;
        });

        $this->queue
            ->expects($this->once())
            ->method('consume')
            ->willReturnCallback(function ($callback) use ($amqpEnvelope): void {
                $callback($amqpEnvelope);
            });

        $this->queue
            ->method('getConsumerTag')
            ->willReturn('test_tag');

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $result = $receiver->get();

        $this->assertCount(1, $result);
        $this->assertInstanceOf(Envelope::class, $result[0]);
        $this->assertInstanceOf(RawMessageStamp::class, $result[0]->last(RawMessageStamp::class));
    }

    public function testAckThrowsExceptionWithoutStamp(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $envelope = new Envelope(new \stdClass());

        $this->expectException(MissingStampException::class);
        $this->expectExceptionMessage('No raw message stamp');

        $receiver->ack($envelope);
    }

    public function testRejectThrowsExceptionWithoutStamp(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $envelope = new Envelope(new \stdClass());

        $this->expectException(MissingStampException::class);
        $this->expectExceptionMessage('No raw message stamp');

        $receiver->reject($envelope);
    }

    public function testAckAcknowledgesMessage(): void
    {
        $options = ['queue' => 'test_queue', 'max_unacked_messages' => 1];

        $receiver = $this->createReceiverWithQueue($options);

        $amqpEnvelope = $this->createMock(\AMQPEnvelope::class);
        $amqpEnvelope
            ->method('getDeliveryTag')
            ->willReturn(123);

        $stamp = new RawMessageStamp($amqpEnvelope);
        $envelope = new Envelope(new \stdClass(), [$stamp]);

        $this->queue
            ->expects($this->once())
            ->method('ack')
            ->with(123, AMQP_MULTIPLE);

        $receiver->ack($envelope);
    }

    public function testBatchAckTriggersAfterMaxUnackedMessages(): void
    {
        $options = ['queue' => 'test_queue', 'max_unacked_messages' => 3];

        $receiver = $this->createReceiverWithQueue($options);

        $envelopes = [];
        for ($i = 1; $i <= 6; $i++) {
            $amqpEnvelope = $this->createMock(\AMQPEnvelope::class);
            $amqpEnvelope->method('getDeliveryTag')->willReturn($i);
            $stamps = [new RawMessageStamp($amqpEnvelope)];
            $envelopes[] = new Envelope(new \stdClass(), $stamps);
        }

        $ackCallCount = 0;
        $ackedTags = [];

        $this->queue
            ->expects($this->exactly(2))
            ->method('ack')
            ->willReturnCallback(function ($tag, $flags) use (&$ackCallCount, &$ackedTags): void {
                $ackCallCount++;
                $ackedTags[] = $tag;
                $this->assertSame(AMQP_MULTIPLE, $flags);
            });

        $receiver->ack($envelopes[0]);
        $receiver->ack($envelopes[1]);
        $receiver->ack($envelopes[2]);
        $receiver->ack($envelopes[3]);
        $receiver->ack($envelopes[4]);
        $receiver->ack($envelopes[5]);

        $this->assertSame([3, 6], $ackedTags);
    }

    public function testRejectRejectsMessage(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = $this->createReceiverWithQueue($options);

        $amqpEnvelope = $this->createMock(\AMQPEnvelope::class);
        $amqpEnvelope
            ->method('getDeliveryTag')
            ->willReturn(456);

        $stamp = new RawMessageStamp($amqpEnvelope);
        $envelope = new Envelope(new \stdClass(), [$stamp]);

        $this->queue
            ->expects($this->once())
            ->method('reject')
            ->with(456);

        $receiver->reject($envelope);
    }

    public function testMaxUnackedMessagesConfigurationDefaultsTo100(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $reflection = new \ReflectionClass(Receiver::class);
        $maxUnackedProperty = $reflection->getProperty('maxUnackedMessages');

        $this->assertSame(Receiver::DEFAULT_MAX_UNACKED_MESSAGES, $maxUnackedProperty->getValue($receiver));
    }

    public function testMaxUnackedMessagesConfigurationCanBeOverridden(): void
    {
        $options = ['queue' => 'test_queue', 'max_unacked_messages' => 50];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $reflection = new \ReflectionClass(Receiver::class);
        $maxUnackedProperty = $reflection->getProperty('maxUnackedMessages');

        $this->assertSame(50, $maxUnackedProperty->getValue($receiver));
    }

    public function testMaxUnackedMessagesMinimumIsOne(): void
    {
        $options = ['queue' => 'test_queue', 'max_unacked_messages' => 0];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $reflection = new \ReflectionClass(Receiver::class);
        $maxUnackedProperty = $reflection->getProperty('maxUnackedMessages');

        $this->assertSame(1, $maxUnackedProperty->getValue($receiver));
    }

    public function testMaxUnackedMessagesNegativeBecomesOne(): void
    {
        $options = ['queue' => 'test_queue', 'max_unacked_messages' => -10];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $reflection = new \ReflectionClass(Receiver::class);
        $maxUnackedProperty = $reflection->getProperty('maxUnackedMessages');

        $this->assertSame(1, $maxUnackedProperty->getValue($receiver));
    }

    public function testGetRethrowsNonTimeoutQueueException(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = $this->createReceiverWithQueue($options);

        $this->queue
            ->expects($this->once())
            ->method('consume')
            ->willThrowException(new \AMQPException('Some other error'));

        $this->queue
            ->method('getConsumerTag')
            ->willReturn('test_tag');

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $this->expectException(\AMQPException::class);
        $this->expectExceptionMessage('Some other error');

        $receiver->get();
    }

    public function testConnectIsLazy(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $reflection = new \ReflectionClass(Receiver::class);
        $queueProperty = $reflection->getProperty('queue');

        $this->assertNull($queueProperty->getValue($receiver));
    }

    public function testConnectUsesFactoryToCreateChannelAndQueue(): void
    {
        $options = ['queue' => 'test_queue', 'max_unacked_messages' => 10];

        $channel = $this->createMock(\AMQPChannel::class);

        $channel
            ->expects($this->once())
            ->method('qos')
            ->with(0, 10);

        $this->queue
            ->expects($this->once())
            ->method('setName')
            ->with('test_queue');

        $this->queue
            ->method('getConsumerTag')
            ->willReturn('test_tag');

        $this->connection
            ->expects($this->once())
            ->method('getChannel')
            ->willReturn($channel);

        $this->factory
            ->expects($this->once())
            ->method('createQueue')
            ->with($channel)
            ->willReturn($this->queue);

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $firstCall = true;
        $this->queue
            ->expects($this->exactly(2))
            ->method('consume')
            ->willReturnCallback(function () use (&$firstCall): void {
                if ($firstCall) {
                    $firstCall = false;
                    return;
                }
                throw new \AMQPException('Consumer timeout exceed');
            });

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);
        $receiver->get();
    }

    private function createReceiverWithQueue(array $options): Receiver
    {
        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $reflection = new \ReflectionClass(Receiver::class);
        $queueProperty = $reflection->getProperty('queue');
        $queueProperty->setValue($receiver, $this->queue);

        $callbackProperty = $reflection->getProperty('callback');
        $callbackProperty->setValue($receiver, fn(\AMQPEnvelope $message): false => false);

        return $receiver;
    }

    /**
     * Verifies that setup() is called before connection operations.
     * This follows the same pattern as get() method for consistency.
     */
    public function testGetMessageCountCallsSetupBeforeConnection(): void
    {
        $setup = $this->createMock(InfrastructureSetup::class);
        $setup->expects($this->once())->method('setup');

        $channel = $this->createMock(\AMQPChannel::class);
        $this->connection->method('getChannel')->willReturn($channel);
        $this->factory->method('createQueue')->willReturn($this->queue);

        $this->queue
            ->expects($this->once())
            ->method('declareQueue')
            ->willReturn(42);

        $options = ['queue' => 'test_queue'];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $setup);

        $this->assertSame(42, $receiver->getMessageCount());
    }

    public function testGetMessageCountReturnsQueueMessageCount(): void
    {
        $options = ['queue' => 'test_queue'];

        $channel = $this->createMock(\AMQPChannel::class);
        $this->connection->method('getChannel')->willReturn($channel);
        $this->factory->method('createQueue')->willReturn($this->queue);

        $this->queue
            ->expects($this->once())
            ->method('declareQueue')
            ->willReturn(100);

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $this->assertSame(100, $receiver->getMessageCount());
    }

    public function testGetMessageCountUsesPassiveFlag(): void
    {
        $options = ['queue' => 'test_queue'];

        $channel = $this->createMock(\AMQPChannel::class);
        $this->connection->method('getChannel')->willReturn($channel);
        $this->factory->method('createQueue')->willReturn($this->queue);

        // Original flags should be 0
        $this->queue->method('getFlags')->willReturn(0);

        // Expect setFlags to be called with AMQP_PASSIVE (1) before declareQueue, then restore (0)
        $capturedFlags = [];
        $this->queue
            ->expects($this->exactly(2))
            ->method('setFlags')
            ->willReturnOnConsecutiveCalls(
                $this->returnCallback(function (int $flags) use (&$capturedFlags): void {
                    $capturedFlags[] = $flags;
                }),
                $this->returnCallback(function (int $flags) use (&$capturedFlags): void {
                    $capturedFlags[] = $flags;
                }),
            );

        $this->queue
            ->expects($this->once())
            ->method('declareQueue')
            ->willReturn(50);

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $this->assertSame(50, $receiver->getMessageCount());

        // Verify flags were set correctly
        $this->assertCount(2, $capturedFlags);
        $this->assertSame(\AMQP_PASSIVE, $capturedFlags[0]);
        $this->assertSame(0, $capturedFlags[1]);
    }

    public function testGetMessageCountRestoresFlagsWhenExceptionThrown(): void
    {
        $options = ['queue' => 'test_queue', 'auto_setup' => false];

        $channel = $this->createMock(\AMQPChannel::class);
        $this->connection->method('getChannel')->willReturn($channel);
        $this->factory->method('createQueue')->willReturn($this->queue);

        $this->queue->method('getFlags')->willReturn(0);

        $capturedFlags = [];
        $this->queue
            ->expects($this->exactly(2))
            ->method('setFlags')
            ->willReturnCallback(function (int $flags) use (&$capturedFlags): void {
                $capturedFlags[] = $flags;
            });

        $this->queue
            ->expects($this->once())
            ->method('declareQueue')
            ->willThrowException(new \AMQPQueueException('PRECONDITION_FAILED - queue does not exist'));

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        try {
            $receiver->getMessageCount();
            $this->fail('Expected AMQPQueueException to be thrown');
        } catch (\AMQPQueueException $e) {
            // Verify flags were restored even when exception was thrown
            $this->assertCount(2, $capturedFlags);
            $this->assertSame(\AMQP_PASSIVE, $capturedFlags[0]);
            $this->assertSame(0, $capturedFlags[1]);
            $this->assertSame('PRECONDITION_FAILED - queue does not exist', $e->getMessage());
        }
    }

    public function testGetMessageCountPreservesExistingFlags(): void
    {
        $options = ['queue' => 'test_queue'];

        $channel = $this->createMock(\AMQPChannel::class);
        $this->connection->method('getChannel')->willReturn($channel);
        $this->factory->method('createQueue')->willReturn($this->queue);

        // Original flags include AMQP_DURABLE (2)
        $originalFlags = \AMQP_DURABLE;
        $this->queue->method('getFlags')->willReturn($originalFlags);

        $capturedFlags = [];
        $this->queue
            ->expects($this->exactly(2))
            ->method('setFlags')
            ->willReturnCallback(function (int $flags) use (&$capturedFlags): void {
                $capturedFlags[] = $flags;
            });

        $this->queue
            ->expects($this->once())
            ->method('declareQueue')
            ->willReturn(75);

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $this->assertSame(75, $receiver->getMessageCount());

        // Verify flags were combined correctly and restored
        $this->assertCount(2, $capturedFlags);
        $this->assertSame($originalFlags | \AMQP_PASSIVE, $capturedFlags[0]);
        $this->assertSame($originalFlags, $capturedFlags[1]);
    }

    public function testGetMessageCountUsesRetryWhenConfigured(): void
    {
        $options = ['queue' => 'test_queue'];
        $retry = $this->createMock(\CrazyGoat\TheConsoomer\ConnectionRetryInterface::class);

        $channel = $this->createMock(\AMQPChannel::class);
        $this->connection->method('getChannel')->willReturn($channel);
        $this->factory->method('createQueue')->willReturn($this->queue);

        $this->queue->method('getFlags')->willReturn(0);
        $this->queue->method('setFlags');
        $this->queue->method('declareQueue')->willReturn(42);

        // Verify that retry->withRetry is called with the operation
        $retry
            ->expects($this->once())
            ->method('withRetry')
            ->willReturnCallback(fn(\Closure $operation): int => $operation());

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup, $retry);

        $this->assertSame(42, $receiver->getMessageCount());
    }

    public function testGetMessageCountWithoutRetryCallsDirectly(): void
    {
        $options = ['queue' => 'test_queue'];

        $channel = $this->createMock(\AMQPChannel::class);
        $this->connection->method('getChannel')->willReturn($channel);
        $this->factory->method('createQueue')->willReturn($this->queue);

        $this->queue->method('getFlags')->willReturn(0);
        $this->queue->method('setFlags');
        $this->queue
            ->expects($this->once())
            ->method('declareQueue')
            ->willReturn(99);

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $this->assertSame(99, $receiver->getMessageCount());
    }

    public function testGetMessageCountThrowsExceptionWhenAutoSetupDisabledAndQueueNotExists(): void
    {
        $options = ['queue' => 'non_existent_queue', 'auto_setup' => false];

        $channel = $this->createMock(\AMQPChannel::class);
        $this->connection->method('getChannel')->willReturn($channel);
        $this->factory->method('createQueue')->willReturn($this->queue);

        $this->queue->method('getFlags')->willReturn(0);
        $this->queue->method('setFlags');

        // Simulate queue not existing - declareQueue with AMQP_PASSIVE throws when queue doesn't exist
        $this->queue
            ->expects($this->once())
            ->method('declareQueue')
            ->willThrowException(new \AMQPQueueException('PRECONDITION_FAILED - queue non_existent_queue does not exist'));

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $this->expectException(\AMQPQueueException::class);
        $this->expectExceptionMessage('PRECONDITION_FAILED - queue non_existent_queue does not exist');

        $receiver->getMessageCount();
    }

    public function testGetMessageCountHandlesReconnectionWhenHeartbeatStale(): void
    {
        $options = ['queue' => 'test_queue'];

        $channel = $this->createMock(\AMQPChannel::class);
        $this->connection->method('getChannel')->willReturn($channel);
        $this->factory->method('createQueue')->willReturn($this->queue);

        // Simulate stale connection - checkHeartbeat returns true (needs reconnect)
        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(true);

        // Reconnect should be called when heartbeat is stale
        $this->connection
            ->expects($this->once())
            ->method('reconnect');

        $this->queue->method('getFlags')->willReturn(0);
        $this->queue->method('setFlags');
        $this->queue
            ->expects($this->once())
            ->method('declareQueue')
            ->willReturn(42);

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        // This should work even after reconnection (queue is reset and reconnected)
        $this->assertSame(42, $receiver->getMessageCount());
    }

    public function testGetMessageCountCallsUpdateActivity(): void
    {
        $options = ['queue' => 'test_queue'];

        $channel = $this->createMock(\AMQPChannel::class);
        $this->connection->method('getChannel')->willReturn($channel);
        $this->factory->method('createQueue')->willReturn($this->queue);

        $this->queue->method('getFlags')->willReturn(0);
        $this->queue->method('setFlags');
        $this->queue->method('declareQueue')->willReturn(42);

        // Verify updateActivity is called after operation
        $this->connection
            ->expects($this->once())
            ->method('updateActivity');

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $receiver->getMessageCount();
    }
}
