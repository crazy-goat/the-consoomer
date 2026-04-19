<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

/**
 * Interface for AMQP infrastructure setup.
 * Handles declaration of exchanges, queues, and bindings.
 */
interface InfrastructureSetupInterface
{
    /**
     * Sets up AMQP infrastructure (exchange, queue, binding).
     *
     * Idempotent - safe to call multiple times.
     *
     * @throws \InvalidArgumentException When exchange or queue is not configured
     * @throws \AMQPException When AMQP declaration fails
     */
    public function setup(): void;
}
