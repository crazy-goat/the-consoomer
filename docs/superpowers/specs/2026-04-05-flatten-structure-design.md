# Design: Flatten Structure — Remove Bunny & PhpAmqpLib

**Date:** 2026-04-05
**Status:** Approved

## Goal

Remove `Bunny/` and `PhpAmqpLib/` backends (unused, buggy, unnecessary dependencies) and flatten the remaining `AmqpExtension/` code into `src/` directly.

## Motivation

- PhpAmqpLib is broken (uses Bunny types instead of PhpAmqpLib types)
- Bunny adds async event-loop complexity unnecessary for Symfony Messenger
- Both are dead code — only `amqp` C extension is used in practice
- Single backend doesn't need `Library/AmqpExtension/` indirection or factory-based backend detection

## Architecture After Changes

```
src/
├── AmqpTransport.php          # Transport + Factory combined
├── AmqpStamp.php              # Routing key stamp (unchanged)
├── Receiver.php               # Moved from Library/AmqpExtension/
├── Sender.php                 # Moved from Library/AmqpExtension/
└── RawMessageStamp.php        # Moved from Library/AmqpExtension/
```

## File Changes

### 1. `src/Receiver.php` (moved)
- Namespace: `CrazyGoat\TheConsoomer`
- Logic: identical to `Library/AmqpExtension/Receiver.php`
- Dependencies: `\AMQPConnection`, `SerializerInterface`, `array $options`, `?LoggerInterface`

### 2. `src/Sender.php` (moved)
- Namespace: `CrazyGoat\TheConsoomer`
- Logic: identical to `Library/AmqpExtension/Sender.php`
- Dependencies: `\AMQPConnection`, `SerializerInterface`, `array $options`

### 3. `src/RawMessageStamp.php` (moved)
- Namespace: `CrazyGoat\TheConsoomer`
- Logic: identical to `Library/AmqpExtension/RawMessageStamp.php`
- Wraps `\AMQPEnvelope`

### 4. `src/AmqpTransport.php` (rewritten)
- Implements both `TransportInterface` and `TransportFactoryInterface`
- Static factory method: `createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface`
- `supports(string $dsn, array $options): bool` — checks `amqp-consoomer://` prefix
- Parses DSN, creates `\AMQPConnection`, `Receiver`, `Sender` inline
- Delegates `get()`, `ack()`, `reject()`, `send()` to receiver/sender (same as before)

### 5. `src/AmqpTransportFactory.php` (deleted)
- Logic merged into `AmqpTransport`

### 6. `src/Library/` (deleted)
- Entire directory removed

### 7. `composer.json` (updated)
- Remove `bunny/bunny` from `require`
- Remove `php-amqplib/php-amqplib` from `require`

### 8. `examples/` (updated)
- Update namespace imports from `CrazyGoat\TheConsoomer\Library\AmqpExtension\*` to `CrazyGoat\TheConsoomer\*`
- Update `examples/symfony/` and `examples/messagner/` to use new `AmqpTransport::createTransport()`

## BC Breaks

- `AmqpTransportFactory` class removed — users registering it manually must update
- `Library\AmqpExtension\*` classes removed — internal, but still a break
- DSN `amqp-consoomer://` remains unchanged
- Public API of `AmqpTransport` (get/ack/reject/send) unchanged

## Testing

- No test suite exists — verify by running examples against RabbitMQ
- Manual test: publish + consume with `examples/messagner/`
