# Missing Features — Business Overview

This document describes features available in `symfony/amqp-messenger` that are **not yet implemented** in `the-consoomer`.

---

## 1. Infrastructure Auto-Setup

**What it does:** Automatically creates exchanges, queues, and bindings when the transport starts. No manual RabbitMQ configuration needed.

**Business value:** Developers don't need to manually configure RabbitMQ. The transport self-configures on first use.

**Implementation in Symfony:**
- `Connection::setup()` — creates exchange, queues, bindings
- `Connection::setupExchangeAndQueues()` — declares exchange and binds queues
- `Connection::setupDelayExchange()` — creates delay exchange for retries
- Triggered automatically on first `get()` or `publish()` when `auto_setup: true`

**Current state in the-consoomer:** ❌ Not implemented. User must manually create queues and exchanges.

---

## 2. Delayed Messages

**What it does:** Sends messages that should be processed after a delay (e.g., "send email in 5 minutes").

**Business value:** Enables scheduling without external tools. Useful for retry delays, scheduled notifications, rate limiting.

**Implementation in Symfony:**
- `Connection::publishWithDelay()` — routes message through delay exchange
- `Connection::createDelayQueue()` — creates temporary queue with TTL + dead-letter exchange
- After TTL expires, message returns to original queue
- Uses `x-message-ttl` and `x-dead-letter-exchange` RabbitMQ features
- Configurable via `delay[exchange_name]` and `delay[queue_name_pattern]`

**Current state in the-consoomer:** ❌ Not implemented. No delay support.

---

## 3. Retry with Proper Routing

**What it does:** When a message fails and is retried, it uses different routing to avoid infinite loops.

**Business value:** Prevents message loss during failures. Ensures failed messages are properly re-queued.

**Implementation in Symfony:**
- `AmqpStamp::isRetryAttempt()` — marks message as retry
- `Connection::getRoutingKeyForDelay()` — adds `_retry` suffix to routing key
- Different dead-letter-exchange for retry vs delay

**Current state in the-consoomer:** ❌ Not implemented. No retry routing support.

---

## 4. Multiple Queues per Transport

**What it does:** One transport can consume from multiple queues with different configurations.

**Business value:** Single worker can process messages from multiple queues. Enables priority queues, different message types per queue.

**Implementation in Symfony:**
- `Connection::$queuesOptions` — array of queue configurations
- `Connection::getQueueNames()` — returns all queue names
- `AmqpReceiver::getFromQueues()` — fetches from specific queues
- `QueueReceiverInterface` — allows `--queues` CLI option

**Current state in the-consoomer:** ❌ Only single queue via `queue` option.

---

## 5. Queue Bindings (Routing Keys)

**What it does:** Binds queues to exchanges with specific routing keys. Enables topic/fanout routing patterns.

**Business value:** Flexible message routing. Different consumers can receive different message types from same exchange.

**Implementation in Symfony:**
- `queues[name][binding_keys]` — routing keys for queue binding
- `queues[name][binding_arguments]` — additional binding arguments
- `Connection::setupExchangeAndQueues()` — creates bindings during setup

**Current state in the-consoomer:** ❌ No binding support. Queue must already exist and be bound.

---

## 6. Exchange-to-Exchange Bindings

**What it does:** Binds one exchange to another. Enables complex routing topologies.

**Business value:** Advanced message routing architectures. Federation between exchanges.

**Implementation in Symfony:**
- `exchange[bindings][name][binding_keys]` — source exchange bindings
- `Connection::setupExchangeAndQueues()` — creates exchange-to-exchange bindings

**Current state in the-consoomer:** ❌ Not implemented.

---

## 7. Full AmqpStamp (Message Attributes)

**What it does:** Control all AMQP message attributes: routing key, flags, headers, content_type, delivery_mode, priority, message_id, etc.

**Business value:** Fine-grained control over message behavior. Priority queuing, custom headers, message IDs for tracking.

**Implementation in Symfony:**
- `AmqpStamp` with `$routingKey`, `$flags`, `$attributes`
- `AmqpStamp::createFromAmqpEnvelope()` — preserves attributes on retry
- `AmqpStamp::createWithAttributes()` — merge attributes
- Attributes: `content_type`, `content_encoding`, `delivery_mode`, `priority`, `timestamp`, `app_id`, `message_id`, `user_id`, `expiration`, `type`, `reply_to`, `correlation_id`, `headers`

**Current state in the-consoomer:** ⚠️ Basic `AmqpStamp` with only `routingKey`. No flags or attributes.

---

## 8. Message Priority

**What it does:** Send messages with priority. Higher priority messages are processed first.

**Business value:** Critical messages processed before routine ones. VIP user requests, urgent notifications.

