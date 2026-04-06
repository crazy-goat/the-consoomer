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

### Usage

```php
$messages = $transport->get();

foreach ($messages as $envelope) {
    $receivedStamp = $envelope->last(AmqpReceivedStamp::class);
    
    if ($receivedStamp) {
        $messageId = $receivedStamp->getMessageId();
        $timestamp = $receivedStamp->getTimestamp();
        $headers = $receivedStamp->getHeaders();
    }
}
```

### Implementation Checklist

- [ ] Rename `RawMessageStamp` to `AmqpReceivedStamp`
- [ ] Add constructor with queue name
- [ ] Add all getter methods for envelope attributes
- [ ] Update Receiver to pass queue name
- [ ] Add tests
- [ ] Add documentation
- [ ] Update any existing code using RawMessageStamp

## Dependencies

- Phase 2: None (standalone improvement to existing stamp)
