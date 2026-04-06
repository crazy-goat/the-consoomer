# Issue #1: Infrastructure Auto-Setup

> **Phase:** [Phase 1: Foundation & DX](../phases/phase1-foundation.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** Automatically creates exchanges, queues, and bindings when the transport starts. No manual RabbitMQ configuration needed.

**Business value:** Developers don't need to manually configure RabbitMQ. The transport self-configures on first use.

## Implementation in Symfony

- `Connection::setup()` — creates exchange, queues, bindings
- `Connection::setupExchangeAndQueues()` — declares exchange and binds queues
- `Connection::setupDelayExchange()` — creates delay exchange for retries
- Triggered automatically on first `get()` or `publish()` when `auto_setup: true`

## Current State in the-consoomer

❌ **Not implemented.** User must manually create queues and exchanges.

Current code in `AmqpTransport::create()`:
```php
$connection = new \AMQPConnection();
$connection->setHost($info['host']);
// ... connection setup
// No exchange/queue creation
```

## Implementation Notes

### Requirements

1. `auto_setup` option (default: `true`)
2. `AmqpTransport::setup()` method that creates:
   - Exchange (if not exists)
   - Queue(s) with bindings
   - Delay exchange for retries (future)

### DSN Options

```
amqp-consoomer://user:pass@host:5672/vhost/exchange?queue=my_queue&auto_setup=true
```

### Usage in Current Codebase

**Before (manual setup):**
```php
// User must manually create exchange and queue in RabbitMQ
$transport = AmqpTransport::create($dsn, [], $serializer);
$transport->send($envelope); // Fails if exchange/queue don't exist
```

**After (auto setup):**
```php
// Exchange and queue created automatically
$transport = AmqpTransport::create($dsn, [], $serializer);
$transport->send($envelope); // Works - exchange/queue created on first use
```

### Lifecycle Management

- Setup is **lazy** - triggered on first `get()` or `publish()`
- Setup is **idempotent** - can be called multiple times safely
- Setup is **thread-safe** - uses connection-level locking
- Setup is **cached** - only runs once per transport instance

### Idempotency

- `AMQPExchange::declareExchange()` is idempotent - no error if exchange exists
- `AMQPQueue::declareQueue()` is idempotent - no error if queue exists
- `AMQPQueue::bind()` is idempotent - no error if binding exists
- Setup can be called multiple times without side effects

### Error Handling

- Throw `\AMQPExchangeException` if exchange creation fails
- Throw `\AMQPQueueException` if queue creation fails
- Throw `\AMQPQueueException` if binding creation fails
- Log errors but don't fail - allow retry on next operation

### Logging

- Log exchange creation: "Created exchange: {exchange_name}"
- Log queue creation: "Created queue: {queue_name}"
- Log binding creation: "Bound queue {queue_name} to exchange {exchange_name} with key {routing_key}"
- Log errors: "Failed to create exchange: {error_message}"

### Performance Considerations

- Setup adds ~10-50ms latency on first operation
- Subsequent operations have no overhead
- Setup is cached - only runs once per transport instance
- No performance impact on existing operations

### Security Considerations

- Requires `configure` permission on vhost
- Requires `write` permission on exchange
- Requires `configure` permission on queue
- Requires `write` permission on queue for binding

### Backward Compatibility

- **Breaking change**: New `auto_setup` option (default: `true`)
- **Migration path**: Set `auto_setup=false` to disable
- **Existing code**: Works without changes (auto_setup enabled by default)
- **New behavior**: Exchange/queue created automatically on first use

### Testing Strategy

**Unit Tests:**
- Test setup with mocked AMQP objects
- Test idempotency - multiple setup calls
- Test error handling - exchange/queue creation failures
- Test logging - verify log messages

**Integration Tests:**
- Test with real RabbitMQ using Docker
- Test exchange/queue creation
- Test binding creation
- Test idempotency with real RabbitMQ

**E2E Tests:**
- Full publish/consume cycle with auto-setup
- Test message flow works end-to-end
- Test setup on first get() and publish()

### Implementation Checklist

- [ ] Add `auto_setup` option parsing
- [ ] Implement `AmqpTransport::setup()` method
- [ ] Implement `AmqpTransport::setupExchangeAndQueues()`
- [ ] Trigger setup on first `get()` or `publish()` (lazy)
- [ ] Add idempotency checks
- [ ] Add error handling
- [ ] Add logging
- [ ] Add unit tests with mocked AMQP objects
- [ ] Add integration tests with Docker
- [ ] Add E2E tests with full message flow
- [ ] Add documentation

## Dependencies

- Phase 1: Factory Pattern (#18) for testability
- Phase 1: Full DSN Parsing (#19) for option handling
