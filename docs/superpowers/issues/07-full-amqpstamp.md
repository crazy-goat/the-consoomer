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

Current code in `src/AmqpStamp.php`:
```php
class AmqpStamp
{
    public function __construct(
        public readonly ?string $routingKey = null,
    ) {
    }
}
```

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

| Attribute | Type | Description |
|-----------|------|-------------|
| `content_type` | string | MIME type |
| `content_encoding` | string | Content encoding |
| `delivery_mode` | int | 1 (non-persistent) or 2 (persistent) |
| `priority` | int | 0-9 |
| `timestamp` | int | Unix timestamp |
| `app_id` | string | Application ID |
| `message_id` | string | Message ID |
| `user_id` | string | User ID |
| `expiration` | string | Expiration time |
| `type` | string | Message type |
| `reply_to` | string | Reply-to address |
| `correlation_id` | string | Correlation ID |
| `headers` | array | Custom headers array |

### Usage in Current Codebase

**Before (basic stamp):**
```php
$stamp = new AmqpStamp('my.routing.key');
$envelope = $envelope->with($stamp);
$transport->send($envelope);
```

**After (full stamp):**
```php
$stamp = new AmqpStamp(
    routingKey: 'my.routing.key',
    flags: AMQP_MANDATORY,
    attributes: [
        'content_type' => 'application/json',
        'delivery_mode' => 2,
        'priority' => 5,
        'message_id' => uniqid(),
        'headers' => ['x-custom' => 'value'],
    ]
);
$envelope = $envelope->with($stamp);
$transport->send($envelope);
```

### Validation

- **routing_key**: Must be string or null
- **flags**: Must be valid AMQP flag constant
- **content_type**: Must be valid MIME type
- **delivery_mode**: Must be 1 or 2
- **priority**: Must be 0-9
- **timestamp**: Must be valid Unix timestamp
- **headers**: Must be array

### Error Handling

- Throw `\InvalidArgumentException` for invalid routing key
- Throw `\InvalidArgumentException` for invalid flags
- Throw `\InvalidArgumentException` for invalid attributes
- Throw `\InvalidArgumentException` for invalid attribute values

### Serialization

- Stamp is **not serialized** - used only during send
- Attributes are passed directly to `AMQPExchange::publish()`
- No custom serialization needed

### Logging

- Log stamp creation: "Created AmqpStamp with routing key: {routing_key}"
- Log stamp attributes: "Stamp attributes: {attributes}"
- Log stamp error: "Invalid stamp: {error_message}"

### Metrics

- **Stamp count**: Number of stamps created
- **Attribute count**: Number of attributes per stamp
- **Flag usage**: Which flags are used

### Performance Considerations

- Stamp creation adds ~0.1ms latency
- Attribute validation adds ~0.05ms latency
- No performance impact on message flow
- Stamp is lightweight object

### Security Considerations

- **Headers**: May contain sensitive information
- **User ID**: May contain sensitive information
- **Message ID**: May contain sensitive information
- **Logging**: Don't log sensitive attributes

### Backward Compatibility

- **Breaking change**: New constructor parameters
- **Migration path**: Existing code works (parameters are optional)
- **New behavior**: Flags and attributes are optional
- **Configuration**: All new parameters have default values

### Testing Strategy

**Unit Tests:**
- Test stamp creation with all attributes
- Test stamp creation with partial attributes
- Test stamp validation
- Test stamp error handling
- Test `createFromAmqpEnvelope()` method
- Test `createWithAttributes()` method

**Integration Tests:**
- Test with real RabbitMQ using Docker
- Test message delivery with all attributes
- Test message delivery with partial attributes
- Test attribute preservation on retry

**E2E Tests:**
- Full publish/consume cycle with full stamp
- Test message flow works end-to-end
- Test attribute preservation

### Implementation Checklist

- [ ] Extend AmqpStamp with flags and attributes
- [ ] Add all getter/setter methods
- [ ] Add `createFromAmqpEnvelope()` method
- [ ] Add `createWithAttributes()` method
- [ ] Add validation
- [ ] Add error handling
- [ ] Add logging
- [ ] Add metrics
- [ ] Update Sender to use flags and attributes
- [ ] Add unit tests
- [ ] Add integration tests with Docker
- [ ] Add E2E tests with full message flow
- [ ] Add documentation

## Dependencies

- Phase 2: None (standalone improvement)
