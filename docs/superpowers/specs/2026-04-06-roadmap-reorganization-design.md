# Roadmap Reorganization Design

> **Date:** 2026-04-06
> **Status:** Approved

## Overview

Reorganization of the implementation roadmap to improve dependency management and developer experience.

## Problem Statement

The original roadmap had several issues:

1. **Auto-Setup (#1) in Phase 2** - Critical for DX, should be in Phase 1
2. **DSN Parsing (#19) in Phase 2** - Basic configuration, should be in Phase 1
3. **Phase 4 and 5 dependencies** - Only depended on Phase 1, missing Phase 2 dependencies
4. **Retry Routing (#3) and Delayed Messages (#2)** - Strongly related but dependencies unclear
5. **Missing testing strategy** - No explicit integration test requirements

## Solution

### Phase Reorganization

#### Phase 1: Foundation & DX (6 tickets)

**Purpose:** Establish testability foundation and developer experience.

| # | Feature | Rationale |
|---|---------|-----------|
| #18 | Factory Pattern | Testability foundation - enables mocking |
| #19 | Full DSN Parsing | Configuration - needed before Auto-Setup |
| #1 | Auto-Setup | DX - developers don't need manual RabbitMQ config |
| #14 | Connection Retry | Stability - basic resilience |
| #11 | Heartbeat | Stability - detect dead connections |
| #10 | TLS/SSL | Security - production requirement |

**Dependencies:**
```
Factory Pattern (#18) ──┬──> Connection Retry (#14)
                       ├──> Heartbeat (#11)
                       └──> TLS/SSL (#10)
                       
DSN Parsing (#19) ──────> Auto-Setup (#1)
```

#### Phase 2: Core Messaging (4 tickets)

**Purpose:** Core message operations and lifecycle control.

| # | Feature | Rationale |
|---|---------|-----------|
| #7 | Full AmqpStamp | Message attributes - foundation for Phase 3 |
| #9 | Received Metadata | Access to received message metadata |
| #20 | Default Routing Key | Simplified publishing |
| #17 | Transport Setup/Close | Explicit lifecycle control |

**Dependencies:**
- Phase 1 complete
- Full AmqpStamp (#7) required for Phase 3 features

#### Phase 3: Advanced Routing (4 tickets)

**Purpose:** Routing patterns and retry mechanisms.

| # | Feature | Rationale |
|---|---------|-----------|
| #2 | Delayed Messages | Scheduling - TTL + dead-letter |
| #3 | Retry Routing | Retry - uses Delayed Messages |
| #5 | Queue Bindings | Routing - binding keys |
| #6 | Exchange Bindings | Routing - exchange-to-exchange |

**Dependencies:**
- Phase 2 complete
- Delayed Messages (#2) required for Retry Routing (#3)
- Auto-Setup (#1) required for Queue/Exchange Bindings

#### Phase 4: Production Ready (4 tickets)

**Purpose:** Production features for reliability and operations.

| # | Feature | Rationale |
|---|---------|-----------|
| #12 | Persistent Connections | Performance - pconnect() |
| #13 | Publisher Confirms | Guarantee - broker confirmation |
| #15 | Message Count | Monitoring - queue depth |
| #16 | Queue Purge | Operations - clear queues |

**Dependencies:**
- Phase 2 complete
- Transport Setup/Close (#17) for lifecycle management

#### Phase 5: Advanced Features (2 tickets)

**Purpose:** Advanced use cases.

| # | Feature | Rationale |
|---|---------|-----------|
| #4 | Multiple Queues | Single worker, multiple queues |
| #8 | Message Priority | Priority queuing |

**Dependencies:**
- Phase 2 complete
- Full AmqpStamp (#7) for routing control

### Testing Strategy

All features require:

1. **Unit tests** - Mock AMQP objects through Factory Pattern
2. **Integration tests** - Docker Compose with RabbitMQ
3. **E2E tests** - Full publish/consume cycle

### Key Changes

1. **Auto-Setup (#1) moved to Phase 1** - Critical for DX
2. **DSN Parsing (#19) moved to Phase 1** - Configuration foundation
3. **Phase 4 and 5 now depend on Phase 2** - Correct dependency chain
4. **Retry Routing (#3) explicitly depends on Delayed Messages (#2)** - Clear relationship
5. **Testing strategy added** - Integration tests with Docker required

## Files Updated

- `docs/superpowers/implementation-roadmap.md` - Main roadmap
- `docs/superpowers/phases/phase1-foundation.md` - Phase 1 details
- `docs/superpowers/phases/phase2-core-messaging.md` - Phase 2 details
- `docs/superpowers/phases/phase3-advanced-routing.md` - Phase 3 details
- `docs/superpowers/phases/phase4-production-ready.md` - Phase 4 details
- `docs/superpowers/phases/phase5-advanced-features.md` - Phase 5 details
- `docs/superpowers/issues/*.md` - All issue dependencies updated
- `docs/missing-features.md` - Summary table updated

## Benefits

1. **Better DX** - Auto-Setup in Phase 1 means developers can start faster
2. **Clearer dependencies** - Each phase has explicit dependencies
3. **Testable architecture** - Factory Pattern enables testing from start
4. **Logical progression** - Foundation → Core → Advanced → Production → Specialized