# Issue #5: Queue Bindings

> **Phase:** [Phase 3: Advanced Routing](../phases/phase3-advanced-routing.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** Binds queues to exchanges with specific routing keys. Enables topic/fanout routing patterns.

**Business value:** Flexible message routing. Different consumers can receive different message types from same exchange.

## Implementation in Symfony

- `queues[name][binding_keys]` — routing keys for queue binding
- `queues[name][binding_arguments]` — additional binding arguments
- `Connection::setupExchangeAndQueues()` — creates bindings during setup

## Current State in the-consoomer

❌ **No binding support.** Queue must already exist and be bound.

Current code in `Receiver::connect()`:
```php
$this->queue = new \AMQPQueue($channel);
$this->queue->setName($this->options['queue']);
$this->queue->consume();
// No binding creation
```

## Implementation Notes

### Requirements

1. `queues[name][binding_keys]` option
2. `queues[name][binding_arguments]` option
3. `Connection::setupQueueBindings()` method
4. Auto-create bindings during setup

### DSN/Options Format

```php
[
    'queues' => [
        'my_queue' => [
            'binding_keys' => ['order.created', 'order.updated', 'order.*'],
            'binding_arguments' => ['x-match' => 'any'],
        ],
    ]
]
```

### Usage in Current Codebase

**Before (no binding support):**
```php
// Receiver::connect()
$this->queue = new \AMQPQueue($channel);
$this->queue->setName($this->options['queue']);
$this->queue->consume();
// Queue must already be bound to exchange
```

**After (with binding support):**
```php
// Receiver::connect()
$this->queue = new \AMQPQueue($channel);
$this->queue->setName($this->options['queue']);
$this->queue->declareQueue();
$this->setupQueueBindings();
$this->queue->consume();

// setupQueueBindings()
private function setupQueueBindings(): void
{
    $bindingKeys = $this->options['queues'][$this->options['queue']]['binding_keys'] ?? [];
    $bindingArguments = $this->options['queues'][$this->options['queue']]['binding_arguments'] ?? [];
    
    foreach ($bindingKeys as $bindingKey) {
        $this->queue->bind($this->options['exchange'], $bindingKey, $bindingArguments);
    }
}
```

### Binding Creation

```php
private function setupQueueBindings(): void
{
    $queueName = $this->options['queue'];
    $bindingKeys = $this->options['queues'][$queueName]['binding_keys'] ?? [];
    $bindingArguments = $this->options['queues'][$queueName]['binding_arguments'] ?? [];
    
    foreach ($bindingKeys as $bindingKey) {
        $this->queue->bind($this->options['exchange'], $bindingKey, $bindingArguments);
    }
}
```

### Validation

- **binding_keys**: Must be array of strings
- **binding_arguments**: Must be array
- **queue**: Must be non-empty string
- **exchange**: Must be non-empty string

### Error Handling

- Throw `\InvalidArgumentException` for invalid binding keys
- Throw `\InvalidArgumentException` for invalid binding arguments
- Throw `\AMQPException` if binding creation fails
- Log binding creation
- Log binding error

### Logging

- Log binding creation: "Bound queue {queue_name} to exchange {exchange_name} with key {binding_key}"
- Log binding error: "Failed to bind queue {queue_name}: {error_message}"

### Metrics

- **Binding count**: Number of bindings created
- **Binding key count**: Number of binding keys per queue
- **Binding success rate**: Percentage of successful bindings

### Performance Considerations

- Binding creation adds ~1-5ms latency per binding
- No performance impact on message flow
- Bindings are persistent

### Security Considerations

- **Binding keys**: May contain sensitive information
- **Logging**: Don't log sensitive binding information
- **Permissions**: Requires configure/write permissions

### Backward Compatibility

- **Breaking change**: New binding options
- **Migration path**: Existing code works without changes
- **New behavior**: Binding is optional
- **Configuration**: All binding options have default values

### Testing Strategy

**Unit Tests:**
- Test binding creation with mocked AMQP objects
- Test binding validation
- Test error handling

**Integration Tests:**
- Test with real RabbitMQ using Docker
- Test binding creation
- Test message routing with bindings
- Test topic/fanout patterns

**E2E Tests:**
- Full publish/consume cycle with bindings
- Test message flow works end-to-end
- Test routing patterns

### Implementation Checklist

- [ ] Add binding_keys option parsing
- [ ] Add binding_arguments option parsing
- [ ] Implement `setupQueueBindings()` method
- [ ] Update auto-setup to create bindings
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
