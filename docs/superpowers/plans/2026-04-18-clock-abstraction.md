# Clock Abstraction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Introduce Clock abstraction to eliminate sleep() calls in tests, making them instant and deterministic.

**Architecture:** Create ClockInterface with SystemClock implementation for production and FrozenClock for tests. Inject into CircuitBreaker via ConnectionRetry.

**Tech Stack:** PHP 8.2, PHPUnit 10

---

## File Structure

| File | Action | Purpose |
|------|--------|---------|
| `src/ClockInterface.php` | Create | Interface for time abstraction |
| `src/Clock/SystemClock.php` | Create | Production implementation |
| `tests/Unit/Clock/FrozenClock.php` | Create | Test helper for controlling time |
| `src/CircuitBreaker.php` | Modify | Inject Clock, use instead of time() |
| `src/ConnectionRetry.php` | Modify | Pass Clock to CircuitBreaker |
| `tests/Unit/ConnectionRetryTest.php` | Modify | Use FrozenClock instead of sleep() |

---

### Task 1: Create ClockInterface

**Files:**
- Create: `src/ClockInterface.php`

- [ ] **Step 1: Create ClockInterface**

```php
<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
```

- [ ] **Step 2: Verify file is valid**

Run: `php -l src/ClockInterface.php`
Expected: No syntax errors

- [ ] **Step 3: Commit**

```bash
git add src/ClockInterface.php
git commit -m "feat: add ClockInterface for time abstraction"
```

---

### Task 2: Create SystemClock

**Files:**
- Create: `src/Clock/SystemClock.php`

- [ ] **Step 1: Create SystemClock**

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

- [ ] **Step 2: Verify file is valid**

Run: `php -l src/Clock/SystemClock.php`
Expected: No syntax errors

- [ ] **Step 3: Commit**

```bash
git add src/Clock/SystemClock.php
git commit -m "feat: add SystemClock implementation"
```

---

### Task 3: Create FrozenClock test helper

**Files:**
- Create: `tests/Unit/Clock/FrozenClock.php`

- [ ] **Step 1: Create FrozenClock**

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

- [ ] **Step 2: Verify file is valid**

Run: `php -l tests/Unit/Clock/FrozenClock.php`
Expected: No syntax errors

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Clock/FrozenClock.php
git commit -m "test: add FrozenClock for deterministic time in tests"
```

---

### Task 4: Write test for CircuitBreaker with Clock

**Files:**
- Modify: `tests/Unit/ConnectionRetryTest.php`

- [ ] **Step 1: Add import for FrozenClock**

Add at top of file after existing imports:

```php
use CrazyGoat\TheConsoomer\Tests\Unit\Clock\FrozenClock;
```

- [ ] **Step 2: Refactor testCircuitBreakerAllowsRequestWhenHalfOpen to use FrozenClock**

Replace the entire test method (lines 104-128):

```php
    public function testCircuitBreakerAllowsRequestWhenHalfOpen(): void
    {
        $clock = new FrozenClock();

        $retry = new ConnectionRetry(
            retryCount: 1,
            retryDelay: 1000,
            retryCircuitBreaker: true,
            retryCircuitBreakerThreshold: 1,
            retryCircuitBreakerTimeout: 2,
            clock: $clock,
        );

        try {
            $retry->withRetry(function (): void {
                throw new \AMQPConnectionException('Connection failed');
            });
        } catch (\AMQPConnectionException) {
        }

        $this->assertTrue($retry->isCircuitOpen());

        $clock->advance(3);

        $result = $retry->withRetry(fn(): string => 'success');

        $this->assertSame('success', $result);
    }
```

- [ ] **Step 3: Run test to verify it fails**

Run: `composer test-unit -- --filter testCircuitBreakerAllowsRequestWhenHalfOpen`
Expected: FAIL - ConnectionRetry does not accept clock parameter yet

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/ConnectionRetryTest.php
git commit -m "test: refactor testCircuitBreakerAllowsRequestWhenHalfOpen to use FrozenClock"
```

---

### Task 5: Write test for CircuitBreaker timeout without failure

