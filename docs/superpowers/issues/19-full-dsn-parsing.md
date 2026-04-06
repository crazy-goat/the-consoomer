# Issue #19: Full DSN Parsing

> **Phase:** [Phase 1: Foundation & DX](../phases/phase1-foundation.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** Parses DSN with all options. Validates configuration.

**Business value:** Single string configuration. All options in one place.

## Implementation in Symfony

- `Connection::fromDsn()` — parses `amqp://user:pass@host:port/vhost/exchange?options`
- `Connection::validateOptions()` — validates all options
- `Connection::normalizeQueueArguments()` — converts types
- Supports 20+ connection options, queue options, exchange options

## Current State in the-consoomer

⚠️ **Basic DSN parsing. Limited options.**

Current implementation in `AmqpTransport::create()`:
```php
$info = parse_url($dsn);
$query = [];
parse_str($info['query'] ?? '', $query);
$mergedOptions = [...$options, ...self::parsePath($info['path'] ?? ''), ...$query];
```

Only supports: `vhost`, `exchange`, `queue`, `routing_key`, `timeout`, `max_unacked_messages`

## Implementation Notes

### Requirements

1. Enhanced DSN parsing for all options
2. `validateOptions()` method
3. `normalizeQueueArguments()` for type conversion
4. Support 20+ connection, queue, and exchange options

### DSN Format

```
amqp-consoomer://user:pass@host:port/vhost/exchange?option1=value1&option2=value2
```

### DSN Examples

**Basic:**
```
amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange
```

**With queue and routing key:**
```
amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange?queue=my_queue&routing_key=my.key
```

**With connection options:**
```
amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange?read_timeout=5&write_timeout=5&connect_timeout=3&heartbeat=60
```

**With retry options:**
```
amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange?retry_count=3&retry_delay=100000
```

**With TLS:**
```
amqps://guest:guest@rabbitmq.example.com:5671/%2f/my_exchange?ssl_cert=/path/to/cert.pem&ssl_key=/path/to/key.pem&ssl_cacert=/path/to/ca.pem
```

**With queue arguments:**
```
amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange?queue=my_queue&queue_arguments[x-max-priority]=10&queue_arguments[x-message-ttl]=60000
```

**With multiple queues:**
```
amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange?queues[queue1][binding_keys][]=key1&queues[queue1][binding_keys][]=key2&queues[queue2][binding_keys][]=key3
```

### Supported Options

**Connection Options:**
| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `read_timeout` | float | 0.1 | Read timeout in seconds |
| `write_timeout` | float | 0.1 | Write timeout in seconds |
| `connect_timeout` | float | 0.1 | Connect timeout in seconds |
| `heartbeat` | int | 0 | Heartbeat interval in seconds |
| `retry_count` | int | 3 | Number of retry attempts |
| `retry_delay` | int | 100000 | Delay between retries in microseconds |
| `persistent` | bool | false | Use persistent connections |
| `confirm_timeout` | int | 0 | Publisher confirm timeout in seconds |

**Queue Options:**
| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `queue` | string | '' | Queue name |
| `queues` | array | [] | Multiple queue configurations |
| `queue_flags` | array | [] | Queue flags (durable, autodelete, etc.) |
| `queue_arguments` | array | [] | Queue arguments (x-max-priority, x-message-ttl, etc.) |
| `binding_keys` | array | [] | Routing keys for queue binding |
| `binding_arguments` | array | [] | Additional binding arguments |
| `max_unacked_messages` | int | 100 | Maximum unacknowledged messages |

**Exchange Options:**
| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `exchange` | string | '' | Exchange name |
| `exchange_type` | string | 'direct' | Exchange type (direct, fanout, topic, headers) |
| `exchange_flags` | array | [] | Exchange flags (durable, autodelete, etc.) |
| `default_publish_routing_key` | string | '' | Default routing key for publishing |

**SSL Options:**
| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `ssl_cert` | string | '' | Path to client certificate file |
| `ssl_key` | string | '' | Path to client key file |
| `ssl_cacert` | string | '' | Path to CA certificate file |
| `ssl_verify` | bool | true | Verify server certificate |

### Validation Rules

1. **Required options:**
   - `exchange` - must be non-empty string
   - `queue` or `queues` - at least one must be specified

2. **Type validation:**
   - Numeric options must be valid numbers
   - Boolean options must be true/false/1/0
   - Array options must be valid arrays

3. **Range validation:**
   - `read_timeout`, `write_timeout`, `connect_timeout` > 0
   - `heartbeat` >= 0
   - `retry_count` >= 0
   - `retry_delay` >= 0
   - `max_unacked_messages` >= 1
   - `confirm_timeout` >= 0

4. **Format validation:**
   - `exchange_type` must be one of: direct, fanout, topic, headers
   - `ssl_cert`, `ssl_key`, `ssl_cacert` must be valid file paths if specified

### Error Handling

- Throw `\InvalidArgumentException` for invalid DSN format
- Throw `\InvalidArgumentException` for missing required options
- Throw `\InvalidArgumentException` for invalid option values
- Throw `\InvalidArgumentException` for invalid option types

### Backward Compatibility

- **Breaking change**: New options added to DSN
- **Migration path**: Existing DSNs continue to work
- **New options**: All new options have default values
- **Validation**: Stricter validation may reject previously accepted invalid DSNs

### Security Considerations

- **Password in DSN**: Password is visible in DSN string
- **File paths**: SSL certificate paths must be validated
- **Logging**: Never log full DSN with password

### Testing Strategy

**Unit Tests:**
- Test DSN parsing with various formats
- Test validation rules
- Test default values
- Test error handling for invalid DSNs
- Test type conversion

**Integration Tests:**
- Test with real RabbitMQ using Docker
- Test connection with parsed options
- Test queue/exchange creation with parsed options

**E2E Tests:**
- Full publish/consume cycle with parsed DSN
- Test all option combinations

### Implementation Checklist

- [ ] Create `DsnParser` class
- [ ] Implement DSN parsing for all options
- [ ] Implement `validateOptions()` method
- [ ] Implement `normalizeQueueArguments()` method
- [ ] Add support for queue_arguments (typed)
- [ ] Add unit tests for DSN parsing
- [ ] Add unit tests for validation
- [ ] Add integration tests with Docker
- [ ] Add E2E tests with full message flow
- [ ] Add documentation

## Dependencies

- None (standalone improvement)
