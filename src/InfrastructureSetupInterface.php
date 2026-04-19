<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

/**
 * Interface for AMQP infrastructure setup.
 * Handles declaration of exchanges, queues, and bindings.
 */
interface InfrastructureSetupInterface
{
    public function setup(): void;
}
