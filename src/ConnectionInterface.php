<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Psr\Log\LoggerInterface;

/**
 * Interface for AMQP connection wrapper.
 * Provides connection management, heartbeat tracking, and channel lifecycle.
 */
interface ConnectionInterface
{
    /**
     * Returns the AMQP channel, creating it if necessary.
     *
     * @throws \AMQPConnectionException When connection fails
     */
    public function getChannel(): \AMQPChannel;

    /**
     * Returns the underlying AMQP connection.
     */
    public function getConnection(): \AMQPConnection;

    /**
     * Checks if heartbeat timeout indicates stale connection.
     *
     * @return bool True if reconnection is needed
     */
    public function checkHeartbeat(): bool;

    /**
     * Reconnects to the AMQP broker.
     *
     * @throws \AMQPConnectionException When reconnection fails
     */
    public function reconnect(): void;

    /**
     * Updates the last activity timestamp.
     */
    public function updateActivity(): void;

    /**
     * Checks if connection is active.
     */
    public function isConnected(): bool;

    /**
     * Sets the heartbeat interval.
     *
     * @param int $seconds Heartbeat interval in seconds
     */
    public function setHeartbeat(int $seconds): void;

    /**
     * Sets the logger instance.
     *
     * @param LoggerInterface $logger Logger instance
     */
    public function setLogger(LoggerInterface $logger): void;

    /**
     * Clears the channel cache.
     */
    public function clearChannelCache(): void;
}
