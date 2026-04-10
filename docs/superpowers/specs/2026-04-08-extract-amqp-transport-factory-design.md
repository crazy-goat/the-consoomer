# Design: Extract AmqpTransportFactory (Issue #46)

**Date:** 2026-04-08
**Issue:** #46 - AmqpTransport implements both TransportInterface and TransportFactoryInterface — SRP violation

## Problem Statement

`AmqpTransport` currently implements both `TransportInterface` and `TransportFactoryInterface`, violating the Single Responsibility Principle. The `createTransport()` method calls `self::create()` and ignores the current instance state entirely. Factory and transport responsibilities are unrelated and should be in separate classes.

## Goals

1. Extract factory logic into separate `AmqpTransportFactory` class
2. Keep `AmqpTransport` as pure transport implementation
3. Maintain backward compatibility where possible
4. Update tests to reflect new structure

## Architecture

### Current Structure
```
AmqpTransport implements TransportInterface, TransportFactoryInterface
├── supports() - checks DSN
├── createTransport() - calls self::create()
└── create() - static method creating transport
```

### New Structure
```
AmqpTransportFactory implements TransportFactoryInterface
├── supports() - checks DSN
└── createTransport() - creates and returns AmqpTransport

AmqpTransport implements TransportInterface
├── get(), ack(), reject(), send() - delegation to receiver/sender
├── getMessageCount() - delegation to receiver
└── setup() - delegation to InfrastructureSetup
```

## Implementation Details

### New File: `src/AmqpTransportFactory.php`

- Implements `TransportFactoryInterface`
- Method `supports()` - checks if DSN starts with `amqp-consoomer://` or `amqps-consoomer://`
- Method `createTransport()` - contains all creation logic from current `create()` method
- Private helper methods: `createRetry()` (moved from `AmqpTransport`)

### Modified: `src/AmqpTransport.php`

- Remove `implements TransportFactoryInterface`
- Remove methods: `supports()`, `createTransport()`
- Remove static method `create()` (moved to factory)
- Remove private method `createRetry()` (moved to factory)
- Class implements only: `TransportInterface, MessageCountAwareInterface, SetupableTransportInterface`

### Test Structure

#### New: `tests/Unit/AmqpTransportFactoryTest.php`

Tests moved from `AmqpTransportTest`:
- `testSupportsReturnsTrueForAmqpConsoomerDsn()`
- `testSupportsReturnsTrueForAmqpsConsoomerScheme()`
- `testSupportsReturnsFalseForOtherDsn()`
- `testCreateTransportCreatesAmqpTransport()`
- `testCreateTransportMergesOptionsWithProgrammaticOptionsTakingPrecedence()`
- `testCreateTransportWithAmqpsScheme()`
- `testCreateTransportPassesInfrastructureSetupToReceiverAndSender()`

#### Modified: `tests/Unit/AmqpTransportTest.php`

Keep only transport delegation tests:
- `testGetDelegatesToReceiver()`
- `testAckDelegatesToReceiver()`
- `testSendDelegatesToSender()`
- `testSetupDelegatesToInfrastructureSetup()`
- `testGetMessageCountDelegatesToReceiver()`
- `testGetMessageCountReturnsZeroWhenReceiverNotMessageCountAware()`
- `testGetReturnsEmptyIterableWhenReceiverReturnsEmpty()`
- `testSendReturnsSameEnvelopeFromSender()`

Remove factory-related tests (moved to new file).

## Breaking Changes

⚠️ **BC Break:** Code using `AmqpTransport::create()` directly (outside Symfony Messenger) must use `AmqpTransportFactory::createTransport()` or create transport manually via constructor.

## Files Affected

- `src/AmqpTransport.php` - remove factory interface and methods
- `src/AmqpTransportFactory.php` - new file
- `tests/Unit/AmqpTransportTest.php` - remove factory tests
- `tests/Unit/AmqpTransportFactoryTest.php` - new file

## Acceptance Criteria

- [ ] `AmqpTransport` no longer implements `TransportFactoryInterface`
- [ ] `AmqpTransportFactory` implements `TransportFactoryInterface`
- [ ] All existing tests pass
- [ ] New factory tests cover all factory functionality
- [ ] Code follows existing project patterns