**Implementation in Symfony:**
- `AmqpPriorityStamp` — sets message priority
- Requires queue with `x-max-priority` argument
- `AmqpSender::send()` — extracts priority from stamp

**Current state in the-consoomer:** ❌ Not implemented.

---

## 9. Received Message Metadata

**What it does:** Access to original AMQP envelope after receiving message.

**Business value:** Access to message metadata: timestamp, app_id, message_id, headers. Useful for debugging, auditing, correlation.

**Implementation in Symfony:**
- `AmqpReceivedStamp` — stores original `\AMQPEnvelope` and queue name
- `AmqpReceivedStamp::getAmqpEnvelope()` — access to all message attributes
- `AmqpReceivedStamp::getQueueName()` — which queue message came from

**Current state in the-consoomer:** ⚠️ Has `RawMessageStamp` but minimal functionality.

---

## 10. TLS/SSL Encryption

**What it does:** Secure connection to RabbitMQ over SSL/TLS.

**Business value:** Required for production environments. Compliance with security standards.

**Implementation in Symfony:**
- `amqps://` DSN scheme
- `cacert`, `cert`, `key`, `verify` options
- `Connection::hasCaCertConfigured()` — validates SSL config
- Default port 5671 for AMQPS

**Current state in the-consoomer:** ❌ Not implemented. No SSL support.

---

## 11. Connection Heartbeat

**What it does:** Keeps connection alive. Detects dead connections.

**Business value:** Prevents connection drops in long-running workers. Better reliability.

**Implementation in Symfony:**
- `heartbeat` option — interval in seconds
- `Connection::channel()` — tracks `$lastActivityTime`
- Auto-disconnect when `time() > lastActivityTime + 2 * heartbeat` and no in-flight messages

**Current state in the-consoomer:** ❌ Not implemented.

---

## 12. Persistent Connections

**What it does:** Reuses connections across requests. Reduces connection overhead.

**Business value:** Better performance in high-throughput scenarios. Less connection churn.

**Implementation in Symfony:**
- `persistent: true` option
- Uses `pconnect()` instead of `connect()`
- `pdisconnect()` for cleanup

**Current state in the-consoomer:** ❌ Not implemented.

---

## 13. Publisher Confirms

**What it does:** Waits for broker confirmation after publish. Guarantees message was received.

**Business value:** Ensures messages aren't lost during publish. Critical for financial transactions, orders.

**Implementation in Symfony:**
- `confirm_timeout` option — wait time in seconds
- `Connection::channel()` — calls `confirmSelect()`
- `Connection::publishOnExchange()` — calls `waitForConfirm()`

**Current state in the-consoomer:** ❌ Not implemented. Fire-and-forget publishing.

---

## 14. Connection Retry on Failure

**What it does:** Automatically retries operations when connection fails.

**Business value:** Resilience to temporary network issues. Less manual intervention.

**Implementation in Symfony:**
- `Connection::withConnectionExceptionRetry()` — wraps operations with retry
- Max 3 retries on `AMQPConnectionException`
- `AmqpReceiver::ack()` and `AmqpReceiver::reject()` — reconnect on failure

**Current state in the-consoomer:** ❌ Not implemented.

---

## 15. Message Count

**What it does:** Returns approximate number of messages in queues.

**Business value:** Monitoring queue depth. Scaling decisions based on backlog.

**Implementation in Symfony:**
- `MessageCountAwareInterface` — interface for message count
- `Connection::countMessagesInQueues()` — sum of `declareQueue()` results
- `AmqpReceiver::getMessageCount()` — exposes count
- `messenger:stats` command uses this

**Current state in the-consoomer:** ❌ Not implemented.

---

## 16. Queue Purge

**What it does:** Removes all messages from queues.

**Business value:** Clear stuck messages during development/testing. Reset queue state.

**Implementation in Symfony:**
- `Connection::purgeQueues()` — calls `purge()` on each queue

**Current state in the-consoomer:** ❌ Not implemented.

---

## 17. Transport Setup/Close

**What it does:** Explicit setup and teardown of transport resources.

**Business value:** Control over when infrastructure is created. Clean shutdown.

**Implementation in Symfony:**
- `SetupableTransportInterface` — `setup()` method
- `CloseableTransportInterface` — `close()` method
- `AmqpTransport::setup()` — creates exchanges/queues
- `AmqpTransport::close()` — clears connection

**Current state in the-consoomer:** ❌ Not implemented.

---

## 18. Testable Architecture (Factory Pattern)

**What it does:** Factory for creating AMQP objects. Enables mocking in tests.

**Business value:** Easier unit testing. Can mock connections, channels, queues.

