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
Phase 1 (Foundation)
└── Phase 2 (Core Messaging)
    └── Phase 5 (Advanced Features)
        ├── Multiple Queues (#4) ────────────────────┐
        └── Message Priority (#8) ───────────────────┴── Advanced features
```

## Rationale

These are advanced features for specific use cases:
- Multiple Queues enables single worker to consume from multiple queues with different configurations
- Message Priority allows critical messages to be processed before routine ones

## Features Overview

- **Multiple Queues**: Single transport can consume from multiple queues
- **Message Priority**: Higher priority messages processed first with x-max-priority
