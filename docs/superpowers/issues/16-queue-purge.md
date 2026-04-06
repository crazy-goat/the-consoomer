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

### Implementation Checklist

- [ ] Implement `Connection::purgeQueues()`
- [ ] Implement `Receiver::purgeQueue()`
- [ ] Return count of purged messages
- [ ] Add tests with RabbitMQ
- [ ] Add documentation

## Dependencies

- Phase 1: Factory Pattern (#18) for Connection class
