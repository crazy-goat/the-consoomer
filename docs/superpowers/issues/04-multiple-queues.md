# Issue #4: Multiple Queues per Transport

> **Phase:** [Phase 5: Advanced Features](../phases/phase5-advanced-features.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** One transport can consume from multiple queues with different configurations.

**Business value:** Single worker can process messages from multiple queues. Enables priority queues, different message types per queue.

## Implementation in Symfony

- `Connection::$queuesOptions` — array of queue configurations
- `Connection::getQueueNames()` — returns all queue names
- `AmqpReceiver::getFromQueues()` — fetches from specific queues
- `QueueReceiverInterface` — allows `--queues` CLI option

## Current State in the-consoomer

❌ **Only single queue via `queue` option.**

## Implementation Notes

### Requirements

1. `queues` option (array of queue configurations)
2. `Connection::getQueueNames()` method
3. Update `Receiver` to handle multiple queues
4. Implement `QueueReceiverInterface`

### DSN Options

```
amqp-consoomer://user:pass@host:5672/vhost/exchange?queues[]=queue1&queues[]=queue2
```

Or via options array:
```php
[
    'queues' => [
        'queue1' => ['binding_keys' => ['key1', 'key2']],
        'queue2' => ['binding_keys' => ['key3']],
    ]
]
```

### Implementation Checklist

- [ ] Add `queues` option parsing (replaces single `queue`)
- [ ] Update Connection for multiple queues
- [ ] Implement `getQueueNames()` method
- [ ] Update Receiver to consume from multiple queues
- [ ] Implement `getFromQueues()` method
- [ ] Add CLI option support if applicable
- [ ] Add tests
- [ ] Add documentation

## Dependencies

- Phase 1: Auto-Setup (#1) for queue creation
- Phase 2: Full AmqpStamp (#7) for routing control
- Phase 3: Queue Bindings (#5) for binding configuration
