# Issue #2: Delayed Messages

> **Phase:** [Phase 3: Advanced Routing](../phases/phase3-advanced-routing.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** Sends messages that should be processed after a delay (e.g., "send email in 5 minutes").

**Business value:** Enables scheduling without external tools. Useful for retry delays, scheduled notifications, rate limiting.

## Implementation in Symfony

- `Connection::publishWithDelay()` — routes message through delay exchange
- `Connection::createDelayQueue()` — creates temporary queue with TTL + dead-letter exchange
- After TTL expires, message returns to original queue
- Uses `x-message-ttl` and `x-dead-letter-exchange` RabbitMQ features
- Configurable via `delay[exchange_name]` and `delay[queue_name_pattern]`

## Current State in the-consoomer

❌ **Not implemented.** No delay support.

Current code in `Sender::send()`:
```php
$this->exchange->publish(
    $data['body'],
    $this->options['routing_key'] ?? $stamp?->routingKey ?? '',
    null,
    $data['headers'] ?? [],
);
// No delay support
```

## Implementation Notes

### Requirements

1. `delay[exchange_name]` option for delay exchange
2. `delay[queue_name_pattern]` option for delay queue naming
3. `Connection::publishWithDelay()` method
4. `Connection::createDelayQueue()` method

### DSN Options

```
amqp-consoomer://user:pass@host:5672/vhost/exchange?delay[exchange_name]=delay_exchange&delay[queue_name_pattern]=delay_queue_{delay}_{queue}
```

### How It Works

```
Producer -> Delay Exchange -> Delay Queue (TTL) -> Dead Letter Exchange -> Original Queue -> Consumer
```

Message is published to delay exchange with `x-delay` header. Router creates temporary delay queue with TTL. When TTL expires, message is routed back via dead-letter-exchange to original queue.

### Usage in Current Codebase

**Before (no delay):**
```php
// Sender::send()
$this->exchange->publish(
    $data['body'],
    $routingKey,
    null,
    $data['headers'] ?? [],
);
// No delay - message processed immediately
```

**After (with delay):**
```php
// Sender::send()
$stamp = $envelope->last(AmqpDelayStamp::class);

if ($stamp && $stamp->delay > 0) {
    $this->publishWithDelay($data, $routingKey, $stamp->delay);
} else {
    $this->exchange->publish(
        $data['body'],
        $routingKey,
        null,
        $data['headers'] ?? [],
    );
}

// publishWithDelay()
private function publishWithDelay(array $data, string $routingKey, int $delay): void
{
    $delayQueueName = $this->createDelayQueue($routingKey, $delay);
    
    $this->exchange->publish(
        $data['body'],
        $delayQueueName,
        AMQP_NOPARAM,
        [
            'x-delay' => $delay,
            'x-dead-letter-exchange' => $this->options['exchange'],
            'x-dead-letter-routing-key' => $routingKey,
        ],
    );
}
```

### AmqpDelayStamp

```php
class AmqpDelayStamp
{
    public function __construct(
        public readonly int $delay, // Delay in milliseconds
    ) {
    }
}
```

### Delay Queue Creation

```php
private function createDelayQueue(string $routingKey, int $delay): string
{
    $queueName = str_replace(
        ['{delay}', '{queue}'],
        [$delay, $routingKey],
        $this->options['delay']['queue_name_pattern']
    );
    
    $queue = new \AMQPQueue($this->channel);
    $queue->setName($queueName);
    $queue->setFlags(AMQP_DURABLE);
    $queue->setArgument('x-message-ttl', $delay);
    $queue->setArgument('x-dead-letter-exchange', $this->options['exchange']);
    $queue->setArgument('x-dead-letter-routing-key', $routingKey);
    $queue->declareQueue();
    $queue->bind($this->options['delay']['exchange_name'], $queueName);
    
    return $queueName;
}
```

### Validation

- **delay**: Must be positive integer (milliseconds)
- **exchange_name**: Must be non-empty string
- **queue_name_pattern**: Must contain {delay} and {queue} placeholders

### Error Handling

- Throw `\InvalidArgumentException` for invalid delay
- Throw `\AMQPException` if delay queue creation fails
- Throw `\AMQPException` if publish with delay fails
- Log delay queue creation
- Log delay publish

### Logging

- Log delay queue creation: "Created delay queue: {queue_name}"
- Log delay publish: "Published message with delay: {delay}ms"
- Log delay error: "Failed to publish with delay: {error_message}"

### Metrics

- **Delay count**: Number of delayed messages
- **Delay duration**: Average delay duration
- **Delay queue count**: Number of delay queues
- **Delay success rate**: Percentage of successful delays

### Performance Considerations

- Delay queue creation adds ~10-50ms latency
- Delay publish adds ~1-5ms latency
- Delay queues consume memory
- Delay queues are cleaned up after TTL expires

### Security Considerations

- **Delay queues**: May contain sensitive information
- **Logging**: Don't log sensitive delay information
- **Permissions**: Requires configure/write permissions

### Backward Compatibility

- **Breaking change**: New delay options
- **Migration path**: Existing code works without changes
- **New behavior**: Delay is optional
- **Configuration**: All delay options have default values

### Testing Strategy

**Unit Tests:**
- Test delay queue creation with mocked AMQP objects
- Test delay publish with mocked AMQP objects
- Test AmqpDelayStamp validation
- Test error handling

**Integration Tests:**
- Test with real RabbitMQ using Docker
- Test delay queue creation
- Test delay publish
- Test message delivery after delay

**E2E Tests:**
- Full publish/consume cycle with delay
- Test message flow works end-to-end
- Test delay timing accuracy

### Implementation Checklist

- [ ] Add delay exchange/queue options
- [ ] Implement delay queue creation with TTL
- [ ] Implement `publishWithDelay()` method
- [ ] Handle `x-delay` header for custom delays
- [ ] Add `AmqpDelayStamp` for message delay specification
- [ ] Add validation
- [ ] Add error handling
- [ ] Add logging
- [ ] Add metrics
- [ ] Add unit tests
- [ ] Add integration tests with Docker
- [ ] Add E2E tests with full message flow
- [ ] Add documentation

## Dependencies

- Phase 1: Auto-Setup (#1) for exchange/queue creation
- Phase 2: Full AmqpStamp (#7) for message attributes
