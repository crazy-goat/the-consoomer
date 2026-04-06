# Issue #6: Exchange-to-Exchange Bindings

> **Phase:** [Phase 3: Advanced Routing](../phases/phase3-advanced-routing.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** Binds one exchange to another. Enables complex routing topologies.

**Business value:** Advanced message routing architectures. Federation between exchanges.

## Implementation in Symfony

- `exchange[bindings][name][binding_keys]` — source exchange bindings
- `Connection::setupExchangeAndQueues()` — creates exchange-to-exchange bindings

## Current State in the-consoomer

❌ **Not implemented.**

## Implementation Notes

### Requirements

1. `exchange[bindings]` option structure
2. `Connection::setupExchangeBindings()` method
3. Bind source exchange to target exchange with routing keys

### Options Format

```php
[
    'exchange' => 'my_exchange',
    'exchange_bindings' => [
        [
            'target' => 'target_exchange',
            'routing_keys' => ['key1', 'key2'],
        ],
    ],
]
```

### Implementation Checklist

- [ ] Add exchange_bindings option parsing
- [ ] Implement `Connection::setupExchangeBindings()`
- [ ] Update auto-setup to create bindings
- [ ] Add tests with RabbitMQ
- [ ] Add documentation

## Dependencies

- Phase 2: Auto-Setup (#1) for exchange creation
