<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use CrazyGoat\TheConsoomer\Clock\SystemClock;
use Psr\Log\LoggerInterface;

/**
 * Circuit breaker implementation for retry logic.
 *
 * Tracks failures and successes to prevent cascading failures.
 * States:
 * - CLOSED: Normal operation, requests flow through
 * - OPEN: Circuit tripped, requests blocked until timeout
 * - HALF_OPEN: Testing if service recovered, limited requests allowed
 */
final class CircuitBreaker
{
    private int $failureCount = 0;
    private int $successCount = 0;
    private ?\DateTimeImmutable $lastFailureTime = null;
    private CircuitState $state = CircuitState::CLOSED;

    /**
     * @param int                  $threshold       Failures before opening circuit
     * @param int                  $timeout         Seconds circuit stays open before half-open
     * @param int                  $successThreshold Successes in half-open to close circuit
     * @param LoggerInterface|null $logger          Logger instance
     * @param ClockInterface|null  $clock           Clock for time tracking
     * @throws \InvalidArgumentException When successThreshold < 2
     */
    public function __construct(
        private readonly int $threshold = 10,
        private readonly int $timeout = 60,
        private readonly int $successThreshold = 2,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?ClockInterface $clock = new SystemClock(),
    ) {
        if ($this->successThreshold < 2) {
            throw new \InvalidArgumentException('successThreshold must be at least 2');
        }
    }

    /**
     * Records a successful operation.
     *
     * In HALF_OPEN state, transitions to CLOSED after reaching success threshold.
     */
    public function recordSuccess(): void
    {
        $this->successCount++;

        if ($this->state === CircuitState::HALF_OPEN && $this->successCount >= $this->successThreshold) {
            $this->transitionTo(CircuitState::CLOSED);
            $this->failureCount = 0;
            $this->successCount = 0;
        }
    }

    /**
     * Records a failed operation.
     *
     * Opens circuit when failure threshold is reached.
     * Immediately opens circuit if failure occurs in HALF_OPEN state.
     */
    public function recordFailure(): void
    {
        $this->failureCount++;
        $this->lastFailureTime = $this->clock->now();

        if ($this->state === CircuitState::HALF_OPEN) {
            $this->transitionTo(CircuitState::OPEN);
            $this->successCount = 0;
        } elseif ($this->failureCount >= $this->threshold) {
            $this->transitionTo(CircuitState::OPEN);
        }
    }

    /**
     * Checks if circuit breaker allows requests.
     *
     * @return bool True if requests are allowed (CLOSED or HALF_OPEN after timeout)
     */
    public function isAvailable(): bool
    {
        if ($this->state === CircuitState::CLOSED) {
            return true;
        }

        if ($this->state === CircuitState::OPEN) {
            if (!$this->lastFailureTime instanceof \DateTimeImmutable) {
                return false;
            }
            $elapsed = $this->clock->now()->getTimestamp() - $this->lastFailureTime->getTimestamp();
            if ($elapsed >= $this->timeout) {
                $this->transitionTo(CircuitState::HALF_OPEN);
                $this->successCount = 0;
                return true;
            }
            return false;
        }

        return true;
    }

    /**
     * Returns current circuit state.
     */
    public function getState(): CircuitState
    {
        return $this->state;
    }

    /**
     * Resets circuit breaker to initial CLOSED state.
     */
    public function reset(): void
    {
        $this->failureCount = 0;
        $this->successCount = 0;
        $this->state = CircuitState::CLOSED;
        $this->lastFailureTime = null;
    }

    /**
     * Transitions to a new state and logs the change.
     *
     * @param CircuitState $newState New state to transition to
     */
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
