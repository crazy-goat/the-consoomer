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

## Implementation Notes

### Requirements

1. `auto_setup` option (default: `true`)
2. `Connection::setup()` method that creates:
   - Exchange (if not exists)
   - Queue(s) with bindings
   - Delay exchange for retries (future)

### DSN Options

```
amqp-consoomer://user:pass@host:5672/vhost/exchange?queue=my_queue&auto_setup=true
```

### Implementation Checklist

- [ ] Add `auto_setup` option parsing
- [ ] Implement `Connection::setup()` method
- [ ] Implement `Connection::setupExchangeAndQueues()`
- [ ] Trigger setup on first `get()` or `publish()` (lazy)
- [ ] Add tests for auto-setup
- [ ] Add documentation

## Dependencies

- Phase 1: Factory Pattern (#18) for testability
- Phase 1: Full DSN Parsing (#19) for option handling
