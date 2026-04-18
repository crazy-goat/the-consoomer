# Clock Abstraction Design

**Issue:** #145 - Slow unit tests due to sleep() calls
**Date:** 2026-04-18

## Problem

Unit tests in `ConnectionRetryTest.php` use `sleep(3)` to test CircuitBreaker timeout behavior, adding ~6 seconds to test runtime.

Relevant lines:
- `tests/Unit/ConnectionRetryTest.php:123`
- `tests/Unit/ConnectionRetryTest.php:216`
- `tests/Unit/ConnectionRetryTest.php:242`
- `tests/Unit/ConnectionRetryTest.php:272`

## Solution

Introduce a `ClockInterface` abstraction to decouple time-dependent code from system time.

## Architecture

### New Files

```
src/ClockInterface.php           # Interface
src/Clock/SystemClock.php        # Default implementation
tests/Unit/Clock/FrozenClock.php # Test helper
```

### ClockInterface

```php
<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
```

### SystemClock

```php
<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Clock;

use CrazyGoat\TheConsoomer\ClockInterface;

final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
```

### FrozenClock (Test Helper)

```php
<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit\Clock;

use CrazyGoat\TheConsoomer\ClockInterface;

final class FrozenClock implements ClockInterface
{
    private \DateTimeImmutable $time;

    public function __construct(?\DateTimeImmutable $time = null)
    {
        $this->time = $time ?? new \DateTimeImmutable();
    }

    public function now(): \DateTimeImmutable
    {
        return $this->time;
    }

    public function advance(int $seconds): void
    {
        $this->time = $this->time->modify("+{$seconds} seconds");
    }
}
```

## Changes to Existing Code

### CircuitBreaker

**Constructor change:**

```php
public function __construct(
    private readonly int $threshold = 10,
    private readonly int $timeout = 60,
    private readonly int $successThreshold = 2,
    private readonly ?LoggerInterface $logger = null,
    private readonly ?ClockInterface $clock = null,  // NEW
) {
    $this->clock = $clock ?? new SystemClock();
    // ...
}
```

**recordFailure() change:**

```php
// Before:
$this->lastFailureTime = new \DateTimeImmutable();

// After:
$this->lastFailureTime = $this->clock->now();
```

**isAvailable() change:**

```php
// Before:
$elapsed = time() - $this->lastFailureTime->getTimestamp();

// After:
$elapsed = $this->clock->now()->getTimestamp() - $this->lastFailureTime->getTimestamp();
```

### ConnectionRetry

Pass Clock to CircuitBreaker when creating it:

```php
if ($this->retryCircuitBreaker) {
    $this->circuitBreaker = new CircuitBreaker(
        $this->retryCircuitBreakerThreshold,
        $this->retryCircuitBreakerTimeout,
        $this->retryCircuitBreakerSuccessThreshold,
        $this->logger,
        $this->clock,  // NEW - optional ClockInterface parameter
    );
}
```

Add optional ClockInterface parameter to ConnectionRetry constructor:

```php
public function __construct(
    private readonly int $retryCount = 3,
    private readonly int $retryDelay = 100000,
    private readonly bool $retryBackoff = false,
    private readonly int $retryMaxDelay = 30000000,
    private readonly bool $retryJitter = true,
    private readonly bool $retryCircuitBreaker = false,
    private readonly int $retryCircuitBreakerThreshold = 10,
    private readonly int $retryCircuitBreakerTimeout = 60,
    private readonly int $retryCircuitBreakerSuccessThreshold = 2,
    private readonly ?LoggerInterface $logger = null,
    private readonly ?ClockInterface $clock = null,  // NEW
) {
    $this->clock = $this->clock ?? new SystemClock();
    // ...
}
```

## Test Changes

Replace `sleep(3)` with `FrozenClock::advance()`:

```php
// Before:
sleep(3);
$result = $retry->withRetry(fn(): string => 'success');

// After:
$clock = new FrozenClock();
$retry = new ConnectionRetry(
    retryCount: 1,
    retryDelay: 1000,
    retryCircuitBreaker: true,
    retryCircuitBreakerThreshold: 1,
    retryCircuitBreakerTimeout: 2,
    clock: $clock,
);

// ... failure happens ...

$clock->advance(3);
$result = $retry->withRetry(fn(): string => 'success');
```

## Benefits

1. **Fast tests** - No more `sleep()` calls, tests run instantly
2. **Deterministic tests** - Time is controlled, no flaky tests
3. **Clean architecture** - Explicit dependency on time
4. **Testability** - Easy to test time-dependent behavior

## Scope

- Only CircuitBreaker uses Clock (minimal scope)
- ConnectionRetry passes Clock to CircuitBreaker
- No changes to other classes

## Files Affected

- `src/ClockInterface.php` - new
- `src/Clock/SystemClock.php` - new
- `tests/Unit/Clock/FrozenClock.php` - new
- `src/CircuitBreaker.php` - modify
- `src/ConnectionRetry.php` - modify
- `tests/Unit/ConnectionRetryTest.php` - modify
