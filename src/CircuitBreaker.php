<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Psr\Log\LoggerInterface;

class CircuitBreaker
{
    private int $failureCount = 0;
    private int $successCount = 0;
    private ?\DateTimeImmutable $lastFailureTime = null;
    private CircuitState $state = CircuitState::CLOSED;

    public function __construct(
        private readonly int $threshold = 10,
        private readonly int $timeout = 60,
        private readonly int $successThreshold = 2,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function recordSuccess(): void
    {
        $this->successCount++;

        if ($this->state === CircuitState::HALF_OPEN && $this->successCount >= $this->successThreshold) {
            $this->transitionTo(CircuitState::CLOSED);
            $this->failureCount = 0;
            $this->successCount = 0;
        }
    }

    public function recordFailure(): void
    {
        $this->failureCount++;
        $this->lastFailureTime = new \DateTimeImmutable();

        if ($this->state === CircuitState::HALF_OPEN) {
            $this->transitionTo(CircuitState::OPEN);
            $this->successCount = 0;
        } elseif ($this->failureCount >= $this->threshold) {
            $this->transitionTo(CircuitState::OPEN);
        }
    }

    public function isAvailable(): bool
    {
        if ($this->state === CircuitState::CLOSED) {
            return true;
        }

        if ($this->state === CircuitState::OPEN) {
            if (!$this->lastFailureTime instanceof \DateTimeImmutable) {
                return false;
            }
            $elapsed = time() - $this->lastFailureTime->getTimestamp();
            if ($elapsed >= $this->timeout) {
                $this->transitionTo(CircuitState::HALF_OPEN);
                $this->successCount = 0;
                return true;
            }
            return false;
        }

        return true;
    }

    public function getState(): CircuitState
    {
        return $this->state;
    }

    public function reset(): void
    {
        $this->failureCount = 0;
        $this->successCount = 0;
        $this->state = CircuitState::CLOSED;
        $this->lastFailureTime = null;
    }

    private function transitionTo(CircuitState $newState): void
    {
        if ($this->state !== $newState) {
            $this->state = $newState;
            $this->logger?->info('Circuit breaker state changed', [
                'state' => $newState->value,
                'failure_count' => $this->failureCount,
            ]);
        }
    }
}
