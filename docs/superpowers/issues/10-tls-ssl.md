# Issue #10: TLS/SSL Encryption

> **Phase:** [Phase 1: Foundation & DX](../phases/phase1-foundation.md)  
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

✅ **Implemented.** SSL support added.

DSN support:
```php
// amqps:// - auto SSL, port 5671
amqps://user:pass@rabbitmq.example.com:5671/%2f/exchange

// amqp-consoomer z opcjami SSL
amqp-consoomer://user:pass@host:5672/vhost/exchange?ssl=true&ssl_cert=/path/to/cert.pem&ssl_key=/path/to/key.pem&ssl_cacert=/path/to/ca.pem&ssl_verify=true
```

Implementation in `AmqpTransport::create()`:
```php
$connection = new \AMQPConnection();
$connection->setHost($info['host']);
$connection->setPort($info['port']);
// ... connection setup

// SSL configuration via factory
$factory->configureSsl($connection, $mergedOptions);
$connection->connect();
```

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

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `ssl_cert` | string | '' | Path to client certificate file |
| `ssl_key` | string | '' | Path to client key file |
| `ssl_cacert` | string | '' | Path to CA certificate file |
| `ssl_verify` | bool | true | Verify server certificate |

### Usage in Current Codebase

**Before (no SSL):**
```php
// AmqpTransport::create()
$connection = new \AMQPConnection();
$connection->setHost($info['host']);
$connection->setPort($info['port']);
// ... connection setup
// No SSL - insecure connection
```

**After (with SSL):**
```php
// AmqpTransport::create()
$connection = new \AMQPConnection();
$connection->setHost($info['host']);
$connection->setPort($info['port']);

if ($this->isSslEnabled()) {
    $connection->setCert($this->sslCert);
    $connection->setKey($this->sslKey);
    $connection->setCaCert($this->sslCaCert);
    $connection->setVerify($this->sslVerify);
}
```

### Certificate Validation

- **ssl_verify=true**: Verify server certificate against CA
- **ssl_verify=false**: Skip server certificate verification (not recommended)
- **ssl_cacert**: Path to CA certificate file for verification
- **ssl_cert**: Path to client certificate file for mutual TLS
- **ssl_key**: Path to client key file for mutual TLS

### Certificate Rotation

- **Hot reload**: Certificates are read on each connection
- **No restart needed**: New certificates are used automatically
- **File monitoring**: Optional file change detection
- **Graceful rotation**: Old connections continue with old certificates

### Logging

- Log SSL enabled: "SSL/TLS enabled for connection"
- Log SSL handshake: "SSL handshake completed successfully"
- Log SSL error: "SSL handshake failed: {error_message}"
- Log certificate: "Using certificate: {cert_path}"

### Metrics

- **SSL handshake time**: Time to complete SSL handshake
- **SSL connection time**: Total connection time with SSL
- **SSL error count**: Number of SSL errors
- **Certificate expiry**: Days until certificate expires

### Error Handling

- Throw `\AMQPConnectionException` if SSL handshake fails
- Throw `\InvalidArgumentException` if certificate files don't exist
- Throw `\InvalidArgumentException` if certificate files are not readable
- Log SSL errors with details

### Performance Considerations

- SSL handshake adds ~10-50ms latency on connection
- SSL encryption adds ~5-10% CPU overhead
- SSL connection uses more memory than plain connection
- SSL performance impact is minimal for most use cases

### Security Considerations

- **Certificate files**: Must be readable by PHP process
- **Private key**: Must be protected with appropriate permissions
- **CA certificate**: Must be trusted CA or self-signed CA
- **Verification**: Always verify server certificate in production
- **Mutual TLS**: Use client certificate for authentication

### Backward Compatibility

- **Breaking change**: New SSL options
- **Migration path**: Existing code works without changes
- **New behavior**: SSL disabled by default (amqp:// scheme)
- **Configuration**: All SSL options have default values

### Testing Strategy

**Unit Tests:**
- Test SSL configuration with mocked AMQP objects
- Test certificate validation
- Test error handling for missing certificates
- Test error handling for invalid certificates

**Integration Tests:**
- Test with real RabbitMQ using Docker with SSL
- Test SSL connection with valid certificates
- Test SSL connection with invalid certificates
- Test mutual TLS with client certificates

**E2E Tests:**
- Full publish/consume cycle with SSL
- Test message flow works end-to-end
- Test SSL connection failures

### Implementation Checklist

- [x] Add `amqps://` scheme detection in supports()
- [x] Add SSL options to Connection class
- [x] Configure AMQPConnection SSL settings
- [x] Set default port 5671 for amqps://
- [x] Add `hasCaCertConfigured()` validation
- [x] Add certificate file validation
- [ ] Add logging
- [ ] Add metrics
- [ ] Add unit tests with mocked AMQP objects
- [ ] Add integration tests with Docker
- [ ] Add E2E tests with full message flow
- [ ] Add documentation

## Dependencies

- Phase 1: Factory Pattern (#18) for testability
