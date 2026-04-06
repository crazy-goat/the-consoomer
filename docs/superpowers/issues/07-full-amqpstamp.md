# Issue #7: Full AmqpStamp

> **Phase:** [Phase 2: Core Messaging](../phases/phase2-core-messaging.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** Control all AMQP message attributes: routing key, flags, headers, content_type, delivery_mode, priority, message_id, etc.

**Business value:** Fine-grained control over message behavior. Priority queuing, custom headers, message IDs for tracking.

## Implementation in Symfony

- `AmqpStamp` with `$routingKey`, `$flags`, `$attributes`
- `AmqpStamp::createFromAmqpEnvelope()` — preserves attributes on retry
- `AmqpStamp::createWithAttributes()` — merge attributes
- Attributes: `content_type`, `content_encoding`, `delivery_mode`, `priority`, `timestamp`, `app_id`, `message_id`, `user_id`, `expiration`, `type`, `reply_to`, `correlation_id`, `headers`

## Current State in the-consoomer

⚠️ **Basic `AmqpStamp` with only `routingKey`. No flags or attributes.**

## Implementation Notes

### Requirements

1. Extend `AmqpStamp` with `$flags` parameter
2. Add `$attributes` array for all AMQP message attributes
3. Add `createFromAmqpEnvelope()` factory method
4. Add `createWithAttributes()` merge method

### Interface Changes

```php
class AmqpStamp
{
    public function __construct(
        ?string $routingKey = null,
        int $flags = AMQP_NOPARAM,
        array $attributes = [],
    );
    
    public function getRoutingKey(): ?string;
    public function getFlags(): int;
    public function getAttributes(): array;
    
    public function withRoutingKey(?string $routingKey): self;
    public function withFlags(int $flags): self;
    public function withAttribute(string $key, mixed $value): self;
    
    public static function createFromAmqpEnvelope(\AMQPEnvelope $envelope): self;
    public static function createWithAttributes(array $attributes): self;
}
```

### Supported Attributes

| Attribute | Description |
|-----------|-------------|
| `content_type` | MIME type |
| `content_encoding` | Content encoding |
| `delivery_mode` | 1 (non-persistent) or 2 (persistent) |
| `priority` | 0-9 |
| `timestamp` | Unix timestamp |
| `app_id` | Application ID |
| `message_id` | Message ID |
| `user_id` | User ID |
| `expiration` | Expiration time |
| `type` | Message type |
| `reply_to` | Reply-to address |
| `correlation_id` | Correlation ID |
| `headers` | Custom headers array |

### Implementation Checklist

- [ ] Extend AmqpStamp with flags and attributes
- [ ] Add all getter/setter methods
- [ ] Add `createFromAmqpEnvelope()` method
- [ ] Add `createWithAttributes()` method
- [ ] Update Sender to use flags and attributes
- [ ] Add tests
- [ ] Add documentation

## Dependencies

- Phase 2: None (standalone improvement)
