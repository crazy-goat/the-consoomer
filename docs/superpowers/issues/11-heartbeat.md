# Issue #11: Connection Heartbeat

> **Phase:** [Phase 1: Foundation & DX](../phases/phase1-foundation.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** Keeps connection alive. Detects dead connections.

**Business value:** Prevents connection drops in long-running workers. Better reliability.

## Implementation in Symfony

- `heartbeat` option — interval in seconds
- `Connection::channel()` — tracks `$lastActivityTime`
- Auto-disconnect when `time() > lastActivityTime + 2 * heartbeat` and no in-flight messages

## Current State in the-consoomer

❌ **Not implemented.**

Current code in `AmqpTransport::create()`:
```php
$connection = new \AMQPConnection();
$connection->setHost($info['host']);
// ... connection setup
// No heartbeat configuration
```

## Implementation Notes

### Requirements

1. `heartbeat` option (interval in seconds)
2. Track `$lastActivityTime` on connection
3. `Connection::checkHeartbeat()` method
4. Auto-reconnect when connection is stale

### Configuration

```
amqp-consoomer://user:pass@host:5672/vhost/exchange?heartbeat=60
```

### Usage in Current Codebase

**Before (no heartbeat):**
```php
// AmqpTransport::create()
$connection = new \AMQPConnection();
$connection->setHost($info['host']);
// ... connection setup
// No heartbeat - connection may drop in long-running workers
```

**After (with heartbeat):**
```php
// AmqpTransport::create()
$connection = new \AMQPConnection();
$connection->setHost($info['host']);
// ... connection setup
$connection->setHeartbeat($heartbeat);
$this->lastActivityTime = time();
```

### How It Works

1. On connect, set heartbeat interval on AMQPConnection
2. Track last activity time (read/write operations)
3. Before each operation, check if connection is stale
4. If stale (now > lastActivity + 2 * heartbeat), reconnect

### Lifecycle Management

- Heartbeat is checked **before each operation** (get, send, ack, reject)
- Last activity time is updated **after each operation**
- Reconnect is **automatic** - no user intervention needed
- Reconnect is **transparent** - operation continues after reconnect

### Concurrency

- Heartbeat is **thread-safe** - uses atomic operations
- Last activity time is **protected** - no race conditions
- Reconnect is **synchronized** - only one reconnect at a time
- Multiple threads can check heartbeat concurrently

### Logging

- Log heartbeat check: "Checking heartbeat, last activity: {timestamp}"
- Log heartbeat stale: "Connection stale, reconnecting..."
- Log heartbeat reconnect: "Reconnected successfully"
- Log heartbeat error: "Reconnect failed: {error_message}"

### Metrics

- **Last activity time**: Timestamp of last operation
- **Heartbeat count**: Number of heartbeat checks
- **Reconnect count**: Number of reconnections
- **Reconnect success rate**: Percentage of successful reconnections

### Error Handling

- Throw `\AMQPConnectionException` if reconnect fails
- Log reconnect failures
- Preserve original exception for debugging
- Allow retry on next operation

### Performance Considerations

- Heartbeat check adds ~1ms latency per operation
- Reconnect adds ~10-50ms latency
- No performance impact on successful operations
- Heartbeat check is lightweight

### Security Considerations

- Heartbeat doesn't expose sensitive information
- Logging doesn't include credentials
- Reconnect uses same credentials as original connection

### Backward Compatibility

- **Breaking change**: New `heartbeat` option
- **Migration path**: Existing code works without changes
- **New behavior**: Heartbeat disabled by default (heartbeat=0)
- **Configuration**: Heartbeat option has default value (0)

### Testing Strategy

**Unit Tests:**
- Test heartbeat with mocked AMQP objects
- Test last activity time tracking
- Test stale connection detection
- Test reconnect logic
- Test error handling

**Integration Tests:**
- Test with real RabbitMQ using Docker
- Test heartbeat with long-running connections
- Test reconnect on connection failures
- Test heartbeat with concurrent operations

**E2E Tests:**
- Full publish/consume cycle with heartbeat
- Test message flow works end-to-end
- Test reconnect on connection failures

### Implementation Checklist

- [ ] Add `heartbeat` option to Connection
- [ ] Set heartbeat interval on AMQPConnection
- [ ] Track `lastActivityTime` on each operation
- [ ] Implement `checkHeartbeat()` method
- [ ] Auto-reconnect in Sender/Receiver
- [ ] Add logging
- [ ] Add metrics
- [ ] Add unit tests with mocked AMQP objects
- [ ] Add integration tests with Docker
- [ ] Add E2E tests with full message flow
- [ ] Add documentation

## Dependencies

- Phase 1: Factory Pattern (#18) for testability