**Implementation in Symfony:**
- `AmqpFactory` — creates `\AMQPConnection`, `\AMQPChannel`, `\AMQPQueue`, `\AMQPExchange`
- Injected into `Connection` constructor
- Used throughout codebase instead of `new` keyword

**Current state in the-consoomer:** ❌ Direct instantiation. Hard to test.

---

## 19. Full DSN Parsing

**What it does:** Parses DSN with all options. Validates configuration.

**Business value:** Single string configuration. All options in one place.

**Implementation in Symfony:**
- `Connection::fromDsn()` — parses `amqp://user:pass@host:port/vhost/exchange?options`
- `Connection::validateOptions()` — validates all options
- `Connection::normalizeQueueArguments()` — converts types
- Supports 20+ connection options, queue options, exchange options

**Current state in the-consoomer:** ⚠️ Basic DSN parsing. Limited options.

---

## 20. Default Publish Routing Key

**What it does:** Default routing key when none specified on message.

**Business value:** Simplifies publishing. Consistent routing without stamps.

**Implementation in Symfony:**
- `exchange[default_publish_routing_key]` option
- `Connection::getDefaultPublishRoutingKey()` — returns default
- `Connection::getRoutingKeyForMessage()` — uses default if no stamp

**Current state in the-consoomer:** ⚠️ Has `routing_key` option but limited.

---

## Implementation Roadmap

All features are organized into phases. See [Implementation Roadmap](./docs/superpowers/implementation-roadmap.md) for full details.

### Phase 0: Test Infrastructure ✅
- Test infrastructure (PHPUnit, Docker, E2E tests)

### [Phase 1: Foundation](./docs/superpowers/phases/phase1-foundation.md) 🚧
- #18 Factory Pattern
- #14 Connection Retry
- #11 Heartbeat
- #10 TLS/SSL

### [Phase 2: Core Messaging](./docs/superpowers/phases/phase2-core-messaging.md) 📋
- #1 Auto-Setup
- #7 Full AmqpStamp
- #9 Received Message Metadata
- #17 Transport Setup/Close
- #19 Full DSN Parsing
- #20 Default Publish Routing Key

### [Phase 3: Advanced Routing](./docs/superpowers/phases/phase3-advanced-routing.md) 📋
- #2 Delayed Messages
- #3 Retry with Proper Routing
- #5 Queue Bindings
- #6 Exchange-to-Exchange Bindings

### [Phase 4: Production Ready](./docs/superpowers/phases/phase4-production-ready.md) 📋
- #12 Persistent Connections
- #13 Publisher Confirms
- #15 Message Count
- #16 Queue Purge

### [Phase 5: Advanced Features](./docs/superpowers/phases/phase5-advanced-features.md) 📋
- #4 Multiple Queues per Transport
- #8 Message Priority

---

## Summary Table

| Feature | Business Value | Symfony Implementation | the-consoomer |
|---------|---------------|------------------------|---------------|
| Auto-Setup | No manual RabbitMQ config | `Connection::setup()` | ❌ |
| Delayed Messages | Scheduling without external tools | `Connection::publishWithDelay()` | ❌ |
| Retry Routing | Prevents message loss | `AmqpStamp::isRetryAttempt()` | ❌ |
| Multiple Queues | Single worker, multiple queues | `QueueReceiverInterface` | ❌ |
| Queue Bindings | Flexible routing | `binding_keys` option | ❌ |
| Exchange Bindings | Complex routing topologies | `exchange[bindings]` | ❌ |
| Full AmqpStamp | Fine-grained message control | `$flags`, `$attributes` | ⚠️ Basic |
| Message Priority | Critical messages first | `AmqpPriorityStamp` | ❌ |
| Received Metadata | Debugging, auditing | `AmqpReceivedStamp` | ⚠️ Basic |
| TLS/SSL | Security compliance | `amqps://`, cert options | ❌ |
| Heartbeat | Connection reliability | `heartbeat` option | ❌ |
| Persistent Connections | Performance | `persistent` option | ❌ |
| Publisher Confirms | Message guarantee | `confirm_timeout` | ❌ |
| Connection Retry | Resilience | `withConnectionExceptionRetry()` | ❌ |
| Message Count | Monitoring, scaling | `MessageCountAwareInterface` | ❌ |
| Queue Purge | Development/testing | `purgeQueues()` | ❌ |
| Setup/Close | Resource control | Interfaces | ❌ |
| Factory Pattern | Testability | `AmqpFactory` | ❌ |
| Full DSN Parsing | Configuration simplicity | `fromDsn()` | ⚠️ Basic |
| Default Routing Key | Simplified publishing | `default_publish_routing_key` | ⚠️ Basic |

---
