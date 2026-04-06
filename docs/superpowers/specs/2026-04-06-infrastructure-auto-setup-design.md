# Spec: Infrastructure Auto-Setup

## Overview

Automatically creates exchanges, queues, and bindings when the transport starts. No manual RabbitMQ configuration needed.

## Architecture

### New Component: `InfrastructureSetup`

```php
class InfrastructureSetup
{
    public function __construct(
        private readonly AmqpFactoryInterface $factory,
        private readonly \AMQPConnection $connection,
        private readonly array $options,
    ) {}

    public function setup(): void
    {
        // Idempotent - safe to call multiple times
    }
}
```

### Responsibilities

- Declare exchange (if not exists)
- Declare queue (if not exists)
- Bind queue to exchange with routing_key
- Cache setup state (run once)

### Usage in Sender/Receiver

```php
// Sender
class Sender {
    public function __construct(
        private readonly InfrastructureSetup $setup,
        private readonly array $options,
        // ...
    ) {}
    
    public function send(Envelope $envelope): Envelope {
        if ($this->options['auto_setup'] ?? true) {
            $this->setup->setup();
        }
        // ... publish
    }
}

// Receiver  
class Receiver {
    public function __construct(
        private readonly InfrastructureSetup $setup,
        private readonly array $options,
        // ...
    ) {}
    
    public function get(): iterable {
        if ($this->options['auto_setup'] ?? true) {
            $this->setup->setup();
        }
        // ... consume
    }
}
```

## Implementation Details

### `InfrastructureSetup::setup()`

```php
public function setup(): void
{
    if ($this->setupPerformed) {
        return;
    }

    $channel = $this->factory->createChannel($this->connection);
    
    // Exchange
    $exchange = $this->factory->createExchange($channel);
    $exchange->setName($this->options['exchange'] ?? '');
    $type = match ($this->options['exchange_type'] ?? 'direct') {
        'fanout' => AMQP_EX_TYPE_FANOUT,
        'topic' => AMQP_EX_TYPE_TOPIC,
        'headers' => AMQP_EX_TYPE_HEADERS,
        default => AMQP_EX_TYPE_DIRECT,
    };
    $exchange->setType($type);
    $exchange->setFlags(AMQP_DURABLE);
    $exchange->declareExchange();
    
    // Queue
    $queue = $this->factory->createQueue($channel);
    $queue->setName($this->options['queue'] ?? '');
    $queue->setFlags(AMQP_DURABLE);
    $queue->declareQueue();
    
    // Binding
    $routingKey = $this->options['routing_key'] ?? '';
    $queue->bind($exchange->getName(), $routingKey);
    
    $this->setupPerformed = true;
}
```

### Options

| Option | Type | Default |
|--------|------|---------|
| `exchange` | string | '' |
| `queue` | string | '' |
| `routing_key` | string | '' |
| `auto_setup` | bool | true |
| `exchange_type` | string | 'direct' |

## Changes to Existing Classes

### `AmqpTransport::create()`

```php
public static function create(string $dsn, array $options, SerializerInterface $serializer, ?AmqpFactoryInterface $factory = null): TransportInterface
{
    $dsnParser = new DsnParser();
    $parsedDsn = $dsnParser->parse($dsn);
    $mergedOptions = [...$options, ...$parsedDsn];

    $factory ??= new AmqpFactory();
    $connection = $factory->createConnection();
    // ... connection setup
    
    $setup = new InfrastructureSetup($factory, $connection, $mergedOptions);

    return new self(
        new Receiver($factory, $connection, $serializer, $mergedOptions, $setup),
        new Sender($factory, $connection, $serializer, $mergedOptions, $setup),
    );
}
```

### Constructor Changes

- `Receiver::__construct(..., InfrastructureSetup $setup)`
- `Sender::__construct(..., InfrastructureSetup $setup)`

## Testing

### Unit Tests

- Test setup is called once
- Test setup is skipped after first call
- Test exchange/queue/declare called with correct args
- Test bind called with correct routing_key

### E2E Tests

- Full publish/consume cycle without manual RabbitMQ setup
- Test with Docker container

## Files to Create/Modify

- **Create:** `src/InfrastructureSetup.php`
- **Modify:** `src/AmqpTransport.php` (add $setup)
- **Modify:** `src/Receiver.php` (add $setup param)
- **Modify:** `src/Sender.php` (add $setup param)
- **Create:** `tests/Unit/InfrastructureSetupTest.php`
- **Create/Modify:** E2E test for auto-setup

## Dependencies

- DsnParser (already implemented)
- AmqpFactoryInterface (already implemented)
