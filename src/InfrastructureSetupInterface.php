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
     * Sets up the full AMQP topology (exchange, exchange bindings, queues and
     * queue bindings).
     *
     * Idempotent - safe to call multiple times.
     *
     * @throws \InvalidArgumentException When exchange or queue is not configured
     * @throws \AMQPException When AMQP declaration fails
     */
    public function setup(): void;

    /**
     * Declares only the message exchange.
     *
     * A producer needs nothing more: queues, queue bindings and
     * exchange-to-exchange bindings are consumer-side topology. Kept separate
     * from {@see setupQueues()} so a send-only transport does not declare
     * every consumer queue on the first publish (#308). Idempotent.
     *
     * @throws \AMQPException When the exchange declaration fails
     */
    public function setupExchange(): void;

    /**
     * Declares the queues, their bindings and the exchange-to-exchange
     * bindings (declaring the exchange first if it has not been set up yet).
     *
     * Idempotent - safe to call multiple times.
     *
     * @throws \AMQPException When a declaration fails
     */
    public function setupQueues(): void;

    /**
     * Resets the setup state so the next call to setup() re-declares topology.
     *
     * Should be called after reconnecting to ensure exchanges, queues,
     * and bindings are re-declared on the new connection/channel.
     */
    public function resetSetup(): void;
}
