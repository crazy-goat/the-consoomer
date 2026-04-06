# Issue #9: Received Message Metadata

> **Phase:** [Phase 2: Core Messaging](../phases/phase2-core-messaging.md)  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** Access to original AMQP envelope after receiving message.

**Business value:** Access to message metadata: timestamp, app_id, message_id, headers. Useful for debugging, auditing, correlation.

## Implementation in Symfony

- `AmqpReceivedStamp` — stores original `\AMQPEnvelope` and queue name
- `AmqpReceivedStamp::getAmqpEnvelope()` — access to all message attributes
- `AmqpReceivedStamp::getQueueName()` — which queue message came from

## Current State in the-consoomer

⚠️ **Has `RawMessageStamp` but minimal functionality.**

Current code in `src/RawMessageStamp.php`:
```php
class RawMessageStamp
{
    public function __construct(
        public readonly \AMQPEnvelope $amqpMessage,
    ) {
    }
}
```

## Implementation Notes

### Requirements

1. Enhance `RawMessageStamp` to `AmqpReceivedStamp`
2. Add `getAmqpEnvelope()` method
3. Add `getQueueName()` method
4. Preserve all envelope attributes

### Interface

```php
class AmqpReceivedStamp implements \Symfony\Component\Messenger\Stamp\NonSendableStampInterface
{
    public function __construct(\AMQPEnvelope $envelope, string $queueName);
    
    public function getAmqpEnvelope(): \AMQPEnvelope;
    public function getQueueName(): string;
    
    // Convenience methods for common attributes
    public function getMessageId(): ?string;
    public function getTimestamp(): ?int;
    public function getAppId(): ?string;
    public function getHeaders(): array;
    public function getCorrelationId(): ?string;
    public function getReplyTo(): ?string;
    public function getContentType(): ?string;
    public function getDeliveryMode(): int;
    public function getPriority(): int;
}
```

### Usage in Current Codebase

**Before (minimal stamp):**
```php
$messages = $transport->get();

foreach ($messages as $envelope) {
    $stamp = $envelope->last(RawMessageStamp::class);
    $body = $stamp->amqpMessage->getBody();
    // No access to metadata
}
```

**After (full stamp):**
```php
$messages = $transport->get();

foreach ($messages as $envelope) {
    $receivedStamp = $envelope->last(AmqpReceivedStamp::class);
    
    if ($receivedStamp) {
        $messageId = $receivedStamp->getMessageId();
        $timestamp = $receivedStamp->getTimestamp();
        $headers = $receivedStamp->getHeaders();
        $correlationId = $receivedStamp->getCorrelationId();
    }
}
```

### Supported Attributes

| Attribute | Method | Description |
|-----------|--------|-------------|
| `message_id` | `getMessageId()` | Message ID |
| `timestamp` | `getTimestamp()` | Unix timestamp |
| `app_id` | `getAppId()` | Application ID |
| `headers` | `getHeaders()` | Custom headers array |
| `correlation_id` | `getCorrelationId()` | Correlation ID |
| `reply_to` | `getReplyTo()` | Reply-to address |
| `content_type` | `getContentType()` | MIME type |
| `delivery_mode` | `getDeliveryMode()` | 1 (non-persistent) or 2 (persistent) |
| `priority` | `getPriority()` | 0-9 |

### Validation

- **envelope**: Must be valid `\AMQPEnvelope`
- **queueName**: Must be non-empty string
- **attributes**: Automatically extracted from envelope

### Error Handling

- Throw `\InvalidArgumentException` for invalid envelope
- Throw `\InvalidArgumentException` for empty queue name
- Return null for missing attributes
- Return empty array for missing headers

### Logging

- Log stamp creation: "Created AmqpReceivedStamp for queue: {queue_name}"
- Log stamp attributes: "Received message attributes: {attributes}"
- Log stamp error: "Invalid stamp: {error_message}"

### Metrics

- **Stamp count**: Number of stamps created
- **Attribute access**: Which attributes are accessed
- **Queue distribution**: Messages per queue

### Performance Considerations

- Stamp creation adds ~0.05ms latency
- Attribute extraction adds ~0.02ms latency
- No performance impact on message flow
- Stamp is lightweight object

### Security Considerations

- **Headers**: May contain sensitive information
- **User ID**: May contain sensitive information
- **Message ID**: May contain sensitive information
- **Logging**: Don't log sensitive attributes

### Backward Compatibility

- **Breaking change**: Rename `RawMessageStamp` to `AmqpReceivedStamp`
- **Migration path**: Update all references to `RawMessageStamp`
- **New behavior**: Queue name is now required
- **Configuration**: No configuration changes needed

### Testing Strategy

**Unit Tests:**
- Test stamp creation with envelope and queue name
- Test all getter methods
- Test validation
- Test error handling
- Test backward compatibility

**Integration Tests:**
- Test with real RabbitMQ using Docker
- Test message receipt with all attributes
- Test attribute extraction
- Test queue name preservation

**E2E Tests:**
- Full publish/consume cycle with received stamp
- Test message flow works end-to-end
- Test attribute preservation

### Implementation Checklist

- [ ] Rename `RawMessageStamp` to `AmqpReceivedStamp`
- [ ] Add constructor with queue name
- [ ] Add all getter methods for envelope attributes
- [ ] Add validation
- [ ] Add error handling
- [ ] Add logging
- [ ] Add metrics
- [ ] Update Receiver to pass queue name
- [ ] Update all references to `RawMessageStamp`
- [ ] Add unit tests
- [ ] Add integration tests with Docker
- [ ] Add E2E tests with full message flow
- [ ] Add documentation

## Dependencies

- Phase 2: None (standalone improvement to existing stamp)
