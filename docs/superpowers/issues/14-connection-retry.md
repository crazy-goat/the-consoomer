# Issue #14: Connection Retry on Failure

> **Phase:** [Phase 1: Foundation](../phases/phase1-foundation.md)  
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

### Implementation Checklist

- [ ] Add `retry_count` and `retry_delay` options
- [ ] Implement `withConnectionExceptionRetry()` in Connection
- [ ] Apply retry to connect operation
- [ ] Apply retry to ack/reject in Receiver
- [ ] Add tests
- [ ] Add documentation

## Dependencies

- Phase 1: Factory Pattern (#18) for Connection class
