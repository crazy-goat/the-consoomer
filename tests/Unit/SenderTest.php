<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\AmqpDelayStamp;
use CrazyGoat\TheConsoomer\AmqpFactory;
use CrazyGoat\TheConsoomer\AmqpPriorityStamp;
use CrazyGoat\TheConsoomer\AmqpStamp;
use CrazyGoat\TheConsoomer\ConnectionInterface;
use CrazyGoat\TheConsoomer\ConnectionRetryInterface;
use CrazyGoat\TheConsoomer\InfrastructureSetupInterface;
use CrazyGoat\TheConsoomer\Sender;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class SenderTest extends TestCase
{
    private AmqpFactory&MockObject $factory;
    private ConnectionInterface&MockObject $connection;
    private SerializerInterface&MockObject $serializer;
    private \AMQPExchange&MockObject $exchange;
    private InfrastructureSetupInterface&MockObject $setup;

    protected function setUp(): void
    {
        $this->factory = $this->createMock(AmqpFactory::class);
        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->exchange = $this->createMock(\AMQPExchange::class);
        $this->setup = $this->createMock(InfrastructureSetupInterface::class);
    }

    public function testSendPublishesToExchange(): void
    {
        $options = ['exchange' => 'test_exchange'];

        $envelope = new Envelope(new \stdClass());

        $this->serializer
            ->expects($this->once())
            ->method('encode')
            ->with($envelope)
            ->willReturn([
                'body' => '{"message":"test"}',
                'headers' => ['content-type' => 'application/json'],
            ]);

        $this->exchange
            ->expects($this->once())
            ->method('publish')
            ->with(
                '{"message":"test"}',
                '',
                \AMQP_NOPARAM,
                ['content-type' => 'application/json'],
            );

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $sender = $this->createSender($options);
        $result = $sender->send($envelope);

        $this->assertSame($envelope, $result);
    }

    public function testSendUsesRoutingKeyFromStamp(): void
    {
        $options = ['exchange' => 'test_exchange'];
        $routingKey = 'my.routing.key';
        $stamp = new AmqpStamp($routingKey);

        $envelope = new Envelope(new \stdClass(), [$stamp]);

        $this->serializer
            ->expects($this->once())
            ->method('encode')
            ->with($envelope)
            ->willReturn([
                'body' => '{"message":"test"}',
                'headers' => [],
            ]);

        $this->exchange
            ->expects($this->once())
            ->method('publish')
            ->with(
                '{"message":"test"}',
                $routingKey,
                \AMQP_NOPARAM,
                [],
            );

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $sender = $this->createSender($options);
        $result = $sender->send($envelope);

        $this->assertSame($envelope, $result);
    }

    /**
     * @dataProvider routingKeyPrecedenceProvider
     */
    public function testSendRoutingKeyPrecedence(
        array $options,
        ?string $stampRoutingKey,
        string $expectedRoutingKey,
    ): void {
        $envelope = $stampRoutingKey !== null
            ? new Envelope(new \stdClass(), [new AmqpStamp($stampRoutingKey)])
            : new Envelope(new \stdClass());

        $this->serializer
            ->expects($this->once())
            ->method('encode')
            ->willReturn([
                'body' => '{"message":"test"}',
                'headers' => [],
            ]);

        $this->exchange
            ->expects($this->once())
            ->method('publish')
            ->with(
                '{"message":"test"}',
                $expectedRoutingKey,
                \AMQP_NOPARAM,
                [],
            );

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $sender = $this->createSender($options);
        $sender->send($envelope);
    }

    /**
     * @return array<string, array{options: array<string, string>, stampRoutingKey: string|null, expectedRoutingKey: string}>
     */
    public static function routingKeyPrecedenceProvider(): array
    {
        return [
            'stamp routing key takes precedence over default_publish_routing_key' => [
                'options' => [
                    'exchange' => 'test_exchange',
                    'default_publish_routing_key' => 'default.routing.key',
                ],
                'stampRoutingKey' => 'stamp.routing.key',
                'expectedRoutingKey' => 'stamp.routing.key',
            ],
            'default_publish_routing_key used when no stamp' => [
                'options' => [
                    'exchange' => 'test_exchange',
                    'default_publish_routing_key' => 'default.routing.key',
                ],
                'stampRoutingKey' => null,
                'expectedRoutingKey' => 'default.routing.key',
            ],
            'empty stamp routing key takes precedence over default_publish_routing_key' => [
                'options' => [
                    'exchange' => 'test_exchange',
                    'default_publish_routing_key' => 'default.routing.key',
                ],
                'stampRoutingKey' => '',
                'expectedRoutingKey' => '',
            ],
            'routing_key is ignored by Sender (used by Receiver only)' => [
                'options' => [
                    'exchange' => 'test_exchange',
                    'routing_key' => 'options.routing.key',
                ],
                'stampRoutingKey' => null,
                'expectedRoutingKey' => '',
            ],
            'routing_key does not affect Sender when both keys are set' => [
                'options' => [
                    'exchange' => 'test_exchange',
                    'routing_key' => 'options.routing.key',
                    'default_publish_routing_key' => 'default.routing.key',
                ],
                'stampRoutingKey' => null,
                'expectedRoutingKey' => 'default.routing.key',
            ],
        ];
    }

    public function testSendUsesEmptyRoutingKeyWhenNotProvided(): void
    {
        $options = ['exchange' => 'test_exchange'];

        $envelope = new Envelope(new \stdClass());

        $this->serializer
            ->method('encode')
            ->willReturn([
                'body' => '{"message":"test"}',
                'headers' => [],
            ]);

        $this->exchange
            ->expects($this->once())
            ->method('publish')
            ->with(
                '{"message":"test"}',
                '',
                \AMQP_NOPARAM,
                [],
            );

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $sender = $this->createSender($options);
        $sender->send($envelope);
    }

    public function testConnectIsLazyAndExchangeIsReused(): void
    {
        $options = ['exchange' => 'test_exchange'];

        $this->serializer
            ->method('encode')
            ->willReturn(['body' => 'test', 'headers' => []]);

        $this->exchange
            ->expects($this->exactly(2))
            ->method('publish');

        $this->connection
            ->expects($this->exactly(2))
            ->method('checkHeartbeat')
            ->willReturn(false);

        $sender = $this->createSender($options);

        $envelope1 = new Envelope(new \stdClass());
        $envelope2 = new Envelope(new \stdClass());

        $sender->send($envelope1);
        $sender->send($envelope2);
    }

    public function testSendUsesFactoryToCreateChannelAndExchange(): void
    {
        $options = ['exchange' => 'test_exchange'];

        $channel = $this->createMock(\AMQPChannel::class);

        $this->connection
            ->expects($this->once())
            ->method('getChannel')
            ->willReturn($channel);

        $this->factory
            ->expects($this->once())
            ->method('createExchange')
            ->with($channel)
            ->willReturn($this->exchange);

        $this->exchange
            ->expects($this->once())
            ->method('setName')
            ->with('test_exchange');

        $this->serializer
            ->method('encode')
            ->willReturn(['body' => 'test', 'headers' => []]);

        $this->exchange
            ->expects($this->once())
            ->method('publish');

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $sender = new Sender($this->factory, $this->connection, $this->serializer, $options, $this->setup);
        $sender->send(new Envelope(new \stdClass()));
    }

    public function testSendCallsSetupFirst(): void
    {
        $setup = $this->createMock(InfrastructureSetupInterface::class);
        $setup->expects($this->once())->method('setup');

        $channel = $this->createMock(\AMQPChannel::class);

        $this->connection
            ->method('getChannel')
            ->willReturn($channel);

        $this->factory
            ->method('createExchange')
            ->willReturn($this->exchange);

        $options = ['exchange' => 'test_exchange', 'routing_key' => 'test_key'];

        $sender = new Sender($this->factory, $this->connection, $this->serializer, $options, $setup);

        $envelope = new Envelope(new \stdClass());
        $this->serializer->method('encode')->willReturn(['body' => '{}', 'headers' => []]);
        $this->exchange->method('publish');

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $sender->send($envelope);
    }

    public function testSendUsesFlagsFromStamp(): void
    {
        $options = ['exchange' => 'test_exchange'];
        $stamp = new AmqpStamp('key', \AMQP_MANDATORY);

        $envelope = new Envelope(new \stdClass(), [$stamp]);

        $this->serializer
            ->method('encode')
            ->willReturn(['body' => 'test', 'headers' => []]);

        $this->exchange
            ->expects($this->once())
            ->method('publish')
            ->with(
                'test',
                'key',
                \AMQP_MANDATORY,
                [],
            );

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $sender = $this->createSender($options);
        $sender->send($envelope);
    }

    public function testSendUsesAttributesFromStamp(): void
    {
        $options = ['exchange' => 'test_exchange'];
        $stamp = new AmqpStamp('key', \AMQP_NOPARAM, ['priority' => 10, 'message_id' => 'abc']);

        $envelope = new Envelope(new \stdClass(), [$stamp]);

        $this->serializer
            ->method('encode')
            ->willReturn(['body' => 'test', 'headers' => []]);

        $this->exchange
            ->expects($this->once())
            ->method('publish')
            ->with(
                'test',
                'key',
                \AMQP_NOPARAM,
                ['priority' => 10, 'message_id' => 'abc'],
            );

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $sender = $this->createSender($options);
        $sender->send($envelope);
    }

    public function testSendMergesAttributesWithHeaders(): void
    {
        $options = ['exchange' => 'test_exchange'];
        $stamp = new AmqpStamp('key', \AMQP_NOPARAM, ['priority' => 10]);

        $envelope = new Envelope(new \stdClass(), [$stamp]);

        $this->serializer
            ->method('encode')
            ->willReturn([
                'body' => 'test',
                'headers' => ['content-type' => 'application/json', 'x-custom' => 'value'],
            ]);

        $this->exchange
            ->expects($this->once())
            ->method('publish')
            ->with(
                'test',
                'key',
                \AMQP_NOPARAM,
                ['content-type' => 'application/json', 'x-custom' => 'value', 'priority' => 10],
            );

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $sender = $this->createSender($options);
        $sender->send($envelope);
    }

    public function testSendStampAttributesOverrideHeaders(): void
    {
        $options = ['exchange' => 'test_exchange'];
        $stamp = new AmqpStamp('key', \AMQP_NOPARAM, ['content-type' => 'text/plain']);

        $envelope = new Envelope(new \stdClass(), [$stamp]);

        $this->serializer
            ->method('encode')
            ->willReturn([
                'body' => 'test',
                'headers' => ['content-type' => 'application/json'],
            ]);

        $this->exchange
            ->expects($this->once())
            ->method('publish')
            ->with(
                'test',
                'key',
                \AMQP_NOPARAM,
                ['content-type' => 'text/plain'],
            );

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $sender = $this->createSender($options);
        $sender->send($envelope);
    }

    public function testSendWithConfirmTimeoutEnablesConfirms(): void
    {
        $options = ['exchange' => 'test_exchange', 'confirm_timeout' => 5];

        $channel = $this->createMock(\AMQPChannel::class);
        $channel->expects($this->once())->method('confirmSelect');
        $channel->expects($this->once())->method('waitForConfirm')->with(5.0);

        $this->connection
            ->expects($this->once())
            ->method('getChannel')
            ->willReturn($channel);

        $envelope = new Envelope(new \stdClass());

        $this->serializer
            ->method('encode')
            ->willReturn(['body' => 'test', 'headers' => []]);

        $this->exchange
            ->expects($this->once())
            ->method('publish');

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $this->connection
            ->expects($this->once())
            ->method('updateActivity');

        $sender = $this->createSender($options);
        $sender->send($envelope);
    }

    public function testSendWithoutConfirmTimeoutDoesNotEnableConfirms(): void
    {
        $options = ['exchange' => 'test_exchange'];

        $channel = $this->createMock(\AMQPChannel::class);
        $channel->expects($this->never())->method('confirmSelect');
        $channel->expects($this->never())->method('waitForConfirm');

        $this->connection
            ->expects($this->never())
            ->method('getChannel');

        $envelope = new Envelope(new \stdClass());

        $this->serializer
            ->method('encode')
            ->willReturn(['body' => 'test', 'headers' => []]);

        $this->exchange
            ->expects($this->once())
            ->method('publish');

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $this->connection
            ->expects($this->once())
            ->method('updateActivity');

        $sender = $this->createSender($options);
        $sender->send($envelope);
    }

    public function testSendWithZeroConfirmTimeoutDoesNotEnableConfirms(): void
    {
        $options = ['exchange' => 'test_exchange', 'confirm_timeout' => 0];

        $channel = $this->createMock(\AMQPChannel::class);
        $channel->expects($this->never())->method('confirmSelect');
        $channel->expects($this->never())->method('waitForConfirm');

        $this->connection
            ->expects($this->never())
            ->method('getChannel');

        $envelope = new Envelope(new \stdClass());

        $this->serializer
            ->method('encode')
            ->willReturn(['body' => 'test', 'headers' => []]);

        $this->exchange
            ->expects($this->once())
            ->method('publish');

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $this->connection
            ->expects($this->once())
            ->method('updateActivity');

        $sender = $this->createSender($options);
        $sender->send($envelope);
    }

    public function testSendWithConfirmTimeoutAndRetryCallsConfirmEachAttempt(): void
    {
        $options = ['exchange' => 'test_exchange', 'confirm_timeout' => 5, 'retry' => true];

        $channel = $this->createMock(\AMQPChannel::class);
        $channel->expects($this->exactly(2))->method('confirmSelect');
        $channel->expects($this->exactly(2))->method('waitForConfirm')->with(5.0);

        $this->connection
            ->method('getChannel')
            ->willReturn($channel);

        $this->connection
            ->method('checkHeartbeat')
            ->willReturn(false);

        $this->connection
            ->expects($this->exactly(2))
            ->method('updateActivity');

        $envelope = new Envelope(new \stdClass());

        $retry = $this->createMock(ConnectionRetryInterface::class);
        $retry
            ->method('withRetry')
            ->willReturnCallback(function (callable $callback): void {
                $callback();
                $callback();
            });

        $this->serializer
            ->method('encode')
            ->willReturn(['body' => 'test', 'headers' => []]);

        $this->exchange
            ->expects($this->exactly(2))
            ->method('publish');

        $sender = $this->createSenderWithRetry($options, $retry);
        $sender->send($envelope);
    }

    public function testSendWithNegativeConfirmTimeoutThrowsException(): void
    {
        $options = ['exchange' => 'test_exchange', 'confirm_timeout' => -1];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('confirm_timeout must be a non-negative value');

        new Sender($this->factory, $this->connection, $this->serializer, $options, $this->setup);
    }

    public function testSendWithNegativeFloatConfirmTimeoutThrowsException(): void
    {
        $options = ['exchange' => 'test_exchange', 'confirm_timeout' => -0.5];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('confirm_timeout must be a non-negative value');

        new Sender($this->factory, $this->connection, $this->serializer, $options, $this->setup);
    }

    public function testSendWithPriorityStampAddsPriorityToAttributes(): void
    {
        $options = ['exchange' => 'test_exchange'];
        $priorityStamp = new AmqpPriorityStamp(7);
        $envelope = new Envelope(new \stdClass(), [$priorityStamp]);

        $this->serializer
            ->method('encode')
            ->willReturn(['body' => 'test', 'headers' => []]);

        $this->exchange
            ->expects($this->once())
            ->method('publish')
            ->with(
                'test',
                '',
                \AMQP_NOPARAM,
                ['priority' => 7],
            );

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $sender = $this->createSender($options);
        $sender->send($envelope);
    }

    public function testSendWithPriorityStampOverridesAmqpStampPriority(): void
    {
        $options = ['exchange' => 'test_exchange'];
        $amqpStamp = new AmqpStamp('key', \AMQP_NOPARAM, ['priority' => 2]);
        $priorityStamp = new AmqpPriorityStamp(8);
        $envelope = new Envelope(new \stdClass(), [$amqpStamp, $priorityStamp]);

        $this->serializer
            ->method('encode')
            ->willReturn(['body' => 'test', 'headers' => []]);

        $this->exchange
            ->expects($this->once())
            ->method('publish')
            ->with(
                'test',
                'key',
                \AMQP_NOPARAM,
                ['priority' => 8],
            );

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $sender = $this->createSender($options);
        $sender->send($envelope);
    }

    public function testSendWithoutPriorityStampDoesNotAddPriority(): void
    {
        $options = ['exchange' => 'test_exchange'];
        $envelope = new Envelope(new \stdClass());

        $this->serializer
            ->method('encode')
            ->willReturn(['body' => 'test', 'headers' => []]);

        $this->exchange
            ->expects($this->once())
            ->method('publish')
            ->with(
                'test',
                '',
                \AMQP_NOPARAM,
                [],
            );

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $sender = $this->createSender($options);
        $sender->send($envelope);
    }

    public function testSendWithDelayStampUsesDelayExchange(): void
    {
        $options = ['exchange' => 'test_exchange'];
        $delayMs = 5000;
        $delayStamp = new AmqpDelayStamp($delayMs);
        $envelope = new Envelope(new \stdClass(), [$delayStamp]);

        $delayExchange = $this->createMock(\AMQPExchange::class);
        $channel = $this->createMock(\AMQPChannel::class);

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $this->connection
            ->method('getChannel')
            ->willReturn($channel);

        $this->factory
            ->expects($this->once())
            ->method('createExchange')
            ->with($channel)
            ->willReturn($delayExchange);

        $delayExchange
            ->expects($this->once())
            ->method('setName')
            ->with('test_exchange_delay');

        $delayExchange
            ->expects($this->once())
            ->method('setType')
            ->with(\AMQP_EX_TYPE_DIRECT);

        $delayExchange
            ->expects($this->once())
            ->method('setFlags')
            ->with(\AMQP_DURABLE);

        $delayExchange
            ->expects($this->once())
            ->method('declareExchange');

        $queue = $this->createMock(\AMQPQueue::class);
        $queue->expects($this->once())->method('setName')->with('delay_5000_');
        $queue->expects($this->once())->method('setFlags')->with(\AMQP_DURABLE);
        $queue->expects($this->exactly(3))->method('setArgument')->willReturnCallback(function (string $key, mixed $value) use ($delayMs): void {
            static $call = 0;
            ++$call;
            if ($call === 1) {
                $this->assertSame('x-message-ttl', $key);
                $this->assertSame($delayMs, $value);
            } elseif ($call === 2) {
                $this->assertSame('x-dead-letter-exchange', $key);
                $this->assertSame('test_exchange', $value);
            } elseif ($call === 3) {
                $this->assertSame('x-dead-letter-routing-key', $key);
                $this->assertSame('', $value);
            }
        });
        $queue->expects($this->once())->method('declareQueue');
        $queue->expects($this->once())->method('bind')->with('test_exchange_delay', 'delay_5000_');

        $this->factory
            ->expects($this->once())
            ->method('createQueue')
            ->with($channel)
            ->willReturn($queue);

        $delayExchange
            ->expects($this->once())
            ->method('publish')
            ->with(
                '{"message":"test"}',
                'delay_5000_',
                \AMQP_NOPARAM,
                [],
            );

        $this->serializer
            ->expects($this->once())
            ->method('encode')
            ->with($envelope)
            ->willReturn(['body' => '{"message":"test"}', 'headers' => []]);

        $this->setup
            ->expects($this->once())
            ->method('setup');

        $sender = $this->createSender($options);
        $sender->send($envelope);
    }

    public function testSendWithDelayUsesRoutingKeyFromStamp(): void
    {
        $options = ['exchange' => 'test_exchange'];
        $routingKey = 'my.routing.key';
        $delayMs = 3000;
        $delayStamp = new AmqpDelayStamp($delayMs);
        $amqpStamp = new AmqpStamp($routingKey);
        $envelope = new Envelope(new \stdClass(), [$amqpStamp, $delayStamp]);

        $delayExchange = $this->createMock(\AMQPExchange::class);
        $channel = $this->createMock(\AMQPChannel::class);

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        $this->connection
            ->method('getChannel')
            ->willReturn($channel);

        $this->factory
            ->expects($this->once())
            ->method('createExchange')
            ->with($channel)
            ->willReturn($delayExchange);

        $delayExchange
            ->expects($this->once())
            ->method('setName')
            ->with('test_exchange_delay');

        $delayExchange
            ->expects($this->once())
            ->method('setType')
            ->with(\AMQP_EX_TYPE_DIRECT);

        $delayExchange
            ->expects($this->once())
            ->method('setFlags')
            ->with(\AMQP_DURABLE);

        $delayExchange
            ->expects($this->once())
            ->method('declareExchange');

        $queue = $this->createMock(\AMQPQueue::class);
        $queue->expects($this->once())->method('setName')->with('delay_3000_my.routing.key');
        $queue->expects($this->once())->method('setFlags')->with(\AMQP_DURABLE);
        $queue->expects($this->exactly(3))->method('setArgument')->willReturnCallback(function (string $key, mixed $value) use ($delayMs, $routingKey): void {
            static $call = 0;
            ++$call;
            if ($call === 1) {
                $this->assertSame('x-message-ttl', $key);
                $this->assertSame($delayMs, $value);
            } elseif ($call === 2) {
                $this->assertSame('x-dead-letter-exchange', $key);
                $this->assertSame('test_exchange', $value);
            } elseif ($call === 3) {
                $this->assertSame('x-dead-letter-routing-key', $key);
                $this->assertSame($routingKey, $value);
            }
        });
        $queue->expects($this->once())->method('declareQueue');
        $queue->expects($this->once())->method('bind')->with('test_exchange_delay', 'delay_3000_my.routing.key');

        $this->factory
            ->expects($this->once())
            ->method('createQueue')
            ->with($channel)
            ->willReturn($queue);

        $delayExchange
            ->expects($this->once())
            ->method('publish')
            ->with(
                '{"message":"test"}',
                'delay_3000_my.routing.key',
                \AMQP_NOPARAM,
                [],
            );

        $this->serializer
            ->expects($this->once())
            ->method('encode')
            ->with($envelope)
            ->willReturn(['body' => '{"message":"test"}', 'headers' => []]);

        $this->setup
            ->expects($this->once())
            ->method('setup');

        $sender = $this->createSender($options);
        $sender->send($envelope);
    }

    public function testSendWithDelayAndConfirmTimeoutEnablesConfirms(): void
    {
        $options = ['exchange' => 'test_exchange', 'confirm_timeout' => 5];
        $delayStamp = new AmqpDelayStamp(1000);
        $envelope = new Envelope(new \stdClass(), [$delayStamp]);

        $delayExchange = $this->createMock(\AMQPExchange::class);
        $channel = $this->createMock(\AMQPChannel::class);

        $channel->expects($this->once())->method('confirmSelect');
        $channel->expects($this->once())->method('waitForConfirm')->with(5.0);

        $this->connection
            ->method('checkHeartbeat')
            ->willReturn(false);

        $this->connection
            ->method('getChannel')
            ->willReturn($channel);

        $this->factory
            ->method('createExchange')
            ->willReturn($delayExchange);

        $delayExchange
            ->method('declareExchange');

        $queue = $this->createMock(\AMQPQueue::class);
        $queue->method('declareQueue');

        $this->factory
            ->method('createQueue')
            ->willReturn($queue);

        $delayExchange
            ->expects($this->once())
            ->method('publish');

        $this->serializer
            ->method('encode')
            ->willReturn(['body' => 'test', 'headers' => []]);

        $this->setup->method('setup');

        $this->connection
            ->expects($this->once())
            ->method('updateActivity');

        $sender = $this->createSender($options);
        $sender->send($envelope);
    }

    public function testSendWithDelayQueueIsCreatedOnlyOnceForSameDelayAndRoutingKey(): void
    {
        $options = ['exchange' => 'test_exchange'];
        $delayStamp = new AmqpDelayStamp(2000);
        $envelope1 = new Envelope(new \stdClass(), [$delayStamp]);
        $envelope2 = new Envelope(new \stdClass(), [$delayStamp]);

        $delayExchange = $this->createMock(\AMQPExchange::class);
        $channel = $this->createMock(\AMQPChannel::class);

        $this->connection
            ->method('checkHeartbeat')
            ->willReturn(false);

        $this->connection
            ->method('getChannel')
            ->willReturn($channel);

        $this->factory
            ->method('createExchange')
            ->willReturn($delayExchange);

        $delayExchange
            ->method('declareExchange');

        $queue = $this->createMock(\AMQPQueue::class);
        $queue->method('declareQueue');

        // Factory should only create one queue for the same delay/routing key combo
        $this->factory
            ->expects($this->once())
            ->method('createQueue')
            ->willReturn($queue);

        $this->serializer
            ->method('encode')
            ->willReturn(['body' => 'test', 'headers' => []]);

        $delayExchange
            ->expects($this->exactly(2))
            ->method('publish');

        $this->setup->method('setup');

        $sender = $this->createSender($options);
        $sender->send($envelope1);
        $sender->send($envelope2);
    }

    public function testSendWithoutDelayStampDoesNotCreateDelayExchange(): void
    {
        $options = ['exchange' => 'test_exchange'];
        $envelope = new Envelope(new \stdClass());

        $this->serializer
            ->method('encode')
            ->willReturn(['body' => 'test', 'headers' => []]);

        $this->exchange
            ->expects($this->once())
            ->method('publish');

        $this->connection
            ->expects($this->once())
            ->method('checkHeartbeat')
            ->willReturn(false);

        // Factory should only create the main exchange, not the delay exchange
        $this->factory
            ->expects($this->never())
            ->method('createQueue');

        $sender = $this->createSender($options);
        $sender->send($envelope);
    }

    public function testSendWithCustomDelayExchangeName(): void
    {
        $options = [
            'exchange' => 'test_exchange',
            'delay' => ['exchange_name' => 'custom_delay_exchange'],
        ];
        $delayStamp = new AmqpDelayStamp(1000);
        $envelope = new Envelope(new \stdClass(), [$delayStamp]);

        $delayExchange = $this->createMock(\AMQPExchange::class);
        $channel = $this->createMock(\AMQPChannel::class);

        $this->connection
            ->method('checkHeartbeat')
            ->willReturn(false);

        $this->connection
            ->method('getChannel')
            ->willReturn($channel);

        $this->factory
            ->method('createExchange')
            ->willReturn($delayExchange);

        $delayExchange
            ->expects($this->once())
            ->method('setName')
            ->with('custom_delay_exchange');

        $delayExchange
            ->method('declareExchange');

        $this->factory
            ->method('createQueue')
            ->willReturn($this->createMock(\AMQPQueue::class));

        $this->serializer
            ->method('encode')
            ->willReturn(['body' => 'test', 'headers' => []]);

        $this->setup->method('setup');

        $sender = $this->createSender($options);
        $sender->send($envelope);
    }

    public function testSendWithCustomDelayQueueNamePattern(): void
    {
        $options = [
            'exchange' => 'test_exchange',
            'delay' => ['queue_name_pattern' => 'custom_{delay}_ms_{queue}'],
        ];
        $delayStamp = new AmqpDelayStamp(2000);
        $routingKey = 'my.routing.key';
        $amqpStamp = new AmqpStamp($routingKey);
        $envelope = new Envelope(new \stdClass(), [$amqpStamp, $delayStamp]);

        $delayExchange = $this->createMock(\AMQPExchange::class);
        $channel = $this->createMock(\AMQPChannel::class);

        $this->connection
            ->method('checkHeartbeat')
            ->willReturn(false);

        $this->connection
            ->method('getChannel')
            ->willReturn($channel);

        $this->factory
            ->method('createExchange')
            ->willReturn($delayExchange);

        $delayExchange
            ->method('declareExchange');

        $queue = $this->createMock(\AMQPQueue::class);
        $queue->expects($this->once())->method('setName')->with('custom_2000_ms_my.routing.key');

        $this->factory
            ->method('createQueue')
            ->willReturn($queue);

        $this->serializer
            ->method('encode')
            ->willReturn(['body' => 'test', 'headers' => []]);

        $delayExchange
            ->method('publish')
            ->with('test', 'custom_2000_ms_my.routing.key', \AMQP_NOPARAM, []);

        $this->setup->method('setup');

        $sender = $this->createSender($options);
        $sender->send($envelope);
    }

    private function createSender(array $options): Sender
    {
        $sender = new Sender($this->factory, $this->connection, $this->serializer, $options, $this->setup);

        $reflection = new \ReflectionClass(Sender::class);
        $exchangeProperty = $reflection->getProperty('exchange');
        $exchangeProperty->setValue($sender, $this->exchange);

        return $sender;
    }

    private function createSenderWithRetry(array $options, ConnectionRetryInterface $retry): Sender
    {
        $sender = new Sender($this->factory, $this->connection, $this->serializer, $options, $this->setup, $retry);

        $reflection = new \ReflectionClass(Sender::class);
        $exchangeProperty = $reflection->getProperty('exchange');
        $exchangeProperty->setValue($sender, $this->exchange);

        return $sender;
    }
}
