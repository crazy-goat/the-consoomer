# Issue #3: Retry with Proper Routing

> **Phase:** [Phase 3: Advanced Routing](../phases/phase3-advanced-routing.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** When a message fails and is retried, it uses different routing to avoid infinite loops.

**Business value:** Prevents message loss during failures. Ensures failed messages are properly re-queued.

## Implementation in Symfony

- `AmqpStamp::isRetryAttempt()` — marks message as retry
- `Connection::getRoutingKeyForDelay()` — adds `_retry` suffix to routing key
- Different dead-letter-exchange for retry vs delay

## Current State in the-consoomer

❌ **Not implemented.** No retry routing support.

Current code in `Receiver::reject()`:
```php
public function reject(Envelope $envelope): void
{
    $stamp = $envelope->last(RawMessageStamp::class);
    if (!$stamp instanceof RawMessageStamp) {
        throw new \RuntimeException('No raw message stamp');
    }
    
    $this->ackPending();
    $this->queue->reject($stamp->amqpMessage->getDeliveryTag());
    // No retry routing - message goes to dead letter exchange
}
```

## Implementation Notes

### Requirements

1. Extend `AmqpStamp` with retry flag
2. `AmqpStamp::isRetryAttempt()` method
3. `Connection::getRoutingKeyForRetry()` method (adds `_retry` suffix)
4. Different routing for retry messages

### How It Works

When Symfony Messenger retries a message:
1. It wraps the message with `AmqpStamp` marked as retry
2. The routing key gets `_retry` suffix
3. Consumer routes retry messages to special retry queue
4. After processing, message either succeeds or goes back to retry queue with incremented delay

### Usage in Current Codebase

**Before (no retry routing):**
```php
// Receiver::reject()
$this->queue->reject($stamp->amqpMessage->getDeliveryTag());
// Message goes to dead letter exchange
```

**After (with retry routing):**
```php
// Receiver::reject()
$stamp = $envelope->last(AmqpStamp::class);

if ($stamp && $stamp->isRetryAttempt()) {
    $routingKey = $this->getRoutingKeyForRetry($stamp->routingKey);
    $this->publishToRetryQueue($stamp->amqpMessage, $routingKey);
} else {
    $this->queue->reject($stamp->amqpMessage->getDeliveryTag());
}
```

### AmqpStamp Extension

```php
class AmqpStamp
{
    private bool $retryAttempt = false;
    
    public function isRetryAttempt(): bool
    {
        return $this->retryAttempt;
    }
    
    public function withRetryAttempt(bool $retry = true): self
    {
        $clone = clone $this;
        $clone->retryAttempt = $retry;
        return $clone;
    }
}
```

### Retry Queue Creation

```php
private function createRetryQueue(string $routingKey): string
{
    $queueName = $routingKey . '_retry';
    
    $queue = new \AMQPQueue($this->channel);
    $queue->setName($queueName);
    $queue->setFlags(AMQP_DURABLE);
    $queue->setArgument('x-dead-letter-exchange', $this->options['exchange']);
    $queue->setArgument('x-dead-letter-routing-key', $routingKey);
    $queue->declareQueue();
    $queue->bind($this->options['retry_exchange'], $queueName);
    
    return $queueName;
}
```

### Retry Exchange

- **Option**: `retry_exchange` (default: `{exchange}_retry`)
- **Purpose**: Separate exchange for retry messages
- **Binding**: Bound to retry queues with routing keys

### Validation

- **routing_key**: Must be non-empty string
- **retry_exchange**: Must be non-empty string
- **retry_attempt**: Must be boolean

### Error Handling

- Throw `\InvalidArgumentException` for invalid routing key
- Throw `\AMQPException` if retry queue creation fails
- Throw `\AMQPException` if publish to retry queue fails
- Log retry queue creation
- Log retry publish

### Logging

- Log retry queue creation: "Created retry queue: {queue_name}"
- Log retry publish: "Published message to retry queue: {queue_name}"
- Log retry error: "Failed to publish to retry queue: {error_message}"

### Metrics

- **Retry count**: Number of retry messages
- **Retry queue count**: Number of retry queues
- **Retry success rate**: Percentage of successful retries
- **Retry delay**: Average retry delay

### Performance Considerations

- Retry queue creation adds ~10-50ms latency
- Retry publish adds ~1-5ms latency
- Retry queues consume memory
- Retry queues are persistent

### Security Considerations

- **Retry queues**: May contain sensitive information
- **Logging**: Don't log sensitive retry information
- **Permissions**: Requires configure/write permissions

### Backward Compatibility

- **Breaking change**: New retry options
- **Migration path**: Existing code works without changes
- **New behavior**: Retry routing is optional
- **Configuration**: All retry options have default values

### Testing Strategy

**Unit Tests:**
- Test retry routing with mocked AMQP objects
- Test retry queue creation with mocked AMQP objects
- Test AmqpStamp retry flag
- Test error handling

**Integration Tests:**
- Test with real RabbitMQ using Docker
- Test retry queue creation
- Test retry publish
- Test message delivery after retry

**E2E Tests:**
- Full publish/consume cycle with retry
- Test message flow works end-to-end
- Test retry routing

### Implementation Checklist

- [ ] Extend `AmqpStamp` with retry attempt tracking
- [ ] Add `isRetryAttempt()` method
- [ ] Add `withRetryAttempt()` method
- [ ] Implement `getRoutingKeyForRetry()` in Receiver
- [ ] Implement retry queue creation
- [ ] Implement retry exchange
- [ ] Update Sender to use retry routing
- [ ] Add validation
- [ ] Add error handling
- [ ] Add logging
- [ ] Add metrics
- [ ] Add unit tests
- [ ] Add integration tests with Docker
- [ ] Add E2E tests with full message flow
- [ ] Add documentation

## Dependencies

- Phase 2: Full AmqpStamp (#7) for stamp extension
- Phase 3: Delayed Messages (#2) for retry queue setup
