# Changelog

## [Unreleased]

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