<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\AmqpFactory;
use CrazyGoat\TheConsoomer\RawMessageStamp;
use CrazyGoat\TheConsoomer\Receiver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class ReceiverTest extends TestCase
{
    private AmqpFactory&MockObject $factory;
    private \AMQPConnection&MockObject $connection;
    private SerializerInterface&MockObject $serializer;
    private \AMQPQueue&MockObject $queue;

    protected function setUp(): void
    {
        $this->factory = $this->createMock(AmqpFactory::class);
        $this->connection = $this->createMock(\AMQPConnection::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->queue = $this->createMock(\AMQPQueue::class);
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
            ->with(['body' => '{"data":"test"}'])
            ->willReturn($messageEnvelope);

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options);

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

        $result = $receiver->get();

        $this->assertCount(1, $result);
        $this->assertInstanceOf(Envelope::class, $result[0]);
        $this->assertInstanceOf(RawMessageStamp::class, $result[0]->last(RawMessageStamp::class));
    }

    public function testAckThrowsExceptionWithoutStamp(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options);

        $envelope = new Envelope(new \stdClass());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No raw message stamp');

        $receiver->ack($envelope);
    }

    public function testRejectThrowsExceptionWithoutStamp(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options);

        $envelope = new Envelope(new \stdClass());

        $this->expectException(\RuntimeException::class);
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

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options);

        $reflection = new \ReflectionClass(Receiver::class);
        $maxUnackedProperty = $reflection->getProperty('maxUnackedMessages');

        $this->assertSame(100, $maxUnackedProperty->getValue($receiver));
    }

    public function testMaxUnackedMessagesConfigurationCanBeOverridden(): void
    {
        $options = ['queue' => 'test_queue', 'max_unacked_messages' => 50];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options);

        $reflection = new \ReflectionClass(Receiver::class);
        $maxUnackedProperty = $reflection->getProperty('maxUnackedMessages');

        $this->assertSame(50, $maxUnackedProperty->getValue($receiver));
    }

    public function testMaxUnackedMessagesMinimumIsOne(): void
    {
        $options = ['queue' => 'test_queue', 'max_unacked_messages' => 0];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options);

        $reflection = new \ReflectionClass(Receiver::class);
        $maxUnackedProperty = $reflection->getProperty('maxUnackedMessages');

        $this->assertSame(1, $maxUnackedProperty->getValue($receiver));
    }

    public function testMaxUnackedMessagesNegativeBecomesOne(): void
    {
        $options = ['queue' => 'test_queue', 'max_unacked_messages' => -10];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options);

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

        $this->expectException(\AMQPException::class);
        $this->expectExceptionMessage('Some other error');

        $receiver->get();
    }

    public function testConnectIsLazy(): void
    {
        $options = ['queue' => 'test_queue'];

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options);

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

        $this->factory
            ->expects($this->once())
            ->method('createChannel')
            ->with($this->connection)
            ->willReturn($channel);

        $this->factory
            ->expects($this->once())
            ->method('createQueue')
            ->with($channel)
            ->willReturn($this->queue);

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

        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options);
        $receiver->get();
    }

    private function createReceiverWithQueue(array $options): Receiver
    {
        $receiver = new Receiver($this->factory, $this->connection, $this->serializer, $options);

        $reflection = new \ReflectionClass(Receiver::class);
        $queueProperty = $reflection->getProperty('queue');
        $queueProperty->setValue($receiver, $this->queue);

        $callbackProperty = $reflection->getProperty('callback');
        $callbackProperty->setValue($receiver, fn(\AMQPEnvelope $message): false => false);

        return $receiver;
    }
}
