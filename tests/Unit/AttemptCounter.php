<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

/**
 * Counts how many times a retried operation was actually invoked.
 *
 * A plain `int` captured by reference cannot be used for this. Once an
 * assertion narrows such a variable to a literal, static analysis keeps the
 * literal and cannot see the increments performed by a later closure call, so
 * the second `assertSame(2, $attempts)` looks impossible even though the value
 * really is 2. Routing the increment through a method hides the value from that
 * narrowing while keeping it exact.
 */
final class AttemptCounter
{
    private int $count = 0;

    public function bump(): void
    {
        ++$this->count;
    }

    public function count(): int
    {
        return $this->count;
    }
}