# Design: TLS/SSL Encryption

**Issue:** #12  
**Date:** 2026-04-06  
**Status:** Approved

## Overview

Secure connection to RabbitMQ over SSL/TLS. Required for production environments.

## Architecture

### Changes Required

1. **DsnParser** - rozpoznaje schemat `amqps://`, parsuje opcje SSL
2. **AmqpTransport::supports()** - obsługuje `amqp-consoomer://` i `amqps://`
3. **AmqpFactory** - konfiguruje SSL dla AMQPConnection

## DSN Formats

```php
// amqps:// - auto SSL, port 5671
amqps://user:pass@rabbitmq/%2f/exchange

// amqp-consoomer z opcjami SSL
amqp-consoomer://user:pass@rabbitmq/%2f/exchange?ssl=true&ssl_cert=/path/cert.pem&ssl_key=/path/key.pem&ssl_cacert=/path/ca.pem&ssl_verify=true

// amqp-consoomer bez SSL (default)
amqp-consoomer://user:pass@rabbitmq/%2f/exchange
```

## Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `ssl` | bool | false | Enable SSL |
| `ssl_cert` | string | '' | Path to client certificate |
| `ssl_key` | string | '' | Path to client key |
| `ssl_cacert` | string | '' | Path to CA certificate |
| `ssl_verify` | bool | true | Verify server certificate |

## Implementation Checklist

- [x] Design approved
- [x] Add `amqps://` scheme detection in supports()
- [x] Add SSL options to DsnParser
- [x] Add SSL configuration to AmqpFactory
- [x] Set default port 5671 for amqps://
- [x] Add hasCaCertConfigured() validation
- [x] Add certificate file validation
- [x] Add unit tests
- [ ] Add logging (optional - requires Symfony dependency)
- [ ] Add metrics (optional - requires monitoring setup)
- [ ] Add E2E tests (optional, requires RabbitMQ with SSL)

## Dependencies

- Factory Pattern (#9) - already implemented
