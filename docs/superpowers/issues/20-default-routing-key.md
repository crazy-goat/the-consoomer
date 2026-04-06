# Issue #20: Default Publish Routing Key

> **Phase:** [Phase 2: Core Messaging](../phases/phase2-core-messaging.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** Default routing key when none specified on message.

**Business value:** Simplifies publishing. Consistent routing without stamps.

## Implementation in Symfony

- `exchange[default_publish_routing_key]` option
- `Connection::getDefaultPublishRoutingKey()` — returns default
- `Connection::getRoutingKeyForMessage()` — uses default if no stamp

## Current State in the-consoomer

⚠️ **Has `routing_key` option but limited.**

Current code in `Sender::send()`:
```php
$this->exchange->publish(
    $data['body'],
    $this->options['routing_key'] ?? $stamp?->routingKey ?? '',
    null,
    $data['headers'] ?? [],
);
```

## Implementation Notes

### Requirements

1. `default_publish_routing_key` option
2. `Connection::getDefaultPublishRoutingKey()` method
3. `Connection::getRoutingKeyForMessage()` method
4. Use default when no AmqpStamp present

### DSN/Options Format

```
amqp-consoomer://user:pass@host:5672/vhost/exchange?default_publish_routing_key=my.default.key
```

Or:
```php
[
    'exchange' => 'my_exchange',
    'default_publish_routing_key' => 'my.default.key',
]
```

### Usage in Current Codebase

**Before (limited routing key):**
```php
// Sender::send()
$this->exchange->publish(
    $data['body'],
    $this->options['routing_key'] ?? $stamp?->routingKey ?? '',
    null,
    $data['headers'] ?? [],
);
// Empty string if no routing key specified
```

**After (default routing key):**
```php
// Sender::send()
$routingKey = $this->getRoutingKeyForMessage($stamp);
$this->exchange->publish(
    $data['body'],
    $routingKey,
    null,
    $data['headers'] ?? [],
);

// getRoutingKeyForMessage()
private function getRoutingKeyForMessage(?AmqpStamp $stamp): string
{
    // Priority: Stamp routing key > Default routing key
    if ($stamp && $stamp->routingKey !== null) {
        return $stamp->routingKey;
    }
    
    return $this->options['default_publish_routing_key'] ?? '';
}
```

### Priority

1. **Stamp routing key** (highest priority)
2. **Default routing key** (from options)
3. **Empty string** (fallback)

### Validation

- **default_publish_routing_key**: Must be string or empty
- **routing_key**: Must be string or null
- **Priority**: Stamp routing key overrides default

### Error Handling

- Throw `\InvalidArgumentException` for invalid default routing key
- Log warning if no routing key specified
- Use empty string as fallback

### Logging

- Log default routing key: "Using default routing key: {routing_key}"
- Log stamp routing key: "Using stamp routing key: {routing_key}"
- Log no routing key: "No routing key specified, using empty string"

### Metrics

- **Default routing key usage**: How often default is used
- **Stamp routing key usage**: How often stamp is used
- **Empty routing key usage**: How often empty is used

### Performance Considerations

- Routing key resolution adds ~0.01ms latency
- No performance impact on message flow
- Routing key is resolved once per message

### Security Considerations

- **Routing key**: May contain sensitive information
- **Logging**: Don't log sensitive routing keys
- **Validation**: Validate routing key format

### Backward Compatibility

- **Breaking change**: New `default_publish_routing_key` option
- **Migration path**: Existing code works without changes
- **New behavior**: Default routing key is optional
- **Configuration**: Default routing key has default value (empty string)

### Testing Strategy

**Unit Tests:**
- Test routing key resolution with stamp
- Test routing key resolution with default
- Test routing key resolution with empty
- Test priority (stamp > default > empty)
- Test validation
- Test error handling

**Integration Tests:**
- Test with real RabbitMQ using Docker
- Test message delivery with default routing key
- Test message delivery with stamp routing key
- Test message delivery with empty routing key

**E2E Tests:**
- Full publish/consume cycle with default routing key
- Test message flow works end-to-end
- Test routing key priority

### Implementation Checklist

- [ ] Add `default_publish_routing_key` option
- [ ] Implement `getDefaultPublishRoutingKey()` method
- [ ] Implement `getRoutingKeyForMessage()` method
- [ ] Update Sender to use default routing key
- [ ] Add priority logic (stamp > default > empty)
- [ ] Add validation
- [ ] Add error handling
- [ ] Add logging
- [ ] Add metrics
- [ ] Add unit tests
- [ ] Add integration tests with Docker
- [ ] Add E2E tests with full message flow
- [ ] Add documentation

## Dependencies

- Phase 1: Full DSN Parsing (#19) for option handling
