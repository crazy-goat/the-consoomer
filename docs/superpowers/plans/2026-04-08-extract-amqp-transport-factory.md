# Extract AmqpTransportFactory Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract factory logic from `AmqpTransport` into separate `AmqpTransportFactory` class to fix SRP violation (issue #46)

**Architecture:** Create new `AmqpTransportFactory` implementing `TransportFactoryInterface`, move all factory methods (`supports()`, `createTransport()`, `create()`, `createRetry()`) to it. `AmqpTransport` becomes pure transport implementation.

**Tech Stack:** PHP 8.3, PHPUnit, Symfony Messenger

---

## File Structure

**New Files:**
- `src/AmqpTransportFactory.php` - Factory class implementing TransportFactoryInterface
- `tests/Unit/AmqpTransportFactoryTest.php` - Unit tests for factory

**Modified Files:**
- `src/AmqpTransport.php` - Remove factory interface and methods
- `tests/Unit/AmqpTransportTest.php` - Remove factory tests, keep transport tests

---

### Task 1: Create AmqpTransportFactory with supports() method

**Files:**
- Create: `src/AmqpTransportFactory.php`
- Test: `tests/Unit/AmqpTransportFactoryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\AmqpTransportFactory;
use PHPUnit\Framework\TestCase;

class AmqpTransportFactoryTest extends TestCase
{
    public function testSupportsReturnsTrueForAmqpConsoomerDsn(): void
    {
        $factory = new AmqpTransportFactory();
        $this->assertTrue($factory->supports('amqp-consoomer://localhost', []));
    }

    public function testSupportsReturnsTrueForAmqpsConsoomerScheme(): void
    {
        $factory = new AmqpTransportFactory();
        $this->assertTrue($factory->supports('amqps-consoomer://localhost/%2f/exchange', []));
    }

    public function testSupportsReturnsFalseForOtherDsn(): void
    {
        $factory = new AmqpTransportFactory();
        $this->assertFalse($factory->supports('amqp://localhost', []));
        $this->assertFalse($factory->supports('rabbitmq://localhost', []));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/AmqpTransportFactoryTest.php --filter testSupportsReturnsTrueForAmqpConsoomerDsn -v`
Expected: FAIL with "Class CrazyGoat\TheConsoomer\AmqpTransportFactory not found"

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class AmqpTransportFactory implements TransportFactoryInterface
{
    public function supports(string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'amqp-consoomer://') || str_starts_with($dsn, 'amqps-consoomer://');
    }

    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        throw new \RuntimeException('Not implemented yet');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/AmqpTransportFactoryTest.php --filter testSupports -v`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add src/AmqpTransportFactory.php tests/Unit/AmqpTransportFactoryTest.php
git commit -m "feat: Add AmqpTransportFactory with supports() method"
```

---

### Task 2: Move createRetry() to AmqpTransportFactory

**Files:**
- Modify: `src/AmqpTransportFactory.php`
- Modify: `src/AmqpTransport.php`

- [ ] **Step 1: Add createRetry() to factory**

Add to `src/AmqpTransportFactory.php` after the `createTransport()` method:

```php
    private function createRetry(array $options, ?\Psr\Log\LoggerInterface $logger = null): ?ConnectionRetryInterface
    {
        if ($options['retry'] ?? false) {
            return new ConnectionRetry(
                retryCount: (int) ($options['retry_count'] ?? 3),
                retryDelay: (int) ($options['retry_delay'] ?? 100000),
                retryBackoff: (bool) ($options['retry_backoff'] ?? false),
                retryMaxDelay: (int) ($options['retry_max_delay'] ?? 30000000),
                retryJitter: (bool) ($options['retry_jitter'] ?? true),
                retryCircuitBreaker: (bool) ($options['retry_circuit_breaker'] ?? false),
                retryCircuitBreakerThreshold: (int) ($options['retry_circuit_breaker_threshold'] ?? 10),
                retryCircuitBreakerTimeout: (int) ($options['retry_circuit_breaker_timeout'] ?? 60),
                logger: $logger,
            );
        }

        return null;
    }
```

- [ ] **Step 2: Remove createRetry() from AmqpTransport**

In `src/AmqpTransport.php`, remove the entire `createRetry()` method (lines 117-134).