**Files:**
- Modify: `tests/Unit/ConnectionRetryTest.php`

- [ ] **Step 1: Refactor testCircuitBreakerDoesNotTransitionWithoutFailure to use FrozenClock**

Replace the entire test method (lines 205-221):

```php
    public function testCircuitBreakerDoesNotTransitionWithoutFailure(): void
    {
        $clock = new FrozenClock();

        $retry = new ConnectionRetry(
            retryCount: 1,
            retryDelay: 1000,
            retryCircuitBreaker: true,
            retryCircuitBreakerThreshold: 1,
            retryCircuitBreakerTimeout: 1,
            clock: $clock,
        );

        $clock->advance(2);

        $this->assertFalse($retry->isCircuitOpen());
        $this->assertSame(CircuitState::CLOSED, $retry->getState());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test-unit -- --filter testCircuitBreakerDoesNotTransitionWithoutFailure`
Expected: FAIL - ConnectionRetry does not accept clock parameter yet

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/ConnectionRetryTest.php
git commit -m "test: refactor testCircuitBreakerDoesNotTransitionWithoutFailure to use FrozenClock"
```

---

### Task 6: Write test for CircuitBreaker success threshold

**Files:**
- Modify: `tests/Unit/ConnectionRetryTest.php`

- [ ] **Step 1: Refactor testCircuitBreakerSuccessThresholdDefaultIsTwo to use FrozenClock**

Replace the entire test method (lines 223-252):

```php
    public function testCircuitBreakerSuccessThresholdDefaultIsTwo(): void
    {
        $clock = new FrozenClock();

        $retry = new ConnectionRetry(
            retryCount: 1,
            retryDelay: 1000,
            retryCircuitBreaker: true,
            retryCircuitBreakerThreshold: 1,
            retryCircuitBreakerTimeout: 2,
            clock: $clock,
        );

        try {
            $retry->withRetry(function (): void {
                throw new \AMQPConnectionException('Connection failed');
            });
        } catch (\AMQPConnectionException) {
        }

        $this->assertTrue($retry->isCircuitOpen());

        $clock->advance(3);

        $retry->isCircuitOpen();
        $this->assertSame(CircuitState::HALF_OPEN, $retry->getState());

        $retry->withRetry(fn(): string => 'success');
        $this->assertSame(CircuitState::HALF_OPEN, $retry->getState());

        $retry->withRetry(fn(): string => 'success');
        $this->assertSame(CircuitState::CLOSED, $retry->getState());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test-unit -- --filter testCircuitBreakerSuccessThresholdDefaultIsTwo`
Expected: FAIL - ConnectionRetry does not accept clock parameter yet

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/ConnectionRetryTest.php
git commit -m "test: refactor testCircuitBreakerSuccessThresholdDefaultIsTwo to use FrozenClock"
```

---

### Task 7: Write test for custom success threshold

**Files:**
- Modify: `tests/Unit/ConnectionRetryTest.php`

- [ ] **Step 1: Refactor testCircuitBreakerCustomSuccessThreshold to use FrozenClock**

Replace the entire test method (lines 254-285):

```php
    public function testCircuitBreakerCustomSuccessThreshold(): void
    {
        $clock = new FrozenClock();

        $retry = new ConnectionRetry(
            retryCount: 1,
            retryDelay: 1000,
            retryCircuitBreaker: true,
            retryCircuitBreakerThreshold: 1,
            retryCircuitBreakerTimeout: 2,
            retryCircuitBreakerSuccessThreshold: 3,
            clock: $clock,
        );

        try {
            $retry->withRetry(function (): void {
                throw new \AMQPConnectionException('Connection failed');
            });
        } catch (\AMQPConnectionException) {
        }

        $clock->advance(3);

        $retry->isCircuitOpen();
        $this->assertSame(CircuitState::HALF_OPEN, $retry->getState());

        $retry->withRetry(fn(): string => 'success');
        $this->assertSame(CircuitState::HALF_OPEN, $retry->getState());

        $retry->withRetry(fn(): string => 'success');
        $this->assertSame(CircuitState::HALF_OPEN, $retry->getState());

        $retry->withRetry(fn(): string => 'success');
        $this->assertSame(CircuitState::CLOSED, $retry->getState());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test-unit -- --filter testCircuitBreakerCustomSuccessThreshold`
Expected: FAIL - ConnectionRetry does not accept clock parameter yet

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/ConnectionRetryTest.php
git commit -m "test: refactor testCircuitBreakerCustomSuccessThreshold to use FrozenClock"
```

---

### Task 8: Modify CircuitBreaker to accept Clock

**Files:**
- Modify: `src/CircuitBreaker.php`

- [ ] **Step 1: Add import for ClockInterface and SystemClock**

Add after existing imports:

```php
use CrazyGoat\TheConsoomer\Clock\SystemClock;
use CrazyGoat\TheConsoomer\ClockInterface;
```

- [ ] **Step 2: Add clock property and modify constructor**

Replace constructor (lines 16-25):

```php
    private readonly ClockInterface $clock;

    public function __construct(
        private readonly int $threshold = 10,
        private readonly int $timeout = 60,
        private readonly int $successThreshold = 2,
        private readonly ?LoggerInterface $logger = null,
        ?ClockInterface $clock = null,
    ) {
        if ($this->successThreshold < 2) {
            throw new \InvalidArgumentException('successThreshold must be at least 2');
        }
        $this->clock = $clock ?? new SystemClock();
    }
```

- [ ] **Step 3: Modify recordFailure to use clock**

Replace line 41:

```php
        $this->lastFailureTime = $this->clock->now();
```

- [ ] **Step 4: Modify isAvailable to use clock**

Replace lines 61-62:

```php
            $elapsed = $this->clock->now()->getTimestamp() - $this->lastFailureTime->getTimestamp();
```

- [ ] **Step 5: Verify file is valid**

Run: `php -l src/CircuitBreaker.php`
Expected: No syntax errors

- [ ] **Step 6: Commit**

```bash
git add src/CircuitBreaker.php
git commit -m "feat: inject ClockInterface into CircuitBreaker"
```

---

### Task 9: Modify ConnectionRetry to accept and pass Clock

**Files:**
- Modify: `src/ConnectionRetry.php`

- [ ] **Step 1: Add import for ClockInterface and SystemClock**

Add after existing imports:

```php
use CrazyGoat\TheConsoomer\Clock\SystemClock;
use CrazyGoat\TheConsoomer\ClockInterface;
```

- [ ] **Step 2: Add clock property and modify constructor**

Replace constructor (lines 16-38):

```php
    private readonly ClockInterface $clock;

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
        ?ClockInterface $clock = null,
    ) {
        $this->metrics = new RetryMetrics();
        $this->clock = $clock ?? new SystemClock();

        if ($this->retryCircuitBreaker) {
            $this->circuitBreaker = new CircuitBreaker(
                $this->retryCircuitBreakerThreshold,
                $this->retryCircuitBreakerTimeout,
                $this->retryCircuitBreakerSuccessThreshold,
                $this->logger,
                $this->clock,
            );
        }
    }
```

- [ ] **Step 3: Verify file is valid**

Run: `php -l src/ConnectionRetry.php`
Expected: No syntax errors

- [ ] **Step 4: Commit**

```bash
git add src/ConnectionRetry.php
git commit -m "feat: inject ClockInterface into ConnectionRetry and pass to CircuitBreaker"
```

---

### Task 10: Run all tests and verify

**Files:**
- None

- [ ] **Step 1: Run unit tests**

Run: `composer test-unit`
Expected: All tests pass, no sleep() delays

- [ ] **Step 2: Run lint checks**

Run: `composer lint`
Expected: All checks pass

- [ ] **Step 3: Verify no sleep() calls remain in tests**

Run: `grep -n "sleep(" tests/Unit/ConnectionRetryTest.php`
Expected: No matches

---

### Task 11: Final commit and cleanup

**Files:**
- None

- [ ] **Step 1: Run full test suite**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 2: Create final commit if any uncommitted changes**

```bash
git status
# If changes exist:
git add -A
git commit -m "feat: complete Clock abstraction for fast deterministic tests (closes #145)"
```
