# Issue #18: Factory Pattern

> **Phase:** [Phase 1: Foundation & DX](../phases/phase1-foundation.md)  
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

Current code uses `new \AMQPConnection()`, `new \AMQPChannel()`, `new \AMQPQueue()`, `new \AMQPExchange()` directly in:
- `AmqpTransport::create()` - creates `\AMQPConnection`
- `Receiver::connect()` - creates `\AMQPChannel` and `\AMQPQueue`
- `Sender::connect()` - creates `\AMQPChannel` and `\AMQPExchange`

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

### Usage in Current Codebase

**Before (direct instantiation):**
```php
// AmqpTransport::create()
$connection = new \AMQPConnection();
$connection->setHost($info['host']);
// ...

// Receiver::connect()
$channel = new \AMQPChannel($this->connection);
$this->queue = new \AMQPQueue($channel);

// Sender::connect()
$this->exchange = new \AMQPExchange(new \AMQPChannel($this->connection));
```

**After (factory pattern):**
```php
// AmqpTransport::create()
$factory = new AmqpFactory();
$connection = $factory->createConnection();
$connection->setHost($info['host']);
// ...

// Receiver::connect()
$channel = $this->factory->createChannel($this->connection);
$this->queue = $this->factory->createQueue($channel);

// Sender::connect()
$this->exchange = $this->factory->createExchange($this->factory->createChannel($this->connection));
```

### Lifecycle Management

- Factory creates **new instances** each time (no caching)
- Each `create*()` method returns a fresh object
- Connection lifecycle managed by `AmqpTransport`, `Receiver`, `Sender`
- Factory is stateless - can be reused across requests

### Error Handling

- Factory methods throw `\AMQPException` on creation failure
- No custom exception handling in factory itself
- Caller (AmqpTransport, Receiver, Sender) handles exceptions

### Backward Compatibility

- **Breaking change**: `AmqpTransport::create()` now requires `AmqpFactory` parameter
- **Migration path**: Pass `new AmqpFactory()` as third parameter
- **Alternative**: Make factory optional with default `new AmqpFactory()`

### Testing Strategy

**Unit Tests:**
- Mock `AmqpFactory` to test `Receiver`, `Sender`, `AmqpTransport` in isolation
- Test each class independently without real RabbitMQ
- Verify correct factory method calls

**Integration Tests:**
- Test with real `AmqpFactory` and Docker RabbitMQ
- Verify objects are created correctly
- Test connection, channel, queue, exchange creation

**E2E Tests:**
- Full publish/consume cycle with factory-created objects
- Verify message flow works end-to-end

### Implementation Checklist

- [ ] Create `AmqpFactory` class
- [ ] Inject factory into `AmqpTransport::create()`
- [ ] Refactor `Receiver::connect()` to use factory
- [ ] Refactor `Sender::connect()` to use factory
- [ ] Add unit tests with mocked factory
- [ ] Add integration tests with real factory
- [ ] Add E2E tests with full message flow
- [ ] Add documentation

## Dependencies

- None (this is a foundational change)

## Note

This is the **foundation for all other features** - without testable architecture, implementing and testing connection resilience (retry, heartbeat, TLS) would be extremely difficult.
