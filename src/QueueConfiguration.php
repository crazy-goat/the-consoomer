<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

/**
 * Represents a single queue configuration for AMQP setup.
 *
 * Immutable value object containing queue name, binding keys, and optional arguments.
 */
final readonly class QueueConfiguration
{
    /**
     * @param string $name Queue name
     * @param list<string> $bindingKeys Binding keys for this queue (defaults to [''] if empty)
     * @param array<string, mixed> $arguments Queue arguments (x-max-priority, x-message-ttl, etc.)
     */
    public function __construct(
        private string $name,
        private array $bindingKeys = [],
        private array $arguments = [],
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<string>
     */
    public function bindingKeys(): array
    {
        return $this->bindingKeys !== [] ? $this->bindingKeys : [''];
    }

    /**
     * @return array<string, mixed>
     */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function hasArguments(): bool
    {
        return $this->arguments !== [];
    }
}