- [ ] **Step 3: Verify no tests fail**

Run: `vendor/bin/phpunit tests/Unit/ -v`
Expected: All tests pass (createRetry is private and not directly tested)

- [ ] **Step 4: Commit**

```bash
git add src/AmqpTransportFactory.php src/AmqpTransport.php
git commit -m "refactor: Move createRetry() to AmqpTransportFactory"
```

---

### Task 3: Move create() logic to createTransport() in factory

**Files:**
- Modify: `src/AmqpTransportFactory.php`
- Modify: `src/AmqpTransport.php`

- [ ] **Step 1: Add full createTransport() implementation**

Replace the `createTransport()` method in `src/AmqpTransportFactory.php` with:

```php
    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        return self::create($dsn, $options, $serializer);
    }

    public static function create(
        string $dsn,
        array $options,
        SerializerInterface $serializer,
        ?AmqpFactoryInterface $factory = null,
        ?\Psr\Log\LoggerInterface $logger = null,
    ): TransportInterface {
        $dsnParser = new DsnParser();
        $parsedDsn = $dsnParser->parse($dsn);
        $mergedOptions = [...$parsedDsn, ...$options];

        $factory ??= new AmqpFactory();

        // Native AMQP heartbeat - negotiated with broker at protocol level
        // Set via constructor to ensure RabbitMQ sees the heartbeat value
        $connection = $factory->createConnection($mergedOptions);

        // Connection parameters (host, port, vhost, user, password, timeout) are always
        // taken from $parsedDsn, not from $mergedOptions. These are part of the DSN
        // authority/path and cannot be overridden by programmatic $options.
        $connection->setHost($parsedDsn['host']);
        $connection->setPort($parsedDsn['port']);
        $connection->setVhost($parsedDsn['vhost']);
        $connection->setLogin($parsedDsn['user']);
        $connection->setPassword($parsedDsn['password']);
        $connection->setReadTimeout((float) ($parsedDsn['timeout'] ?? 0.1));

        $factory->configureSsl($connection, $mergedOptions, $logger);

        // Client-side heartbeat tracking for auto-reconnect detection
        $amqpConnection = new Connection($factory, $connection);
        if (isset($mergedOptions['heartbeat'])) {
            $amqpConnection->setHeartbeat((int) $mergedOptions['heartbeat']);
        }
        if ($logger instanceof \Psr\Log\LoggerInterface) {
            $amqpConnection->setLogger($logger);
        }

        $connection->connect();
        $amqpConnection->updateActivity();

        $setup = new InfrastructureSetup($factory, $amqpConnection, $mergedOptions);

        $retry = self::createRetry($mergedOptions, $logger);

        return new AmqpTransport(
            new Receiver($factory, $amqpConnection, $serializer, $mergedOptions, $setup, $retry),
            new Sender($factory, $amqpConnection, $serializer, $mergedOptions, $setup, $retry),
            $setup,
        );
    }
```

- [ ] **Step 2: Remove create() from AmqpTransport**

In `src/AmqpTransport.php`, remove the entire `create()` method (lines 70-115).

- [ ] **Step 3: Remove unused imports from AmqpTransport**

In `src/AmqpTransport.php`, remove these unused imports:
- `use Psr\Log\LoggerInterface;`
- `use Symfony\Component\Messenger\Transport\TransportFactoryInterface;`

- [ ] **Step 4: Add missing import to AmqpTransportFactory**

Add to `src/AmqpTransportFactory.php`:
```php
use Psr\Log\LoggerInterface;
```

- [ ] **Step 5: Run tests to verify**

Run: `vendor/bin/phpunit tests/Unit/AmqpTransportFactoryTest.php -v`
Expected: PASS (existing supports tests still pass)

- [ ] **Step 6: Commit**

```bash
git add src/AmqpTransportFactory.php src/AmqpTransport.php
git commit -m "refactor: Move create() logic to AmqpTransportFactory"
```

---

### Task 4: Remove TransportFactoryInterface from AmqpTransport

**Files:**
- Modify: `src/AmqpTransport.php`

- [ ] **Step 1: Remove interface and methods**

