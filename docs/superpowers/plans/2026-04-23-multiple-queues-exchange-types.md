# Multiple Queues and Exchange Types Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend InfrastructureSetup to support multiple queues with different binding keys and queue arguments per transport.

**Architecture:** Add `queues` option parsing to DsnParser (arguments support), refactor InfrastructureSetup to iterate over multiple queues while maintaining backward compatibility with single-queue mode.

**Tech Stack:** PHP 8.2+, PHPUnit, AMQP extension

---

## File Structure

| File | Action | Responsibility |
|------|--------|----------------|
| `src/DsnParser.php` | Modify | Parse `queues[name][arguments][key]=value` from DSN, update return type |
| `src/InfrastructureSetup.php` | Modify | Add `setupQueues()` method, validation, refactor `setup()` for multi-queue |
| `tests/Unit/DsnParserTest.php` | Modify | Tests for queue arguments parsing |
| `tests/Unit/InfrastructureSetupTest.php` | Modify | Tests for multi-queue setup, validation, backward compatibility |

---

### Task 1: DsnParser - Parse queue arguments in DSN

**Files:**
- Modify: `src/DsnParser.php`
- Test: `tests/Unit/DsnParserTest.php`

- [ ] **Step 1: Write failing test for queue arguments parsing**

Add to `tests/Unit/DsnParserTest.php`:

```php
public function testParsesQueuesWithArguments(): void
{
    $parser = new DsnParser();
    $result = $parser->parse('amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange?queues[orders][arguments][x-max-priority]=10&queues[orders][arguments][x-message-ttl]=60000');

    $this->assertIsArray($result['queues']);
    $this->assertArrayHasKey('orders', $result['queues']);
    $this->assertArrayHasKey('arguments', $result['queues']['orders']);
    $this->assertSame(10, $result['queues']['orders']['arguments']['x-max-priority']);
    $this->assertSame(60000, $result['queues']['orders']['arguments']['x-message-ttl']);
}

public function testParsesQueuesWithMultipleBindingKeysAndArguments(): void
{
    $parser = new DsnParser();
    $result = $parser->parse('amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange?queues[orders][binding_keys][0]=order.created&queues[orders][binding_keys][1]=order.updated&queues[orders][arguments][x-max-priority]=10&queues[notifications][binding_keys][0]=notification.*');

    $this->assertIsArray($result['queues']);
    $this->assertSame(['order.created', 'order.updated'], $result['queues']['orders']['binding_keys']);
    $this->assertSame(10, $result['queues']['orders']['arguments']['x-max-priority']);
    $this->assertSame(['notification.*'], $result['queues']['notifications']['binding_keys']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test-unit -- --filter=testParsesQueuesWithArguments`
Expected: FAIL - `arguments` key not present in parsed queues

- [ ] **Step 3: Add queue arguments parsing to DsnParser**

In `src/DsnParser.php`, add method after `normalizeQueueArguments()`:

```php
/**
 * Normalizes queue arguments from DSN query parameters.
 *
 * Parses queues[name][arguments][key]=value syntax.
 *
 * @param array<string, mixed> $query Parsed query parameters
 * @return array<string, array{binding_keys?: list<string>, arguments?: array<string, mixed>}>
 */
private function parseQueuesOption(array $query): array
{
    $queues = [];

    foreach ($query as $key => $value) {
        // Match queues[name][arguments][key]=value
        if (preg_match('/^queues\[([^\]]+)\]\[arguments\]\[(.+)\]$/', (string) $key, $matches)) {
            $queueName = $matches[1];
            $argKey = $matches[2];
            $queues[$queueName]['arguments'][$argKey] = $this->normalizeValue($value);
        }

        // Match queues[name][binding_keys][index]=value
        if (preg_match('/^queues\[([^\]]+)\]\[binding_keys\]\[(\d+)\]$/', (string) $key, $matches)) {
            $queueName = $matches[1];
            $index = (int) $matches[2];
            $queues[$queueName]['binding_keys'][$index] = $this->normalizeValue($value);
        }
    }

    // Reindex binding_keys arrays (they come with numeric string keys from parse_str)
    foreach ($queues as $name => $config) {
        if (isset($config['binding_keys'])) {
            $queues[$name]['binding_keys'] = array_values($config['binding_keys']);
        }
    }

    return $queues;
}
```

