# Issue #15: Message Count

> **Phase:** [Phase 4: Production Ready](../phases/phase4-production-ready.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** Returns approximate number of messages in queues.

**Business value:** Monitoring queue depth. Scaling decisions based on backlog.

## Implementation in Symfony

- `MessageCountAwareInterface` — interface for message count
- `Connection::countMessagesInQueues()` — sum of `declareQueue()` results
- `AmqpReceiver::getMessageCount()` — exposes count
- `messenger:stats` command uses this

## Current State in the-consoomer

❌ **Not implemented.**

Current code in `Receiver`:
```php
class Receiver implements ReceiverInterface
{
    // No getMessageCount() method
}
```

## Implementation Notes

### Requirements

1. `MessageCountAwareInterface` interface
2. `Connection::countMessagesInQueues()` method
3. `Receiver::getMessageCount()` method

### Interface

```php
interface MessageCountAwareInterface
{
    public function getMessageCount(): int;
}
```

### Usage in Current Codebase

**Before (no message count):**
```php
// No way to get message count
```

**After (with message count):**
```php
// Receiver implements MessageCountAwareInterface
class Receiver implements ReceiverInterface, MessageCountAwareInterface
{
    public function getMessageCount(): int
    {
        $queue = new \AMQPQueue($this->channel);
        $queue->setName($this->options['queue']);
        $queue->declareQueue();
        return $queue->declareQueue();
    }
}
```

### Message Count Implementation

```php
public function getMessageCount(): int
{
    $queue = new \AMQPQueue($this->channel);
    $queue->setName($this->options['queue']);
    $queue->declareQueue();
    return $queue->declareQueue();
}
```

### Validation

- **queue**: Must be non-empty string
- **channel**: Must be valid AMQPChannel

### Error Handling

- Throw `\AMQPException` if queue declaration fails
- Return 0 if queue doesn't exist
- Log message count retrieval
- Log message count error

### Logging

- Log message count: "Queue {queue_name} has {count} messages"
- Log message count error: "Failed to get message count: {error_message}"

### Metrics

- **Message count**: Number of messages in queue
- **Queue depth**: Queue depth over time
- **Count retrieval time**: Time to get message count

### Performance Considerations

- Message count retrieval adds ~1-5ms latency
- No performance impact on message flow
- Message count is approximate
- Message count is cached briefly

### Security Considerations

- **Message count**: May reveal queue activity
- **Logging**: Don't log sensitive queue information
- **Permissions**: Requires read permissions

### Backward Compatibility

- **Breaking change**: New interface
- **Migration path**: Existing code works without changes
- **New behavior**: Message count is optional
- **Configuration**: No configuration changes needed

### Testing Strategy

**Unit Tests:**
- Test message count with mocked AMQP objects
- Test error handling
- Test interface implementation

**Integration Tests:**
- Test with real RabbitMQ using Docker
- Test message count retrieval
- Test message count accuracy

**E2E Tests:**
- Full publish/consume cycle with message count
- Test message flow works end-to-end
- Test message count updates

### Implementation Checklist

- [ ] Create `MessageCountAwareInterface`
- [ ] Implement `Receiver::getMessageCount()`
- [ ] Update Receiver to implement interface
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
- Phase 5: Multiple Queues (#4) for multi-queue count support
