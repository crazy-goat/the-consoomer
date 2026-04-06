# Issue #5: Queue Bindings

> **Phase:** [Phase 3: Advanced Routing](../phases/phase3-advanced-routing.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** Binds queues to exchanges with specific routing keys. Enables topic/fanout routing patterns.

**Business value:** Flexible message routing. Different consumers can receive different message types from same exchange.

## Implementation in Symfony

- `queues[name][binding_keys]` — routing keys for queue binding
- `queues[name][binding_arguments]` — additional binding arguments
- `Connection::setupExchangeAndQueues()` — creates bindings during setup

## Current State in the-consoomer

❌ **No binding support.** Queue must already exist and be bound.

## Implementation Notes

### Requirements

1. `queues[name][binding_keys]` option
2. `queues[name][binding_arguments]` option
3. `Connection::setupQueueBindings()` method
4. Auto-create bindings during setup

### DSN/Options Format

```php
[
    'queues' => [
        'my_queue' => [
            'binding_keys' => ['order.created', 'order.updated', 'order.*'],
            'binding_arguments' => ['x-match' => 'any'],
        ],
    ]
]
```

### Implementation Checklist

- [ ] Add binding_keys option parsing
- [ ] Add binding_arguments option parsing
- [ ] Implement `Connection::setupQueueBindings()`
- [ ] Update auto-setup to create bindings
- [ ] Add tests with RabbitMQ
- [ ] Add documentation

## Dependencies

- Phase 1: Auto-Setup (#1) for queue creation
