# Issue #4: Multiple Queues per Transport

> **Phase:** [Phase 5: Advanced Features](../phases/phase5-advanced-features.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** One transport can consume from multiple queues with different configurations.

**Business value:** Single worker can process messages from multiple queues. Enables priority queues, different message types per queue.

## Implementation in Symfony

- `Connection::$queuesOptions` — array of queue configurations
- `Connection::getQueueNames()` — returns all queue names
- `AmqpReceiver::getFromQueues()` — fetches from specific queues
- `QueueReceiverInterface` — allows `--queues` CLI option

## Current State in the-consoomer

❌ **Only single queue via `queue` option.**

Current code in `Receiver::connect()`:
```php
$this->queue = new \AMQPQueue($channel);
$this->queue->setName($this->options['queue']);
$this->queue->consume();
// Only single queue support
```

## Implementation Notes

### Requirements

1. `queues` option (array of queue configurations)
2. `Connection::getQueueNames()` method
3. Update `Receiver` to handle multiple queues
4. Implement `QueueReceiverInterface`

### DSN Options

```
amqp-consoomer://user:pass@host:5672/vhost/exchange?queues[]=queue1&queues[]=queue2
```

Or via options array:
```php
[
    'queues' => [
        'queue1' => ['binding_keys' => ['key1', 'key2']],
        'queue2' => ['binding_keys' => ['key3']],
    ]
]
```

### Usage in Current Codebase

**Before (single queue):**
```php
// Receiver::connect()
$this->queue = new \AMQPQueue($channel);
$this->queue->setName($this->options['queue']);
$this->queue->consume();
// Only one queue
```

**After (multiple queues):**
```php
// Receiver::connect()
$this->queues = [];
foreach ($this->options['queues'] as $queueName => $queueConfig) {
    $queue = new \AMQPQueue($channel);
    $queue->setName($queueName);
    $queue->declareQueue();
    $this->setupQueueBindings($queue, $queueConfig);
    $queue->consume();
    $this->queues[$queueName] = $queue;
}
```

### Multiple Queue Implementation

```php
private function connect(): void
{
    if (!empty($this->queues)) {
        return;
    }
    
    $channel = new \AMQPChannel($this->connection);
    $channel->qos(0, $this->maxUnackedMessages);
    
    foreach ($this->options['queues'] as $queueName => $queueConfig) {
        $queue = new \AMQPQueue($channel);
        $queue->setName($queueName);
        $queue->declareQueue();
        $this->setupQueueBindings($queue, $queueConfig);
        $queue->consume();
        $this->queues[$queueName] = $queue;
    }
}
```

### Queue Configuration

```php
[
    'queues' => [
        'queue1' => [
            'binding_keys' => ['key1', 'key2'],
            'binding_arguments' => ['x-match' => 'any'],
        ],
        'queue2' => [
            'binding_keys' => ['key3'],
        ],
    ]
]
```

### Validation

- **queues**: Must be array
- **queue names**: Must be non-empty strings
- **binding_keys**: Must be array of strings
- **binding_arguments**: Must be array

### Error Handling

- Throw `\InvalidArgumentException` for invalid queues configuration
- Throw `\AMQPException` if queue creation fails
- Throw `\AMQPException` if binding creation fails
- Log queue creation
- Log binding creation
- Log error

### Logging

- Log queue creation: "Created queue: {queue_name}"
- Log binding creation: "Bound queue {queue_name} with keys: {binding_keys}"
- Log error: "Failed to create queue {queue_name}: {error_message}"

### Metrics

- **Queue count**: Number of queues
- **Binding count**: Number of bindings per queue
- **Message count**: Messages per queue
- **Queue creation time**: Time to create queues

### Performance Considerations

- Multiple queue creation adds ~10-50ms latency per queue
- No performance impact on message flow
- Queues are persistent
- Bindings are persistent

### Security Considerations

- **Queue configuration**: May contain sensitive information
- **Logging**: Don't log sensitive queue information
- **Permissions**: Requires configure/write permissions

### Backward Compatibility

- **Breaking change**: New queues option (replaces queue)
- **Migration path**: Update queue to queues array
- **New behavior**: Multiple queues supported
- **Configuration**: All queue options have default values

### Testing Strategy

**Unit Tests:**
- Test multiple queue creation with mocked AMQP objects
- Test queue configuration validation
- Test error handling

**Integration Tests:**
- Test with real RabbitMQ using Docker
- Test multiple queue creation
- Test message routing to multiple queues
- Test queue bindings

**E2E Tests:**
- Full publish/consume cycle with multiple queues
- Test message flow works end-to-end
- Test queue routing

### Implementation Checklist

- [ ] Add `queues` option parsing (replaces single `queue`)
- [ ] Update Connection for multiple queues
- [ ] Implement `getQueueNames()` method
- [ ] Update Receiver to consume from multiple queues
- [ ] Implement `getFromQueues()` method
- [ ] Add CLI option support if applicable
- [ ] Add validation
- [ ] Add error handling
- [ ] Add logging
- [ ] Add metrics
- [ ] Add unit tests
- [ ] Add integration tests with Docker
- [ ] Add E2E tests with full message flow
- [ ] Add documentation

## Dependencies

- Phase 1: Auto-Setup (#1) for queue creation
- Phase 2: Full AmqpStamp (#7) for routing control
- Phase 3: Queue Bindings (#5) for binding configuration
