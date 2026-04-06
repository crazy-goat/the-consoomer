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

### Implementation Checklist

- [ ] Add `persistent` option to Connection
- [ ] Use `pconnect()` instead of `connect()` when enabled
- [ ] Track persistent connections
- [ ] Add proper cleanup with `pdisconnect()`
- [ ] Handle connection persistence across requests
- [ ] Add tests
- [ ] Add documentation

## Dependencies

- Phase 1: Factory Pattern (#18) for Connection class
