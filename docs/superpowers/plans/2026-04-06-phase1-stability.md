# Phase 1: Stability Features Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add production-ready stability features: connection retry, heartbeat, and TLS/SSL support.

**Architecture:** Introduce Connection class with retry logic and heartbeat tracking. Refactor AmqpTransport to use factory pattern for testability. Add TLS/SSL configuration via DSN options.

**Tech Stack:** PHP 8.1+, AMQP extension, Symfony Messenger 7.2

---

## File Structure

**New files:**
- `src/Connection.php` - Connection management with retry, heartbeat, TLS
- `src/AmqpFactory.php` - Factory for AMQP objects (testability)
- `tests/ConnectionTest.php` - Unit tests for Connection
- `tests/AmqpFactoryTest.php` - Unit tests for Factory

**Modified files:**
- `src/AmqpTransport.php` - Use Connection class, factory pattern
- `src/Receiver.php` - Use Connection class
- `src/Sender.php` - Use Connection class

---

## Task 1: Create AmqpFactory

**Files:**
- Create: `src/AmqpFactory.php`
- Create: `tests/AmqpFactoryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace CrazyGoat\TheConsoomer\Tests;

use CrazyGoat\TheConsoomer\AmqpFactory;
use PHPUnit\Framework\TestCase;

class AmqpFactoryTest extends TestCase
{
    public function testCreateConnection(): void
    {
        $factory = new AmqpFactory();
        $connection = $factory->createConnection();
        
        $this->assertInstanceOf(\AMQPConnection::class, $connection);
    }
    
    public function testCreateChannel(): void
    {
        $factory = new AmqpFactory();
        $mockConnection = $this->createMock(\AMQPConnection::class);
        $channel = $factory->createChannel($mockConnection);
        
        $this->assertInstanceOf(\AMQPChannel::class, $channel);
    }
    
    public function testCreateQueue(): void
    {
        $factory = new AmqpFactory();
        $mockChannel = $this->createMock(\AMQPChannel::class);
        $queue = $factory->createQueue($mockChannel);
        
        $this->assertInstanceOf(\AMQPQueue::class, $queue);
    }
    
    public function testCreateExchange(): void
    {
        $factory = new AmqpFactory();
        $mockChannel = $this->createMock(\AMQPChannel::class);
        $exchange = $factory->createExchange($mockChannel);
        
        $this->assertInstanceOf(\AMQPExchange::class, $exchange);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/AmqpFactoryTest.php`
Expected: FAIL with "Class CrazyGoat\TheConsoomer\AmqpFactory not found"

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace CrazyGoat\TheConsoomer;

class AmqpFactory
{
    public function createConnection(): \AMQPConnection
    {
        return new \AMQPConnection();
    }
    
    public function createChannel(\AMQPConnection $connection): \AMQPChannel
    {
        return new \AMQPChannel($connection);
    }
    
    public function createQueue(\AMQPChannel $channel): \AMQPQueue
    {
        return new \AMQPQueue($channel);
    }
    
