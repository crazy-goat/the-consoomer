# The Consoomer
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Symfony Messenger AMQP transport that uses `consume` instead of `get`.

![alt text](docs/theconsoomer.webp)

---

## Overview

**the-consoomer** is a custom AMQP transport for the Symfony Messenger component. Unlike the default AMQP transport, which relies on the `get` method for message retrieval, this package uses the `consume` method to process messages from an AMQP broker. This can result in different performance characteristics and is more suitable for certain messaging patterns.

**Requirements:** PHP 8.4+ with the `amqp` extension (PECL `amqp`) **`^2.0`** installed.

| Symfony Messenger | Minimum PHP | Minimum `amqp` extension |
|-------------------|-------------|--------------------------|
| 6.4 / 7.4 / 8.0   | 8.4         | 2.0                      |

- **Language**: PHP
- **Framework**: Symfony
- **License**: MIT
- **Status**: Public, actively maintained

## Features

- Custom AMQP transport for Symfony Messenger
- Uses `basic_consume` for push-based message processing
- Lower latency and better throughput than polling-based `basic_get`

## Installation

```bash
composer require crazy-goat/the-consoomer
```

## Usage

1. Register the transport factory in your Symfony services configuration.
2. Use it in your Messenger transport configuration.

### Service registration (`config/services.yaml`):

```yaml
services:
    CrazyGoat\TheConsoomer\AmqpTransportFactory:
        tags:
            - { name: 'messenger.transport_factory' }
```

### Messenger configuration (`config/packages/messenger.yaml`):

```yaml
framework:
    messenger:
        transports:
            consoomer:
                dsn: 'amqp-consoomer://guest:guest@localhost:5672/%2f/messages?queue=my_queue'
```

### DSN format

```
amqp-consoomer://<user>:<password>@<host>:<port>/<vhost>/<exchange>/?queue=<queue_name>
```

Example: `amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange/?queue=test`

