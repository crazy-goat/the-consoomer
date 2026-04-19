<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Psr\Log\LoggerInterface;

/**
 * AMQP connection wrapper with heartbeat tracking and channel lifecycle management.
 *
 * Provides connection management, heartbeat monitoring for stale connection detection,
 * and automatic channel recreation when connection is lost.
 */
final class Connection implements ConnectionInterface
{
    private int $heartbeat = 0;
    private int $lastActivityTime;
    private ?LoggerInterface $logger = null;
    private ?\AMQPChannel $channel = null;

    /**
     * @param AmqpFactoryInterface $factory      Factory for creating AMQP channels
     * @param \AMQPConnection      $amqpConnection Underlying AMQP connection
     */
    public function __construct(
        private readonly AmqpFactoryInterface $factory,
        private readonly \AMQPConnection $amqpConnection,
    ) {
        $this->lastActivityTime = time();
    }

    /**
     * {@inheritdoc}
     *
     * @param int $seconds Heartbeat interval in seconds
     */
    public function setHeartbeat(int $seconds): void
    {
        $this->heartbeat = $seconds;
        $this->logger?->debug('Heartbeat set to {heartbeat} seconds (client-side tracking)', ['heartbeat' => $seconds]);
    }

    /**
     * {@inheritdoc}
     *
     * @param LoggerInterface $logger Logger instance
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     *
     * @return \AMQPChannel
     * @throws \AMQPConnectionException When connection fails
     */
    public function getChannel(): \AMQPChannel
    {
        if (!$this->channel instanceof \AMQPChannel || !$this->channel->isConnected()) {
            $this->channel = $this->factory->createChannel($this->amqpConnection);
        }

        return $this->channel;
    }

    /**
     * {@inheritdoc}
     */
    public function clearChannelCache(): void
    {
        $this->channel = null;
        $this->logger?->debug('Channel cache cleared');
    }

    /**
     * {@inheritdoc}
     *
     * @return \AMQPConnection
     */
    public function getConnection(): \AMQPConnection
    {
        return $this->amqpConnection;
    }

    /**
     * {@inheritdoc}
     *
     * @return bool True if heartbeat timeout detected (connection needs reconnection)
     */
    public function checkHeartbeat(): bool
    {
        if ($this->heartbeat === 0) {
            return false;
        }

        $now = time();
        $elapsed = $now - $this->lastActivityTime;
        $threshold = 2 * $this->heartbeat;

        $this->logger?->debug('Checking heartbeat, last activity: {lastActivity}, elapsed: {elapsed}, threshold: {threshold}', [
            'lastActivity' => $this->lastActivityTime,
            'elapsed' => $elapsed,
            'threshold' => $threshold,
        ]);

        return $elapsed > $threshold;
    }

    /**
     * {@inheritdoc}
     *
     * @throws \AMQPConnectionException When reconnection fails
     */
    public function reconnect(): void
    {
        $this->logger?->info('Connection stale, reconnecting...');

        try {
            if ($this->amqpConnection->isConnected()) {
                $this->amqpConnection->reconnect();
            } else {
                $this->amqpConnection->connect();
            }
            $this->lastActivityTime = time();
            $this->logger?->debug('Reconnected successfully');
        } catch (\AMQPConnectionException $e) {
            $this->logger?->error('Reconnect failed: {error}', ['error' => $e->getMessage()]);
            throw $e;
        }

        // Clear channel cache only after successful reconnect
        $this->channel = null;
    }

    /**
     * {@inheritdoc}
     */
    public function updateActivity(): void
    {
        $this->lastActivityTime = time();
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->amqpConnection->isConnected();
    }
}
