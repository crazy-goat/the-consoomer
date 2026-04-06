# Phase 1: Foundation

> **Backlog:** [missing-features.md](../missing-features.md)

This phase establishes foundational infrastructure required by all other features.

## Issues

| # | Feature | Status |
|---|---------|--------|
| #18 | [Factory Pattern](./issues/18-factory-pattern.md) | ⚠️ Basic |
| #14 | [Connection Retry](./issues/14-connection-retry.md) | ❌ |
| #11 | [Heartbeat](./issues/11-heartbeat.md) | ❌ |
| #10 | [TLS/SSL](./issues/10-tls-ssl.md) | ❌ |

## Dependencies

```
Phase 1 (Foundation)
├── Factory Pattern (#18) ──────┐
├── Connection Retry (#14) ────┼── Required by all subsequent phases
├── Heartbeat (#11) ───────────┤
└── TLS/SSL (#10) ─────────────┘
```

## Rationale

Factory Pattern (#18) is a prerequisite for Connection Retry, Heartbeat, and TLS - it enables mocking AMQP objects in tests. Without testable architecture, implementing and testing connection resilience features would be extremely difficult.

## Implementation Notes

See existing plan: [2026-04-06-phase1-stability.md](./2026-04-06-phase1-stability.md)