> **Reserved query keys:** the query string must not use the keys `host`, `port`, `user`, `password`, `vhost` or `exchange` — these come from the DSN authority/path and are authoritative. A query parameter with one of these keys (e.g. `?host=evil&password=secret`) throws `InvalidArgumentException` at parse time instead of being applied (#207/#360) — a previously-silent connection/credential override was a security hole, so this is an intentional BC break rather than a deprecation.

### Options

| Option | Description | Default |
|--------|-------------|---------|
| `queue` | Queue name to consume from | (required) |
| `max_unacked_messages` | Prefetch count and ack-batch flush threshold | 100 |
| `batch_size` | Max messages collected per `get()` call (lower = lower latency, higher = higher throughput) | 1 |
| `max_body_bytes` | Max raw message body size accepted per message (0 = disabled). Oversized bodies are rejected without being decoded | 16777216 (16 MiB) |
| `timeout` | Consumer timeout in seconds | 0.1 |
| `heartbeat` | Connection heartbeat interval in seconds (0 = disabled) | 0 |
| `confirm_timeout` | Publisher confirms timeout in seconds (0 = disabled). See [Publish Reliability](#publish-reliability) | 0 |
| `routing_key` | **Consumer-side**: binding key used when declaring/binding the queue | `''` |
| `default_publish_routing_key` | **Sender-side**: default routing key used when publishing messages | `''` |

### Routing Key Resolution

The transport uses separate routing keys for consuming and sending:

- **Consumer (Receiver)**: Uses `routing_key` as the binding key when declaring/binding the queue to an exchange. This determines which messages are routed to the queue.
- **Sender**: Uses `default_publish_routing_key` as the default routing key when publishing messages. This determines how messages are routed through the exchange.

When sending a message, the routing key precedence is:
1. `AmqpStamp::getRoutingKey()` — message-specific routing key (highest priority)
2. `default_publish_routing_key` — configured default for publishing
3. `''` — empty string (no routing key)

This separation prevents unintended coupling: setting `routing_key` for consumer binding does not affect how messages are published.

> **Pitfall — consumer binding vs sender stamp:** because the consumer and sender keys are resolved independently, stamping a message with `AmqpStamp::getRoutingKey()` (or setting `default_publish_routing_key`) that differs from the queue's `routing_key` binding causes the broker to drop every unroutable message on a direct exchange — publish still reports success. When you publish with a per-message `AmqpStamp`, make sure your `routing_key` DSN option binds the queue with that same key (e.g. publish with `new AmqpStamp('test')` and configure `...?queue=test&routing_key=test`).

### Heartbeat

The heartbeat feature keeps connections alive and detects dead connections. When enabled, the connection will automatically reconnect if no activity is detected for twice the heartbeat interval.

```yaml
framework:
    messenger:
        transports:
            consoomer:
                dsn: 'amqp-consoomer://guest:guest@localhost:5672/%2f/messages?queue=my_queue&heartbeat=60'
```

With heartbeat enabled:
- Connection is checked before each operation (send, get, ack, reject)
- If stale (elapsed > 2 * heartbeat), automatic reconnect occurs
- Activity is updated after each operation
- In-flight messages delivered before a reconnect are not acknowledged on the new channel — their delivery tag belongs to the dead channel, so ack/reject become no-ops and the broker redelivers them on the next get()

### SSL/TLS

For TLS-encrypted connections use the `amqps-consoomer://` scheme (or the legacy `amqps://`, which emits a deprecation notice). SSL options are passed as DSN query parameters or programmatic options:

| Option | Description | Default |
|--------|-------------|---------|
| `ssl` | Enable TLS (set implicitly by the `amqps` schemes) | `false` |
| `ssl_cert` | Client certificate PEM file path | (none) |
| `ssl_key` | Client private key PEM file path | (none) |
| `ssl_cacert` | CA certificate PEM file path used to verify the broker | (none) |
| `ssl_verify` | Verify the broker's peer certificate | `true` |
| `allow_insecure_verify` | Programmatic opt-in to allow `ssl_verify=false` (cannot be set from the DSN) | `false` |

> **Pitfall — `ssl_verify` without `ssl_cacert`:** when verification is on (the default) but no CA certificate is pinned, the connection falls back to the **system CA store**. On builds where the system has no trusted CAs the handshake fails, or on some builds it verifies against an empty trust set — both silently. `AmqpFactory::configureSsl()` emits a prominent warning in this case — through the configured logger, or as a PHP `E_USER_WARNING` when no logger is wired (#351); set `ssl_cacert` explicitly in production to pin the broker's CA.

> **Security (#361):** `ssl_verify=false` disables TLS peer-certificate verification, enabling man-in-the-middle / broker impersonation. It is **refused** unless you set `"allow_insecure_verify" => true` in the **programmatic** transport options — it cannot be set from the DSN query string, so a config-file or env-var DSN can never self-authorize the downgrade. Use it only for local development with self-signed certificates.

```yaml
framework:
    messenger:
        transports:
            consoomer:
                dsn: 'amqps-consoomer://guest:guest@rabbit:5671/%2f/messages?queue=my_queue&ssl_cacert=/etc/ssl/certs/rabbit-ca.pem'
```

### Retry Configuration

The transport supports configurable retry logic with exponential backoff, jitter, and circuit breaker.

| Option | Description | Default |
|--------|-------------|---------|
| `retry` | Enable retry mechanism | `false` |
| `retry_count` | Maximum number of execution attempts including the first (`maxAttempts`) | `3` |
| `retry_delay` | Base delay between retries in microseconds | `100000` |
| `retry_backoff` | Enable exponential backoff (delay doubles each retry) | `false` |
| `retry_max_delay` | Maximum delay cap in microseconds | `30000000` |
| `retry_jitter` | Enable random jitter (±25%) to prevent thundering herd | `true` |
| `retry_circuit_breaker` | Enable circuit breaker pattern | `false` |
| `retry_circuit_breaker_threshold` | Consecutive failures before circuit opens | `10` |
| `retry_circuit_breaker_timeout` | Seconds circuit stays open before half-open probe | `60` |
| `retry_circuit_breaker_success_threshold` | Successful attempts needed to close circuit | `2` |

```yaml
framework:
    messenger:
        transports:
            consoomer:
                dsn: 'amqp-consoomer://guest:guest@localhost:5672/%2f/messages?queue=my_queue&retry=1&retry_count=3&retry_delay=500000&retry_backoff=1&retry_jitter=1&retry_circuit_breaker=1'
```

With retry enabled:
- Connection and channel failures are retried automatically up to `retry_count` times (including the first attempt)
- Non-AMQP exceptions are not retried
- Resource errors (`AMQPQueueException`, `AMQPExchangeException`) are permanent and not retried;
  connection/channel errors (`AMQPConnectionException`, `AMQPChannelException`) are always retried.
  For generic `AMQPException`, reply codes 403/404/406 are treated as permanent.
- On exhaustion, a `RetryExhaustedException` is thrown with the last failure as previous

### Circuit Breaker Scope

> **The circuit-breaker state is per-process and in-memory only.** There is no shared
> store behind it, so its protection is limited to the lifetime of the current process:
>
> - **PHP-FPM / request-scoped senders:** a new `CircuitBreaker` instance is created on
>   every request, so failure counters reset each time and a high `retry_circuit_breaker_threshold`
>   is effectively never reached. Only failures that recur within a single request count.
> - **Worker fleet (`messenger:consume`):** each consumer process has its own independent
>   breaker. A broker-wide outage is not coordinated across the fleet — every process must
>   trip (and recover) on its own.
> - **Long-lived single workers:** this is where the breaker works as documented — counters
>   persist between operations and the circuit opens after `retry_circuit_breaker_threshold`
>   consecutive failures.

To size the breaker usefully, keep the effective failure budget in mind: with the defaults
(`retry_count=3` attempts per operation) an `OPEN` circuit is reached after roughly
`retry_circuit_breaker_threshold / retry_count` consecutive failed operations — by default
`10 / 3`, i.e. after about 3–4 consecutive failing operations.

No shared-state backend (APCu, Redis, …) is currently provided; do not treat
`retry_circuit_breaker_*` as fleet-wide or request-spanning protection.

### Untrusted brokers and poison messages

The raw AMQP body is broker-controlled input: anyone who can publish to (or impersonate) a
consumed queue decides what bytes reach your serializer. Two guards apply on the receive
path (#288):

- **Size guard** — a body larger than `max_body_bytes` is rejected without ever being
  handed to the serializer, so a single oversized publish cannot push the consumer into
  memory pressure. Set `max_body_bytes: 0` to disable the guard.
- **Poison-pill containment** — when the serializer rejects a body (raises
  `MessageDecodingFailedException`), the offending message is rejected (dropped or
  dead-lettered per broker policy) and the current batch keeps flowing. If the message
  cannot even be rejected (broken channel), the decode failure propagates so the problem
  stays visible instead of looping silently on broker redelivery.

> **Warning: do not use `PhpSerializer` with untrusted publishers.**
> Symfony's `PhpSerializer` runs `unserialize()` on the raw body. That is PHP object
> injection and gadget-chain RCE, not a data error — no size limit changes that. It is a
> supported option only for first-party publishers you fully trust; with anything
> untrusted, use a data-format serializer (JSON) and validate on the application side.

### Publish Reliability

> **Warning: without `confirm_timeout`, publishes are fire-and-forget.**
> `AMQPExchange::publish()` writes to the socket buffer and returns immediately.
> If the broker is down, the exchange is missing, or the topology was lost after
> a restart, `send()` still reports success and the message is silently lost.
> The retry mechanism is inert on the send path without an error signal.

**Enable publisher confirms** (`confirm_timeout > 0`) for reliable publishing:

```yaml
framework:
    messenger:
        transports:
            consoomer:
                dsn: 'amqp-consoomer://guest:guest@localhost:5672/%2f/messages?queue=my_queue&confirm_timeout=5&retry=1'
```

With `confirm_timeout` enabled:
- The sender calls `confirmSelect()` on the channel (once per channel, cached) and `waitForConfirm()` after each publish
- The broker acknowledges each publish; a 404 (missing exchange) or nack surfaces as an `AMQPException`
- Combined with `retry=1`, transient failures are retried automatically

With `retry=1` enabled (regardless of confirms):
- The sender checks `isConnected()` before each publish attempt and reconnects if the broker is down, giving the retry wrapper an error signal to act on

With `auto_setup=true` (default):
- Topology (exchange, queues, bindings) is re-declared after a reconnect — `Sender::ensureConnected()` resets the setup flag so `auto_setup` is not a false promise after a broker restart or topology loss

## Testing

### Run tests

```bash
# All tests
composer test

# Unit tests only
composer test-unit

# E2E tests (requires RabbitMQ)
composer test-e2e-full
```

### E2E tests

E2E tests require RabbitMQ. The `test-e2e-full` script automatically:
1. Starts RabbitMQ via Docker
2. Waits for RabbitMQ to be ready
3. Runs E2E tests
4. Stops RabbitMQ

### Code quality

```bash
# Run rector + php-cs-fixer
composer lint
```

## Contributing

Contributions are welcome! Please open an issue or submit a pull request.

## License

This project is licensed under the [MIT License](LICENSE).

## Links

- [GitHub Repository](https://github.com/crazy-goat/the-consoomer)
- [Symfony Messenger Documentation](https://symfony.com/doc/current/messenger.html)