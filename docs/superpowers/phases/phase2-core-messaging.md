# Phase 2: Core Messaging

> **Backlog:** [missing-features.md](../missing-features.md)

This phase implements core messaging functionality that enables self-configuring transports.

## Issues

| # | Feature | Status |
|---|---------|--------|
| #1 | [Auto-Setup](./issues/01-auto-setup.md) | ❌ |
| #7 | [Full AmqpStamp](./issues/07-full-amqpstamp.md) | ⚠️ Basic |
| #9 | [Received Message Metadata](./issues/09-received-metadata.md) | ⚠️ Basic |
| #17 | [Transport Setup/Close](./issues/17-transport-setup-close.md) | ❌ |
| #19 | [Full DSN Parsing](./issues/19-full-dsn-parsing.md) | ⚠️ Basic |
| #20 | [Default Publish Routing Key](./issues/20-default-routing-key.md) | ⚠️ Basic |

## Dependencies

```
Phase 1 (Foundation)
└── Phase 2 (Core Messaging)
    ├── Auto-Setup (#1) ─────────────────────────────┐
    ├── Full AmqpStamp (#7) ────────────────────────┼── Core messaging
    ├── Received Metadata (#9) ──────────────────────┤
    ├── Transport Setup/Close (#17) ─────────────────┤
    ├── Full DSN Parsing (#19) ──────────────────────┤
    └── Default Routing Key (#20) ──────────────────┘
```

## Rationale

Auto-Setup is critical for developer experience - currently users must manually configure RabbitMQ. Full AmqpStamp and Received Metadata provide the message attribute control that Delayed Messages and other advanced features will build upon. Transport Setup/Close gives explicit lifecycle control.

## Features Overview

- **Auto-Setup**: Transport creates exchanges/queues automatically on first use
- **Full AmqpStamp**: Control flags, headers, content_type, delivery_mode, etc.
- **Received Metadata**: Access to original AMQP envelope after receiving
- **Transport Setup/Close**: Explicit setup/teardown interfaces
- **Full DSN Parsing**: 20+ connection and queue options
- **Default Routing Key**: Consistent routing without stamps
