# Phase 2: Core Messaging

> **Backlog:** [missing-features.md](../missing-features.md)

This phase implements core messaging functionality that enables message attribute control and lifecycle management.

## Issues

| # | Feature | Status |
|---|---------|--------|
| #7 | [Full AmqpStamp](./issues/07-full-amqpstamp.md) | ⚠️ Basic |
| #9 | [Received Message Metadata](./issues/09-received-metadata.md) | ⚠️ Basic |
| #20 | [Default Publish Routing Key](./issues/20-default-routing-key.md) | ⚠️ Basic |
| #17 | [Transport Setup/Close](./issues/17-transport-setup-close.md) | ❌ |

## Dependencies

```
Phase 1 (Foundation & DX)
└── Phase 2 (Core Messaging)
    ├── Full AmqpStamp (#7) ───────────────────────┐
    ├── Received Metadata (#9) ────────────────────┼── Core messaging
    ├── Default Routing Key (#20) ─────────────────┤
    └── Transport Setup/Close (#17) ───────────────┘
```

## Rationale

**Full AmqpStamp (#7)** provides control over all AMQP message attributes (flags, headers, content_type, delivery_mode, etc.) - essential foundation for Phase 3 features like Delayed Messages.

**Received Metadata (#9)** gives access to original AMQP envelope after receiving - useful for debugging, auditing, and correlation.

**Default Routing Key (#20)** simplifies publishing by providing consistent routing without stamps.

**Transport Setup/Close (#17)** gives explicit lifecycle control over transport resources.

## Features Overview

- **Full AmqpStamp**: Control flags, headers, content_type, delivery_mode, priority, etc.
- **Received Metadata**: Access to original AMQP envelope (timestamp, app_id, message_id, headers)
- **Default Routing Key**: Consistent routing without stamps
- **Transport Setup/Close**: Explicit setup/teardown interfaces
