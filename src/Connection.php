<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Psr\Log\LoggerInterface;

class Connection
{
    private int $heartbeat = 0;
    private int $lastActivityTime = 0;
    private ?LoggerInterface $logger = null;

    public function __construct(
        private readonly AmqpFactoryInterface $factory,
        private \AMQPConnection $amqpConnection,
    ) {
    }

    public function setHeartbeat(int $seconds): void
    {
        $this->heartbeat = $seconds;
        $this->logger?->debug('Heartbeat set to {heartbeat} seconds (client-side tracking)', ['heartbeat' => $seconds]);
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function getChannel(): \AMQPChannel
    {
        return $this->factory->createChannel($this->amqpConnection);
    }

    public function getConnection(): \AMQPConnection
    {
        return $this->amqpConnection;
    }

    public function checkHeartbeat(): bool
    {
        if ($this->heartbeat === 0) {
            return false;
        }

        $now = time();
        $elapsed = $now - $this->lastActivityTime;
        $threshold = 2 * $this->heartbeat;

        $this->logger?->info('Checking heartbeat, last activity: {lastActivity}, elapsed: {elapsed}, threshold: {threshold}', [
            'lastActivity' => $this->lastActivityTime,
            'elapsed' => $elapsed,
            'threshold' => $threshold,
        ]);

        return $elapsed > $threshold;
    }

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
        } catch (\Exception $e) {
            $this->logger?->error('Reconnect failed: {error}', ['error' => $e->getMessage()]);
            throw new \AMQPConnectionException('Reconnect failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function updateActivity(): void
    {
        $this->lastActivityTime = time();
    }

    public function isConnected(): bool
    {
        return $this->amqpConnection->isConnected();
    }
}