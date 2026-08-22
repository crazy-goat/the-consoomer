<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\AmqpFactory;
use CrazyGoat\TheConsoomer\AmqpReceivedStamp;
use CrazyGoat\TheConsoomer\ConnectionInterface;
use CrazyGoat\TheConsoomer\Exception\MissingStampException;
use CrazyGoat\TheConsoomer\InfrastructureSetupInterface;
use CrazyGoat\TheConsoomer\Receiver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class ReceiverTest extends TestCase
{
    private AmqpFactory&MockObject $factory;
    private ConnectionInterface&MockObject $connection;
    private SerializerInterface&MockObject $serializer;
    private \AMQPQueue&MockObject $queue;
    private InfrastructureSetupInterface&MockObject $setup;

    protected function setUp(): void
    {
        $this->factory = $this->createMock(AmqpFactory::class);
        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->queue = $this->createMock(\AMQPQueue::class);
        $this->setup = $this->createMock(InfrastructureSetupInterface::class);
    }

    private function createReceiverWithQueue(array $options): Receiver
    {
        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $reflection = new \ReflectionClass(Receiver::class);
        $queuesProperty = $reflection->getProperty('queues');
        $queueName = isset($options['queues'])
            ? array_key_first($options['queues'])
            : ($options['queue'] ?? 'test_queue');
        $queuesProperty->setValue($receiver, [$queueName => $this->queue]);

        return $receiver;
    }

    private function createReceiverWithQueueAndRetry(array $options, \CrazyGoat\TheConsoomer\ConnectionRetryInterface $retry): Receiver
    {
        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup, $retry);

        $reflection = new \ReflectionClass(Receiver::class);
        $queuesProperty = $reflection->getProperty('queues');
        $queueName = isset($options['queues'])
            ? array_key_first($options['queues'])
            : ($options['queue'] ?? 'test_queue');
        $queuesProperty->setValue($receiver, [$queueName => $this->queue]);

        return $receiver;
    }

    public function testGetCallsSetupFirst(): void
    {
        $setup = $this->createMock(InfrastructureSetupInterface::class);
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
            ->willThrowException(new \AMQPQueueException('Consumer timeout exceeded'));

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
            ->with(['body' => '{"data":"test"}', 'headers' => []])
            ->willReturn($messageEnvelope);

        $receiver = $this->createReceiverWithQueue($options);

        $this->queue
            ->expects($this->once())
            ->method('consume')
            ->willReturnCallback(function (?callable $callback, int $flags, ?string $consumerTag): void {
                if ($flags === AMQP_JUST_CONSUME) {
                    // Invoke the inline callback with a message
                    $amqpEnvelope = new \AMQPEnvelope();
                    $refl = new \ReflectionClass(\AMQPEnvelope::class);
                    $bodyProp = $refl->getProperty('body');
                    $bodyProp->setValue($amqpEnvelope, '{"data":"test"}');
                    $callback($amqpEnvelope);
                }
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
        $this->assertInstanceOf(AmqpReceivedStamp::class, $result[0]->last(AmqpReceivedStamp::class));
    }

    public function testGetPassesHeadersToDecode(): void
    {
        $options = ['queue' => 'test_queue'];

        $amqpEnvelope = $this->createMock(\AMQPEnvelope::class);
        $amqpEnvelope
            ->method('getBody')
            ->willReturn('{"data":"test"}');
        $amqpEnvelope
            ->method('getHeaders')
            ->willReturn(['type' => 'App\\Msg', 'X-Message-Stamp-Foo' => '[{}]']);

        $messageEnvelope = new Envelope(new \stdClass());

        $this->serializer
            ->expects($this->once())
            ->method('decode')
            ->with(['body' => '{"data":"test"}', 'headers' => ['type' => 'App\\Msg', 'X-Message-Stamp-Foo' => '[{}]']])
            ->willReturn($messageEnvelope);

        $receiver = $this->createReceiverWithQueue($options);

        $this->queue
            ->expects($this->once())
            ->method('consume')
            ->willReturnCallback(function (?callable $callback, int $flags, ?string $consumerTag) use ($amqpEnvelope): void {
                if ($flags === AMQP_JUST_CONSUME) {
                    $callback($amqpEnvelope);
                }
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
    }

    public function testAckThrowsExceptionWithoutStamp(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $envelope = new Envelope(new \stdClass());

        $this->expectException(MissingStampException::class);
        $this->expectExceptionMessage('No AMQP received stamp');

        $receiver->ack($envelope);
    }

    public function testRejectThrowsExceptionWithoutStamp(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $envelope = new Envelope(new \stdClass());

        $this->expectException(MissingStampException::class);
        $this->expectExceptionMessage('No AMQP received stamp');

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

        $stamp = new AmqpReceivedStamp($amqpEnvelope, 'test_queue');
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
            $stamps = [new AmqpReceivedStamp($amqpEnvelope, 'test_queue')];
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

        $stamp = new AmqpReceivedStamp($amqpEnvelope, 'test_queue');
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

    public function testMaxUnackedMessagesConfigurationOverridesDefault(): void
    {
        $options = ['queue' => 'test_queue', 'max_unacked_messages' => 50];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $reflection = new \ReflectionClass(Receiver::class);
        $maxUnackedProperty = $reflection->getProperty('maxUnackedMessages');

        $this->assertSame(50, $maxUnackedProperty->getValue($receiver));
    }

    public function testMaxUnackedMessagesConfigurationIsAtLeastOne(): void
    {
        $options = ['queue' => 'test_queue', 'max_unacked_messages' => 0];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $reflection = new \ReflectionClass(Receiver::class);
        $maxUnackedProperty = $reflection->getProperty('maxUnackedMessages');

        $this->assertSame(1, $maxUnackedProperty->getValue($receiver));
    }

    public function testMaxUnackedMessagesConfigurationWithNegativeValue(): void
    {
        $options = ['queue' => 'test_queue', 'max_unacked_messages' => -5];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $reflection = new \ReflectionClass(Receiver::class);
        $maxUnackedProperty = $reflection->getProperty('maxUnackedMessages');

        $this->assertSame(1, $maxUnackedProperty->getValue($receiver));
    }

    public function testMaxUnackedMessagesConfigurationRespectsLargeValues(): void
    {
        $options = ['queue' => 'test_queue', 'max_unacked_messages' => 1000];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $reflection = new \ReflectionClass(Receiver::class);
        $maxUnackedProperty = $reflection->getProperty('maxUnackedMessages');

        $this->assertSame(1000, $maxUnackedProperty->getValue($receiver));
    }

    public function testGetCallsUpdateActivityAfterConsume(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = $this->createReceiverWithQueue($options);

        $this->queue
            ->method('consume')
            ->willThrowException(new \AMQPQueueException('Consumer timeout exceeded'));

        $this->queue
            ->method('getConsumerTag')
            ->willReturn('test_tag');

        $this->connection
            ->expects($this->once())
            ->method('updateActivity');

        $this->connection
            ->method('checkHeartbeat')
            ->willReturn(false);

        $receiver->get();
    }

    public function testGetHandlesAmqpExceptionGracefully(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = $this->createReceiverWithQueue($options);

        $this->queue
            ->expects($this->once())
            ->method('consume')
            ->willThrowException(new \AMQPException('Connection failed'));

        $this->queue
            ->method('getConsumerTag')
            ->willReturn('test_tag');

        $this->connection
            ->method('checkHeartbeat')
            ->willReturn(false);

        $this->connection
            ->expects($this->once())
            ->method('clearChannelCache');

        $receiver->get();
    }

    public function testGetResetsQueuesOnAmqpException(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = $this->createReceiverWithQueue($options);

        $this->queue
            ->expects($this->once())
            ->method('consume')
            ->willThrowException(new \AMQPException('Channel error'));

        $this->queue
            ->method('getConsumerTag')
            ->willReturn('test_tag');

        $this->connection
            ->method('checkHeartbeat')
            ->willReturn(false);

        $this->connection
            ->method('clearChannelCache');

        $receiver->get();

        $reflection = new \ReflectionClass(Receiver::class);
        $queuesProperty = $reflection->getProperty('queues');
        $this->assertSame([], $queuesProperty->getValue($receiver));
    }

    public function testGetResetsUnackedMessagesOnAmqpException(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = $this->createReceiverWithQueue($options);

        $this->queue
            ->expects($this->once())
            ->method('consume')
            ->willThrowException(new \AMQPException('Consumer cancelled'));

        $this->queue
            ->method('getConsumerTag')
            ->willReturn('test_tag');

        $this->connection
            ->method('checkHeartbeat')
            ->willReturn(false);

        $this->connection
            ->method('clearChannelCache');

        $receiver->get();

        $reflection = new \ReflectionClass(Receiver::class);
        $this->assertSame([], $reflection->getProperty('unacked')->getValue($receiver));
        $this->assertSame([], $reflection->getProperty('lastUnacked')->getValue($receiver));
    }

    public function testGetReturnsMessagesWhenExceptionOccursAfterPartialConsume(): void
    {
        $options = ['queue' => 'test_queue', 'batch_size' => 5];

        $this->serializer
            ->method('decode')
            ->willReturn(new Envelope(new \stdClass()));

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $reflection = new \ReflectionClass(Receiver::class);
        $queuesProperty = $reflection->getProperty('queues');
        $queuesProperty->setValue($receiver, ['test_queue' => $this->queue]);

        $callbackCapture = null;

        $this->queue
            ->expects($this->once())
            ->method('consume')
            ->willReturnCallback(function (?callable $callback, int $flags, ?string $consumerTag) use (&$callbackCapture): void {
                $callbackCapture = $callback;
                // Deliver one message via callback before throwing
                $amqpEnvelope = new \AMQPEnvelope();
                $refl = new \ReflectionClass(\AMQPEnvelope::class);
                $bodyProp = $refl->getProperty('body');
                $bodyProp->setValue($amqpEnvelope, '{"data":"test"}');
                $callback($amqpEnvelope);
                throw new \AMQPException('Consumer cancelled server-side');
            });

        $this->queue
            ->method('getConsumerTag')
            ->willReturn('test_tag');

        $this->connection
            ->method('checkHeartbeat')
            ->willReturn(false);

        $this->connection
            ->method('clearChannelCache');

        $result = $receiver->get();

        $this->assertCount(1, $result);
    }

    public function testGetReturnsEmptyArrayOnAmqpExceptionWithoutMessages(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = $this->createReceiverWithQueue($options);

        $this->queue
            ->expects($this->once())
            ->method('consume')
            ->willThrowException(new \AMQPException('Consumer cancelled server-side'));

        $this->queue
            ->method('getConsumerTag')
            ->willReturn('test_tag');

        $this->connection
            ->method('checkHeartbeat')
            ->willReturn(false);

        $this->connection
            ->method('clearChannelCache');

        $result = $receiver->get();

        $this->assertSame([], $result);
    }

    public function testConnectIsLazy(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $reflection = new \ReflectionClass(Receiver::class);
        $queuesProperty = $reflection->getProperty('queues');

        $this->assertSame([], $queuesProperty->getValue($receiver));
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
            ->method('checkHeartbeat')
            ->willReturn(false);

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $receiver->get();
    }

    public function testConnectUsesFactoryToCreateMultipleQueues(): void
    {
        $options = ['queues' => ['queue_a' => ['binding_keys' => ['key_a']], 'queue_b' => ['binding_keys' => ['key_b']]], 'max_unacked_messages' => 10];

        $channel = $this->createMock(\AMQPChannel::class);

        $channel
            ->expects($this->once())
            ->method('qos')
            ->with(0, 10);

        $queueA = $this->createMock(\AMQPQueue::class);
        $queueB = $this->createMock(\AMQPQueue::class);

        $queueA->method('getConsumerTag')->willReturn('tag_a');
        $queueB->method('getConsumerTag')->willReturn('tag_b');

        $this->connection
            ->expects($this->once())
            ->method('getChannel')
            ->willReturn($channel);

        $this->factory
            ->expects($this->exactly(2))
            ->method('createQueue')
            ->with($channel)
            ->willReturnOnConsecutiveCalls($queueA, $queueB);

        $this->connection
            ->method('checkHeartbeat')
            ->willReturn(false);

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $receiver->get();
    }

    public function testRejectRoutesToCorrectQueue(): void
    {
        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, ['queues' => ['queue_a' => [], 'queue_b' => []]], $this->setup);

        $reflection = new \ReflectionClass(Receiver::class);
        $queuesProperty = $reflection->getProperty('queues');

        $queueA = $this->createMock(\AMQPQueue::class);
        $queueB = $this->createMock(\AMQPQueue::class);
        $queuesProperty->setValue($receiver, ['queue_a' => $queueA, 'queue_b' => $queueB]);

        $amqpEnvelope = $this->createMock(\AMQPEnvelope::class);
        $amqpEnvelope->method('getDeliveryTag')->willReturn(42);

        $stamp = new AmqpReceivedStamp($amqpEnvelope, 'queue_b');
        $envelope = new Envelope(new \stdClass(), [$stamp]);

        $queueA->expects($this->never())->method('reject');
        $queueB
            ->expects($this->once())
            ->method('reject')
            ->with(42);

        $receiver->reject($envelope);
    }

    public function testRejectThrowsOnUnknownQueueName(): void
    {
        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, ['queues' => ['queue_a' => []]], $this->setup);

        $reflection = new \ReflectionClass(Receiver::class);
        $queuesProperty = $reflection->getProperty('queues');
        $queuesProperty->setValue($receiver, ['queue_a' => $this->createMock(\AMQPQueue::class)]);

        $amqpEnvelope = $this->createMock(\AMQPEnvelope::class);
        $stamp = new AmqpReceivedStamp($amqpEnvelope, 'unknown_queue');
        $envelope = new Envelope(new \stdClass(), [$stamp]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown queue "unknown_queue"');

        $receiver->reject($envelope);
    }

    public function testGetMessageCountSumsAcrossMultipleQueues(): void
    {
        $options = ['queues' => ['queue_a' => [], 'queue_b' => []]];

        $channel = $this->createMock(\AMQPChannel::class);
        $this->connection->method('getChannel')->willReturn($channel);

        $queueA = $this->createMock(\AMQPQueue::class);
        $queueB = $this->createMock(\AMQPQueue::class);

        $queueA->method('getFlags')->willReturn(0);
        $queueA->method('setFlags');
        $queueA->expects($this->once())->method('declareQueue')->willReturn(30);

        $queueB->method('getFlags')->willReturn(0);
        $queueB->method('setFlags');
        $queueB->expects($this->once())->method('declareQueue')->willReturn(70);

        $this->factory
            ->method('createQueue')
            ->willReturnOnConsecutiveCalls($queueA, $queueB);

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $this->assertSame(100, $receiver->getMessageCount());
    }

    public function testGetMessageCountCallsSetupBeforeConnection(): void
    {
        $setup = $this->createMock(InfrastructureSetupInterface::class);
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

    public function testGetMessageCountDoesNotStartConsumers(): void
    {
        $options = ['queue' => 'test_queue'];

        $channel = $this->createMock(\AMQPChannel::class);
        $this->connection->method('getChannel')->willReturn($channel);
        $this->factory->method('createQueue')->willReturn($this->queue);

        $this->queue->method('getFlags')->willReturn(0);
        $this->queue->method('setFlags');
        $this->queue->method('declareQueue')->willReturn(42);

        // Consume must NEVER be called from getMessageCount()
        $this->queue
            ->expects($this->never())
            ->method('consume');

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $receiver->getMessageCount();
    }

    public function testGetMessageCountDoesNotAlterReceiverState(): void
    {
        $options = ['queue' => 'test_queue'];

        $channel = $this->createMock(\AMQPChannel::class);
        $this->connection->method('getChannel')->willReturn($channel);
        $this->factory->method('createQueue')->willReturn($this->queue);

        $this->queue->method('getFlags')->willReturn(0);
        $this->queue->method('setFlags');
        $this->queue->method('declareQueue')->willReturn(42);

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $reflection = new \ReflectionClass(Receiver::class);
        $queuesProperty = $reflection->getProperty('queues');

        // Before getMessageCount, queues should be empty
        $this->assertSame([], $queuesProperty->getValue($receiver));

        $receiver->getMessageCount();

        // After getMessageCount, queues should still be empty (no consumer state left)
        $this->assertSame([], $queuesProperty->getValue($receiver));
    }

    public function testGetMessageCountReturnsZeroForNoQueues(): void
    {
        $options = [];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $this->assertSame(0, $receiver->getMessageCount());
    }

    public function testPurgeQueuePurgingConfiguredQueue(): void
    {
        $options = ['queue' => 'test_queue'];

        $channel = $this->createMock(\AMQPChannel::class);
        $this->connection->method('getChannel')->willReturn($channel);

        $purgeQueue = $this->createMock(\AMQPQueue::class);
        $purgeQueue
            ->expects($this->once())
            ->method('setName')
            ->with('test_queue');
        $purgeQueue
            ->expects($this->once())
            ->method('purge')
            ->willReturn(42);

        $this->factory
            ->expects($this->once())
            ->method('createQueue')
            ->with($channel)
            ->willReturn($purgeQueue);

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $this->assertSame(42, $receiver->purgeQueue());
    }

    public function testPurgeQueueWithCustomQueueName(): void
    {
        $options = ['queue' => 'default_queue'];

        $channel = $this->createMock(\AMQPChannel::class);
        $this->connection->method('getChannel')->willReturn($channel);

        $purgeQueue = $this->createMock(\AMQPQueue::class);
        $purgeQueue
            ->expects($this->once())
            ->method('setName')
            ->with('custom_queue');
        $purgeQueue
            ->expects($this->once())
            ->method('purge')
            ->willReturn(10);

        $this->factory
            ->expects($this->once())
            ->method('createQueue')
            ->with($channel)
            ->willReturn($purgeQueue);

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $this->assertSame(10, $receiver->purgeQueue('custom_queue'));
    }

    public function testPurgeQueueCallsSetupFirst(): void
    {
        $setup = $this->createMock(InfrastructureSetupInterface::class);
        $setup->expects($this->once())->method('setup');

        $channel = $this->createMock(\AMQPChannel::class);
        $this->connection->method('getChannel')->willReturn($channel);

        $purgeQueue = $this->createMock(\AMQPQueue::class);
        $purgeQueue->method('purge')->willReturn(0);

        $this->factory
            ->method('createQueue')
            ->willReturn($purgeQueue);

        $options = ['queue' => 'test_queue'];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $setup);

        $receiver->purgeQueue();
    }

    public function testPurgeQueueCallsUpdateActivity(): void
    {
        $options = ['queue' => 'test_queue'];

        $channel = $this->createMock(\AMQPChannel::class);
        $this->connection->method('getChannel')->willReturn($channel);

        $purgeQueue = $this->createMock(\AMQPQueue::class);
        $purgeQueue->method('purge')->willReturn(0);

        $this->factory
            ->method('createQueue')
            ->willReturn($purgeQueue);

        $this->connection
            ->expects($this->once())
            ->method('updateActivity');

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $receiver->purgeQueue();
    }

    public function testPurgeQueueUsesRetryWhenConfigured(): void
    {
        $options = ['queue' => 'test_queue'];
        $retry = $this->createMock(\CrazyGoat\TheConsoomer\ConnectionRetryInterface::class);

        $channel = $this->createMock(\AMQPChannel::class);
        $this->connection->method('getChannel')->willReturn($channel);

        $purgeQueue = $this->createMock(\AMQPQueue::class);
        $purgeQueue->method('purge')->willReturn(42);

        $this->factory
            ->method('createQueue')
            ->willReturn($purgeQueue);

        $retry
            ->expects($this->once())
            ->method('withRetry')
            ->willReturnCallback(fn(\Closure $operation): int => $operation());

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup, $retry);

        $this->assertSame(42, $receiver->purgeQueue());
    }

    public function testPurgeQueueHandlesReconnectionWhenHeartbeatStale(): void
    {
        $options = ['queue' => 'test_queue'];

        $channel = $this->createMock(\AMQPChannel::class);
        $this->connection->method('getChannel')->willReturn($channel);

        $purgeQueue = $this->createMock(\AMQPQueue::class);
        $purgeQueue->method('purge')->willReturn(42);

        $this->factory
            ->method('createQueue')
            ->willReturn($purgeQueue);

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(true);

        $this->connection
            ->expects($this->once())
            ->method('reconnect');

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $this->assertSame(42, $receiver->purgeQueue());
    }

    public function testPurgeQueueThrowsWhenPurgeFails(): void
    {
        $options = ['queue' => 'test_queue'];

        $channel = $this->createMock(\AMQPChannel::class);
        $this->connection->method('getChannel')->willReturn($channel);

        $purgeQueue = $this->createMock(\AMQPQueue::class);
        $purgeQueue
            ->expects($this->once())
            ->method('purge')
            ->willThrowException(new \AMQPException('Failed to purge queue'));

        $this->factory
            ->method('createQueue')
            ->willReturn($purgeQueue);

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $this->expectException(\AMQPException::class);
        $this->expectExceptionMessage('Failed to purge queue');

        $receiver->purgeQueue();
    }

    public function testPurgeQueueSkipsSetupWhenAutoSetupDisabled(): void
    {
        $setup = $this->createMock(InfrastructureSetupInterface::class);
        $setup->expects($this->never())->method('setup');

        $channel = $this->createMock(\AMQPChannel::class);
        $this->connection->method('getChannel')->willReturn($channel);

        $purgeQueue = $this->createMock(\AMQPQueue::class);
        $purgeQueue->method('purge')->willReturn(0);

        $this->factory
            ->method('createQueue')
            ->willReturn($purgeQueue);

        $options = ['queue' => 'test_queue', 'auto_setup' => false];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $setup);

        $receiver->purgeQueue();
    }

    public function testPurgeQueueThrowsWhenNoQueueName(): void
    {
        $options = [];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Queue name must be provided');

        $receiver->purgeQueue();
    }

    public function testPurgeQueueWithQueuesOptionUsesFirstQueue(): void
    {
        $options = ['queues' => ['queue_a' => [], 'queue_b' => []]];

        $channel = $this->createMock(\AMQPChannel::class);
        $this->connection->method('getChannel')->willReturn($channel);

        $purgeQueue = $this->createMock(\AMQPQueue::class);
        $purgeQueue
            ->expects($this->once())
            ->method('setName')
            ->with('queue_a');
        $purgeQueue
            ->expects($this->once())
            ->method('purge')
            ->willReturn(42);

        $this->factory
            ->method('createQueue')
            ->willReturn($purgeQueue);

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $this->assertSame(42, $receiver->purgeQueue());
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

    public function testBatchSizeDefaultsToOne(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $reflection = new \ReflectionClass(Receiver::class);
        $batchSizeProperty = $reflection->getProperty('batchSize');

        $this->assertSame(1, $batchSizeProperty->getValue($receiver));
    }

    public function testBatchSizeConfiguration(): void
    {
        $options = ['queue' => 'test_queue', 'batch_size' => 10];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $reflection = new \ReflectionClass(Receiver::class);
        $batchSizeProperty = $reflection->getProperty('batchSize');

        $this->assertSame(10, $batchSizeProperty->getValue($receiver));
    }

    public function testBatchSizeIsAtLeastOne(): void
    {
        $options = ['queue' => 'test_queue', 'batch_size' => 0];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $reflection = new \ReflectionClass(Receiver::class);
        $batchSizeProperty = $reflection->getProperty('batchSize');

        $this->assertSame(1, $batchSizeProperty->getValue($receiver));
    }

    public function testBatchSizeWithNegativeValue(): void
    {
        $options = ['queue' => 'test_queue', 'batch_size' => -5];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $reflection = new \ReflectionClass(Receiver::class);
        $batchSizeProperty = $reflection->getProperty('batchSize');

        $this->assertSame(1, $batchSizeProperty->getValue($receiver));
    }

    public function testCloseFlushesPendingAcks(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = $this->createReceiverWithQueue($options);

        $amqpEnvelope1 = $this->createMock(\AMQPEnvelope::class);
        $amqpEnvelope1->method('getDeliveryTag')->willReturn(1);
        $amqpEnvelope2 = $this->createMock(\AMQPEnvelope::class);
        $amqpEnvelope2->method('getDeliveryTag')->willReturn(2);

        $stamp1 = new AmqpReceivedStamp($amqpEnvelope1, 'test_queue');
        $stamp2 = new AmqpReceivedStamp($amqpEnvelope2, 'test_queue');
        $envelope1 = new Envelope(new \stdClass(), [$stamp1]);
        $envelope2 = new Envelope(new \stdClass(), [$stamp2]);

        $receiver->ack($envelope1);
        $receiver->ack($envelope2);

        $this->queue
            ->expects($this->once())
            ->method('ack')
            ->with(2, AMQP_MULTIPLE);

        $receiver->close();
    }

    public function testCloseWithNoPendingAcksDoesNothing(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = $this->createReceiverWithQueue($options);

        $this->queue
            ->expects($this->never())
            ->method('ack');

        $receiver->close();
    }

    public function testGetReturnsMultipleMessagesWhenAvailable(): void
    {
        $options = ['queue' => 'test_queue', 'max_unacked_messages' => 5, 'batch_size' => 5];

        $this->serializer
            ->expects($this->exactly(3))
            ->method('decode')
            ->willReturnCallback(fn(array $data): Envelope => new Envelope(new \stdClass()));

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $reflection = new \ReflectionClass(Receiver::class);
        $queuesProperty = $reflection->getProperty('queues');
        $queuesProperty->setValue($receiver, ['test_queue' => $this->queue]);
        $invokedCallback = null;

        $this->queue
            ->expects($this->once())
            ->method('consume')
            ->willReturnCallback(function (?callable $callback, int $flags, ?string $consumerTag) use (&$invokedCallback): void {
                if ($flags === AMQP_JUST_CONSUME && $callback !== null) {
                    $invokedCallback = $callback;
                }
            });

        $this->queue
            ->method('getConsumerTag')
            ->willReturn('test_tag');

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        // Invoke the actual inline callback 3 times with fake messages
        $receiver->get();

        // Now simulate invoking the callback that was captured
        if ($invokedCallback !== null) {
            for ($i = 0; $i < 3; $i++) {
                $envelope = $this->createMock(\AMQPEnvelope::class);
                $envelope->method('getBody')->willReturn('{"data":"test' . $i . '"}');
                $invokedCallback($envelope);
            }
        }
    }

    public function testGetStopsAtBatchSize(): void
    {
        $options = ['queue' => 'test_queue', 'max_unacked_messages' => 5, 'batch_size' => 2];

        $this->serializer
            ->expects($this->exactly(2))
            ->method('decode')
            ->willReturnCallback(fn(array $data): Envelope => new Envelope(new \stdClass()));

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $reflection = new \ReflectionClass(Receiver::class);
        $queuesProperty = $reflection->getProperty('queues');
        $queuesProperty->setValue($receiver, ['test_queue' => $this->queue]);

        $invokedCallback = null;

        $this->queue
            ->expects($this->once())
            ->method('consume')
            ->willReturnCallback(function (?callable $callback, int $flags, ?string $consumerTag) use (&$invokedCallback): void {
                if ($flags === AMQP_JUST_CONSUME && $callback !== null) {
                    $invokedCallback = $callback;
                }
            });

        $this->queue
            ->method('getConsumerTag')
            ->willReturn('test_tag');

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $receiver->get();

        // Invoke the actual inline callback with 5 messages - should stop at 2
        $callbackCalls = 0;
        if ($invokedCallback !== null) {
            for ($i = 0; $i < 5; $i++) {
                $envelope = $this->createMock(\AMQPEnvelope::class);
                $envelope->method('getBody')->willReturn('{"data":"test' . $i . '"}');
                $shouldContinue = $invokedCallback($envelope);
                $callbackCalls++;
                if (!$shouldContinue) {
                    break;
                }
            }
        }

        $this->assertSame(2, $callbackCalls);
    }

    public function testGetReturnsCollectedMessagesOnTimeout(): void
    {
        $options = ['queue' => 'test_queue', 'max_unacked_messages' => 10, 'batch_size' => 10];

        $this->serializer
            ->expects($this->once())
            ->method('decode')
            ->willReturnCallback(fn(array $data): Envelope => new Envelope(new \stdClass()));

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $reflection = new \ReflectionClass(Receiver::class);
        $queuesProperty = $reflection->getProperty('queues');
        $queuesProperty->setValue($receiver, ['test_queue' => $this->queue]);

        $this->queue
            ->expects($this->once())
            ->method('consume')
            ->willReturnCallback(function (?callable $callback, int $flags, ?string $consumerTag): void {
                if ($flags !== AMQP_JUST_CONSUME || $callback === null) {
                    return;
                }
                // Invoke callback once then throw timeout
                $amqpEnvelope = new \AMQPEnvelope();
                $refl = new \ReflectionClass(\AMQPEnvelope::class);
                $bodyProp = $refl->getProperty('body');
                $bodyProp->setValue($amqpEnvelope, '{"data":"test"}');
                $callback($amqpEnvelope);
                throw new \AMQPQueueException('Consumer timeout exceeded');
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
    }

    public function testBatchAckWorksWithBatchFetching(): void
    {
        $options = ['queue' => 'test_queue', 'max_unacked_messages' => 3];

        $receiver = $this->createReceiverWithQueue($options);

        $envelopes = [];
        for ($i = 1; $i <= 6; $i++) {
            $amqpEnvelope = $this->createMock(\AMQPEnvelope::class);
            $amqpEnvelope->method('getDeliveryTag')->willReturn($i);
            $stamps = [new AmqpReceivedStamp($amqpEnvelope, 'test_queue')];
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

    public function testGetRejectsMessageWhenDecodeFails(): void
    {
        $options = ['queue' => 'test_queue'];

        $amqpEnvelope = $this->createMock(\AMQPEnvelope::class);
        $amqpEnvelope
            ->method('getBody')
            ->willReturn('not-json');
        $amqpEnvelope
            ->method('getDeliveryTag')
            ->willReturn(99);

        $this->serializer
            ->expects($this->once())
            ->method('decode')
            ->with(['body' => 'not-json', 'headers' => []])
            ->willThrowException(new MessageDecodingFailedException('Cannot decode message'));

        $receiver = $this->createReceiverWithQueue($options);

        $this->queue
            ->expects($this->once())
            ->method('consume')
            ->willReturnCallback(function (?callable $callback, int $flags, ?string $consumerTag) use ($amqpEnvelope): void {
                if ($flags === AMQP_JUST_CONSUME && $callback !== null) {
                    $callback($amqpEnvelope);
                }
            });

        $this->queue
            ->expects($this->once())
            ->method('reject')
            ->with(99);

        $this->queue
            ->method('getConsumerTag')
            ->willReturn('test_tag');

        $this->connection
            ->method('checkHeartbeat')
            ->willReturn(false);

        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessage('Cannot decode message');

        $receiver->get();
    }

    public function testGetStillThrowsDecodeFailureWhenRejectThrows(): void
    {
        $options = ['queue' => 'test_queue'];

        $amqpEnvelope = $this->createMock(\AMQPEnvelope::class);
        $amqpEnvelope->method('getBody')->willReturn('not-json');
        $amqpEnvelope->method('getDeliveryTag')->willReturn(99);

        $this->serializer
            ->expects($this->once())
            ->method('decode')
            ->with(['body' => 'not-json', 'headers' => []])
            ->willThrowException(new MessageDecodingFailedException('Cannot decode message'));

        $receiver = $this->createReceiverWithQueue($options);

        $this->queue
            ->expects($this->once())
            ->method('consume')
            ->willReturnCallback(function (?callable $callback, int $flags, ?string $consumerTag) use ($amqpEnvelope): void {
                if ($flags === AMQP_JUST_CONSUME && $callback !== null) {
                    $callback($amqpEnvelope);
                }
            });

        // reject() itself fails (e.g. broken channel) — decode exception must still propagate.
        $this->queue
            ->expects($this->once())
            ->method('reject')
            ->with(99)
            ->willThrowException(new \AMQPChannelException('Channel closed'));

        $this->queue->method('getConsumerTag')->willReturn('test_tag');
        $this->connection->method('checkHeartbeat')->willReturn(false);

        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessage('Cannot decode message');

        $receiver->get();
    }

    public function testGetRejectsPoisonMessageViaRetryWhenConfigured(): void
    {
        $options = ['queue' => 'test_queue'];
        $retry = $this->createMock(\CrazyGoat\TheConsoomer\ConnectionRetryInterface::class);

        $amqpEnvelope = $this->createMock(\AMQPEnvelope::class);
        $amqpEnvelope->method('getBody')->willReturn('not-json');
        $amqpEnvelope->method('getDeliveryTag')->willReturn(77);

        $this->serializer
            ->expects($this->once())
            ->method('decode')
            ->with(['body' => 'not-json', 'headers' => []])
            ->willThrowException(new MessageDecodingFailedException('Cannot decode message'));

        $receiver = $this->createReceiverWithQueueAndRetry($options, $retry);

        $this->queue
            ->expects($this->once())
            ->method('consume')
            ->willReturnCallback(function (?callable $callback, int $flags, ?string $consumerTag) use ($amqpEnvelope): void {
                if ($flags === AMQP_JUST_CONSUME && $callback !== null) {
                    $callback($amqpEnvelope);
                }
            });

        // The reject must go through the retry wrapper, not bypass it.
        $retry
            ->expects($this->once())
            ->method('withRetry')
            ->willReturnCallback(function (\Closure $operation): mixed {
                $operation();

                return null;
            });

        $this->queue
            ->expects($this->once())
            ->method('reject')
            ->with(77);

        $this->queue->method('getConsumerTag')->willReturn('test_tag');
        $this->connection->method('checkHeartbeat')->willReturn(false);
        $this->connection->expects($this->atLeastOnce())->method('updateActivity');

        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessage('Cannot decode message');

        $receiver->get();
    }

    public function testGetPoisonMessageInFirstQueuePropagatesAndSkipsSecondQueue(): void
    {
        $options = ['queues' => ['queue_a' => [], 'queue_b' => []]];

        $queueA = $this->createMock(\AMQPQueue::class);
        $queueB = $this->createMock(\AMQPQueue::class);

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $reflection = new \ReflectionClass(Receiver::class);
        $queuesProperty = $reflection->getProperty('queues');
        $queuesProperty->setValue($receiver, ['queue_a' => $queueA, 'queue_b' => $queueB]);

        $amqpEnvelope = $this->createMock(\AMQPEnvelope::class);
        $amqpEnvelope->method('getBody')->willReturn('not-json');
        $amqpEnvelope->method('getDeliveryTag')->willReturn(5);

        $this->serializer
            ->expects($this->once())
            ->method('decode')
            ->with(['body' => 'not-json', 'headers' => []])
            ->willThrowException(new MessageDecodingFailedException('Cannot decode message'));

        $queueA
            ->expects($this->once())
            ->method('consume')
            ->willReturnCallback(function (?callable $callback, int $flags, ?string $consumerTag) use ($amqpEnvelope): void {
                if ($flags === AMQP_JUST_CONSUME && $callback !== null) {
                    $callback($amqpEnvelope);
                }
            });

        $queueA->expects($this->once())->method('reject')->with(5);
        $queueA->method('getConsumerTag')->willReturn('tag_a');

        // queue_b must never be consumed this round — exception propagates out of get().
        $queueB->expects($this->never())->method('consume');
        $queueB->method('getConsumerTag')->willReturn('tag_b');

        $this->connection->method('checkHeartbeat')->willReturn(false);

        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessage('Cannot decode message');

        $receiver->get();
    }

    public function testAckIsNoOpWhenReconnectHappensMidOperation(): void
    {
        $options = ['queue' => 'test_queue', 'max_unacked_messages' => 1];

        $receiver = $this->createReceiverWithQueue($options);

        $amqpEnvelope = $this->createMock(\AMQPEnvelope::class);
        $amqpEnvelope->method('getDeliveryTag')->willReturn(123);

        $stamp = new AmqpReceivedStamp($amqpEnvelope, 'test_queue');
        $envelope = new Envelope(new \stdClass(), [$stamp]);

        // Heartbeat is stale → ensureConnected() reconnects and wipes the queue map.
        $this->connection->method('checkHeartbeat')->willReturn(true);
        $this->connection->expects($this->once())->method('reconnect');
        $this->setup->expects($this->once())->method('resetSetup');

        // No ack must be sent — the delivery tag belongs to the dead channel.
        $this->queue->expects($this->never())->method('ack');

        $receiver->ack($envelope);
    }

    public function testRejectIsNoOpWhenReconnectHappensMidOperation(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = $this->createReceiverWithQueue($options);

        $amqpEnvelope = $this->createMock(\AMQPEnvelope::class);
        $amqpEnvelope->method('getDeliveryTag')->willReturn(456);

        $stamp = new AmqpReceivedStamp($amqpEnvelope, 'test_queue');
        $envelope = new Envelope(new \stdClass(), [$stamp]);

        // Heartbeat is stale → ensureConnected() reconnects and wipes the queue map.
        $this->connection->method('checkHeartbeat')->willReturn(true);
        $this->connection->expects($this->once())->method('reconnect');
        $this->setup->expects($this->once())->method('resetSetup');

        // No reject must be sent — the delivery tag belongs to the dead channel.
        $this->queue->expects($this->never())->method('reject');

        $receiver->reject($envelope);
    }

    public function testCloseDoesNotFatalAfterReconnectWipedQueueMap(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = $this->createReceiverWithQueue($options);

        // Buffer two acks without flushing (max_unacked_messages default is 100).
        $amqpEnvelope1 = $this->createMock(\AMQPEnvelope::class);
        $amqpEnvelope1->method('getDeliveryTag')->willReturn(1);
        $amqpEnvelope2 = $this->createMock(\AMQPEnvelope::class);
        $amqpEnvelope2->method('getDeliveryTag')->willReturn(2);

        $envelope1 = new Envelope(new \stdClass(), [new AmqpReceivedStamp($amqpEnvelope1, 'test_queue')]);
        $envelope2 = new Envelope(new \stdClass(), [new AmqpReceivedStamp($amqpEnvelope2, 'test_queue')]);

        // First two acks succeed (heartbeat healthy).
        $this->connection->method('checkHeartbeat')->willReturn(false);
        $receiver->ack($envelope1);
        $receiver->ack($envelope2);

        // Now simulate a reconnect that wipes the queue map before close().
        $reflection = new \ReflectionClass(Receiver::class);
        $queuesProperty = $reflection->getProperty('queues');
        $queuesProperty->setValue($receiver, []);

        // close() must not fatal on the missing queue — acks are silently dropped.
        $this->queue->expects($this->never())->method('ack');

        $receiver->close();
    }

    public function testIdleTimeoutDoesNotClearChannelCache(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = $this->createReceiverWithQueue($options);

        $this->queue
            ->method('consume')
            ->willThrowException(new \AMQPQueueException('Consumer timeout exceeded'));

        $this->queue->method('getConsumerTag')->willReturn('test_tag');
        $this->connection->method('checkHeartbeat')->willReturn(false);

        // An idle consume timeout must NOT tear down the channel.
        $this->connection->expects($this->never())->method('clearChannelCache');

        $receiver->get();
    }

    public function testIdleTimeoutPreservesQueuesAndUnacked(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = $this->createReceiverWithQueue($options);

        $this->queue
            ->method('consume')
            ->willThrowException(new \AMQPQueueException('Consumer timeout exceeded'));

        $this->queue->method('getConsumerTag')->willReturn('test_tag');
        $this->connection->method('checkHeartbeat')->willReturn(false);

        $receiver->get();

        $reflection = new \ReflectionClass(Receiver::class);
        $queuesProperty = $reflection->getProperty('queues');
        $unackedProperty = $reflection->getProperty('unacked');
        $lastUnackedProperty = $reflection->getProperty('lastUnacked');

        // The queue map must survive an idle timeout.
        $this->assertSame(['test_queue' => $this->queue], $queuesProperty->getValue($receiver));
        $this->assertSame([], $unackedProperty->getValue($receiver));
        $this->assertSame([], $lastUnackedProperty->getValue($receiver));
    }

    public function testIdleTimeoutPreservesBufferedAcks(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = $this->createReceiverWithQueue($options);

        // Buffer an ack without flushing (max_unacked_messages default is 100).
        $amqpEnvelope = $this->createMock(\AMQPEnvelope::class);
        $amqpEnvelope->method('getDeliveryTag')->willReturn(7);
        $envelope = new Envelope(new \stdClass(), [new AmqpReceivedStamp($amqpEnvelope, 'test_queue')]);

        $this->connection->method('checkHeartbeat')->willReturn(false);
        $receiver->ack($envelope);

        // Now an idle get() must not wipe the buffered ack.
        $this->queue
            ->method('consume')
            ->willThrowException(new \AMQPQueueException('Consumer timeout exceeded'));

        $this->queue->method('getConsumerTag')->willReturn('test_tag');

        $receiver->get();

        $reflection = new \ReflectionClass(Receiver::class);
        $unackedProperty = $reflection->getProperty('unacked');
        $lastUnackedProperty = $reflection->getProperty('lastUnacked');

        $this->assertSame(['test_queue' => 1], $unackedProperty->getValue($receiver));
        $this->assertSame(7, $lastUnackedProperty->getValue($receiver)['test_queue']->getDeliveryTag());
    }

    public function testGenuineFailureFlushesBufferedAcksBeforeTeardown(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = $this->createReceiverWithQueue($options);

        // Buffer an ack without flushing.
        $amqpEnvelope = $this->createMock(\AMQPEnvelope::class);
        $amqpEnvelope->method('getDeliveryTag')->willReturn(11);
        $envelope = new Envelope(new \stdClass(), [new AmqpReceivedStamp($amqpEnvelope, 'test_queue')]);

        $this->connection->method('checkHeartbeat')->willReturn(false);
        $receiver->ack($envelope);

        // A genuine connection failure must best-effort flush before clearing.
        $this->queue
            ->method('consume')
            ->willThrowException(new \AMQPException('Connection lost'));

        $this->queue->method('getConsumerTag')->willReturn('test_tag');

        $this->queue
            ->expects($this->once())
            ->method('ack')
            ->with(11, AMQP_MULTIPLE);

        $this->connection->expects($this->once())->method('clearChannelCache');

        $receiver->get();
    }

    public function testMultiQueueIdleSecondQueueDoesNotFatalOnAckFlush(): void
    {
        $options = ['queues' => ['queue_a' => [], 'queue_b' => []]];

        $queueA = $this->createMock(\AMQPQueue::class);
        $queueB = $this->createMock(\AMQPQueue::class);

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $reflection = new \ReflectionClass(Receiver::class);
        $queuesProperty = $reflection->getProperty('queues');
        $queuesProperty->setValue($receiver, ['queue_a' => $queueA, 'queue_b' => $queueB]);

        $amqpEnvelope = $this->createMock(\AMQPEnvelope::class);
        $amqpEnvelope->method('getBody')->willReturn('{"data":"test"}');
        $amqpEnvelope->method('getDeliveryTag')->willReturn(5);

        $this->serializer
            ->expects($this->once())
            ->method('decode')
            ->willReturn(new Envelope(new \stdClass()));

        // queue_a yields a message; queue_b throws an idle timeout — the
        // expected outcome of polling a queue with no messages ready (#271).
        $queueA
            ->expects($this->once())
            ->method('consume')
            ->willReturnCallback(function (?callable $callback, int $flags, ?string $consumerTag) use ($amqpEnvelope): void {
                if ($flags === AMQP_JUST_CONSUME && $callback !== null) {
                    $callback($amqpEnvelope);
                }
            });
        $queueA->method('getConsumerTag')->willReturn('tag_a');
        $queueA->expects($this->once())->method('ack')->with(5, AMQP_MULTIPLE);

        $queueB
            ->expects($this->once())
            ->method('consume')
            ->willThrowException(new \AMQPQueueException('Consumer timeout exceeded'));
        $queueB->method('getConsumerTag')->willReturn('tag_b');

        $this->connection->method('checkHeartbeat')->willReturn(false);

        // get() returns queue_a's message and breaks early.
        $result = $receiver->get();
        $this->assertCount(1, $result);

        $stamp = $result[0]->last(AmqpReceivedStamp::class);
        $this->assertSame('queue_a', $stamp->getQueueName());

        // The worker acknowledges the returned envelope — must not fatal.
        $receiver->ack($result[0]);

        // close() flushes the buffered ack — must not raise Error and must not
        // lose the ack silently (the queue map survived the idle timeout).
        $receiver->close();
    }

    public function testAckDoesNotBufferWhenQueueMissingFromMap(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = $this->createReceiverWithQueue($options);

        $amqpEnvelope = $this->createMock(\AMQPEnvelope::class);
        $amqpEnvelope->method('getDeliveryTag')->willReturn(42);
        $envelope = new Envelope(new \stdClass(), [new AmqpReceivedStamp($amqpEnvelope, 'test_queue')]);

        $this->connection->method('checkHeartbeat')->willReturn(false);

        // Simulate the queue map being wiped (e.g. a genuine AMQPException
        // cleared it between get() and ack()) — the delivery tag belongs to
        // the dead channel, so ackMessage() must refuse to buffer it.
        $reflection = new \ReflectionClass(Receiver::class);
        $queuesProperty = $reflection->getProperty('queues');
        $queuesProperty->setValue($receiver, []);

        $this->queue->expects($this->never())->method('ack');

        $receiver->ack($envelope);

        // No tracking state must have been written.
        $unackedProperty = $reflection->getProperty('unacked');
        $lastUnackedProperty = $reflection->getProperty('lastUnacked');
        $this->assertSame([], $unackedProperty->getValue($receiver));
        $this->assertSame([], $lastUnackedProperty->getValue($receiver));
    }
}
