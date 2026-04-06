# Phase 1: Foundation & DX

> **Backlog:** [missing-features.md](../missing-features.md)

This phase establishes foundational infrastructure and developer experience required by all other features.

## Issues

| # | Feature | Status |
|---|---------|--------|
| #18 | [Factory Pattern](./issues/18-factory-pattern.md) | ⚠️ Basic |
| #19 | [Full DSN Parsing](./issues/19-full-dsn-parsing.md) | ⚠️ Basic |
| #1 | [Auto-Setup](./issues/01-auto-setup.md) | ❌ |
| #14 | [Connection Retry](./issues/14-connection-retry.md) | ❌ |
| #11 | [Heartbeat](./issues/11-heartbeat.md) | ❌ |
| #10 | [TLS/SSL](./issues/10-tls-ssl.md) | ❌ |

## Dependencies

```
Phase 1 (Foundation & DX)
├── Factory Pattern (#18) ────────┐
├── Full DSN Parsing (#19) ───────┤
├── Auto-Setup (#1) ──────────────┼── Required by all subsequent phases
├── Connection Retry (#14) ──────┤
├── Heartbeat (#11) ──────────────┤
└── TLS/SSL (#10) ───────────────┘
```

## Rationale

**Factory Pattern (#18)** is the foundation for testability - it enables mocking AMQP objects in tests. Without testable architecture, implementing and testing connection resilience features would be extremely difficult.

**Full DSN Parsing (#19)** provides comprehensive configuration through DSN - needed before Auto-Setup.

**Auto-Setup (#1)** is critical for developer experience - currently users must manually configure RabbitMQ. Moving to Phase 1 accelerates development and testing.

**Connection Retry (#14)**, **Heartbeat (#11)**, and **TLS/SSL (#10)** provide connection resilience and security required for production use.

## Testing Strategy

All features in this phase require:
- **Unit tests** - mock AMQP objects through Factory Pattern
- **Integration tests** - Docker Compose with RabbitMQ
- **E2E tests** - full publish/consume cycle
