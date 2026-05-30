# Changelog

## [Unreleased]

### Changed
- **BC BREAK**: Renamed `ConnectionRetry::$retryCount` constructor parameter to `maxAttempts` to clarify semantics (#228)
  - `retryCount` previously meant "total attempts" (ambiguous) — now `maxAttempts` explicitly means "maximum number of execution attempts including the first"
  - Config key `retry_count` in DSN/options remains unchanged and maps to `maxAttempts`
  - Validation added: `maxAttempts` must be at least 1 (throws `\InvalidArgumentException` for 0 or negative)
  - Direct `new ConnectionRetry(retryCount: ...)` calls must use `maxAttempts: ...` instead

### Fixed
- `normalizeValue` no longer silently truncates scientific-notation numbers (e.g. `1e3` → `1000.0` instead of `1`) — DSN query parameters like `read_timeout=1e5` now produce the correct float value (#246)
- "Consumer timeout" detection now uses exception **type** (`AMQPQueueException`) instead of fragile `str_contains` on message text — substring collision swallowed real errors with "Consumer timeout" in message, and wording variations caused benign timeouts to crash workers. Timeout is only swallowed when no messages were collected (true empty poll); partial batches are returned (#222)
- Permanent-failure classification now uses exception **type** (AMQPQueueException/AMQPExchangeException) instead of unreliable `getCode()` integer matching — ext-amqp frequently returns 0 or a librabbitmq errno instead of the AMQP reply code, so a resource-not-found error with code 0 was incorrectly retried, and a connection-level error with code 404 was incorrectly treated as permanent (#224)
  - `AMQPConnectionException` / `AMQPChannelException` are always transient (reconnectable)
  - `AMQPQueueException` / `AMQPExchangeException` are always permanent (resource errors won't resolve on retry)
  - Generic `AMQPException` still falls back to code matching for backward compatibility
- Circuit-breaker HALF_OPEN single-probe semantics are no longer defeated by the retry loop — when the circuit transitions to HALF_OPEN the operation is executed exactly once (not `retryCount` times), preserving the failure-isolation the feature advertises (#223)
- Retry jitter is now applied BEFORE the `retry_max_delay` cap — jitter no longer pushes the effective delay up to 25% above the configured maximum (#225)
- `AmqpStamp::createFromAmqpEnvelope()` no longer drops `priority`, `delivery_mode`, or `timestamp` when their value is `0` — priority 0 is a valid AMQP level that was lost on receive→re-send round-trips (#226)
- `AmqpPriorityStamp` priority cap relaxed from 9 to 255 — RabbitMQ supports up to 255 via `x-max-priority` queue argument (#227)
- Topology is now re-declared after reconnect — `setupPerformed` flag is reset on reconnect so exchanges, queues, and bindings are re-declared on the new connection/channel (#229)
  - Added `InfrastructureSetupInterface::resetSetup()` to allow clearing the setup-once flag
  - `Receiver::ensureConnected()` calls `resetSetup()` after reconnecting
  - `Receiver::get()`, `purgeQueue()`, and `getMessageCount()` reordered to run `setup()` after `ensureConnected()` — topology is declared on the fresh connection before consumers are created
- Circuit-breaker elapsed-time now uses monotonic clock (`hrtime(true)`) instead of wall-clock `DateTimeImmutable` — prevents NTP backward step from sticking the circuit OPEN (#237)
  - Added `ClockInterface::monotonic(): float` method backed by `hrtime(true)` in `SystemClock`
  - `CircuitBreaker::recordFailure()` stores monotonic timestamp for elapsed measurement
  - `CircuitBreaker::isAvailable()` computes elapsed from monotonic clock — immune to wall-clock corrections
- Decoupled `max_unacked_messages` into three separate concerns: QoS prefetch, per-`get()` return-batch size, and ack-batch flush threshold (#238)
  - Added `batch_size` option (default: 1) for per-`get()` return-batch size
  - `max_unacked_messages` now controls only QoS prefetch and ack-batch flush threshold
  - Added `Receiver::close()` / `AmqpTransport::close()` flush of pending acks on worker shutdown
  - Eager consumption no longer delays first message until full batch is collected
  - Lost ack redelivery on worker stop is eliminated — pending acks are flushed before disconnect
- `Receiver::get()` no longer silently stalls on server-side consumer cancellation — catching `\AMQPException` now resets consumer state (`queues`, `unacked`, `lastUnacked`) and clears channel cache, forcing fresh consumer re‑registration on the next `get()` call (#221)
  - Previously, `AMQP_JUST_CONSUME` against a dead consumer tag blocked forever or panicked silently
  - Caught exceptions now trigger `ConnectionInterface::clearChannelCache()`, queue‑list reset, and unacked‑state reset

## [v0.2.0] - 2026-04-22

### Added
- `AmqpReceivedStamp` - new stamp for received messages with queue name and metadata accessors (#25)
  - Provides `getQueueName()`, `getAmqpEnvelope()` methods
  - Convenience getters for envelope attributes: `getMessageId()`, `getTimestamp()`, `getAppId()`, `getHeaders()`, `getCorrelationId()`, `getReplyTo()`, `getContentType()`, `getDeliveryMode()`, `getPriority()`
  - Replaces `RawMessageStamp`
- `AmqpStamp` extended with full AMQP message attributes support (#23)
  - Added `$flags` (int) and `$attributes` (array) parameters
  - Added getters: `getRoutingKey()`, `getFlags()`, `getAttributes()`
  - Added immutable withers: `withRoutingKey()`, `withFlags()`, `withAttribute()`
  - Added factory methods: `createFromAmqpEnvelope()`, `createWithAttributes()`
- `Sender::send()` now uses stamp flags and merges stamp attributes with headers
- `CloseableTransportInterface` support - enables explicit connection closing (#30)
  - `AmqpTransport::close()` method to disconnect AMQP connection and clear channel cache
  - `ConnectionInterface::close()` and `Connection::close()` for low-level disconnect
  - Polyfill for Symfony 6.4 compatibility
- `default_publish_routing_key` option for `Sender` (#32)
  - Separate routing key configuration for publishing vs. consumer binding
  - Backward compatible with existing `routing_key` option
- Batch fetching in `Receiver::get()` - fetches multiple messages up to `maxUnackedMessages` (#40)
- Array shape annotations for `AmqpStamp` and `AmqpReceivedStamp` (#182)
- PHPStan level 3 static analysis support

### Changed
- **BC BREAK**: `Sender` no longer reads `routing_key` option — use `default_publish_routing_key` for publish defaults (#180)
  - `routing_key` is now exclusively used by `Receiver` for queue binding
  - `default_publish_routing_key` is exclusively used by `Sender` for publish routing
  - This prevents unintended coupling between consumer binding and publisher routing
- **BC BREAK**: `AmqpStamp` properties changed from `public` to `private` (#23)
  - Direct property access (`$stamp->routingKey`) no longer works — use `$stamp->getRoutingKey()` instead
  - Default `routingKey` changed from `''` to `null`
- **BC BREAK**: `AmqpReceivedStamp` uses private properties with getters only (#184)
  - Renamed internal `$amqpMessage` to `$envelope` to match `getAmqpEnvelope()` getter
  - All call sites must use getter methods
- `AmqpTransport` constructor now requires `InfrastructureSetup` parameter (BC break for direct instantiation)
- `Receiver::get()` now batch-fetches messages instead of single message retrieval
- Replaced `eval()`-based `CloseableTransportInterface` polyfill with stub file (#183)
- Filter numeric fields (`delivery_mode`, `priority`, `timestamp`) when value is `0` in `AmqpStamp::createFromAmqpEnvelope()` (#181)

### Fixed
- Removed redundant `instanceof` check in `getRoutingKeyForMessage()` (#186)
- Aligned PHP version constraint in `examples/symfony/composer.json` with root (#173)
- Non-SSL DSNs now correctly exclude SSL keys (#170)

### Documentation
- Strengthened `CONTRIBUTING.md` rules on issue selection, CI wait, and merge policy (#171)
- Added contributing workflow guide (#168)

## [v0.1.0] - 2026-04-20

### Added
- `AmqpTransport` now implements `SetupableTransportInterface` - enables `bin/console messenger:setup-transports` command

### Changed
- `AmqpTransport` constructor now requires `InfrastructureSetup` parameter (BC break for direct instantiation)

## [v0.1] - 2024-XX-XX

Initial release