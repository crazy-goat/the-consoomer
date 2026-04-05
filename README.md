# The Consoomer
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Symfony Messenger AMQP transport that uses `consume` instead of `get`.

![alt text](docs/theconsoomer.webp)

---

## Overview

**the-consoomer** is a custom AMQP transport for the Symfony Messenger component. Unlike the default AMQP transport, which relies on the `get` method for message retrieval, this package uses the `consume` method to process messages from an AMQP broker. This can result in different performance characteristics and is more suitable for certain messaging patterns.

**Requirements:** PHP 8.4+ with the `amqp` extension installed.

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

## Contributing

Contributions are welcome! Please open an issue or submit a pull request.

## License

This project is licensed under the [MIT License](LICENSE).

## Links

- [GitHub Repository](https://github.com/crazy-goat/the-consoomer)
- [Symfony Messenger Documentation](https://symfony.com/doc/current/messenger.html)