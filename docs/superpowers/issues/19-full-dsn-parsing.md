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

## Implementation Notes

### Requirements

1. Enhanced DSN parsing for all options
2. `validateOptions()` method
3. `normalizeQueueArguments()` for type conversion
4. Support 20+ connection, queue, and exchange options

### Supported Options

**Connection Options:**
- `read_timeout`, `write_timeout`, `connect_timeout`
- `heartbeat`
- `retry_count`, `retry_delay`
- `ssl_*` options for TLS

**Queue Options:**
- `queue`
- `queues` (array for multiple queues)
- `queue_flags` (durable, autodelete, etc.)
- `queue_arguments` (x-max-priority, x-message-ttl, etc.)
- `binding_keys`, `binding_arguments`

**Exchange Options:**
- `exchange`
- `exchange_type` (direct, fanout, topic, headers)
- `exchange_flags`
- `default_publish_routing_key`

### Implementation Checklist

- [ ] Enhance DSN parsing to handle all options
- [ ] Add `validateOptions()` method
- [ ] Add `normalizeQueueArguments()` method
- [ ] Add support for queue_arguments (typed)
- [ ] Add tests
- [ ] Add documentation

## Dependencies

- None (standalone improvement)
