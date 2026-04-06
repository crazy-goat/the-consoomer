# Issue #2: Delayed Messages

> **Phase:** [Phase 3: Advanced Routing](../phases/phase3-advanced-routing.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** Sends messages that should be processed after a delay (e.g., "send email in 5 minutes").

**Business value:** Enables scheduling without external tools. Useful for retry delays, scheduled notifications, rate limiting.

## Implementation in Symfony

- `Connection::publishWithDelay()` — routes message through delay exchange
- `Connection::createDelayQueue()` — creates temporary queue with TTL + dead-letter exchange
- After TTL expires, message returns to original queue
- Uses `x-message-ttl` and `x-dead-letter-exchange` RabbitMQ features
- Configurable via `delay[exchange_name]` and `delay[queue_name_pattern]`

## Current State in the-consoomer

❌ **Not implemented.** No delay support.

## Implementation Notes

### Requirements

1. `delay[exchange_name]` option for delay exchange
2. `delay[queue_name_pattern]` option for delay queue naming
3. `Connection::publishWithDelay()` method
4. `Connection::createDelayQueue()` method

### How It Works

```
Producer -> Delay Exchange -> Delay Queue (TTL) -> Dead Letter Exchange -> Original Queue -> Consumer
```

Message is published to delay exchange with `x-delay` header. Router creates temporary delay queue with TTL. When TTL expires, message is routed back via dead-letter-exchange to original queue.

### Implementation Checklist

- [ ] Add delay exchange/queue options
- [ ] Implement delay queue creation with TTL
- [ ] Implement `publishWithDelay()` method
- [ ] Handle `x-delay` header for custom delays
- [ ] Add `AmqpDelayStamp` for message delay specification
- [ ] Add tests with real RabbitMQ
- [ ] Add documentation

## Dependencies

- Phase 1: Auto-Setup (#1) for exchange/queue creation
- Phase 2: Full AmqpStamp (#7) for message attributes
