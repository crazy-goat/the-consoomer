# Design: Multiple Queues and Exchange Types in Auto-Setup (Issue #35)

**Date:** 2026-04-23
**Issue:** [#35](https://github.com/crazy-goat/the-consoomer/issues/35)
**Milestone:** 0.3.0

## Overview

Extend `InfrastructureSetup` to support multiple queues with different binding keys per transport, different exchange types (already supported), and queue arguments (x-max-priority, x-message-ttl, etc.).

## Architecture

### DSN Format

```
amqp-consoomer://guest:guest@localhost/%2f/my_exchange?queue=primary_queue&queues[orders][binding_keys][0]=order.*&queues[orders][arguments][x-max-priority]=10&queues[notifications][binding_keys][0]=notification.*&queues[notifications][arguments][x-message-ttl]=60000&exchange_type=topic
```

### Queues Option Structure

```php
[
    'orders' => [
        'binding_keys' => ['order.created', 'order.updated'],
        'arguments' => ['x-max-priority' => 10],
    ],
    'notifications' => [
        'binding_keys' => ['notification.*'],
        'arguments' => ['x-message-ttl' => 60000],
    ],
]
```

### Backward Compatibility

- `queue` = primary queue (existing behavior preserved)
- If `queues` not provided → single queue from `queue` option (current behavior)
- If `queues` provided → all queues declared, `queue` = primary (first)
- Retry queue: single retry queue per transport, named `{primary_queue}_retry`

## Implementation

### DsnParser.php

- Add parsing for `queues[name][arguments][key]=value` syntax
- Current parsing already handles `queues[name][binding_keys][]`
- Add `arguments` to the queues shape in return type

### InfrastructureSetup.php

**Updated options shape:**
```php
[
    'exchange' => string,
    'queue' => string,
    'queues' => array<string, array{
        binding_keys?: list<string>,
        arguments?: array<string, mixed>,
    }>,
    'exchange_type' => string,
    'routing_key' => string,
    'queue_arguments' => array<string, mixed>,
    'exchange_flags' => int,
    'queue_flags' => int,
    'exchange_bindings' => array<array{target: string, routing_keys?: list<string>}>,
    'retry_exchange' => string,
    'retry_queue_arguments' => array<string, mixed>,
]
```

**Setup flow:**
1. Declare exchange (no change)
2. If `queues` exists → iterate and declare each queue with binding_keys and arguments
3. If `queues` NOT exists → use legacy logic (single queue from `queue` + `queue_arguments`)
4. Retry queue created once (name from `queue` option)

**New method `setupQueues()`:**
```php
private function setupQueues(\AMQPExchange $exchange): void
{
    $queues = $this->options['queues'] ?? [];
    
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

### Validation

- If `queues` provided but empty → `InvalidArgumentException`
- `binding_keys` cannot be empty array
- `arguments` is optional per queue

## Tests

### Unit Tests (InfrastructureSetupTest.php)

1. `testSetupWithMultipleQueuesAndBindingKeys` - declares 2 queues with different binding keys
2. `testSetupWithMultipleQueuesAndArguments` - queues with x-max-priority, x-message-ttl
3. `testSetupWithSingleQueueWhenQueuesNotProvided` - backward compat (legacy logic)
4. `testSetupThrowsWhenQueuesIsEmptyArray` - validation for empty queues
5. `testSetupWithEmptyBindingKeysThrows` - validation for empty binding_keys

### Unit Tests (DsnParserTest.php)

1. `testParsesQueuesWithArguments` - `queues[name][arguments][x-max-priority]=10`
2. `testParsesQueuesWithMultipleBindingKeysAndArguments` - full format

### E2E Tests

1. `testMultipleQueuesWithTopicExchange` - topic exchange + 2 queues with wildcard bindings
2. `testQueueArgumentsApplied` - x-max-priority works
