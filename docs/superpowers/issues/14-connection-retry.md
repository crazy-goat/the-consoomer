# Issue #14: Connection Retry on Failure

> **Phase:** [Phase 1: Foundation & DX](../phases/phase1-foundation.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** Automatically retries operations when connection fails.

**Business value:** Resilience to temporary network issues. Less manual intervention.

## Implementation in Symfony

- `Connection::withConnectionExceptionRetry()` — wraps operations with retry
- Max 3 retries on `AMQPConnectionException`
- `AmqpReceiver::ack()` and `AmqpReceiver::reject()` — reconnect on failure

## Current State in the-consoomer

❌ **Not implemented.**

Current code in `Receiver::ack()` and `Receiver::reject()`:
```php
public function ack(Envelope $envelope): void
{
    $stamp = $envelope->last(RawMessageStamp::class);
    if (!$stamp instanceof RawMessageStamp) {
        throw new \RuntimeException('No raw message stamp');
    }
    $this->ackMessage($stamp->amqpMessage);
    // No retry on connection failure
}
```

## Implementation Notes

### Requirements

1. `retry_count` option (default: 3)
2. `retry_delay` option (default: 100000 microseconds)
3. `Connection::withConnectionExceptionRetry()` method
4. Apply retry to connect, ack, reject operations

### Configuration

```
amqp-consoomer://user:pass@host:5672/vhost/exchange?retry_count=3&retry_delay=100000
```

### Usage in Current Codebase

**Before (no retry):**
```php
// Receiver::ack()
$this->ackMessage($stamp->amqpMessage);
// Fails on connection error

// Receiver::reject()
$this->queue->reject($stamp->amqpMessage->getDeliveryTag());
// Fails on connection error
```

**After (with retry):**
```php
// Receiver::ack()
$this->withConnectionExceptionRetry(function () use ($stamp) {
    $this->ackMessage($stamp->amqpMessage);
});

// Receiver::reject()
$this->withConnectionExceptionRetry(function () use ($stamp) {
    $this->queue->reject($stamp->amqpMessage->getDeliveryTag());
});
```

### How It Works

```php
private function withConnectionExceptionRetry(callable $operation): void
{
    $attempts = 0;
    $lastException = null;
    
    while ($attempts < $this->retryCount) {
        try {
            $operation();
            return;
        } catch (\AMQPConnectionException $exception) {
            $lastException = $exception;
            $attempts++;
            
            if ($attempts < $this->retryCount) {
                usleep($this->retryDelay);
            }
        }
    }
    
    throw $lastException;
}
```

### Exponential Backoff

- **Option**: `retry_backoff` (default: `false`)
- **Formula**: `delay = base_delay * (2 ^ attempt)`
- **Example**: 100ms, 200ms, 400ms, 800ms, 1600ms
- **Max delay**: `retry_max_delay` option (default: 30 seconds)

### Jitter

- **Option**: `retry_jitter` (default: `true`)
- **Purpose**: Avoid thundering herd problem
- **Implementation**: Add random ±25% to delay
- **Example**: 100ms ± 25ms = 75-125ms

### Circuit Breaker

- **Option**: `retry_circuit_breaker` (default: `false`)
- **Purpose**: Stop retrying after too many failures
- **Threshold**: `retry_circuit_breaker_threshold` (default: 10)
- **Timeout**: `retry_circuit_breaker_timeout` (default: 60 seconds)
- **State**: Closed (normal) → Open (stop retrying) → Half-Open (try once)

### Logging

- Log retry attempt: "Retry attempt {attempt}/{max_attempts} after {delay}ms"
- Log retry success: "Retry successful after {attempt} attempts"
- Log retry failure: "Retry failed after {max_attempts} attempts: {error_message}"
- Log circuit breaker: "Circuit breaker opened after {threshold} failures"

### Metrics

- **Retry count**: Number of retry attempts
- **Retry delay**: Delay between retries
- **Retry success rate**: Percentage of successful retries
- **Circuit breaker state**: Open/Closed/Half-Open

### Error Handling

- Throw `\AMQPConnectionException` after all retries exhausted
- Throw `\RuntimeException` if circuit breaker is open
- Log all retry attempts and failures
- Preserve original exception for debugging

### Performance Considerations

- Retry adds latency on connection failures
- Exponential backoff reduces load on RabbitMQ
- Jitter prevents thundering herd
- Circuit breaker prevents cascading failures

### Security Considerations

- Retry doesn't expose sensitive information
- Logging doesn't include credentials
- Circuit breaker prevents DoS on RabbitMQ

### Backward Compatibility

- **Breaking change**: New retry options
- **Migration path**: Existing code works without changes
- **New behavior**: Retry on connection failures by default
- **Configuration**: All retry options have default values

### Testing Strategy

**Unit Tests:**
- Test retry with mocked AMQP objects
- Test exponential backoff
- Test jitter
- Test circuit breaker
- Test error handling

**Integration Tests:**
- Test with real RabbitMQ using Docker
- Test retry on connection failures
- Test retry on ack/reject failures
- Test circuit breaker with real failures

**E2E Tests:**
- Full publish/consume cycle with retry
- Test message flow works end-to-end
- Test retry on connection failures

### Implementation Checklist

- [ ] Add `retry_count` and `retry_delay` options
- [ ] Add `retry_backoff` and `retry_max_delay` options
- [ ] Add `retry_jitter` option
- [ ] Add `retry_circuit_breaker` options
- [ ] Implement `withConnectionExceptionRetry()` in Receiver
- [ ] Apply retry to connect operation
- [ ] Apply retry to ack/reject in Receiver
- [ ] Add exponential backoff
- [ ] Add jitter
- [ ] Add circuit breaker
- [ ] Add logging
- [ ] Add metrics
- [ ] Add unit tests with mocked AMQP objects
- [ ] Add integration tests with Docker
- [ ] Add E2E tests with full message flow
- [ ] Add documentation

## Dependencies

- Phase 1: Factory Pattern (#18) for testability
