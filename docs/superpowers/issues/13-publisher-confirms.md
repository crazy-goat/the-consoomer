# Issue #13: Publisher Confirms

> **Phase:** [Phase 4: Production Ready](../phases/phase4-production-ready.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** Waits for broker confirmation after publish. Guarantees message was received.

**Business value:** Ensures messages aren't lost during publish. Critical for financial transactions, orders.

## Implementation in Symfony

- `confirm_timeout` option — wait time in seconds
- `Connection::channel()` — calls `confirmSelect()`
- `Connection::publishOnExchange()` — calls `waitForConfirm()`

## Current State in the-consoomer

❌ **Not implemented.** Fire-and-forget publishing.

## Implementation Notes

### Requirements

1. `confirm_timeout` option
2. Enable confirm mode on channel with `confirmSelect()`
3. Call `waitForConfirm()` after publish
4. Throw exception if confirmation not received in time

### Configuration

```
amqp-consoomer://user:pass@host:5672/vhost/exchange?confirm_timeout=5
```

### Implementation Checklist

- [ ] Add `confirm_timeout` option
- [ ] Enable confirm mode with `confirmSelect()` in Sender
- [ ] Implement confirmation waiting after publish
- [ ] Throw `AMQPConfirmException` on timeout
- [ ] Add tests with RabbitMQ
- [ ] Add documentation

## Dependencies

- Phase 1: Factory Pattern (#18) for Connection class