- [ ] **Step 4: Call parseQueuesOption in parse() method**

In `src/DsnParser.php`, in the `parse()` method, add after the queue_arguments block (before `return $this->validateParsedOptions($result);`):

```php
$queues = $this->parseQueuesOption($query);
if ($queues !== []) {
    $result['queues'] = $queues;
}
```

- [ ] **Step 5: Update return type annotation**

In `src/DsnParser.php`, update the `@return` annotation for `parse()` method. Change:

```php
 *     queues?: array<string, array{binding_keys?: list<string>}>,
```

To:

```php
 *     queues?: array<string, array{binding_keys?: list<string>, arguments?: array<string, mixed>}>,
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `composer test-unit -- --filter=testParsesQueuesWith`
Expected: PASS

- [ ] **Step 7: Run all DsnParser tests to verify no regression**

Run: `composer test-unit -- --filter=DsnParserTest`
Expected: All pass

- [ ] **Step 8: Commit**

```bash
git add src/DsnParser.php tests/Unit/DsnParserTest.php
git commit -m "feat: parse queue arguments in DSN for multi-queue support"
```

---

### Task 2: InfrastructureSetup - Add validation for queues option

**Files:**
- Modify: `src/InfrastructureSetup.php`
- Test: `tests/Unit/InfrastructureSetupTest.php`

- [ ] **Step 1: Write failing tests for validation**

Add to `tests/Unit/InfrastructureSetupTest.php`:

```php
public function testSetupThrowsWhenQueuesIsEmptyArray(): void
{
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('queues option must not be empty');

    $options = [
        'exchange' => 'test_exchange',
        'queue' => 'test_queue',
        'queues' => [],
    ];

    new InfrastructureSetup($this->factory, $this->connection, $options);
}

