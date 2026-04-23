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
}
