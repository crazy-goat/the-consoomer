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

Current code in `Sender::send()`:
```php
$this->exchange->publish(
    $data['body'],
    $routingKey,
    null,
    $data['headers'] ?? [],
);
// No priority support
```

## Implementation Notes

### Requirements

1. `AmqpPriorityStamp` class
2. Queue option `x-max-priority` (1-10)
3. Update Sender to extract priority

### Usage in Current Codebase

**Before (no priority):**
```php
// Sender::send()
$this->exchange->publish(
    $data['body'],
    $routingKey,
    null,
    $data['headers'] ?? [],
);
// No priority - all messages equal
```

**After (with priority):**
```php
// Sender::send()
$stamp = $envelope->last(AmqpPriorityStamp::class);
$priority = $stamp?->priority ?? 0;

$this->exchange->publish(
    $data['body'],
    $routingKey,
    AMQP_NOPARAM,
    $data['headers'] ?? [],
    [
        'priority' => $priority,
    ],
);
```

### AmqpPriorityStamp

```php
class AmqpPriorityStamp
{
    public function __construct(
        public readonly int $priority, // 0-9
    ) {
        if ($priority < 0 || $priority > 9) {
            throw new \InvalidArgumentException('Priority must be between 0 and 9');
        }
    }
}
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

### Validation

- **priority**: Must be 0-9
- **x-max-priority**: Must be 1-10

### Error Handling

- Throw `\InvalidArgumentException` for invalid priority
- Throw `\AMQPException` if queue creation fails
- Log priority setting
- Log priority error

### Logging

- Log priority setting: "Set message priority: {priority}"
- Log priority error: "Invalid priority: {priority}"

### Metrics

- **Priority count**: Number of messages per priority level
- **Priority distribution**: Distribution of priorities
- **Priority processing time**: Processing time per priority

### Performance Considerations

- Priority setting adds ~0.01ms latency
- No performance impact on message flow
- Priority queues may have different performance characteristics
- Higher priority messages processed first

### Security Considerations

- **Priority**: May reveal message importance
- **Logging**: Don't log sensitive priority information
- **Permissions**: No special permissions needed

### Backward Compatibility

- **Breaking change**: New AmqpPriorityStamp class
- **Migration path**: Existing code works without changes
- **New behavior**: Priority is optional
- **Configuration**: All priority options have default values

### Testing Strategy

**Unit Tests:**
- Test AmqpPriorityStamp creation
- Test priority validation
- Test error handling

**Integration Tests:**
- Test with real RabbitMQ using Docker
- Test priority queue creation
- Test message delivery with priority
- Test priority ordering

**E2E Tests:**
- Full publish/consume cycle with priority
- Test message flow works end-to-end
- Test priority ordering

### Implementation Checklist

- [ ] Create `AmqpPriorityStamp` class
- [ ] Add queue option `x-max-priority` support
- [ ] Update Sender to extract and apply priority
- [ ] Update Receiver to preserve priority
- [ ] Add validation
- [ ] Add error handling
- [ ] Add logging
- [ ] Add metrics
- [ ] Add unit tests
- [ ] Add integration tests with Docker
- [ ] Add E2E tests with full message flow
- [ ] Add documentation

## Dependencies

- Phase 2: Full AmqpStamp (#7) for stamp infrastructure
- Phase 1: Full DSN Parsing (#19) for queue arguments
