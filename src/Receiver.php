<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use CrazyGoat\TheConsoomer\Exception\MissingStampException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class Receiver implements ReceiverInterface, MessageCountAwareInterface
{
    public const DEFAULT_MAX_UNACKED_MESSAGES = 100;
    public const DEFAULT_BATCH_SIZE = 1;
    /** @var array<string, int> */
    private array $unacked = [];
    /** @var array<string, ?\AMQPEnvelope> */
    private array $lastUnacked = [];
    /** @var array<Envelope> */
    private array $messages = [];
    /** @var array<string, \AMQPQueue> */
    private array $queues = [];

    /**
     * @param array{
     *     queue?: string,
     *     queues?: array<string, array{binding_keys?: list<string>}>,
     *     exchange?: string,
     *     max_unacked_messages?: int,
     *     batch_size?: int,
     *     auto_setup?: bool,
     *     retry?: bool,
     *     retry_exchange?: string,
     *     routing_key?: string,
     * } $options
     */
    public function __construct(
        private readonly AmqpFactoryInterface $factory,
        private readonly ConnectionInterface $connection,
        private readonly SerializerInterface $serializer,
        private readonly array $options,
        private readonly InfrastructureSetupInterface $setup,
        private readonly ?ConnectionRetryInterface $retry = null,
    ) {
        $this->maxUnackedMessages = max(1, intval($this->options['max_unacked_messages'] ?? self::DEFAULT_MAX_UNACKED_MESSAGES));
        $this->batchSize = max(1, intval($this->options['batch_size'] ?? self::DEFAULT_BATCH_SIZE));
    }

    private int $maxUnackedMessages = self::DEFAULT_MAX_UNACKED_MESSAGES;
    private int $batchSize = self::DEFAULT_BATCH_SIZE;

    /**
     * @return list<string>
     */
    private function getQueueNames(): array
    {
        if (isset($this->options['queues']) && $this->options['queues'] !== []) {
            return array_keys($this->options['queues']);
        }

        $queue = $this->options['queue'] ?? '';
        if ($queue !== '') {
            return [$queue];
        }

        return [];
    }

    private function ensureConnected(): void
    {
        if ($this->connection->checkHeartbeat()) {
            $this->connection->reconnect();
            $this->setup->resetSetup();
            $this->queues = [];
            $this->unacked = [];
            $this->lastUnacked = [];
        }
    }

    private function connect(): void
    {
        if ($this->queues !== []) {
            return;
        }

        $channel = $this->connection->getChannel();
        $channel->qos(0, $this->maxUnackedMessages);

        foreach ($this->getQueueNames() as $queueName) {
            $queue = $this->factory->createQueue($channel);
            $queue->setName($queueName);
            $queue->consume(null, AMQP_NOPARAM);
            $this->queues[$queueName] = $queue;
        }
    }

    public function get(): iterable
    {
        $this->messages = [];
        $this->ensureConnected();
        if ($this->options['auto_setup'] ?? true) {
            $this->setup->setup();
        }
        $this->connect();

        foreach ($this->queues as $queueName => $queue) {
            $callback = function (\AMQPEnvelope $message) use ($queueName): bool {
                $envelope = $this->serializer->decode(['body' => $message->getBody()]);
                $this->messages[] = $envelope->with(new AmqpReceivedStamp($message, $queueName));

                return count($this->messages) < $this->batchSize;
            };

            try {
                $queue->consume($callback, AMQP_JUST_CONSUME, $queue->getConsumerTag());
            } catch (\AMQPException) {
                $this->connection->clearChannelCache();
                $this->queues = [];
                $this->unacked = [];
                $this->lastUnacked = [];

                if ($this->messages !== []) {
                    break;
                }
            }
        }

        $this->connection->updateActivity();

        return $this->messages;
    }

    public function ack(Envelope $envelope): void
    {
        $this->ensureConnected();

        $stamp = $envelope->last(AmqpReceivedStamp::class);
        if (!$stamp instanceof AmqpReceivedStamp) {
            throw new MissingStampException('No AMQP received stamp');
        }

        $operation = function () use ($stamp): void {
            $this->ackMessage($stamp->getAmqpEnvelope(), $stamp->getQueueName());
            $this->connection->updateActivity();
        };

        if ($this->retry instanceof ConnectionRetryInterface) {
            $this->retry->withRetry($operation);
        } else {
            $operation();
        }
    }

    public function reject(Envelope $envelope): void
    {
        $this->ensureConnected();

        $stamp = $envelope->last(AmqpReceivedStamp::class);
        if (!$stamp instanceof AmqpReceivedStamp) {
            throw new MissingStampException('No AMQP received stamp');
        }

        $operation = function () use ($stamp): void {
            $this->rejectMessage($stamp);
            $this->connection->updateActivity();
        };

        if ($this->retry instanceof ConnectionRetryInterface) {
            $this->retry->withRetry($operation);
        } else {
            $operation();
        }
    }

    private function rejectMessage(AmqpReceivedStamp $stamp): void
    {
        $queueName = $stamp->getQueueName();
        if (!isset($this->queues[$queueName])) {
            throw new \InvalidArgumentException(sprintf('Unknown queue "%s" in received message', $queueName));
        }

        $this->ackPending($queueName);

        $amqpStamp = $stamp->getAmqpStamp();
        if ($amqpStamp && $amqpStamp->isRetryAttempt()) {
            $this->publishToRetryQueue($stamp);
        } else {
            $this->queues[$queueName]->reject($stamp->getAmqpEnvelope()->getDeliveryTag());
        }
    }

    private function publishToRetryQueue(AmqpReceivedStamp $stamp): void
    {
        $retryExchangeName = $this->options['retry_exchange'] ?? $this->options['exchange'] . '_retry';
        $routingKey = $this->getRoutingKeyForRetry($stamp->getAmqpStamp()?->getRoutingKey());

        $retryExchange = $this->factory->createExchange($this->connection->getChannel());
        $retryExchange->setName($retryExchangeName);
        $retryExchange->publish(
            $stamp->getAmqpEnvelope()->getBody(),
            $routingKey,
            \AMQP_NOPARAM,
            $stamp->getAmqpEnvelope()->getHeaders(),
        );
    }

    private function getRoutingKeyForRetry(?string $routingKey): string
    {
        $baseKey = $routingKey ?? $this->options['routing_key'] ?? '';

        return $baseKey . '_retry';
    }

    public function ackPending(?string $queueName = null): void
    {
        if ($queueName !== null) {
            $this->ackPendingForQueue($queueName);
        } else {
            foreach (array_keys($this->unacked) as $name) {
                $this->ackPendingForQueue($name);
            }
        }
    }

    private function ackPendingForQueue(string $queueName): void
    {
        if (isset($this->lastUnacked[$queueName])) {
            $this->queues[$queueName]->ack($this->lastUnacked[$queueName]->getDeliveryTag(), AMQP_MULTIPLE);
        }
        $this->lastUnacked[$queueName] = null;
        $this->unacked[$queueName] = 0;
    }

    private function ackMessage(\AMQPEnvelope $message, string $queueName): void
    {
        $this->lastUnacked[$queueName] = $message;
        $this->unacked[$queueName] = ($this->unacked[$queueName] ?? 0) + 1;

        if (($this->unacked[$queueName] ?? 0) >= $this->maxUnackedMessages) {
            $this->ackPending($queueName);
        }
    }

    public function close(): void
    {
        $this->ackPending();
    }

    public function purgeQueue(?string $queueName = null): int
    {
        $this->ensureConnected();
        if ($this->options['auto_setup'] ?? true) {
            $this->setup->setup();
        }

        $queueName ??= $this->options['queue'] ?? '';
        if ($queueName === '' && !isset($this->options['queues'])) {
            throw new \InvalidArgumentException('Queue name must be provided either as argument or in receiver options.');
        }

        if ($queueName === '' && isset($this->options['queues'])) {
            $queueName = $this->getQueueNames()[0] ?? '';
            if ($queueName === '') {
                throw new \InvalidArgumentException('No queues configured for purge.');
            }
        }

        $channel = $this->connection->getChannel();
        $purgeQueue = $this->factory->createQueue($channel);
        $purgeQueue->setName($queueName);

        $purgeOperation = fn(): int => $purgeQueue->purge();

        $result = $this->retry instanceof ConnectionRetryInterface
            ? $this->retry->withRetry($purgeOperation)
            : $purgeOperation();

        $this->connection->updateActivity();

        return $result;
    }

    public function getMessageCount(): int
    {
        $this->ensureConnected();
        if ($this->options['auto_setup'] ?? true) {
            $this->setup->setup();
        }

        $channel = $this->connection->getChannel();
        $total = 0;

        foreach ($this->getQueueNames() as $queueName) {
            $queue = $this->factory->createQueue($channel);
            $queue->setName($queueName);

            $getCount = function () use ($queue): int {
                $flags = $queue->getFlags();
                $queue->setFlags($flags | \AMQP_PASSIVE);

                try {
                    return $queue->declareQueue();
                } finally {
                    $queue->setFlags($flags);
                }
            };

            $total += $this->retry instanceof ConnectionRetryInterface
                ? $this->retry->withRetry($getCount)
                : $getCount();
        }

        $this->connection->updateActivity();

        return $total;
    }
}
