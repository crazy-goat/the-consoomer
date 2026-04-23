<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\AmqpFactoryInterface;
use CrazyGoat\TheConsoomer\ConnectionInterface;
use CrazyGoat\TheConsoomer\InfrastructureSetup;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ExchangeBindingsTest extends TestCase
{
    private AmqpFactoryInterface&MockObject $factory;
    private ConnectionInterface&MockObject $connection;
    private \AMQPChannel&MockObject $channel;
    private \AMQPExchange&MockObject $exchange;
    private \AMQPQueue&MockObject $queue;

    protected function setUp(): void
    {
        $this->factory = $this->createMock(AmqpFactoryInterface::class);
        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->channel = $this->createMock(\AMQPChannel::class);
        $this->exchange = $this->createMock(\AMQPExchange::class);
        $this->queue = $this->createMock(\AMQPQueue::class);
    }

    public function testSetupCreatesExchangeBindings(): void
    {
        $this->connection->method('getChannel')->willReturn($this->channel);
        $this->factory->method('createExchange')->with($this->channel)->willReturn($this->exchange);
        $this->factory->method('createQueue')->with($this->channel)->willReturn($this->queue);

        $this->exchange->method('getName')->willReturn('source_exchange');

        $callCount = 0;
        $this->exchange->expects($this->exactly(2))
            ->method('bind')
            ->willReturnCallback(function ($target, $routingKey) use (&$callCount): void {
                if ($callCount === 0) {
                    $this->assertSame('target_exchange', $target);
                    $this->assertSame('routing.key.1', $routingKey);
                } elseif ($callCount === 1) {
                    $this->assertSame('target_exchange', $target);
                    $this->assertSame('routing.key.2', $routingKey);
                }
                $callCount++;
            });

        $options = [
            'exchange' => 'source_exchange',
            'queue' => 'test_queue',
            'exchange_bindings' => [
                [
                    'target' => 'target_exchange',
                    'routing_keys' => ['routing.key.1', 'routing.key.2'],
                ],
            ],
        ];

        $setup = new InfrastructureSetup($this->factory, $this->connection, $options);
        $setup->setup();
    }

    public function testSetupCreatesMultipleExchangeBindings(): void
    {
        $this->connection->method('getChannel')->willReturn($this->channel);
        $this->factory->method('createExchange')->with($this->channel)->willReturn($this->exchange);
        $this->factory->method('createQueue')->with($this->channel)->willReturn($this->queue);

        $this->exchange->method('getName')->willReturn('source_exchange');

        $expectedCalls = [
            ['target_exchange_1', 'key.1'],
            ['target_exchange_2', 'key.2'],
            ['target_exchange_2', 'key.3'],
        ];
        $callIndex = 0;

        $this->exchange->expects($this->exactly(3))
            ->method('bind')
            ->willReturnCallback(function ($target, $routingKey) use ($expectedCalls, &$callIndex): void {
                $this->assertSame($expectedCalls[$callIndex][0], $target);
                $this->assertSame($expectedCalls[$callIndex][1], $routingKey);
                $callIndex++;
            });

        $options = [
            'exchange' => 'source_exchange',
            'queue' => 'test_queue',
            'exchange_bindings' => [
                [
                    'target' => 'target_exchange_1',
                    'routing_keys' => ['key.1'],
                ],
                [
                    'target' => 'target_exchange_2',
                    'routing_keys' => ['key.2', 'key.3'],
                ],
            ],
        ];

        $setup = new InfrastructureSetup($this->factory, $this->connection, $options);
        $setup->setup();
    }

    public function testSetupWithEmptyRoutingKeysUsesEmptyString(): void
    {
        $this->connection->method('getChannel')->willReturn($this->channel);
        $this->factory->method('createExchange')->with($this->channel)->willReturn($this->exchange);
        $this->factory->method('createQueue')->with($this->channel)->willReturn($this->queue);

        $this->exchange->method('getName')->willReturn('source_exchange');

        $this->exchange->expects($this->once())
            ->method('bind')
            ->with('target_exchange', '');

        $options = [
            'exchange' => 'source_exchange',
            'queue' => 'test_queue',
            'exchange_bindings' => [
                [
                    'target' => 'target_exchange',
                ],
            ],
        ];

        $setup = new InfrastructureSetup($this->factory, $this->connection, $options);
        $setup->setup();
    }

    public function testValidationThrowsExceptionForInvalidBindingsType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exchange_bindings must be an array');

        $options = [
            'exchange' => 'test_exchange',
            'queue' => 'test_queue',
            'exchange_bindings' => 'invalid',
        ];

        new InfrastructureSetup($this->factory, $this->connection, $options);
    }

    public function testValidationThrowsExceptionForInvalidBindingType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exchange_bindings[0] must be an array');

        $options = [
            'exchange' => 'test_exchange',
            'queue' => 'test_queue',
            'exchange_bindings' => ['invalid'],
        ];

        new InfrastructureSetup($this->factory, $this->connection, $options);
    }

    public function testValidationThrowsExceptionForMissingTarget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exchange_bindings[0].target must be a non-empty string');

        $options = [
            'exchange' => 'test_exchange',
            'queue' => 'test_queue',
            'exchange_bindings' => [
                [
                    'routing_keys' => ['key.1'],
                ],
            ],
        ];

        new InfrastructureSetup($this->factory, $this->connection, $options);
    }

    public function testValidationThrowsExceptionForEmptyTarget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exchange_bindings[0].target must be a non-empty string');

        $options = [
            'exchange' => 'test_exchange',
            'queue' => 'test_queue',
            'exchange_bindings' => [
                [
                    'target' => '',
                ],
            ],
        ];

        new InfrastructureSetup($this->factory, $this->connection, $options);
    }

    public function testValidationThrowsExceptionForInvalidRoutingKeysType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exchange_bindings[0].routing_keys must be an array');

        $options = [
            'exchange' => 'test_exchange',
            'queue' => 'test_queue',
            'exchange_bindings' => [
                [
                    'target' => 'target_exchange',
                    'routing_keys' => 'invalid',
                ],
            ],
        ];

        new InfrastructureSetup($this->factory, $this->connection, $options);
    }

    public function testValidationThrowsExceptionForEmptyRoutingKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exchange_bindings[0].routing_keys must not be empty');

        $options = [
            'exchange' => 'test_exchange',
            'queue' => 'test_queue',
            'exchange_bindings' => [
                [
                    'target' => 'target_exchange',
                    'routing_keys' => [],
                ],
            ],
        ];

        new InfrastructureSetup($this->factory, $this->connection, $options);
    }

    public function testValidationThrowsExceptionForInvalidRoutingKeyType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exchange_bindings[0].routing_keys[0] must be a string');

        $options = [
            'exchange' => 'test_exchange',
            'queue' => 'test_queue',
            'exchange_bindings' => [
                [
                    'target' => 'target_exchange',
                    'routing_keys' => [123],
                ],
            ],
        ];

        new InfrastructureSetup($this->factory, $this->connection, $options);
    }

    public function testValidationPassesForValidBindings(): void
    {
        $this->connection->method('getChannel')->willReturn($this->channel);
        $this->factory->method('createExchange')->with($this->channel)->willReturn($this->exchange);
        $this->factory->method('createQueue')->with($this->channel)->willReturn($this->queue);

        $this->exchange->method('getName')->willReturn('test_exchange');
        $this->exchange->expects($this->once())->method('bind')->with('target_exchange', 'key.1');

        $options = [
            'exchange' => 'test_exchange',
            'queue' => 'test_queue',
            'exchange_bindings' => [
                [
                    'target' => 'target_exchange',
                    'routing_keys' => ['key.1'],
                ],
            ],
        ];

        $setup = new InfrastructureSetup($this->factory, $this->connection, $options);
        $setup->setup();
    }
}
