# Changelog

## [Unreleased]

### Added
- `AmqpStamp` extended with full AMQP message attributes support (#23)
  - Added `$flags` (int) and `$attributes` (array) parameters
  - Added getters: `getRoutingKey()`, `getFlags()`, `getAttributes()`
  - Added immutable withers: `withRoutingKey()`, `withFlags()`, `withAttribute()`
  - Added factory methods: `createFromAmqpEnvelope()`, `createWithAttributes()`
- `Sender::send()` now uses stamp flags and merges stamp attributes with headers

### Changed
- **BC BREAK**: `AmqpStamp` properties changed from `public` to `private` (#23)
  - Direct property access (`$stamp->routingKey`) no longer works — use `$stamp->getRoutingKey()` instead
  - Default `routingKey` changed from `''` to `null`

## [v0.1.0] - 2026-04-20

### Added
- `AmqpTransport` now implements `SetupableTransportInterface` - enables `bin/console messenger:setup-transports` command

### Changed
- **BC BREAK**: `RetryMetrics::getSuccessRate()` renamed to `getRetrySuccessRate()` for clarity (#61)
- **BC BREAK**: `RetryMetrics::toArray()` key `success_rate` renamed to `retry_success_rate` (#61)
- `AmqpTransport` constructor now requires `InfrastructureSetup` parameter (BC break for direct instantiation)
- **BC BREAK**: Routing key precedence changed - `AmqpStamp` routing key now takes precedence over DSN/config options (#62)
  - Previous: options → stamp → empty
  - New: stamp → options → empty
  - This aligns with user expectations where message-specific stamp overrides defaults

## [v0.1] - 2024-XX-XX

Initial release