public function testSetupWithEmptyBindingKeysThrows(): void
{
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('queues[orders].binding_keys must not be empty');

    $options = [
        'exchange' => 'test_exchange',
        'queue' => 'test_queue',
        'queues' => [
            'orders' => [
                'binding_keys' => [],
            ],
        ],
    ];

    new InfrastructureSetup($this->factory, $this->connection, $options);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test-unit -- --filter=testSetupThrowsWhenQueuesIsEmptyArray`
Expected: FAIL - exception not thrown

- [ ] **Step 3: Add validation in constructor**

In `src/InfrastructureSetup.php`, add after the `exchange_bindings` validation (line 44):

```php
if (isset($options['queues'])) {
    $this->validateQueues($options['queues']);
}
```

- [ ] **Step 4: Add validateQueues method**

Add to `src/InfrastructureSetup.php` (before `validateExchangeBindings`):

```php
/**
 * @throws \InvalidArgumentException
 */
private function validateQueues(array $queues): void
{
    if ($queues === []) {
        throw new \InvalidArgumentException('queues option must not be empty');
    }

    foreach ($queues as $name => $config) {
        if (!is_array($config)) {
            throw new \InvalidArgumentException(sprintf('queues[%s] must be an array', $name));
        }

        if (isset($config['binding_keys'])) {
            if (!is_array($config['binding_keys'])) {
                throw new \InvalidArgumentException(sprintf('queues[%s].binding_keys must be an array', $name));
            }

            if ($config['binding_keys'] === []) {
                throw new \InvalidArgumentException(sprintf('queues[%s].binding_keys must not be empty', $name));
            }

            foreach ($config['binding_keys'] as $keyIndex => $key) {
                if (!is_string($key)) {
                    throw new \InvalidArgumentException(sprintf('queues[%s].binding_keys[%d] must be a string', $name, $keyIndex));
                }
            }
        }

        if (isset($config['arguments']) && !is_array($config['arguments'])) {
            throw new \InvalidArgumentException(sprintf('queues[%s].arguments must be an array', $name));
        }
    }
}
```

- [ ] **Step 5: Update constructor docblock**

Update the `@param` annotation in constructor to include `queues`:

```php
/**
 * @param array{
 *     exchange: string,
 *     queue: string,
 *     queues?: array<string, array{binding_keys?: list<string>, arguments?: array<string, mixed>}>,
 *     exchange_type?: string,
 *     routing_key?: string,
 *     queue_arguments?: array<string, mixed>,
 *     exchange_flags?: int,
 *     queue_flags?: int,
 *     exchange_bindings?: array<array{target: string, routing_keys?: list<string>}>,
 *     retry_exchange?: string,
 *     retry_queue_arguments?: array<string, mixed>,
 * } $options
 */
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `composer test-unit -- --filter=testSetupThrowsWhenQueuesIsEmptyArray`
Expected: PASS

Run: `composer test-unit -- --filter=testSetupWithEmptyBindingKeysThrows`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add src/InfrastructureSetup.php tests/Unit/InfrastructureSetupTest.php
git commit -m "feat: add validation for queues option in InfrastructureSetup"
```

---

### Task 3: InfrastructureSetup - Implement multi-queue setup

**Files:**
- Modify: `src/InfrastructureSetup.php`
- Test: `tests/Unit/InfrastructureSetupTest.php`

- [ ] **Step 1: Write failing test for multi-queue setup**

Add to `tests/Unit/InfrastructureSetupTest.php`:

```php
public function testSetupWithMultipleQueuesAndBindingKeys(): void
{
    $queue2 = $this->createMock(\AMQPQueue::class);

    $this->connection
        ->expects($this->exactly(4))
        ->method('getChannel')
        ->willReturn($this->channel);

    $this->factory
        ->method('createExchange')
        ->willReturnOnConsecutiveCalls($this->exchange, $this->retryExchange);

    $this->factory
        ->method('createQueue')
        ->willReturnOnConsecutiveCalls($this->queue, $queue2, $this->retryQueue);

    $this->exchange->method('getName')->willReturn('test_exchange');

    // First queue: orders
    $this->queue->expects($this->once())->method('setName')->with('orders');
    $this->queue->expects($this->once())->method('declareQueue');
    $this->queue->expects($this->exactly(2))->method('bind')
        ->withConsecutive(
            ['test_exchange', 'order.created'],
            ['test_exchange', 'order.updated'],
        );

    // Second queue: notifications
    $queue2->expects($this->once())->method('setName')->with('notifications');
    $queue2->expects($this->once())->method('declareQueue');
    $queue2->expects($this->once())->method('bind')->with('test_exchange', 'notification.*');

    $this->retryExchange->method('setName');
    $this->retryExchange->method('setType');
    $this->retryExchange->method('declareExchange');

    $this->retryQueue->method('setName');
    $this->retryQueue->method('setFlags');
    $this->retryQueue->method('setArguments');
    $this->retryQueue->method('declareQueue');
    $this->retryQueue->method('bind');

    $options = [
        'exchange' => 'test_exchange',
        'queue' => 'orders',
        'queues' => [
            'orders' => [
                'binding_keys' => ['order.created', 'order.updated'],
            ],
            'notifications' => [
                'binding_keys' => ['notification.*'],
            ],
        ],
    ];

    $setup = new InfrastructureSetup($this->factory, $this->connection, $options);
    $setup->setup();
}

public function testSetupWithMultipleQueuesAndArguments(): void
{
    $queue2 = $this->createMock(\AMQPQueue::class);

    $this->connection
        ->expects($this->exactly(4))
        ->method('getChannel')
        ->willReturn($this->channel);

    $this->factory
        ->method('createExchange')
        ->willReturnOnConsecutiveCalls($this->exchange, $this->retryExchange);

    $this->factory
        ->method('createQueue')
        ->willReturnOnConsecutiveCalls($this->queue, $queue2, $this->retryQueue);

    $this->exchange->method('getName')->willReturn('test_exchange');

    // First queue with arguments
    $this->queue->expects($this->once())->method('setName')->with('orders');
    $this->queue->expects($this->once())->method('setArguments')->with(['x-max-priority' => 10]);
    $this->queue->expects($this->once())->method('declareQueue');
    $this->queue->expects($this->once())->method('bind')->with('test_exchange', 'order.*');

    // Second queue with different arguments
    $queue2->expects($this->once())->method('setName')->with('notifications');
    $queue2->expects($this->once())->method('setArguments')->with(['x-message-ttl' => 60000]);
    $queue2->expects($this->once())->method('declareQueue');
    $queue2->expects($this->once())->method('bind')->with('test_exchange', 'notification.*');

    $this->retryExchange->method('setName');
    $this->retryExchange->method('setType');
    $this->retryExchange->method('declareExchange');

    $this->retryQueue->method('setName');
    $this->retryQueue->method('setFlags');
    $this->retryQueue->method('setArguments');
    $this->retryQueue->method('declareQueue');
    $this->retryQueue->method('bind');

    $options = [
        'exchange' => 'test_exchange',
        'queue' => 'orders',
        'queues' => [
            'orders' => [
                'binding_keys' => ['order.*'],
                'arguments' => ['x-max-priority' => 10],
            ],
            'notifications' => [
                'binding_keys' => ['notification.*'],
                'arguments' => ['x-message-ttl' => 60000],
            ],
        ],
    ];

    $setup = new InfrastructureSetup($this->factory, $this->connection, $options);
    $setup->setup();
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test-unit -- --filter=testSetupWithMultipleQueuesAndBindingKeys`
Expected: FAIL - queues not declared

- [ ] **Step 3: Refactor setup() method for multi-queue**

Replace the queue declaration section in `setup()` method (lines 73-82) with:

```php
if (isset($this->options['queues'])) {
    $this->setupQueues($exchange);
} else {
    $this->setupSingleQueue($exchange);
}
```

- [ ] **Step 4: Add setupQueues method**

Add to `src/InfrastructureSetup.php` (after `setupExchangeBindings`):

```php
private function setupQueues(\AMQPExchange $exchange): void
{
    $channel = $this->connection->getChannel();
    $queues = $this->options['queues'];

    foreach ($queues as $name => $config) {
        $queue = $this->factory->createQueue($channel);
        $queue->setName($name);
        $queue->setFlags(\AMQP_DURABLE | ($this->options['queue_flags'] ?? 0));

        if (isset($config['arguments'])) {
            $queue->setArguments($config['arguments']);
        }

        $queue->declareQueue();

        $bindingKeys = $config['binding_keys'] ?? [''];
        foreach ($bindingKeys as $bindingKey) {
            $queue->bind($exchange->getName(), $bindingKey);
        }
    }
}
```

- [ ] **Step 5: Extract legacy logic to setupSingleQueue method**

Add to `src/InfrastructureSetup.php` (after `setupQueues`):

```php
private function setupSingleQueue(\AMQPExchange $exchange): void
{
    $channel = $this->connection->getChannel();
    $queue = $this->factory->createQueue($channel);
    $queue->setName($this->options['queue']);
    $queue->setFlags(\AMQP_DURABLE | ($this->options['queue_flags'] ?? 0));
    if (isset($this->options['queue_arguments'])) {
        $queue->setArguments($this->options['queue_arguments']);
    }
    $queue->declareQueue();

    $routingKey = $this->options['routing_key'] ?? '';
    $queue->bind($exchange->getName(), $routingKey);
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `composer test-unit -- --filter=testSetupWithMultipleQueues`
Expected: PASS

- [ ] **Step 7: Run all InfrastructureSetup tests to verify no regression**

Run: `composer test-unit -- --filter=InfrastructureSetupTest`
Expected: All pass

- [ ] **Step 8: Commit**

```bash
git add src/InfrastructureSetup.php tests/Unit/InfrastructureSetupTest.php
git commit -m "feat: implement multi-queue setup in InfrastructureSetup"
```

---

### Task 4: Add backward compatibility test

**Files:**
- Test: `tests/Unit/InfrastructureSetupTest.php`

- [ ] **Step 1: Write test for single-queue backward compatibility**

Add to `tests/Unit/InfrastructureSetupTest.php`:

```php
public function testSetupWithSingleQueueWhenQueuesNotProvided(): void
{
    $this->connection
        ->expects($this->exactly(3))
        ->method('getChannel')
        ->willReturn($this->channel);

    $this->factory
        ->method('createExchange')
        ->willReturnOnConsecutiveCalls($this->exchange, $this->retryExchange);

    $this->factory
        ->method('createQueue')
        ->willReturnOnConsecutiveCalls($this->queue, $this->retryQueue);

    $this->exchange->method('getName')->willReturn('test_exchange');

    // Should use legacy single-queue logic
    $this->queue->expects($this->once())->method('setName')->with('test_queue');
    $this->queue->expects($this->once())->method('setArguments')->with(['x-max-priority' => 10]);
    $this->queue->expects($this->once())->method('declareQueue');
    $this->queue->expects($this->once())->method('bind')->with('test_exchange', 'test_key');

    $this->retryExchange->method('setName');
    $this->retryExchange->method('setType');
    $this->retryExchange->method('declareExchange');

    $this->retryQueue->method('setName');
    $this->retryQueue->method('setFlags');
    $this->retryQueue->method('setArguments');
    $this->retryQueue->method('declareQueue');
    $this->retryQueue->method('bind');

    $options = [
        'exchange' => 'test_exchange',
        'queue' => 'test_queue',
        'routing_key' => 'test_key',
        'queue_arguments' => ['x-max-priority' => 10],
    ];

    $setup = new InfrastructureSetup($this->factory, $this->connection, $options);
    $setup->setup();
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `composer test-unit -- --filter=testSetupWithSingleQueueWhenQueuesNotProvided`
Expected: PASS (existing tests already cover this behavior)

- [ ] **Step 3: Run full unit test suite**

Run: `composer test-unit`
Expected: All pass

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/InfrastructureSetupTest.php
git commit -m "test: add backward compatibility test for single-queue mode"
```

---

### Task 5: Run linters and final verification

- [ ] **Step 1: Run PHPStan**

Run: `composer phpstan`
Expected: No errors

- [ ] **Step 2: Run Rector**

Run: `composer rector`
Expected: No changes needed

- [ ] **Step 3: Run PHP CS Fixer**

Run: `composer lint:fix`
Expected: No changes needed (or auto-fixed)

- [ ] **Step 4: Run full test suite**

Run: `composer test-unit`
Expected: All pass

- [ ] **Step 5: Final commit if lint:fix made changes**

```bash
git add -A
git commit -m "style: apply lint fixes"
```

---

## Self-Review

### Spec Coverage Check

| Spec Requirement | Task |
|-----------------|------|
| DSN parsing for `queues[name][arguments][key]=value` | Task 1 |
| `arguments` added to queues shape in return type | Task 1 |
| `setupQueues()` method | Task 3 |
| If `queues` exists → iterate and declare each queue | Task 3 |
| If `queues` NOT exists → legacy single queue logic | Task 3, Task 4 |
| Retry queue created once (name from `queue` option) | Task 3 (unchanged - uses `options['queue']`) |
| Validation: empty queues → exception | Task 2 |
| Validation: empty binding_keys → exception | Task 2 |
| Test: multiple queues with binding keys | Task 3 |
| Test: multiple queues with arguments | Task 3 |
| Test: backward compat (single queue) | Task 4 |
| Test: empty queues validation | Task 2 |
| Test: empty binding_keys validation | Task 2 |
| Test: DSN parsing with arguments | Task 1 |

### Placeholder Scan
- ✅ No TBD, TODO, "implement later"
- ✅ All error handling specified with exact messages
- ✅ All tests have complete code
- ✅ No "Similar to Task N" references
- ✅ All types and method signatures consistent

### Type Consistency
- ✅ `queues` shape consistent across DsnParser return type and InfrastructureSetup param type
- ✅ `binding_keys` always `list<string>`
- ✅ `arguments` always `array<string, mixed>`
- ✅ Method names: `setupQueues()`, `setupSingleQueue()`, `validateQueues()`
