# The Consoomer
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Symfony Messenger AMQP transport that uses `consume` instead of `get`.

![alt text](docs/theconsoomer.webp)



---

## Overview

**the-consoomer** is a custom AMQP transport for the Symfony Messenger component. Unlike the default AMQP transport, which relies on the `get` method for message retrieval, this package uses the `consume` method to process messages from an AMQP broker. This can result in different performance characteristics and is more suitable for certain messaging patterns.

- **Language**: PHP
- **Framework**: Symfony
- **License**: MIT
- **Status**: Public, actively maintained

## Features

- Custom AMQP transport for Symfony Messenger
- Uses `consume` for message processing
- Designed for projects that require alternative AMQP consumption strategies

## Installation

```bash
composer require crazy-goat/the-consoomer
```

## Usage

1. Register the transport in your Symfony Messenger configuration.
2. Use it as you would any other Messenger transport, but benefit from the `consume`-based message retrieval.

Example configuration (add to `config/packages/messenger.yaml`):

```yaml
framework:
    messenger:
        transports:
            consoomer:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                # ...other options
```

## Configuration

You can configure this transport similarly to the default Symfony AMQP transport, but check the documentation (or source code) for any transport-specific options.

## Contributing

Contributions are welcome! Please open an issue or submit a pull request.

## License

This project is licensed under the [MIT License](LICENSE).

## Links

- [GitHub Repository](https://github.com/crazy-goat/the-consoomer)
- [Symfony Messenger Documentation](https://symfony.com/doc/current/messenger.html)
