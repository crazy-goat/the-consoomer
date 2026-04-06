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

### Implementation Checklist

- [ ] Create `MessageCountAwareInterface`
- [ ] Implement `Connection::countMessagesInQueues()`
- [ ] Implement `Receiver::getMessageCount()`
- [ ] Update Receiver to implement interface
- [ ] Add tests
- [ ] Add documentation

## Dependencies

- Phase 1: Factory Pattern (#18) for Connection class
- Phase 5: Multiple Queues (#4) for multi-queue count support
