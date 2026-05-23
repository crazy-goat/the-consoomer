<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\AmqpFactoryInterface;
use CrazyGoat\TheConsoomer\ConnectionInterface;
use CrazyGoat\TheConsoomer\InfrastructureSetup;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class InfrastructureSetupTest extends TestCase
{
    private AmqpFactoryInterface&MockObject $factory;
    private ConnectionInterface&MockObject $connection;
    private \AMQPChannel&MockObject $channel;
    private \AMQPExchange&MockObject $exchange;
    private \AMQPQueue&MockObject $queue;
    private \AMQPExchange&MockObject $retryExchange;
    private \AMQPQueue&MockObject $retryQueue;

    protected function setUp(): void
    {
        $this->factory = $this->createMock(AmqpFactoryInterface::class);
        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->channel = $this->createMock(\AMQPChannel::class);
        $this->exchange = $this->createMock(\AMQPExchange::class);
        $this->queue = $this->createMock(\AMQPQueue::class);
        $this->retryExchange = $this->createMock(\AMQPExchange::class);
        $this->retryQueue = $this->createMock(\AMQPQueue::class);
    }

    public function testSetupIsIdempotentAndOnlyExecutesOnce(): void
    {
        $this->connection->method('getChannel')->willReturn($this->channel);
        $this->factory->method('createExchange')
            ->willReturnOnConsecutiveCalls($this->exchange, $this->retryExchange);
        $this->factory->method('createQueue')
            ->willReturnOnConsecutiveCalls($this->queue, $this->retryQueue);

        $this->exchange->expects($this->once())->method('setName')->with('test_exchange');
        $this->exchange->expects($this->once())->method('setType')->with(AMQP_EX_TYPE_DIRECT);
        $this->exchange->expects($this->once())->method('declareExchange');
        $this->exchange->method('getName')->willReturn('test_exchange');

        $this->queue->expects($this->once())->method('setName')->with('test_queue');
        $this->queue->expects($this->once())->method('declareQueue');
        $this->queue->expects($this->once())->method('bind')->with('test_exchange', 'test_key');

        $this->retryExchange->method('setName');
        $this->retryExchange->method('setType');
        $this->retryExchange->method('declareExchange');

        $this->retryQueue->method('setName');
        $this->retryQueue->method('setFlags');
        $this->retryQueue->method('setArguments');
        $this->retryQueue->method('declareQueue');
        $this->retryQueue->method('bind');

        $options = [
            'exchange' => 'test_exchange',
            'queue' => 'test_queue',
            'routing_key' => 'test_key',
        ];

        $setup = new InfrastructureSetup($this->factory, $this->connection, $options);

        $setup->setup();
        $setup->setup();
    }

    public function testSetupCreatesExchangeAndQueueWithCorrectParameters(): void
    {
        $this->connection
            ->expects($this->exactly(3))
            ->method('getChannel')
            ->willReturn($this->channel);

        $this->factory
            ->method('createExchange')
            ->willReturnOnConsecutiveCalls($this->exchange, $this->retryExchange);

        $this->factory
            ->method('createQueue')
            ->willReturnOnConsecutiveCalls($this->queue, $this->retryQueue);

        $this->exchange
            ->expects($this->once())
            ->method('setName')
            ->with('my_exchange');

        $this->exchange
            ->expects($this->once())
            ->method('setType')
            ->with(AMQP_EX_TYPE_DIRECT);

        $this->exchange
            ->expects($this->once())
            ->method('declareExchange');

        $this->exchange
            ->method('getName')
            ->willReturn('my_exchange');

        $this->queue
            ->expects($this->once())
            ->method('setName')
            ->with('my_queue');

        $this->queue
            ->expects($this->once())
            ->method('declareQueue');

        $this->queue
            ->expects($this->once())
            ->method('bind')
            ->with('my_exchange', 'my_key');

        $this->retryExchange->method('setName');
        $this->retryExchange->method('setType');
        $this->retryExchange->method('declareExchange');

        $this->retryQueue->method('setName');
        $this->retryQueue->method('setFlags');
        $this->retryQueue->method('setArguments');
        $this->retryQueue->method('declareQueue');
        $this->retryQueue->method('bind');

        $options = [
            'exchange' => 'my_exchange',
            'queue' => 'my_queue',
            'routing_key' => 'my_key',
        ];

        $setup = new InfrastructureSetup($this->factory, $this->connection, $options);
        $setup->setup();
    }

    public function testSetupWithFanoutExchangeType(): void
    {
        $this->connection->method('getChannel')->willReturn($this->channel);
        $this->factory->method('createExchange')
            ->willReturnOnConsecutiveCalls($this->exchange, $this->retryExchange);
        $this->factory->method('createQueue')
            ->willReturnOnConsecutiveCalls($this->queue, $this->retryQueue);

        $this->exchange->expects($this->once())->method('setType')->with(AMQP_EX_TYPE_FANOUT);
        $this->exchange->method('getName')->willReturn('test_exchange');

        $this->retryExchange->method('setName');
        $this->retryExchange->method('setType');
        $this->retryExchange->method('declareExchange');

        $this->retryQueue->method('setName');
        $this->retryQueue->method('setFlags');
        $this->retryQueue->method('setArguments');
        $this->retryQueue->method('declareQueue');
        $this->retryQueue->method('bind');

        $options = [
            'exchange' => 'test_exchange',
            'queue' => 'test_queue',
            'exchange_type' => 'fanout',
        ];

        $setup = new InfrastructureSetup($this->factory, $this->connection, $options);
        $setup->setup();
    }

    public function testSetupWithTopicExchangeType(): void
    {
        $this->connection->method('getChannel')->willReturn($this->channel);
        $this->factory->method('createExchange')
            ->willReturnOnConsecutiveCalls($this->exchange, $this->retryExchange);
        $this->factory->method('createQueue')
            ->willReturnOnConsecutiveCalls($this->queue, $this->retryQueue);

        $this->exchange->expects($this->once())->method('setType')->with(AMQP_EX_TYPE_TOPIC);
        $this->exchange->method('getName')->willReturn('test_exchange');

        $this->retryExchange->method('setName');
        $this->retryExchange->method('setType');
        $this->retryExchange->method('declareExchange');

        $this->retryQueue->method('setName');
        $this->retryQueue->method('setFlags');
        $this->retryQueue->method('setArguments');
        $this->retryQueue->method('declareQueue');
        $this->retryQueue->method('bind');

        $options = [
            'exchange' => 'test_exchange',
            'queue' => 'test_queue',
            'exchange_type' => 'topic',
        ];

        $setup = new InfrastructureSetup($this->factory, $this->connection, $options);
        $setup->setup();
    }

    public function testSetupWithHeadersExchangeType(): void
    {
        $this->connection->method('getChannel')->willReturn($this->channel);
        $this->factory->method('createExchange')
            ->willReturnOnConsecutiveCalls($this->exchange, $this->retryExchange);
        $this->factory->method('createQueue')
            ->willReturnOnConsecutiveCalls($this->queue, $this->retryQueue);

        $this->exchange->expects($this->once())->method('setType')->with(AMQP_EX_TYPE_HEADERS);
        $this->exchange->method('getName')->willReturn('test_exchange');

        $this->retryExchange->method('setName');
        $this->retryExchange->method('setType');
        $this->retryExchange->method('declareExchange');

        $this->retryQueue->method('setName');
        $this->retryQueue->method('setFlags');
        $this->retryQueue->method('setArguments');
        $this->retryQueue->method('declareQueue');
        $this->retryQueue->method('bind');

        $options = [
            'exchange' => 'test_exchange',
            'queue' => 'test_queue',
            'exchange_type' => 'headers',
        ];

        $setup = new InfrastructureSetup($this->factory, $this->connection, $options);
        $setup->setup();
    }

    public function testSetupWithInvalidExchangeTypeFallsBackToDirect(): void
    {
        $this->connection->method('getChannel')->willReturn($this->channel);
        $this->factory->method('createExchange')
            ->willReturnOnConsecutiveCalls($this->exchange, $this->retryExchange);
        $this->factory->method('createQueue')
            ->willReturnOnConsecutiveCalls($this->queue, $this->retryQueue);

        $this->exchange->expects($this->once())->method('setType')->with(AMQP_EX_TYPE_DIRECT);
        $this->exchange->method('getName')->willReturn('test_exchange');

        $this->retryExchange->method('setName');
        $this->retryExchange->method('setType');
        $this->retryExchange->method('declareExchange');

        $this->retryQueue->method('setName');
        $this->retryQueue->method('setFlags');
        $this->retryQueue->method('setArguments');
        $this->retryQueue->method('declareQueue');
        $this->retryQueue->method('bind');

        $options = [
            'exchange' => 'test_exchange',
            'queue' => 'test_queue',
            'exchange_type' => 'invalid_type',
        ];

        $setup = new InfrastructureSetup($this->factory, $this->connection, $options);
        $setup->setup();
    }

    public function testSetupWithNullExchangeTypeFallsBackToDirect(): void
    {
        $this->connection->method('getChannel')->willReturn($this->channel);
        $this->factory->method('createExchange')
            ->willReturnOnConsecutiveCalls($this->exchange, $this->retryExchange);
        $this->factory->method('createQueue')
            ->willReturnOnConsecutiveCalls($this->queue, $this->retryQueue);

        $this->exchange->expects($this->once())->method('setType')->with(AMQP_EX_TYPE_DIRECT);
        $this->exchange->method('getName')->willReturn('test_exchange');

        $this->retryExchange->method('setName');
        $this->retryExchange->method('setType');
        $this->retryExchange->method('declareExchange');

        $this->retryQueue->method('setName');
        $this->retryQueue->method('setFlags');
        $this->retryQueue->method('setArguments');
        $this->retryQueue->method('declareQueue');
        $this->retryQueue->method('bind');

        $options = [
            'exchange' => 'test_exchange',
            'queue' => 'test_queue',
            'exchange_type' => null,
        ];

        $setup = new InfrastructureSetup($this->factory, $this->connection, $options);
        $setup->setup();
    }

    public function testSetupAppliesQueueArgumentsToQueue(): void
    {
        $this->connection->method('getChannel')->willReturn($this->channel);
        $this->factory->method('createExchange')
            ->willReturnOnConsecutiveCalls($this->exchange, $this->retryExchange);
        $this->factory->method('createQueue')
            ->willReturnOnConsecutiveCalls($this->queue, $this->retryQueue);

        $this->exchange->method('getName')->willReturn('test_exchange');

        $expectedArguments = [
            'x-max-priority' => 10,
            'x-message-ttl' => 60000,
            'x-dead-letter-exchange' => 'dlx',
        ];

        $this->queue->expects($this->once())->method('setArguments')->with($expectedArguments);
        $this->queue->expects($this->once())->method('declareQueue');

        $this->retryExchange->method('setName');
        $this->retryExchange->method('setType');
        $this->retryExchange->method('declareExchange');

        $this->retryQueue->method('setName');
        $this->retryQueue->method('setFlags');
        $this->retryQueue->method('setArguments');
        $this->retryQueue->method('declareQueue');
        $this->retryQueue->method('bind');

        $options = [
            'exchange' => 'test_exchange',
            'queue' => 'test_queue',
            'queue_arguments' => $expectedArguments,
        ];

        $setup = new InfrastructureSetup($this->factory, $this->connection, $options);
        $setup->setup();
    }

    public function testSetupDoesNotSetArgumentsWhenQueueArgumentsNotProvided(): void
    {
        $this->connection->method('getChannel')->willReturn($this->channel);
        $this->factory->method('createExchange')
            ->willReturnOnConsecutiveCalls($this->exchange, $this->retryExchange);
        $this->factory->method('createQueue')
            ->willReturnOnConsecutiveCalls($this->queue, $this->retryQueue);

        $this->exchange->method('getName')->willReturn('test_exchange');

        // setArguments should never be called when queue_arguments is not in options
        $this->queue->expects($this->never())->method('setArguments');
        $this->queue->expects($this->once())->method('declareQueue');

        $this->retryExchange->method('setName');
        $this->retryExchange->method('setType');
        $this->retryExchange->method('declareExchange');

        $this->retryQueue->method('setName');
        $this->retryQueue->method('setFlags');
        $this->retryQueue->method('setArguments');
        $this->retryQueue->method('declareQueue');
        $this->retryQueue->method('bind');

        $options = [
            'exchange' => 'test_exchange',
            'queue' => 'test_queue',
        ];

        $setup = new InfrastructureSetup($this->factory, $this->connection, $options);
        $setup->setup();
    }

    public function testSetupWithMultipleQueues(): void
    {
        $this->connection->method('getChannel')->willReturn($this->channel);
        $this->factory->method('createExchange')
            ->willReturnOnConsecutiveCalls($this->exchange, $this->retryExchange);
        $this->factory->method('createQueue')
            ->willReturnOnConsecutiveCalls($this->queue, $this->retryQueue, $this->createMock(\AMQPQueue::class));

        $this->exchange->method('getName')->willReturn('test_exchange');

        $this->exchange->expects($this->once())->method('setName')->with('test_exchange');
        $this->exchange->expects($this->once())->method('setType')->with(AMQP_EX_TYPE_DIRECT);
        $this->exchange->expects($this->once())->method('declareExchange');

        $this->queue->expects($this->once())->method('setName')->with('queue_a');
        $this->queue->expects($this->once())->method('declareQueue');
        $this->queue->expects($this->once())->method('bind')->with('test_exchange', '');

        $this->retryExchange->method('setName');
        $this->retryExchange->method('setType');
        $this->retryExchange->method('declareExchange');

        $this->retryQueue->method('setName');
        $this->retryQueue->method('setFlags');
        $this->retryQueue->method('setArguments');
        $this->retryQueue->method('declareQueue');
        $this->retryQueue->method('bind');

        $options = [
            'exchange' => 'test_exchange',
            'queues' => [
                'queue_a' => [],
                'queue_b' => [],
            ],
        ];

        $setup = new InfrastructureSetup($this->factory, $this->connection, $options);
        $setup->setup();
    }

    public function testSetupWithMultipleQueuesAndBindingKeys(): void
    {
        $this->connection->method('getChannel')->willReturn($this->channel);
        $this->factory->method('createExchange')
            ->willReturnOnConsecutiveCalls($this->exchange, $this->retryExchange);

        $queueA = $this->createMock(\AMQPQueue::class);
        $queueB = $this->createMock(\AMQPQueue::class);

        $this->factory->method('createQueue')
            ->willReturnOnConsecutiveCalls($queueA, $queueB, $this->retryQueue);

        $this->exchange->method('getName')->willReturn('test_exchange');
        $this->exchange->method('setName');
        $this->exchange->method('setType');
        $this->exchange->method('declareExchange');

        $bindCallCount = 0;
        $queueA->expects($this->exactly(2))->method('bind')
            ->willReturnCallback(function ($exchange, $key, $args = []) use (&$bindCallCount): void {
                if ($bindCallCount === 0) {
                    $this->assertSame('test_exchange', $exchange);
                    $this->assertSame('order.created', $key);
                } elseif ($bindCallCount === 1) {
                    $this->assertSame('test_exchange', $exchange);
                    $this->assertSame('order.updated', $key);
                }
                $bindCallCount++;
            });

        $queueB->expects($this->once())->method('setName')->with('notifications');
        $queueB->expects($this->once())->method('declareQueue');
        $queueB->expects($this->once())->method('bind')->with('test_exchange', 'notification.*');

        $this->retryExchange->method('setName');
        $this->retryExchange->method('setType');
        $this->retryExchange->method('declareExchange');

        $this->retryQueue->method('setName');
        $this->retryQueue->method('setFlags');
        $this->retryQueue->method('setArguments');
        $this->retryQueue->method('declareQueue');
        $this->retryQueue->method('bind');

        $options = [
            'exchange' => 'test_exchange',
            'queues' => [
                'orders' => [
                    'binding_keys' => ['order.created', 'order.updated'],
                ],
                'notifications' => [
                    'binding_keys' => ['notification.*'],
                ],
            ],
        ];

        $setup = new InfrastructureSetup($this->factory, $this->connection, $options);
        $setup->setup();
    }

    public function testSetupWithMultipleQueuesAndBindingArguments(): void
    {
        $this->connection->method('getChannel')->willReturn($this->channel);
        $this->factory->method('createExchange')
            ->willReturnOnConsecutiveCalls($this->exchange, $this->retryExchange);

        $queueA = $this->createMock(\AMQPQueue::class);

        $this->factory->method('createQueue')
            ->willReturnOnConsecutiveCalls($queueA, $this->retryQueue);

        $this->exchange->method('getName')->willReturn('test_exchange');
        $this->exchange->method('setName');
        $this->exchange->method('setType');
        $this->exchange->method('declareExchange');

        $queueA->expects($this->once())->method('setName')->with('my_queue');
        $queueA->expects($this->once())->method('declareQueue');
        $queueA->expects($this->once())->method('bind')
            ->with('test_exchange', 'my.key', ['x-match' => 'any']);

        $this->retryExchange->method('setName');
        $this->retryExchange->method('setType');
        $this->retryExchange->method('declareExchange');

        $this->retryQueue->method('setName');
        $this->retryQueue->method('setFlags');
        $this->retryQueue->method('setArguments');
        $this->retryQueue->method('declareQueue');
        $this->retryQueue->method('bind');

        $options = [
            'exchange' => 'test_exchange',
            'queues' => [
                'my_queue' => [
                    'binding_keys' => ['my.key'],
                    'binding_arguments' => ['x-match' => 'any'],
                ],
            ],
        ];

        $setup = new InfrastructureSetup($this->factory, $this->connection, $options);
        $setup->setup();
    }

    public function testSetupWithMultipleQueuesAndPerQueueArguments(): void
    {
        $this->connection->method('getChannel')->willReturn($this->channel);
        $this->factory->method('createExchange')
            ->willReturnOnConsecutiveCalls($this->exchange, $this->retryExchange);

        $queueA = $this->createMock(\AMQPQueue::class);

        $this->factory->method('createQueue')
            ->willReturnOnConsecutiveCalls($queueA, $this->retryQueue);

        $this->exchange->method('getName')->willReturn('test_exchange');
        $this->exchange->method('setName');
        $this->exchange->method('setType');
        $this->exchange->method('declareExchange');

        $queueA->expects($this->once())->method('setName')->with('priority_queue');
        $queueA->expects($this->once())->method('setArguments')->with(['x-max-priority' => 10]);
        $queueA->expects($this->once())->method('declareQueue');
        $queueA->expects($this->once())->method('bind')->with('test_exchange', '');

        $this->retryExchange->method('setName');
        $this->retryExchange->method('setType');
        $this->retryExchange->method('declareExchange');

        $this->retryQueue->method('setName');
        $this->retryQueue->method('setFlags');
        $this->retryQueue->method('setArguments');
        $this->retryQueue->method('declareQueue');
        $this->retryQueue->method('bind');

        $options = [
            'exchange' => 'test_exchange',
            'queues' => [
                'priority_queue' => [
                    'binding_keys' => [''],
                    'arguments' => ['x-max-priority' => 10],
                ],
            ],
        ];

        $setup = new InfrastructureSetup($this->factory, $this->connection, $options);
        $setup->setup();
    }

    public function testConstructorThrowsWhenNeitherQueueNorQueuesProvided(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('either queue or queues option is required');

        new InfrastructureSetup($this->factory, $this->connection, ['exchange' => 'test_exchange']);
    }

    public function testConstructorThrowsWhenQueuesIsNotAnArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('queues option must be an array');

        new InfrastructureSetup($this->factory, $this->connection, [
            'exchange' => 'test_exchange',
            'queues' => 'invalid',
        ]);
    }

    public function testConstructorThrowsWhenQueuesIsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('queues option must not be empty');

        new InfrastructureSetup($this->factory, $this->connection, [
            'exchange' => 'test_exchange',
            'queues' => [],
        ]);
    }

    public function testConstructorThrowsWhenQueueNameIsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Each queue name must be a non-empty string');

        new InfrastructureSetup($this->factory, $this->connection, [
            'exchange' => 'test_exchange',
            'queues' => ['' => []],
        ]);
    }

    public function testConstructorThrowsWhenQueueConfigIsNotArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('queues[bad] must be an array');

        new InfrastructureSetup($this->factory, $this->connection, [
            'exchange' => 'test_exchange',
            'queues' => ['bad' => 'not_an_array'],
        ]);
    }

    public function testConstructorThrowsWhenBindingKeysIsNotArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('queues[q].binding_keys must be an array');

        new InfrastructureSetup($this->factory, $this->connection, [
            'exchange' => 'test_exchange',
            'queues' => [
                'q' => ['binding_keys' => 'invalid'],
            ],
        ]);
    }

    public function testConstructorThrowsWhenBindingKeyIsNotString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('queues[q].binding_keys[0] must be a string');

        new InfrastructureSetup($this->factory, $this->connection, [
            'exchange' => 'test_exchange',
            'queues' => [
                'q' => ['binding_keys' => [42]],
            ],
        ]);
    }

    public function testConstructorThrowsWhenBindingArgumentsIsNotArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('queues[q].binding_arguments must be an array');

        new InfrastructureSetup($this->factory, $this->connection, [
            'exchange' => 'test_exchange',
            'queues' => [
                'q' => ['binding_arguments' => 'invalid'],
            ],
        ]);
    }

    public function testConstructorThrowsWhenQueueArgumentsIsNotArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('queues[q].arguments must be an array');

        new InfrastructureSetup($this->factory, $this->connection, [
            'exchange' => 'test_exchange',
            'queues' => [
                'q' => ['arguments' => 'invalid'],
            ],
        ]);
    }

    public function testSetupIdempotentWithMultipleQueues(): void
    {
        $this->connection->method('getChannel')->willReturn($this->channel);
        $this->factory->method('createExchange')
            ->willReturnOnConsecutiveCalls($this->exchange, $this->retryExchange);

        $queueA = $this->createMock(\AMQPQueue::class);

        $this->factory->method('createQueue')
            ->willReturnOnConsecutiveCalls($queueA, $this->retryQueue);

        $this->exchange->method('getName')->willReturn('test_exchange');
        $this->exchange->method('setName');
        $this->exchange->method('setType');
        $this->exchange->method('declareExchange');

        $queueA->expects($this->once())->method('setName')->with('my_queue');
        $queueA->expects($this->once())->method('declareQueue');
        $queueA->expects($this->once())->method('bind');

        $this->retryExchange->method('setName');
        $this->retryExchange->method('setType');
        $this->retryExchange->method('declareExchange');

        $this->retryQueue->method('setName');
        $this->retryQueue->method('setFlags');
        $this->retryQueue->method('setArguments');
        $this->retryQueue->method('declareQueue');
        $this->retryQueue->method('bind');

        $options = [
            'exchange' => 'test_exchange',
            'queues' => [
                'my_queue' => [],
            ],
        ];

        $setup = new InfrastructureSetup($this->factory, $this->connection, $options);
        $setup->setup();
        $setup->setup();
    }

    public function testSetupWithMultipleQueuesUsesGlobalQueueArguments(): void
    {
        $this->connection->method('getChannel')->willReturn($this->channel);
        $this->factory->method('createExchange')
            ->willReturnOnConsecutiveCalls($this->exchange, $this->retryExchange);

        $queueA = $this->createMock(\AMQPQueue::class);

        $this->factory->method('createQueue')
            ->willReturnOnConsecutiveCalls($queueA, $this->retryQueue);

        $this->exchange->method('getName')->willReturn('test_exchange');
        $this->exchange->method('setName');
        $this->exchange->method('setType');
        $this->exchange->method('declareExchange');

        $queueA->expects($this->once())->method('setName')->with('my_queue');
        $queueA->expects($this->once())->method('setArguments')->with(['x-message-ttl' => 60000]);
        $queueA->expects($this->once())->method('declareQueue');
        $queueA->expects($this->once())->method('bind')->with('test_exchange', '');

        $this->retryExchange->method('setName');
        $this->retryExchange->method('setType');
        $this->retryExchange->method('declareExchange');

        $this->retryQueue->method('setName');
        $this->retryQueue->method('setFlags');
        $this->retryQueue->method('setArguments');
        $this->retryQueue->method('declareQueue');
        $this->retryQueue->method('bind');

        $options = [
            'exchange' => 'test_exchange',
            'queues' => [
                'my_queue' => [],
            ],
            'queue_arguments' => ['x-message-ttl' => 60000],
        ];

        $setup = new InfrastructureSetup($this->factory, $this->connection, $options);
        $setup->setup();
    }

    public function testSetupWithSingleQueueAndBindingKeys(): void
    {
        $this->connection->method('getChannel')->willReturn($this->channel);
        $this->factory->method('createExchange')
            ->willReturnOnConsecutiveCalls($this->exchange, $this->retryExchange);
        $this->factory->method('createQueue')
            ->willReturnOnConsecutiveCalls($this->queue, $this->retryQueue);

        $this->exchange->method('getName')->willReturn('test_exchange');
        $this->exchange->method('setName');
        $this->exchange->method('setType');
        $this->exchange->method('declareExchange');

        $bindCallCount = 0;
        $this->queue->expects($this->exactly(2))->method('bind')
            ->willReturnCallback(function ($exchange, $key, $args = []) use (&$bindCallCount): void {
                if ($bindCallCount === 0) {
                    $this->assertSame('test_exchange', $exchange);
                    $this->assertSame('order.created', $key);
                } elseif ($bindCallCount === 1) {
                    $this->assertSame('test_exchange', $exchange);
                    $this->assertSame('order.updated', $key);
                }
                $bindCallCount++;
            });

        $this->retryExchange->method('setName');
        $this->retryExchange->method('setType');
        $this->retryExchange->method('declareExchange');

        $this->retryQueue->method('setName');
        $this->retryQueue->method('setFlags');
        $this->retryQueue->method('setArguments');
        $this->retryQueue->method('declareQueue');
        $this->retryQueue->method('bind');

        $options = [
            'exchange' => 'test_exchange',
            'queue' => 'test_queue',
            'binding_keys' => ['order.created', 'order.updated'],
        ];

        $setup = new InfrastructureSetup($this->factory, $this->connection, $options);
        $setup->setup();
    }

    public function testSetupWithSingleQueueAndBindingArguments(): void
    {
        $this->connection->method('getChannel')->willReturn($this->channel);
        $this->factory->method('createExchange')
            ->willReturnOnConsecutiveCalls($this->exchange, $this->retryExchange);
        $this->factory->method('createQueue')
            ->willReturnOnConsecutiveCalls($this->queue, $this->retryQueue);

        $this->exchange->method('getName')->willReturn('test_exchange');
        $this->exchange->method('setName');
        $this->exchange->method('setType');
        $this->exchange->method('declareExchange');

        $this->queue->expects($this->once())->method('bind')
            ->with('test_exchange', 'my.key', ['x-match' => 'any']);

        $this->retryExchange->method('setName');
        $this->retryExchange->method('setType');
        $this->retryExchange->method('declareExchange');

        $this->retryQueue->method('setName');
        $this->retryQueue->method('setFlags');
        $this->retryQueue->method('setArguments');
        $this->retryQueue->method('declareQueue');
        $this->retryQueue->method('bind');

        $options = [
            'exchange' => 'test_exchange',
            'queue' => 'test_queue',
            'binding_keys' => ['my.key'],
            'binding_arguments' => ['x-match' => 'any'],
        ];

        $setup = new InfrastructureSetup($this->factory, $this->connection, $options);
        $setup->setup();
    }

    public function testConstructorThrowsWhenTopLevelBindingKeysIsNotArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('binding_keys must be an array');

        new InfrastructureSetup($this->factory, $this->connection, [
            'exchange' => 'test_exchange',
            'queue' => 'test_queue',
            'binding_keys' => 'invalid',
        ]);
    }

    public function testConstructorThrowsWhenTopLevelBindingKeyIsNotString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('binding_keys[0] must be a string');

        new InfrastructureSetup($this->factory, $this->connection, [
            'exchange' => 'test_exchange',
            'queue' => 'test_queue',
            'binding_keys' => [42],
        ]);
    }

    public function testConstructorThrowsWhenTopLevelBindingArgumentsIsNotArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('binding_arguments must be an array');

        new InfrastructureSetup($this->factory, $this->connection, [
            'exchange' => 'test_exchange',
            'queue' => 'test_queue',
            'binding_arguments' => 'invalid',
        ]);
    }

    public function testConstructorThrowsWhenTopLevelBindingKeysIsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('binding_keys must not be empty');

        new InfrastructureSetup($this->factory, $this->connection, [
            'exchange' => 'test_exchange',
            'queue' => 'test_queue',
            'binding_keys' => [],
        ]);
    }

    public function testSetupWithSingleQueueFallsBackToRoutingKeyWhenNoBindingKeys(): void
    {
        $this->connection->method('getChannel')->willReturn($this->channel);
        $this->factory->method('createExchange')
            ->willReturnOnConsecutiveCalls($this->exchange, $this->retryExchange);
        $this->factory->method('createQueue')
            ->willReturnOnConsecutiveCalls($this->queue, $this->retryQueue);

        $this->exchange->method('getName')->willReturn('test_exchange');
        $this->exchange->method('setName');
        $this->exchange->method('setType');
        $this->exchange->method('declareExchange');

        $this->queue->expects($this->once())->method('bind')
            ->with('test_exchange', 'fallback_key');

        $this->retryExchange->method('setName');
        $this->retryExchange->method('setType');
        $this->retryExchange->method('declareExchange');

        $this->retryQueue->method('setName');
        $this->retryQueue->method('setFlags');
        $this->retryQueue->method('setArguments');
        $this->retryQueue->method('declareQueue');
        $this->retryQueue->method('bind');

        $options = [
            'exchange' => 'test_exchange',
            'queue' => 'test_queue',
            'routing_key' => 'fallback_key',
        ];

        $setup = new InfrastructureSetup($this->factory, $this->connection, $options);
        $setup->setup();
    }

    public function testSetupWithSingleQueuePassesBindingArgumentsWithRoutingKeyFallback(): void
    {
        $this->connection->method('getChannel')->willReturn($this->channel);
        $this->factory->method('createExchange')
            ->willReturnOnConsecutiveCalls($this->exchange, $this->retryExchange);
        $this->factory->method('createQueue')
            ->willReturnOnConsecutiveCalls($this->queue, $this->retryQueue);

        $this->exchange->method('getName')->willReturn('test_exchange');
        $this->exchange->method('setName');
        $this->exchange->method('setType');
        $this->exchange->method('declareExchange');

        $this->queue->expects($this->once())->method('bind')
            ->with('test_exchange', 'routing_key_val', ['x-match' => 'all']);

        $this->retryExchange->method('setName');
        $this->retryExchange->method('setType');
        $this->retryExchange->method('declareExchange');

        $this->retryQueue->method('setName');
        $this->retryQueue->method('setFlags');
        $this->retryQueue->method('setArguments');
        $this->retryQueue->method('declareQueue');
        $this->retryQueue->method('bind');

        $options = [
            'exchange' => 'test_exchange',
            'queue' => 'test_queue',
            'routing_key' => 'routing_key_val',
            'binding_arguments' => ['x-match' => 'all'],
        ];

        $setup = new InfrastructureSetup($this->factory, $this->connection, $options);
        $setup->setup();
    }

    public function testSetupWithMultipleQueuesPerQueueArgumentsOverrideGlobal(): void
    {
        $this->connection->method('getChannel')->willReturn($this->channel);
        $this->factory->method('createExchange')
            ->willReturnOnConsecutiveCalls($this->exchange, $this->retryExchange);

        $queueA = $this->createMock(\AMQPQueue::class);

        $this->factory->method('createQueue')
            ->willReturnOnConsecutiveCalls($queueA, $this->retryQueue);

        $this->exchange->method('getName')->willReturn('test_exchange');
        $this->exchange->method('setName');
        $this->exchange->method('setType');
        $this->exchange->method('declareExchange');

        // Per-queue arguments should override global queue_arguments
        $queueA->expects($this->once())->method('setName')->with('my_queue');
        $queueA->expects($this->once())->method('setArguments')->with(['x-max-priority' => 10]);
        $queueA->expects($this->once())->method('declareQueue');
        $queueA->expects($this->once())->method('bind')->with('test_exchange', '');

        $this->retryExchange->method('setName');
        $this->retryExchange->method('setType');
        $this->retryExchange->method('declareExchange');

        $this->retryQueue->method('setName');
        $this->retryQueue->method('setFlags');
        $this->retryQueue->method('setArguments');
        $this->retryQueue->method('declareQueue');
        $this->retryQueue->method('bind');

        $options = [
            'exchange' => 'test_exchange',
            'queues' => [
                'my_queue' => [
                    'arguments' => ['x-max-priority' => 10],
                ],
            ],
            'queue_arguments' => ['x-message-ttl' => 60000],
        ];

        $setup = new InfrastructureSetup($this->factory, $this->connection, $options);
        $setup->setup();
    }
}