    public function createExchange(\AMQPChannel $channel): \AMQPExchange
    {
        return new \AMQPExchange($channel);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/AmqpFactoryTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/AmqpFactory.php tests/AmqpFactoryTest.php
git commit -m "feat: add AmqpFactory for testability"
```

---

## Task 2: Create Connection class with retry logic

**Files:**
- Create: `src/Connection.php`
- Create: `tests/ConnectionTest.php`

- [ ] **Step 1: Write the failing test for retry**

```php
<?php

namespace CrazyGoat\TheConsoomer\Tests;

use CrazyGoat\TheConsoomer\Connection;
use CrazyGoat\TheConsoomer\AmqpFactory;
use PHPUnit\Framework\TestCase;

class ConnectionTest extends TestCase
{
    public function testRetryOnConnectionException(): void
    {
        $factory = $this->createMock(AmqpFactory::class);
        $amqpConnection = $this->createMock(\AMQPConnection::class);
        
        $callCount = 0;
        $amqpConnection->method('connect')
            ->will($this->returnCallback(function() use (&$callCount) {
                $callCount++;
                if ($callCount < 3) {
                    throw new \AMQPConnectionException('Connection failed');
                }
            }));
        
        $factory->method('createConnection')
            ->willReturn($amqpConnection);
        
        $connection = new Connection($factory, ['retry_count' => 3]);
        $connection->connect();
        
        $this->assertEquals(3, $callCount);
    }
    
    public function testRetryExhausted(): void
    {
        $factory = $this->createMock(AmqpFactory::class);
        $amqpConnection = $this->createMock(\AMQPConnection::class);
        
        $amqpConnection->method('connect')
            ->willThrowException(new \AMQPConnectionException('Connection failed'));
        
        $factory->method('createConnection')
            ->willReturn($amqpConnection);
        
        $connection = new Connection($factory, ['retry_count' => 3]);
        
        $this->expectException(\AMQPConnectionException::class);
        $connection->connect();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/ConnectionTest.php`
Expected: FAIL with "Class CrazyGoat\TheConsoomer\Connection not found"

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace CrazyGoat\TheConsoomer;

class Connection
{
    private ?\AMQPConnection $connection = null;
    private int $retryCount = 3;
    private int $retryDelay = 100000; // 100ms in microseconds
    
    public function __construct(
        private readonly AmqpFactory $factory,
        private readonly array $options = [],
    ) {
        $this->retryCount = max(1, intval($options['retry_count'] ?? $this->retryCount));
        $this->retryDelay = intval($options['retry_delay'] ?? $this->retryDelay);
    }
    
    public function connect(): void
    {
        $this->withConnectionExceptionRetry(function() {
            $this->connection = $this->factory->createConnection();
            $this->connection->connect();
        });
    }
    
    public function isConnected(): bool
    {
        return $this->connection instanceof \AMQPConnection && $this->connection->isConnected();
    }
    
    public function getChannel(): \AMQPChannel
    {
        if (!$this->isConnected()) {
            $this->connect();
        }
        
        return $this->factory->createChannel($this->connection);
    }
    
    private function withConnectionExceptionRetry(callable $operation): void
    {
        $attempts = 0;
        $lastException = null;
        
        while ($attempts < $this->retryCount) {
            try {
                $operation();
                return;
            } catch (\AMQPConnectionException $exception) {
                $lastException = $exception;
                $attempts++;
                
                if ($attempts < $this->retryCount) {
                    usleep($this->retryDelay);
                }
            }
        }
        
        throw $lastException;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/ConnectionTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Connection.php tests/ConnectionTest.php
git commit -m "feat: add Connection with retry logic"
```

---

## Task 3: Add heartbeat support

**Files:**
- Modify: `src/Connection.php`
- Modify: `tests/ConnectionTest.php`

- [ ] **Step 1: Write the failing test for heartbeat**

```php
public function testHeartbeatTracking(): void
{
    $factory = $this->createMock(AmqpFactory::class);
    $amqpConnection = $this->createMock(\AMQPConnection::class);
    
    $amqpConnection->method('connect');
    $amqpConnection->method('isConnected')->willReturn(true);
    
    $factory->method('createConnection')
        ->willReturn($amqpConnection);
    
    $connection = new Connection($factory, ['heartbeat' => 60]);
    $connection->connect();
    
    $lastActivity = $connection->getLastActivityTime();
    $this->assertGreaterThan(0, $lastActivity);
    
    // Simulate time passing
    sleep(1);
    $connection->checkHeartbeat();
    
    $this->assertGreaterThan($lastActivity, $connection->getLastActivityTime());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/ConnectionTest.php --filter testHeartbeatTracking`
Expected: FAIL with "Call to undefined method getLastActivityTime()"

- [ ] **Step 3: Implement heartbeat tracking**

```php
class Connection
{
    private ?\AMQPConnection $connection = null;
    private int $retryCount = 3;
    private int $retryDelay = 100000;
    private ?int $heartbeat = null;
    private ?int $lastActivityTime = null;
    
    public function __construct(
        private readonly AmqpFactory $factory,
        private readonly array $options = [],
    ) {
        $this->retryCount = max(1, intval($options['retry_count'] ?? $this->retryCount));
        $this->retryDelay = intval($options['retry_delay'] ?? $this->retryDelay);
        $this->heartbeat = isset($options['heartbeat']) ? intval($options['heartbeat']) : null;
    }
    
    public function connect(): void
    {
        $this->withConnectionExceptionRetry(function() {
            $this->connection = $this->factory->createConnection();
            
            if ($this->heartbeat !== null) {
                $this->connection->setHeartbeatInterval($this->heartbeat);
            }
            
            $this->connection->connect();
            $this->lastActivityTime = time();
        });
    }
    
    public function getLastActivityTime(): ?int
    {
        return $this->lastActivityTime;
    }
    
    public function checkHeartbeat(): void
    {
        if ($this->heartbeat === null || !$this->isConnected()) {
            return;
        }
        
        $now = time();
        $threshold = $this->lastActivityTime + (2 * $this->heartbeat);
        
        if ($now > $threshold) {
            // Connection might be dead, reconnect
            $this->disconnect();
            $this->connect();
        }
        
        $this->lastActivityTime = $now;
    }
    
    public function disconnect(): void
    {
        if ($this->connection instanceof \AMQPConnection && $this->connection->isConnected()) {
            $this->connection->disconnect();
        }
        $this->connection = null;
        $this->lastActivityTime = null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/ConnectionTest.php --filter testHeartbeatTracking`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Connection.php tests/ConnectionTest.php
git commit -m "feat: add heartbeat support to Connection"
```

---

## Task 4: Add TLS/SSL support

**Files:**
- Modify: `src/Connection.php`
- Modify: `tests/ConnectionTest.php`

- [ ] **Step 1: Write the failing test for TLS**

```php
public function testTlsConfiguration(): void
{
    $factory = $this->createMock(AmqpFactory::class);
    $amqpConnection = $this->createMock(\AMQPConnection::class);
    
    $amqpConnection->expects($this->once())
        ->method('setSslCert')
        ->with('/path/to/cert.pem');
    
    $amqpConnection->expects($this->once())
        ->method('setSslKey')
        ->with('/path/to/key.pem');
    
    $amqpConnection->expects($this->once())
        ->method('setSslCACert')
        ->with('/path/to/ca.pem');
    
    $amqpConnection->method('connect');
    
    $factory->method('createConnection')
        ->willReturn($amqpConnection);
    
    $connection = new Connection($factory, [
        'ssl_cert' => '/path/to/cert.pem',
        'ssl_key' => '/path/to/key.pem',
        'ssl_cacert' => '/path/to/ca.pem',
    ]);
    
    $connection->connect();
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/ConnectionTest.php --filter testTlsConfiguration`
Expected: FAIL (method setSslCert not called)

- [ ] **Step 3: Implement TLS configuration**

```php
public function connect(): void
{
    $this->withConnectionExceptionRetry(function() {
        $this->connection = $this->factory->createConnection();
        
        // TLS/SSL configuration
        if (isset($this->options['ssl_cert'])) {
            $this->connection->setSslCert($this->options['ssl_cert']);
        }
        
        if (isset($this->options['ssl_key'])) {
            $this->connection->setSslKey($this->options['ssl_key']);
        }
        
        if (isset($this->options['ssl_cacert'])) {
            $this->connection->setSslCACert($this->options['ssl_cacert']);
        }
        
        if (isset($this->options['ssl_verify'])) {
            $this->connection->setSslVerify($this->options['ssl_verify']);
        }
        
        if ($this->heartbeat !== null) {
            $this->connection->setHeartbeatInterval($this->heartbeat);
        }
        
        $this->connection->connect();
        $this->lastActivityTime = time();
    });
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/ConnectionTest.php --filter testTlsConfiguration`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Connection.php tests/ConnectionTest.php
git commit -m "feat: add TLS/SSL support to Connection"
```

---

## Task 5: Refactor AmqpTransport to use Connection

**Files:**
- Modify: `src/AmqpTransport.php`
- Modify: `src/Receiver.php`
- Modify: `src/Sender.php`

- [ ] **Step 1: Update AmqpTransport::create()**

```php
public static function create(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
{
    $info = parse_url($dsn);
    $query = [];
    parse_str($info['query'] ?? '', $query);
    $mergedOptions = [...$options, ...self::parsePath($info['path'] ?? ''), ...$query];
    
    $factory = new AmqpFactory();
    $connectionOptions = [
        'host' => $info['host'],
        'port' => $info['port'],
        'vhost' => $mergedOptions['vhost'],
        'login' => $info['user'],
        'password' => $info['pass'],
        'read_timeout' => $mergedOptions['timeout'] ?? 0.1,
    ];
    
    // Add retry options
    if (isset($mergedOptions['retry_count'])) {
        $connectionOptions['retry_count'] = $mergedOptions['retry_count'];
    }
    
    if (isset($mergedOptions['retry_delay'])) {
        $connectionOptions['retry_delay'] = $mergedOptions['retry_delay'];
    }
    
    // Add heartbeat
    if (isset($mergedOptions['heartbeat'])) {
        $connectionOptions['heartbeat'] = $mergedOptions['heartbeat'];
    }
    
    // Add TLS/SSL options
    if (isset($mergedOptions['ssl_cert'])) {
        $connectionOptions['ssl_cert'] = $mergedOptions['ssl_cert'];
    }
    
    if (isset($mergedOptions['ssl_key'])) {
        $connectionOptions['ssl_key'] = $mergedOptions['ssl_key'];
    }
    
    if (isset($mergedOptions['ssl_cacert'])) {
        $connectionOptions['ssl_cacert'] = $mergedOptions['ssl_cacert'];
    }
    
    if (isset($mergedOptions['ssl_verify'])) {
        $connectionOptions['ssl_verify'] = $mergedOptions['ssl_verify'];
    }
    
    $connection = new Connection($factory, $connectionOptions);
    $connection->connect();
    
    $logger = new NullLogger();
    
    return new self(
        new Receiver($connection, $serializer, $mergedOptions, $logger),
        new Sender($connection, $serializer, $mergedOptions),
    );
}
```

- [ ] **Step 2: Update Receiver to use Connection**

```php
class Receiver implements ReceiverInterface
{
    private int $unacked = 0;
    private int $maxUnackedMessages = 100;
    private ?\AMQPEnvelope $lastUnacked = null;
    private ?Envelope $message = null;
    private ?\AMQPQueue $queue = null;
    private \Closure $callback;
    
    public function __construct(
        private readonly Connection $connection,
        private readonly SerializerInterface $serializer,
        private readonly array $options,
    ) {
        $this->maxUnackedMessages = max(1, intval($this->options['max_unacked_messages'] ?? $this->maxUnackedMessages));
    }
    
    private function connect(): void
    {
        if ($this->queue instanceof \AMQPQueue) {
            return;
        }
        
        $this->callback = function (\AMQPEnvelope $message): false {
            $envelope = $this->serializer->decode(['body' => $message->getBody()]);
            $this->message = $envelope->with(new RawMessageStamp($message));
            
            return false;
        };
        
        $channel = $this->connection->getChannel();
        $channel->qos(0, $this->maxUnackedMessages);
        $this->queue = new \AMQPQueue($channel);
        $this->queue->setName($this->options['queue']);
        $this->queue->consume();
    }
    
    public function get(): iterable
    {
        $this->connect();
        
        // Check heartbeat before consuming
        $this->connection->checkHeartbeat();
        
        try {
            $this->queue->consume($this->callback, AMQP_JUST_CONSUME, $this->queue->getConsumerTag());
        } catch (\AMQPQueueException $exception) {
            if ('Consumer timeout exceed' !== $exception->getMessage()) {
                throw $exception;
            }
        }
        
        return $this->message instanceof Envelope ? [$this->message] : [];
    }
    
    // ... rest of methods unchanged
}
```

- [ ] **Step 3: Update Sender to use Connection**

```php
class Sender implements SenderInterface
{
    private ?\AMQPExchange $exchange = null;
    
    public function __construct(
        private readonly Connection $connection,
        private readonly SerializerInterface $serializer,
        private readonly array $options,
    ) {
    }
    
    private function connect(): void
    {
        if ($this->exchange instanceof \AMQPExchange) {
            return;
        }
        
        $channel = $this->connection->getChannel();
        $this->exchange = new \AMQPExchange($channel);
        $this->exchange->setName($this->options['exchange'] ?? '');
    }
    
    public function send(Envelope $envelope): Envelope
    {
        $this->connect();
        
        // Check heartbeat before publishing
        $this->connection->checkHeartbeat();
        
        $stamp = $envelope->last(AmqpStamp::class);
        
        $data = $this->serializer->encode($envelope);
        
        $this->exchange->publish(
            $data['body'],
            $this->options['routing_key'] ?? $stamp?->routingKey ?? '',
            null,
            $data['headers'] ?? [],
        );
        
        return $envelope;
    }
}
```

- [ ] **Step 4: Run existing tests (if any)**

Run: `vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 5: Commit**

```bash
git add src/AmqpTransport.php src/Receiver.php src/Sender.php
git commit -m "refactor: use Connection class in transport components"
```

---

## Task 6: Add integration test

**Files:**
- Create: `tests/Integration/ConnectionIntegrationTest.php`

- [ ] **Step 1: Write integration test**

```php
<?php

namespace CrazyGoat\TheConsoomer\Tests\Integration;

use CrazyGoat\TheConsoomer\Connection;
use CrazyGoat\TheConsoomer\AmqpFactory;
use PHPUnit\Framework\TestCase;

class ConnectionIntegrationTest extends TestCase
{
    private ?Connection $connection = null;
    
    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->connection->disconnect();
        }
    }
    
    public function testRealConnection(): void
    {
        if (!getenv('RABBITMQ_HOST')) {
            $this->markTestSkipped('RABBITMQ_HOST not set');
        }
        
        $factory = new AmqpFactory();
        $this->connection = new Connection($factory, [
            'host' => getenv('RABBITMQ_HOST'),
            'port' => intval(getenv('RABBITMQ_PORT') ?: 5672),
            'vhost' => getenv('RABBITMQ_VHOST') ?: '/',
            'login' => getenv('RABBITMQ_USER') ?: 'guest',
            'password' => getenv('RABBITMQ_PASSWORD') ?: 'guest',
            'heartbeat' => 60,
        ]);
        
        $this->connection->connect();
        
        $this->assertTrue($this->connection->isConnected());
        
        $channel = $this->connection->getChannel();
        $this->assertInstanceOf(\AMQPChannel::class, $channel);
    }
    
    public function testHeartbeatKeepsConnectionAlive(): void
    {
        if (!getenv('RABBITMQ_HOST')) {
            $this->markTestSkipped('RABBITMQ_HOST not set');
        }
        
        $factory = new AmqpFactory();
        $this->connection = new Connection($factory, [
            'host' => getenv('RABBITMQ_HOST'),
            'port' => intval(getenv('RABBITMQ_PORT') ?: 5672),
            'vhost' => getenv('RABBITMQ_VHOST') ?: '/',
            'login' => getenv('RABBITMQ_USER') ?: 'guest',
            'password' => getenv('RABBITMQ_PASSWORD') ?: 'guest',
            'heartbeat' => 1, // 1 second for testing
        ]);
        
        $this->connection->connect();
        
        sleep(2);
        $this->connection->checkHeartbeat();
        
        $this->assertTrue($this->connection->isConnected());
    }
}
```

- [ ] **Step 2: Run integration test**

Run: `RABBITMQ_HOST=localhost vendor/bin/phpunit tests/Integration/ConnectionIntegrationTest.php`
Expected: PASS (requires RabbitMQ running)

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/ConnectionIntegrationTest.php
git commit -m "test: add integration tests for Connection"
```

---

## Task 7: Update documentation

**Files:**
- Create: `docs/configuration.md`

- [ ] **Step 1: Write configuration documentation**

```markdown
# Configuration

## DSN Format

```
amqp-consoomer://user:password@host:port/vhost/exchange?options
```

## Connection Options

### Retry Configuration

- `retry_count` (int, default: 3) - Number of connection retry attempts
- `retry_delay` (int, default: 100000) - Delay between retries in microseconds

### Heartbeat

- `heartbeat` (int, optional) - Heartbeat interval in seconds. Recommended for long-running workers.

### TLS/SSL

- `ssl_cert` (string) - Path to client certificate file
- `ssl_key` (string) - Path to client key file
- `ssl_cacert` (string) - Path to CA certificate file
- `ssl_verify` (bool) - Verify server certificate (default: true)

## Examples

### Basic Connection

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            my_transport:
                dsn: 'amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange'
```

### With Retry and Heartbeat

```yaml
framework:
    messenger:
        transports:
            my_transport:
                dsn: 'amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange?retry_count=5&heartbeat=60'
```

### With TLS/SSL

```yaml
framework:
    messenger:
        transports:
            my_transport:
                dsn: 'amqp-consoomer://user:pass@rabbitmq.example.com:5671/%2f/my_exchange'
                options:
                    ssl_cert: '/path/to/client.cert'
                    ssl_key: '/path/to/client.key'
                    ssl_cacert: '/path/to/ca.cert'
                    ssl_verify: true
```
```

- [ ] **Step 2: Commit**

```bash
git add docs/configuration.md
git commit -m "docs: add configuration documentation"
```

---

## Summary

This plan implements Phase 1 stability features:

1. **AmqpFactory** - Factory pattern for testability
2. **Connection class** - Centralized connection management
3. **Connection retry** - Automatic retry on connection failures
4. **Heartbeat** - Keep-alive for long-running workers
5. **TLS/SSL** - Secure connections to RabbitMQ
6. **Integration tests** - Verify real RabbitMQ connectivity
7. **Documentation** - Configuration examples

All features are backward compatible. Existing DSNs continue to work without changes.
