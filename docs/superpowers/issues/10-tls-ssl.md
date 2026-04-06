# Issue #10: TLS/SSL Encryption

> **Phase:** [Phase 1: Foundation](../phases/phase1-foundation.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** Secure connection to RabbitMQ over SSL/TLS.

**Business value:** Required for production environments. Compliance with security standards.

## Implementation in Symfony

- `amqps://` DSN scheme
- `cacert`, `cert`, `key`, `verify` options
- `Connection::hasCaCertConfigured()` — validates SSL config
- Default port 5671 for AMQPS

## Current State in the-consoomer

❌ **Not implemented.** No SSL support.

## Implementation Notes

### Requirements

1. Support `amqps://` DSN scheme
2. Add SSL options: `ssl_cert`, `ssl_key`, `ssl_cacert`, `ssl_verify`
3. Configure AMQPConnection for SSL
4. Default port 5671 for AMQPS

### DSN Format

```
amqps://user:pass@rabbitmq.example.com:5671/%2f/exchange
```

Or with options:
```
amqp-consoomer://user:pass@host:5672/vhost/exchange?ssl_cert=/path/to/cert.pem&ssl_key=/path/to/key.pem&ssl_cacert=/path/to/ca.pem
```

### Options

| Option | Description |
|--------|-------------|
| `ssl_cert` | Path to client certificate file |
| `ssl_key` | Path to client key file |
| `ssl_cacert` | Path to CA certificate file |
| `ssl_verify` | Verify server certificate (default: true) |

### Implementation Checklist

- [ ] Add `amqps://` scheme detection in supports()
- [ ] Add SSL options to Connection class
- [ ] Configure AMQPConnection SSL settings
- [ ] Set default port 5671 for amqps://
- [ ] Add `hasCaCertConfigured()` validation
- [ ] Add tests (integration with RabbitMQ TLS)
- [ ] Add documentation

## Dependencies

- Phase 1: Factory Pattern (#18) for Connection class (prerequisite for testability)
