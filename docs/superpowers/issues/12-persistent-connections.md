# Issue #12: Persistent Connections

> **Phase:** [Phase 4: Production Ready](../phases/phase4-production-ready.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** Reuses connections across requests. Reduces connection overhead.

**Business value:** Better performance in high-throughput scenarios. Less connection churn.

## Implementation in Symfony

- `persistent: true` option
- Uses `pconnect()` instead of `connect()`
- `pdisconnect()` for cleanup

## Current State in the-consoomer

❌ **Not implemented.**

Current code in `AmqpTransport::create()`:
```php
$connection = new \AMQPConnection();
$connection->setHost($info['host']);
$connection->setPort($info['port']);
$connection->setVhost($mergedOptions['vhost']);
$connection->setLogin($info['user']);
$connection->setPassword($info['pass']);
$connection->setReadTimeout((float) ($mergedOptions['timeout'] ?? 0.1));
$connection->connect();
// No persistent connection support
```

## Implementation Notes

### Requirements

1. `persistent` option (boolean)
2. Use `pconnect()` when persistent is true
3. Use `pdisconnect()` for cleanup
4. Connection pooling/lifecycle management

### Configuration

```
amqp-consoomer://user:pass@host:5672/vhost/exchange?persistent=true
```

### Usage in Current Codebase

**Before (no persistent connections):**
```php
// AmqpTransport::create()
$connection = new \AMQPConnection();
$connection->setHost($info['host']);
$connection->setPort($info['port']);
$connection->setVhost($mergedOptions['vhost']);
$connection->setLogin($info['user']);
$connection->setPassword($info['pass']);
$connection->setReadTimeout((float) ($mergedOptions['timeout'] ?? 0.1));
$connection->connect();
// New connection on each request
```

**After (with persistent connections):**
```php
// AmqpTransport::create()
$connection = new \AMQPConnection();
$connection->setHost($info['host']);
$connection->setPort($info['port']);
$connection->setVhost($mergedOptions['vhost']);
$connection->setLogin($info['user']);
$connection->setPassword($info['pass']);
$connection->setReadTimeout((float) ($mergedOptions['timeout'] ?? 0.1));

if ($mergedOptions['persistent'] ?? false) {
    $connection->pconnect();
} else {
    $connection->connect();
}
```

### Connection Pooling

- **Pool size**: Configurable via `persistent_pool_size` option
- **Pool key**: Based on DSN hash
- **Pool cleanup**: Automatic cleanup on close
- **Pool sharing**: Connections shared across requests

### Lifecycle Management

- **pconnect()**: Creates or reuses persistent connection
- **pdisconnect()**: Closes persistent connection
- **Connection reuse**: Same connection across requests
- **Connection cleanup**: Automatic cleanup on shutdown

### Validation

- **persistent**: Must be boolean
- **persistent_pool_size**: Must be positive integer

### Error Handling

- Throw `\AMQPConnectionException` if pconnect() fails
- Throw `\AMQPConnectionException` if pdisconnect() fails
- Log persistent connection creation
- Log persistent connection reuse
- Log persistent connection cleanup

### Logging

- Log persistent connection creation: "Created persistent connection"
- Log persistent connection reuse: "Reusing persistent connection"
- Log persistent connection cleanup: "Cleaned up persistent connection"
- Log persistent connection error: "Persistent connection error: {error_message}"

### Metrics

- **Connection count**: Number of persistent connections
- **Connection reuse count**: Number of connection reuses
- **Connection pool size**: Current pool size
- **Connection creation time**: Time to create connection

### Performance Considerations

- Persistent connection creation adds ~10-50ms latency
- Connection reuse adds ~0.1ms latency
- Connection pooling reduces connection overhead
- Connection cleanup adds ~1-5ms latency

### Security Considerations

- **Connection credentials**: Stored in memory
- **Connection pooling**: May expose credentials
- **Logging**: Don't log sensitive connection information
- **Permissions**: No special permissions needed

### Backward Compatibility

- **Breaking change**: New persistent option
- **Migration path**: Existing code works without changes
- **New behavior**: Persistent connections disabled by default
- **Configuration**: All persistent options have default values

### Testing Strategy

**Unit Tests:**
- Test persistent connection creation with mocked AMQP objects
- Test persistent connection reuse with mocked AMQP objects
- Test persistent connection cleanup with mocked AMQP objects
- Test error handling

**Integration Tests:**
- Test with real RabbitMQ using Docker
- Test persistent connection creation
- Test persistent connection reuse
- Test persistent connection cleanup

**E2E Tests:**
- Full publish/consume cycle with persistent connections
- Test message flow works end-to-end
- Test connection reuse

### Implementation Checklist

- [ ] Add `persistent` option to Connection
- [ ] Use `pconnect()` instead of `connect()` when enabled
- [ ] Track persistent connections
- [ ] Add proper cleanup with `pdisconnect()`
- [ ] Handle connection persistence across requests
- [ ] Add connection pooling
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
- Phase 2: Transport Setup/Close (#17) for lifecycle management
