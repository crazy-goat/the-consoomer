# Issue #11: Connection Heartbeat

> **Phase:** [Phase 1: Foundation](../phases/phase1-foundation.md)  
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

### How It Works

1. On connect, set heartbeat interval on AMQPConnection
2. Track last activity time (read/write operations)
3. Before each operation, check if connection is stale
4. If stale (now > lastActivity + 2 * heartbeat), reconnect

### Implementation Checklist

- [ ] Add `heartbeat` option to Connection
- [ ] Set heartbeat interval on AMQPConnection
- [ ] Track `lastActivityTime` on each operation
- [ ] Implement `checkHeartbeat()` method
- [ ] Auto-reconnect in Sender/Receiver
- [ ] Add tests
- [ ] Add documentation

## Dependencies

- Phase 1: Factory Pattern (#18) for Connection class
