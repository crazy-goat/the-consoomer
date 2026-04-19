<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

interface ConnectionInterface
{
    public function getChannel(): \AMQPChannel;

    public function getConnection(): \AMQPConnection;

    public function checkHeartbeat(): bool;

    public function reconnect(): void;

    public function updateActivity(): void;

    public function isConnected(): bool;

    public function setHeartbeat(int $seconds): void;

    public function setLogger(\Psr\Log\LoggerInterface $logger): void;

    public function clearChannelCache(): void;
}
