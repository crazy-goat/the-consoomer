# Phase 3: Advanced Routing

> **Backlog:** [missing-features.md](../missing-features.md)

This phase implements advanced routing patterns including delayed messages, retry logic, and complex bindings.

## Issues

| # | Feature | Status |
|---|---------|--------|
| #2 | [Delayed Messages](./issues/02-delayed-messages.md) | ❌ |
| #3 | [Retry with Proper Routing](./issues/03-retry-routing.md) | ❌ |
| #5 | [Queue Bindings](./issues/05-queue-bindings.md) | ❌ |
| #6 | [Exchange-to-Exchange Bindings](./issues/06-exchange-bindings.md) | ❌ |

## Dependencies

```
Phase 1 (Foundation & DX)
└── Phase 2 (Core Messaging)
    └── Phase 3 (Advanced Routing)
        ├── Delayed Messages (#2) ────────────────────┐
        │   └── depends on Full AmqpStamp (#7)       │
        ├── Retry Routing (#3) ──────────────────────┼── Advanced routing
        │   └── depends on Delayed Messages (#2)     │
        ├── Queue Bindings (#5) ─────────────────────┤
        │   └── depends on Auto-Setup (#1)           │
        └── Exchange Bindings (#6) ─────────────────┘
            └── depends on Auto-Setup (#1)
```

## Rationale

**Delayed Messages (#2)** are essential for scheduling and retry mechanisms - uses TTL + dead-letter exchange pattern.

**Retry Routing (#3)** ensures messages aren't lost during failures - uses different routing keys for retry attempts to avoid infinite loops. **Depends on Delayed Messages** for retry delays.

**Queue Bindings (#5)** enable flexible routing with binding keys - different consumers can receive different message types from same exchange. **Depends on Auto-Setup** from Phase 1.

**Exchange Bindings (#6)** enable complex routing topologies - federation between exchanges. **Depends on Auto-Setup** from Phase 1.

## Features Overview

- **Delayed Messages**: Send messages to be processed after a delay using TTL + dead-letter exchange
- **Retry Routing**: Different routing keys for retry attempts to avoid infinite loops
- **Queue Bindings**: Bind queues to exchanges with specific routing keys
- **Exchange Bindings**: Bind exchanges to exchanges for complex topologies
