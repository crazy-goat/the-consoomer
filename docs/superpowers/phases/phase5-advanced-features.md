# Phase 5: Advanced Features

> **Backlog:** [missing-features.md](../missing-features.md)

This phase implements advanced features for specific use cases.

## Issues

| # | Feature | Status |
|---|---------|--------|
| #4 | [Multiple Queues per Transport](./issues/04-multiple-queues.md) | ❌ |
| #8 | [Message Priority](./issues/08-message-priority.md) | ❌ |

## Dependencies

```
Phase 1 (Foundation & DX)
└── Phase 2 (Core Messaging)
    └── Phase 5 (Advanced Features)
        ├── Multiple Queues (#4) ────────────────────┐
        │   └── depends on Full AmqpStamp (#7)      │
        └── Message Priority (#8) ───────────────────┴── Advanced features
            └── depends on Full AmqpStamp (#7)
```

## Rationale

These are advanced features for specific use cases:

**Multiple Queues (#4)** enables single worker to consume from multiple queues with different configurations. **Depends on Full AmqpStamp** from Phase 2 for routing control.

**Message Priority (#8)** allows critical messages to be processed before routine ones using `x-max-priority` queue argument. **Depends on Full AmqpStamp** from Phase 2 for priority attribute.

## Features Overview

- **Multiple Queues**: Single transport can consume from multiple queues
- **Message Priority**: Higher priority messages processed first with x-max-priority
