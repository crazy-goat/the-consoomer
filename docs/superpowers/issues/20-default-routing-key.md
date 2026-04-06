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

### Implementation Checklist

- [ ] Add `default_publish_routing_key` option
- [ ] Implement `getDefaultPublishRoutingKey()` method
- [ ] Update Sender to use default routing key
- [ ] Priority: Stamp routing key > Default routing key
- [ ] Add tests
- [ ] Add documentation

## Dependencies

- Phase 2: Full DSN Parsing (#19) for option handling
