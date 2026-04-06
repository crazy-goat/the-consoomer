# Issue #13: Publisher Confirms

> **Phase:** [Phase 4: Production Ready](../phases/phase4-production-ready.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** Waits for broker confirmation after publish. Guarantees message was received.

**Business value:** Ensures messages aren't lost during publish. Critical for financial transactions, orders.

## Implementation in Symfony

- `confirm_timeout` option — wait time in seconds
- `Connection::channel()` — calls `confirmSelect()`
- `Connection::publishOnExchange()` — calls `waitForConfirm()`

## Current State in the-consoomer

❌ **Not implemented.** Fire-and-forget publishing.

Current code in `Sender::send()`:
```php
$this->exchange->publish(
    $data['body'],
    $routingKey,
    null,
    $data['headers'] ?? [],
);
// No confirmation - fire-and-forget
```

## Implementation Notes

### Requirements

1. `confirm_timeout` option
2. Enable confirm mode on channel with `confirmSelect()`
3. Call `waitForConfirm()` after publish
4. Throw exception if confirmation not received in time

### Configuration

```
amqp-consoomer://user:pass@host:5672/vhost/exchange?confirm_timeout=5
```

### Usage in Current Codebase

**Before (fire-and-forget):**
```php
// Sender::send()
$this->exchange->publish(
    $data['body'],
    $routingKey,
    null,
    $data['headers'] ?? [],
);
// No confirmation - message may be lost
```

**After (with confirmation):**
```php
// Sender::send()
if ($this->confirmTimeout > 0) {
    $this->channel->confirmSelect();
}

$this->exchange->publish(
    $data['body'],
    $routingKey,
    null,
    $data['headers'] ?? [],
);

if ($this->confirmTimeout > 0) {
    $this->channel->waitForConfirm($this->confirmTimeout);
}
```

### Confirm Mode

```php
private function enableConfirmMode(): void
{
    if ($this->confirmTimeout > 0) {
        $this->channel->confirmSelect();
    }
}
```

### Confirmation Waiting

```php
private function waitForConfirmation(): void
{
    if ($this->confirmTimeout > 0) {
        try {
            $this->channel->waitForConfirm($this->confirmTimeout);
        } catch (\AMQPException $exception) {
            throw new \RuntimeException(
                "Publisher confirm timeout after {$this->confirmTimeout} seconds",
                0,
                $exception
            );
        }
    }
}
```

### Validation

- **confirm_timeout**: Must be non-negative integer (seconds)
- **confirm_timeout**: 0 means disabled

### Error Handling

- Throw `\RuntimeException` if confirmation timeout
- Throw `\AMQPException` if confirmation fails
- Log confirmation success
- Log confirmation timeout
- Log confirmation error

### Logging

- Log confirmation enabled: "Publisher confirms enabled with timeout: {timeout}s"
- Log confirmation success: "Publisher confirm received"
- Log confirmation timeout: "Publisher confirm timeout after {timeout}s"
- Log confirmation error: "Publisher confirm error: {error_message}"

### Metrics

- **Confirm count**: Number of confirms
- **Confirm success rate**: Percentage of successful confirms
- **Confirm timeout count**: Number of timeouts
- **Confirm latency**: Average confirm latency

### Performance Considerations

- Confirm mode adds ~1-10ms latency per publish
- Confirm timeout adds ~timeout seconds latency on failure
- No performance impact when disabled
- Confirm mode is optional

### Security Considerations

- **Confirmation**: Ensures message delivery
- **Logging**: Don't log sensitive confirmation information
- **Timeout**: Prevents indefinite waiting

### Backward Compatibility

- **Breaking change**: New confirm_timeout option
- **Migration path**: Existing code works without changes
- **New behavior**: Confirm mode disabled by default
- **Configuration**: All confirm options have default values

### Testing Strategy

**Unit Tests:**
- Test confirm mode with mocked AMQP objects
- Test confirmation waiting with mocked AMQP objects
- Test timeout handling
- Test error handling

**Integration Tests:**
- Test with real RabbitMQ using Docker
- Test confirm mode
- Test confirmation waiting
- Test timeout handling

**E2E Tests:**
- Full publish/consume cycle with confirms
- Test message flow works end-to-end
- Test confirmation delivery

### Implementation Checklist

- [ ] Add `confirm_timeout` option
- [ ] Enable confirm mode with `confirmSelect()` in Sender
- [ ] Implement confirmation waiting after publish
- [ ] Throw `RuntimeException` on timeout
- [ ] Add validation
- [ ] Add error handling
- [ ] Add logging
- [ ] Add metrics
- [ ] Add unit tests
- [ ] Add integration tests with Docker
- [ ] Add E2E tests with full message flow
- [ ] Add documentation

## Dependencies

- Phase 1: Factory Pattern (#18) for testability
- Phase 2: Transport Setup/Close (#17) for lifecycle management
