# Issue #17: Transport Setup/Close

> **Phase:** [Phase 2: Core Messaging](../phases/phase2-core-messaging.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** Explicit setup and teardown of transport resources.

**Business value:** Control over when infrastructure is created. Clean shutdown.

## Implementation in Symfony

- `SetupableTransportInterface` — `setup()` method
- `CloseableTransportInterface` — `close()` method
- `AmqpTransport::setup()` — creates exchanges/queues
- `AmqpTransport::close()` — clears connection

## Current State in the-consoomer

❌ **Not implemented.**

Current code in `src/AmqpTransport.php`:
```php
class AmqpTransport implements TransportInterface, TransportFactoryInterface
{
    public function __construct(
        private readonly ReceiverInterface $receiver,
        private readonly SenderInterface $sender,
    ) {
    }
    
    // No setup() or close() methods
}
```

## Implementation Notes

### Requirements

1. `SetupableTransportInterface` interface
2. `CloseableTransportInterface` interface
3. `AmqpTransport::setup()` method
4. `AmqpTransport::close()` method

### Interfaces

```php
interface SetupableTransportInterface
{
    public function setup(): void;
}

interface CloseableTransportInterface
{
    public function close(): void;
}

class AmqpTransport implements 
    TransportInterface,
    TransportFactoryInterface,
    SetupableTransportInterface,
    CloseableTransportInterface
{
    public function setup(): void;
    public function close(): void;
}
```

### Usage in Current Codebase

**Before (no lifecycle control):**
```php
$transport = AmqpTransport::create($dsn, [], $serializer);

// No explicit setup - happens lazily on first use
$transport->send($envelope);

// No explicit close - connection stays open
```

**After (with lifecycle control):**
```php
$transport = AmqpTransport::create($dsn, [], $serializer);

// Explicit setup (optional - also happens lazily)
$transport->setup();

// Use transport...
$transport->send($envelope);
$messages = $transport->get();

// Clean shutdown
$transport->close();
```

### Lifecycle Management

- **setup()**: Creates exchange, queues, bindings (idempotent)
- **close()**: Closes connection, releases resources
- **Lazy setup**: Setup happens automatically on first use if not called explicitly
- **Idempotent**: Both setup() and close() can be called multiple times

### Error Handling

- Throw `\AMQPException` if setup fails
- Throw `\AMQPException` if close fails
- Log setup errors
- Log close errors
- Allow retry on next operation

### Logging

- Log setup start: "Starting transport setup"
- Log setup success: "Transport setup completed"
- Log setup error: "Transport setup failed: {error_message}"
- Log close start: "Closing transport"
- Log close success: "Transport closed"
- Log close error: "Transport close failed: {error_message}"

### Metrics

- **Setup count**: Number of setup calls
- **Setup time**: Time to complete setup
- **Close count**: Number of close calls
- **Close time**: Time to complete close

### Performance Considerations

- Setup adds ~10-50ms latency on first operation
- Close adds ~1-5ms latency
- No performance impact on existing operations
- Setup is cached - only runs once per transport instance

### Security Considerations

- **Setup**: Requires configure/write permissions
- **Close**: No special permissions needed
- **Logging**: Don't log sensitive information

### Backward Compatibility

- **Breaking change**: New interfaces
- **Migration path**: Existing code works without changes
- **New behavior**: Setup/close are optional
- **Configuration**: No configuration changes needed

### Testing Strategy

**Unit Tests:**
- Test setup with mocked AMQP objects
- Test close with mocked AMQP objects
- Test idempotency
- Test error handling
- Test lazy setup

**Integration Tests:**
- Test with real RabbitMQ using Docker
- Test setup creates exchange/queues
- Test close releases resources
- Test idempotency with real RabbitMQ

**E2E Tests:**
- Full publish/consume cycle with setup/close
- Test message flow works end-to-end
- Test lifecycle management

### Implementation Checklist

- [ ] Create `SetupableTransportInterface`
- [ ] Create `CloseableTransportInterface`
- [ ] Implement `AmqpTransport::setup()` using Auto-Setup
- [ ] Implement `AmqpTransport::close()`
- [ ] Add idempotency
- [ ] Add error handling
- [ ] Add logging
- [ ] Add metrics
- [ ] Add unit tests
- [ ] Add integration tests with Docker
- [ ] Add E2E tests with full message flow
- [ ] Add documentation

## Dependencies

- Phase 1: Auto-Setup (#1) for setup implementation
