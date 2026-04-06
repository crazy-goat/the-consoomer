# Issue #16: Queue Purge

> **Phase:** [Phase 4: Production Ready](../phases/phase4-production-ready.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** Removes all messages from queues.

**Business value:** Clear stuck messages during development/testing. Reset queue state.

## Implementation in Symfony

- `Connection::purgeQueues()` — calls `purge()` on each queue

## Current State in the-consoomer

❌ **Not implemented.**

Current code in `Receiver`:
```php
class Receiver implements ReceiverInterface
{
    // No purgeQueue() method
}
```

## Implementation Notes

### Requirements

1. `Connection::purgeQueues()` method
2. `Receiver::purgeQueue()` method (optional convenience)
3. Use `AMQPQueue::purge()` for actual purge

### Interface

```php
class Receiver implements ReceiverInterface, MessageCountAwareInterface
{
    public function purgeQueue(?string $queueName = null): int;
}
```

### Usage in Current Codebase

**Before (no queue purge):**
```php
// No way to purge queue
```

**After (with queue purge):**
```php
// Receiver::purgeQueue()
public function purgeQueue(?string $queueName = null): int
{
    $queue = new \AMQPQueue($this->channel);
    $queue->setName($queueName ?? $this->options['queue']);
    return $queue->purge();
}
```

### Queue Purge Implementation

```php
public function purgeQueue(?string $queueName = null): int
{
    $queue = new \AMQPQueue($this->channel);
    $queue->setName($queueName ?? $this->options['queue']);
    return $queue->purge();
}
```

### Validation

- **queueName**: Must be non-empty string or null
- **channel**: Must be valid AMQPChannel

### Error Handling

- Throw `\AMQPException` if queue purge fails
- Return 0 if queue doesn't exist
- Log queue purge
- Log queue purge error

### Logging

- Log queue purge: "Purged {count} messages from queue {queue_name}"
- Log queue purge error: "Failed to purge queue {queue_name}: {error_message}"

### Metrics

- **Purge count**: Number of purges
- **Purged messages**: Total messages purged
- **Purge success rate**: Percentage of successful purges

### Performance Considerations

- Queue purge adds ~1-10ms latency
- No performance impact on message flow
- Queue purge is immediate
- Queue purge is irreversible

### Security Considerations

- **Queue purge**: Removes all messages
- **Logging**: Don't log sensitive queue information
- **Permissions**: Requires purge permissions

### Backward Compatibility

- **Breaking change**: New purge method
- **Migration path**: Existing code works without changes
- **New behavior**: Queue purge is optional
- **Configuration**: No configuration changes needed

### Testing Strategy

**Unit Tests:**
- Test queue purge with mocked AMQP objects
- Test error handling
- Test return value

**Integration Tests:**
- Test with real RabbitMQ using Docker
- Test queue purge
- Test message count after purge

**E2E Tests:**
- Full publish/consume cycle with queue purge
- Test message flow works end-to-end
- Test queue purge effect

### Implementation Checklist

- [ ] Implement `Receiver::purgeQueue()`
- [ ] Return count of purged messages
- [ ] Add validation
- [ ] Add error handling
- [ ] Add logging
- [ ] Add metrics
- [ ] Add unit tests
- [ ] Add integration tests with Docker
- [ ] Add E2E tests with full message flow
- [ ] Add documentation

## Dependencies

- Phase 1: Factory Pattern (#18) for testability
