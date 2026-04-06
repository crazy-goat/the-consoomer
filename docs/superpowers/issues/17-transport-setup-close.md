# Issue #17: Transport Setup/Close

> **Phase:** [Phase 2: Core Messaging](../phases/phase2-core-messaging.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** Explicit setup and teardown of transport resources.

**Business value:** Control over when infrastructure is created. Clean shutdown.

## Implementation in Symfony

- `SetupableTransportInterface` — `setup()` method
- `CloseableTransportInterface` — `close()` method
- `AmqpTransport::setup()` — creates exchanges/queues
- `AmqpTransport::close()` — clears connection

## Current State in the-consoomer

❌ **Not implemented.**

## Implementation Notes

### Requirements

1. `SetupableTransportInterface` interface
2. `CloseableTransportInterface` interface
3. `AmqpTransport::setup()` method
4. `AmqpTransport::close()` method

### Interfaces

```php
interface SetupableTransportInterface
{
    public function setup(): void;
}

interface CloseableTransportInterface
{
    public function close(): void;
}

class AmqpTransport implements 
    TransportInterface,
    TransportFactoryInterface,
    SetupableTransportInterface,
    CloseableTransportInterface
{
    public function setup(): void;
    public function close(): void;
}
```

### Usage

```php
$transport = AmqpTransport::create($dsn, [], $serializer);

// Explicit setup (optional - also happens lazily)
$transport->setup();

// Use transport...
$transport->send($envelope);
$messages = $transport->get();

// Clean shutdown
$transport->close();
```

### Implementation Checklist

- [ ] Create `SetupableTransportInterface`
- [ ] Create `CloseableTransportInterface`
- [ ] Implement `AmqpTransport::setup()` using Auto-Setup
- [ ] Implement `AmqpTransport::close()`
- [ ] Add tests
- [ ] Add documentation

## Dependencies

- Phase 1: Auto-Setup (#1) for setup implementation
