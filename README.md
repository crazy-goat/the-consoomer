# The Consoomer
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Symfony Messenger AMQP transport that uses `consume` instead of `get`.

![alt text](docs/theconsoomer.webp)

---

## Overview

**the-consoomer** is a custom AMQP transport for the Symfony Messenger component. Unlike the default AMQP transport, which relies on the `get` method for message retrieval, this package uses the `consume` method to process messages from an AMQP broker. This can result in different performance characteristics and is more suitable for certain messaging patterns.

**Requirements:** PHP 8.2+ with the `amqp` extension installed.

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
    CrazyGoat\TheConsoomer\AmqpTransport:
        tags:
            - { name: 'messenger.transport_factory' }
```

### Messenger configuration (`config/packages/messenger.yaml`):

```yaml
framework:
    messenger:
        transports:
            consoomer:
                dsn: 'amqp-consoomer://guest:guest@localhost:5672/%2f/?queue=my_queue'
```

### DSN format

```
amqp-consoomer://<user>:<password>@<host>:<port>/<vhost>/<exchange>/?queue=<queue_name>
```

Example: `amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange/?queue=test`

### Options

| Option | Description | Default |
|--------|-------------|---------|
| `queue` | Queue name to consume from | (required) |
| `max_unacked_messages` | Prefetch count / batch size | 100 |
| `timeout` | Consumer timeout in seconds | 0.1 |
| `heartbeat` | Connection heartbeat interval in seconds (0 = disabled) | 0 |
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

### Heartbeat

The heartbeat feature keeps connections alive and detects dead connections. When enabled, the connection will automatically reconnect if no activity is detected for twice the heartbeat interval.

```yaml
framework:
    messenger:
        transports:
            consoomer:
                dsn: 'amqp-consoomer://guest:guest@localhost:5672/%2f/?queue=my_queue&heartbeat=60'
```

With heartbeat enabled:
- Connection is checked before each operation (send, get, ack, reject)
- If stale (elapsed > 2 * heartbeat), automatic reconnect occurs
- Activity is updated after each operation

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