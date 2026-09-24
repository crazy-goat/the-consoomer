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
     * Checks whether the wall-clock staleness window has elapsed since the last
     * application activity.
     *
     * This is not a liveness probe — the broker-negotiated heartbeat on the
     * native connection detects a dead socket. Only use it to decide whether to
     * renew the channel before an idempotent read; never inside `ack()`/
     * `reject()`, where a false positive redelivers in-flight messages (#235).
     *
     * @return bool True if the staleness window has elapsed
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
     *
     * Callers should bump this at the start and end of long operations so the
     * staleness window is measured from real interactions, not handler time
     * (#235).
     */
    public function updateActivity(): void;

    /**
     * Checks if connection is active.
     */
    public function isConnected(): bool;

    /**
     * Ensures the underlying connection is established, connecting lazily if needed.
     *
     * The first call that needs the broker (getChannel, etc.) will establish
     * the connection, wrapped in retry when configured. See #230.
     *
     * @throws \AMQPConnectionException When connection fails
     */
    public function ensureConnected(): void;

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

    /**
     * Closes the AMQP connection and releases all resources.
     *
     * Idempotent - safe to call multiple times.
     */
    public function close(): void;
}
