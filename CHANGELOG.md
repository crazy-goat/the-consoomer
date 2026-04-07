# Changelog

## [Unreleased]

### Added
- `AmqpTransport` now implements `SetupableTransportInterface` - enables `bin/console messenger:setup-transports` command

### Changed
- `AmqpTransport` constructor now requires `InfrastructureSetup` parameter (BC break for direct instantiation)

## [v0.1] - 2024-XX-XX

Initial release