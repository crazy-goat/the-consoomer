# Issue #8: Message Priority

> **Phase:** [Phase 5: Advanced Features](../phases/phase5-advanced-features.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** Send messages with priority. Higher priority messages are processed first.

**Business value:** Critical messages processed before routine ones. VIP user requests, urgent notifications.

## Implementation in Symfony

- `AmqpPriorityStamp` — sets message priority
- Requires queue with `x-max-priority` argument
- `AmqpSender::send()` — extracts priority from stamp

## Current State in the-consoomer

❌ **Not implemented.**

## Implementation Notes

### Requirements

1. `AmqpPriorityStamp` class
2. Queue option `x-max-priority` (1-10)
3. Update Sender to extract priority

### Usage

```php
use CrazyGoat\TheConsoomer\AmqpPriorityStamp;

$envelope = $envelope->with(new AmqpPriorityStamp(5));
$transport->send($envelope);
```

### Queue Configuration

```php
[
    'queue' => 'my_queue',
    'queue_arguments' => [
        'x-max-priority' => 10, // 1-10, higher = more priority
    ],
]
```

### Implementation Checklist

- [ ] Create `AmqpPriorityStamp` class
- [ ] Add queue option `x-max-priority` support
- [ ] Update Sender to extract and apply priority
- [ ] Update Receiver to preserve priority
- [ ] Add tests with RabbitMQ
- [ ] Add documentation

## Dependencies

- Phase 2: Full AmqpStamp (#7) for stamp infrastructure
- Phase 1: Full DSN Parsing (#19) for queue arguments
