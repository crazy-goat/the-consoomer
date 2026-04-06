# Issue #18: Factory Pattern

> **Phase:** [Phase 1: Foundation](../phases/phase1-foundation.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** Factory for creating AMQP objects. Enables mocking in tests.

**Business value:** Easier unit testing. Can mock connections, channels, queues.

## Implementation in Symfony

- `AmqpFactory` — creates `\AMQPConnection`, `\AMQPChannel`, `\AMQPQueue`, `\AMQPExchange`
- Injected into `Connection` constructor
- Used throughout codebase instead of `new` keyword

## Current State in the-consoomer

❌ **Direct instantiation. Hard to test.**

## Implementation Notes

### Requirements

1. `AmqpFactory` class with methods:
   - `createConnection(): \AMQPConnection`
   - `createChannel(\AMQPConnection $connection): \AMQPChannel`
   - `createQueue(\AMQPChannel $channel): \AMQPQueue`
   - `createExchange(\AMQPChannel $channel): \AMQPExchange`

### Interface

```php
class AmqpFactory
{
    public function createConnection(): \AMQPConnection;
    public function createChannel(\AMQPConnection $connection): \AMQPChannel;
    public function createQueue(\AMQPChannel $channel): \AMQPQueue;
    public function createExchange(\AMQPChannel $channel): \AMQPExchange;
}
```

### Implementation Checklist

- [ ] Create `AmqpFactory` class
- [ ] Inject factory into Connection class
- [ ] Refactor Receiver to use factory
- [ ] Refactor Sender to use factory
- [ ] Add tests using mocked factory
- [ ] Add documentation

## Dependencies

- None (this is a foundational change)

## Note

This is the **foundation for all other features** - without testable architecture, implementing and testing connection resilience (retry, heartbeat, TLS) would be extremely difficult.
