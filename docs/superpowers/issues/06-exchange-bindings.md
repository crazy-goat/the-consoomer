# Issue #6: Exchange-to-Exchange Bindings

> **Phase:** [Phase 3: Advanced Routing](../phases/phase3-advanced-routing.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** Binds one exchange to another. Enables complex routing topologies.

**Business value:** Advanced message routing architectures. Federation between exchanges.

## Implementation in Symfony

- `exchange[bindings][name][binding_keys]` — source exchange bindings
- `Connection::setupExchangeAndQueues()` — creates exchange-to-exchange bindings

## Current State in the-consoomer

❌ **Not implemented.**

Current code in `Sender::connect()`:
```php
$this->exchange = new \AMQPExchange(new \AMQPChannel($this->connection));
$this->exchange->setName($this->options['exchange'] ?? '');
// No exchange-to-exchange bindings
```

## Implementation Notes

### Requirements

1. `exchange[bindings]` option structure
2. `Connection::setupExchangeBindings()` method
3. Bind source exchange to target exchange with routing keys

### Options Format

```php
[
    'exchange' => 'my_exchange',
    'exchange_bindings' => [
        [
            'target' => 'target_exchange',
            'routing_keys' => ['key1', 'key2'],
        ],
    ],
]
```

### Usage in Current Codebase

**Before (no exchange bindings):**
```php
// Sender::connect()
$this->exchange = new \AMQPExchange(new \AMQPChannel($this->connection));
$this->exchange->setName($this->options['exchange'] ?? '');
// No exchange-to-exchange bindings
```

**After (with exchange bindings):**
```php
// Sender::connect()
$this->exchange = new \AMQPExchange(new \AMQPChannel($this->connection));
$this->exchange->setName($this->options['exchange'] ?? '');
$this->exchange->declareExchange();
$this->setupExchangeBindings();

// setupExchangeBindings()
private function setupExchangeBindings(): void
{
    $bindings = $this->options['exchange_bindings'] ?? [];
    
    foreach ($bindings as $binding) {
        $target = $binding['target'];
        $routingKeys = $binding['routing_keys'] ?? [];
        
        foreach ($routingKeys as $routingKey) {
            $this->exchange->bind($target, $routingKey);
        }
    }
}
```

### Exchange Binding Creation

```php
private function setupExchangeBindings(): void
{
    $bindings = $this->options['exchange_bindings'] ?? [];
    
    foreach ($bindings as $binding) {
        $target = $binding['target'];
        $routingKeys = $binding['routing_keys'] ?? [];
        
        foreach ($routingKeys as $routingKey) {
            $this->exchange->bind($target, $routingKey);
        }
    }
}
```

### Validation

- **exchange_bindings**: Must be array
- **target**: Must be non-empty string
- **routing_keys**: Must be array of strings

### Error Handling

- Throw `\InvalidArgumentException` for invalid exchange bindings
- Throw `\InvalidArgumentException` for invalid target
- Throw `\InvalidArgumentException` for invalid routing keys
- Throw `\AMQPException` if exchange binding creation fails
- Log exchange binding creation
- Log exchange binding error

### Logging

- Log exchange binding creation: "Bound exchange {source} to exchange {target} with key {routing_key}"
- Log exchange binding error: "Failed to bind exchange {source}: {error_message}"

### Metrics

- **Exchange binding count**: Number of exchange bindings created
- **Routing key count**: Number of routing keys per binding
- **Binding success rate**: Percentage of successful bindings

### Performance Considerations

- Exchange binding creation adds ~1-5ms latency per binding
- No performance impact on message flow
- Exchange bindings are persistent

### Security Considerations

- **Exchange bindings**: May contain sensitive information
- **Logging**: Don't log sensitive binding information
- **Permissions**: Requires configure/write permissions

### Backward Compatibility

- **Breaking change**: New exchange binding options
- **Migration path**: Existing code works without changes
- **New behavior**: Exchange binding is optional
- **Configuration**: All exchange binding options have default values

### Testing Strategy

**Unit Tests:**
- Test exchange binding creation with mocked AMQP objects
- Test exchange binding validation
- Test error handling

**Integration Tests:**
- Test with real RabbitMQ using Docker
- Test exchange binding creation
- Test message routing with exchange bindings
- Test complex routing topologies

**E2E Tests:**
- Full publish/consume cycle with exchange bindings
- Test message flow works end-to-end
- Test routing topologies

### Implementation Checklist

- [ ] Add exchange_bindings option parsing
- [ ] Implement `setupExchangeBindings()` method
- [ ] Update auto-setup to create bindings
- [ ] Add validation
- [ ] Add error handling
- [ ] Add logging
- [ ] Add metrics
- [ ] Add unit tests
- [ ] Add integration tests with Docker
- [ ] Add E2E tests with full message flow
- [ ] Add documentation

## Dependencies

- Phase 1: Auto-Setup (#1) for exchange creation
