# Implementation Roadmap

> **Backlog:** [missing-features.md](./missing-features.md)

This document tracks the implementation progress of all missing features, organized into phases.

## Phases Overview

| Phase | Name | Issues | Status |
|-------|------|--------|--------|
| 0 | [Test Infrastructure](./phases/phase0-test-infrastructure.md) | Infrastructure setup | ✅ Implemented |
| 1 | [Foundation](./phases/phase1-foundation.md) | #18, #14, #11, #10 | 🚧 Planned |
| 2 | [Core Messaging](./phases/phase2-core-messaging.md) | #1, #7, #9, #17, #19, #20 | 📋 Backlog |
| 3 | [Advanced Routing](./phases/phase3-advanced-routing.md) | #2, #3, #5, #6 | 📋 Backlog |
| 4 | [Production Ready](./phases/phase4-production-ready.md) | #12, #13, #15, #16 | 📋 Backlog |
| 5 | [Advanced Features](./phases/phase5-advanced-features.md) | #4, #8 | 📋 Backlog |

## All Issues

| # | Feature | Phase | Priority |
|---|---------|-------|----------|
| #1 | [Auto-Setup](./issues/01-auto-setup.md) | Phase 2 | High |
| #2 | [Delayed Messages](./issues/02-delayed-messages.md) | Phase 3 | High |
| #3 | [Retry with Proper Routing](./issues/03-retry-routing.md) | Phase 3 | High |
| #4 | [Multiple Queues per Transport](./issues/04-multiple-queues.md) | Phase 5 | Low |
| #5 | [Queue Bindings](./issues/05-queue-bindings.md) | Phase 3 | Medium |
| #6 | [Exchange-to-Exchange Bindings](./issues/06-exchange-bindings.md) | Phase 3 | Low |
| #7 | [Full AmqpStamp](./issues/07-full-amqpstamp.md) | Phase 2 | High |
| #8 | [Message Priority](./issues/08-message-priority.md) | Phase 5 | Low |
| #9 | [Received Message Metadata](./issues/09-received-metadata.md) | Phase 2 | Medium |
| #10 | [TLS/SSL](./issues/10-tls-ssl.md) | Phase 1 | Medium |
| #11 | [Heartbeat](./issues/11-heartbeat.md) | Phase 1 | Medium |
| #12 | [Persistent Connections](./issues/12-persistent-connections.md) | Phase 4 | Low |
| #13 | [Publisher Confirms](./issues/13-publisher-confirms.md) | Phase 4 | Medium |
| #14 | [Connection Retry](./issues/14-connection-retry.md) | Phase 1 | High |
| #15 | [Message Count](./issues/15-message-count.md) | Phase 4 | Medium |
| #16 | [Queue Purge](./issues/16-queue-purge.md) | Phase 4 | Low |
| #17 | [Transport Setup/Close](./issues/17-transport-setup-close.md) | Phase 2 | Medium |
| #18 | [Factory Pattern](./issues/18-factory-pattern.md) | Phase 1 | High |
| #19 | [Full DSN Parsing](./issues/19-full-dsn-parsing.md) | Phase 2 | Medium |
| #20 | [Default Publish Routing Key](./issues/20-default-routing-key.md) | Phase 2 | Low |

## Dependency Graph

```
Phase 0 (Test Infrastructure) ✅
    │
    ▼
Phase 1 (Foundation) ──────────────────────────┐
├── #18 Factory Pattern                        │
├── #14 Connection Retry                      │
├── #11 Heartbeat                              │
└── #10 TLS/SSL                                │
    │                                          │
    └──────────────────────────────────────────┤
                                                │
    ▼                                          ▼
Phase 2 (Core Messaging) ◄─────────────────────┘
├── #1 Auto-Setup
├── #7 Full AmqpStamp
├── #9 Received Metadata
├── #17 Transport Setup/Close
├── #19 Full DSN Parsing
└── #20 Default Routing Key
    │
    ├───────────────────────────────────────┐
    │                                       │
    ▼                                       ▼
Phase 3 (Advanced Routing)      Phase 4 (Production Ready)
├── #2 Delayed Messages         ├── #12 Persistent Connections
├── #3 Retry Routing             ├── #13 Publisher Confirms
├── #5 Queue Bindings           ├── #15 Message Count
└── #6 Exchange Bindings        └── #16 Queue Purge
    │
    ▼
Phase 5 (Advanced Features)
├── #4 Multiple Queues
└── #8 Message Priority
```

## GitHub Milestones

See GitHub repository for milestone tracking:
- `phase-0-test-infrastructure`
- `phase-1-foundation`
- `phase-2-core-messaging`
- `phase-3-advanced-routing`
- `phase-4-production-ready`
- `phase-5-advanced-features`

## Implementation Status

- **Implemented**: Phase 0 (Test Infrastructure)
- **In Progress**: Phase 1 (Foundation) - see [existing plan](./phases/2026-04-06-phase1-stability.md)
- **Backlog**: Phases 2-5
