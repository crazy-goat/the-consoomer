# Issue #3: Retry with Proper Routing

> **Phase:** [Phase 3: Advanced Routing](../phases/phase3-advanced-routing.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** When a message fails and is retried, it uses different routing to avoid infinite loops.

**Business value:** Prevents message loss during failures. Ensures failed messages are properly re-queued.

## Implementation in Symfony

- `AmqpStamp::isRetryAttempt()` — marks message as retry
- `Connection::getRoutingKeyForDelay()` — adds `_retry` suffix to routing key
- Different dead-letter-exchange for retry vs delay

## Current State in the-consoomer

❌ **Not implemented.** No retry routing support.

## Implementation Notes

### Requirements

1. Extend `AmqpStamp` with retry flag
2. `AmqpStamp::isRetryAttempt()` method
3. `Connection::getRoutingKeyForRetry()` method (adds `_retry` suffix)
4. Different routing for retry messages

### How It Works

When Symfony Messenger retries a message:
1. It wraps the message with `AmqpStamp` marked as retry
2. The routing key gets `_retry` suffix
3. Consumer routes retry messages to special retry queue
4. After processing, message either succeeds or goes back to retry queue with incremented delay

### Implementation Checklist

- [ ] Extend `AmqpStamp` with retry attempt tracking
- [ ] Add `isRetryAttempt()` method
- [ ] Implement `getRoutingKeyForRetry()` in Connection
- [ ] Update Sender to use retry routing
- [ ] Add retry-specific dead-letter-exchange
- [ ] Add tests
- [ ] Add documentation

## Dependencies

- Phase 2: Full AmqpStamp (#7) for stamp extension
- Phase 3: Delayed Messages (#2) for retry queue setup