In `src/AmqpTransport.php`:
1. Change line 17 from:
   ```php
   class AmqpTransport implements TransportInterface, TransportFactoryInterface, MessageCountAwareInterface, SetupableTransportInterface
   ```
   to:
   ```php
   class AmqpTransport implements TransportInterface, MessageCountAwareInterface, SetupableTransportInterface
   ```

2. Remove the `supports()` method (lines 60-63)

3. Remove the `createTransport()` method (lines 65-68)

- [ ] **Step 2: Run tests to verify AmqpTransport still works**

Run: `vendor/bin/phpunit tests/Unit/AmqpTransportTest.php -v`
Expected: Tests that don't rely on factory methods should pass (some will fail until we update tests in Task 5)

- [ ] **Step 3: Commit**

```bash
git add src/AmqpTransport.php
git commit -m "refactor: Remove TransportFactoryInterface from AmqpTransport"
```

---

### Task 5: Move factory tests to AmqpTransportFactoryTest

**Files:**
- Modify: `tests/Unit/AmqpTransportFactoryTest.php`
- Modify: `tests/Unit/AmqpTransportTest.php`

- [ ] **Step 1: Add factory tests to new test file**

Add these test methods to `tests/Unit/AmqpTransportFactoryTest.php`:

```php
    private function createMockFactoryAndConnection(): array
    {
        $factory = $this->createMock(AmqpFactoryInterface::class);
        $connection = $this->createMock(\AMQPConnection::class);
        
        $factory
            ->expects($this->once())
            ->method('createConnection')
            ->willReturn($connection);
        
        $connection
            ->expects($this->once())
            ->method('connect');
        
        return [$factory, $connection];
    }

    public function testCreateTransportCreatesAmqpTransport(): void
    {
        [$factory, $connection] = $this->createMockFactoryAndConnection();
        $serializer = $this->createMock(SerializerInterface::class);

        $transport = AmqpTransportFactory::create(
            'amqp-consoomer://guest:guest@localhost:5672/vhost/test-exchange',
            ['queue' => 'test-queue'],
            $serializer,
            $factory,
        );

        $this->assertInstanceOf(AmqpTransport::class, $transport);
    }

    public function testCreateTransportWithAmqpsScheme(): void
    {
        $factory = $this->createMock(AmqpFactoryInterface::class);
        $connection = $this->createMock(\AMQPConnection::class);

        $factory
            ->expects($this->once())
            ->method('createConnection')
            ->willReturn($connection);

        $factory
            ->expects($this->once())
            ->method('configureSsl')
            ->with(
                $connection,
                $this->callback(function (array $options): true {
                    $this->assertTrue($options['ssl'] ?? false);
                    $this->assertSame(5671, $options['port']);
                    return true;
                }),
            );

        $connection
            ->expects($this->once())
            ->method('setHost')
            ->with('localhost');

        $connection
            ->expects($this->once())
            ->method('setPort')
            ->with(5671);

        $connection
            ->expects($this->once())
            ->method('connect');

        $transport = AmqpTransportFactory::create(
            'amqps-consoomer://guest:guest@localhost/%2f/my_exchange',
            ['exchange' => 'my_exchange', 'queue' => 'my_queue'],
            $this->createMock(SerializerInterface::class),
            $factory,
        );

        $this->assertInstanceOf(AmqpTransport::class, $transport);
    }

    public function testCreateTransportMergesOptionsWithProgrammaticOptionsTakingPrecedence(): void
    {
        $factory = $this->createMock(AmqpFactoryInterface::class);
        $connection = $this->createMock(\AMQPConnection::class);
        $serializer = $this->createMock(SerializerInterface::class);

        $factory
            ->expects($this->once())
            ->method('createConnection')
            ->with(
                $this->callback(function (array $options): true {
                    // Programmatic options (retry_count=5) should override DSN options (retry_count=3)
                    $this->assertSame(5, $options['retry_count']);
                    // DSN options should still be present if not overridden
                    $this->assertSame(100000, $options['retry_delay']);
                    return true;
                }),
            )
            ->willReturn($connection);

        $connection
            ->expects($this->once())
            ->method('connect');

        AmqpTransportFactory::create(
            'amqp-consoomer://guest:guest@localhost:5672/%2f/exchange?retry_count=3&retry_delay=100000',
            ['exchange' => 'test-exchange', 'queue' => 'test-queue', 'retry_count' => 5],
            $serializer,
            $factory,
        );
    }

    public function testCreateTransportPassesInfrastructureSetupToReceiverAndSender(): void
    {
        [$factory, $connection] = $this->createMockFactoryAndConnection();
        $serializer = $this->createMock(SerializerInterface::class);

        $transport = AmqpTransportFactory::create(
            'amqp-consoomer://guest:guest@localhost:5672/vhost/test-exchange',
            ['queue' => 'test-queue'],
            $serializer,
            $factory,
        );

        $reflection = new \ReflectionClass($transport);
        $receiverProperty = $reflection->getProperty('receiver');
        $senderProperty = $reflection->getProperty('sender');

        $receiver = $receiverProperty->getValue($transport);
        $sender = $senderProperty->getValue($transport);

        $receiverReflection = new \ReflectionClass($receiver);
        $senderReflection = new \ReflectionClass($sender);

        $receiverSetupProperty = $receiverReflection->getProperty('setup');
        $senderSetupProperty = $senderReflection->getProperty('setup');

        $receiverSetup = $receiverSetupProperty->getValue($receiver);
        $senderSetup = $senderSetupProperty->getValue($sender);

        $this->assertInstanceOf(InfrastructureSetup::class, $receiverSetup);
        $this->assertInstanceOf(InfrastructureSetup::class, $senderSetup);
        $this->assertSame($receiverSetup, $senderSetup);
    }
```

