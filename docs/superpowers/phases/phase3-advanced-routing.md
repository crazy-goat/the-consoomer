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
Phase 1 (Foundation)
└── Phase 2 (Core Messaging)
    └── Phase 3 (Advanced Routing)
        ├── Delayed Messages (#2) ────────────────────┐
        ├── Retry Routing (#3) ──────────────────────┼── Advanced routing
        ├── Queue Bindings (#5) ─────────────────────┤
        └── Exchange Bindings (#6) ─────────────────┘
```

## Rationale

Delayed Messages are essential for scheduling and retry mechanisms. Retry with Proper Routing ensures messages aren't lost during failures. Queue and Exchange Bindings enable flexible, complex routing topologies that match Symfony Messenger behavior.

## Features Overview

- **Delayed Messages**: Send messages to be processed after a delay using TTL + dead-letter exchange
- **Retry Routing**: Different routing keys for retry attempts to avoid infinite loops
- **Queue Bindings**: Bind queues to exchanges with specific routing keys
- **Exchange Bindings**: Bind exchanges to exchanges for complex topologies
