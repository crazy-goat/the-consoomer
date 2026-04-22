# Changelog

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