Add these imports to the top of `tests/Unit/AmqpTransportFactoryTest.php`:
```php
use CrazyGoat\TheConsoomer\AmqpFactoryInterface;
use CrazyGoat\TheConsoomer\AmqpTransport;
use CrazyGoat\TheConsoomer\InfrastructureSetup;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
```

- [ ] **Step 2: Remove factory tests from AmqpTransportTest**

In `tests/Unit/AmqpTransportTest.php`, remove these test methods:
- `testSupportsReturnsTrueForAmqpConsoomerDsn()` (lines 33-38)
- `testSupportsReturnsTrueForAmqpConsoomerDsnWithHost()` (lines 40-45)
- `testSupportsReturnsFalseForOtherDsn()` (lines 47-52)
- `testSupportsReturnsFalseForAmqpDsnWithoutConsoomer()` (lines 54-59)
- `testSupportsReturnsFalseForRabbitMqDsn()` (lines 61-66)
- `testSupportsAmqpsConsoomerScheme()` (lines 68-77)
- `testSupportsReturnsFalseForGenericAmqpsScheme()` (lines 79-88)
- `testCreatePassesInfrastructureSetupToReceiverAndSender()` (lines 183-224)
- `testCreateWithAmqpsScheme()` (lines 226-270)
- `testCreateMergesOptionsWithProgrammaticOptionsTakingPrecedence()` (lines 310-340)

Also remove the `AmqpFactoryInterface` import if it's no longer used.

- [ ] **Step 3: Run all unit tests**

Run: `vendor/bin/phpunit tests/Unit/ -v`
Expected: All tests pass

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/AmqpTransportFactoryTest.php tests/Unit/AmqpTransportTest.php
git commit -m "test: Move factory tests to AmqpTransportFactoryTest"
```

---

### Task 6: Final verification and cleanup

- [ ] **Step 1: Run full test suite**

Run: `vendor/bin/phpunit tests/Unit/ -v`
Expected: All unit tests pass

- [ ] **Step 2: Check code style**

Run: `vendor/bin/ecs check src/AmqpTransportFactory.php tests/Unit/AmqpTransportFactoryTest.php`
Expected: No style errors

- [ ] **Step 3: Static analysis**

Run: `vendor/bin/phpstan analyse src/AmqpTransportFactory.php --level=max`
Expected: No errors

- [ ] **Step 4: Final commit**

```bash
git commit --allow-empty -m "feat: Complete extraction of AmqpTransportFactory (closes #46)"
```

---

## Summary

After completing all tasks:

1. `AmqpTransport` implements only `TransportInterface`, `MessageCountAwareInterface`, `SetupableTransportInterface`
2. `AmqpTransportFactory` implements `TransportFactoryInterface` with `supports()` and `createTransport()`
3. All factory logic moved to `AmqpTransportFactory`
4. Tests properly separated between transport and factory test files
5. SRP violation resolved
