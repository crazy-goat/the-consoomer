# Phase 0: Test Infrastructure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Establish test infrastructure: PHPUnit, Docker RabbitMQ, composer scripts, unit tests, and e2e tests for existing code.

**Architecture:** PHPUnit for unit/integration tests. Docker Compose for RabbitMQ test environment. Separate test suites: unit (fast, mocked) and e2e (real RabbitMQ).

**Tech Stack:** PHPUnit 10+, Docker Compose, RabbitMQ 4.x, Symfony Messenger 7.2

---

## File Structure

**New files:**
- `phpunit.xml.dist` - PHPUnit configuration
- `docker-compose.test.yml` - RabbitMQ for testing
- `tests/Unit/ReceiverTest.php` - Unit tests for Receiver
- `tests/Unit/SenderTest.php` - Unit tests for Sender
- `tests/Unit/AmqpTransportTest.php` - Unit tests for AmqpTransport
- `tests/Unit/RawMessageStampTest.php` - Unit tests for RawMessageStamp
- `tests/Unit/AmqpStampTest.php` - Unit tests for AmqpStamp
- `tests/E2E/ConsumeProduceTest.php` - E2E test with real RabbitMQ
- `tests/E2E/TestCase.php` - Base E2E test case
- `.env.test` - Environment variables for testing
- `scripts/wait-for-rabbitmq.sh` - Script to wait for RabbitMQ startup

**Modified files:**
- `composer.json` - Add PHPUnit, add test scripts

---

## Task 1: Install and configure PHPUnit

**Files:**
- Modify: `composer.json`
- Create: `phpunit.xml.dist`

- [ ] **Step 1: Add PHPUnit to composer.json**

```bash
composer require --dev phpunit/phpunit:^10
```

- [ ] **Step 2: Create phpunit.xml.dist**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache"
         executionOrder="depends,defects"
         failOnRisky="true"
         failOnWarning="true"
         displayDetailsOnTestsThatTriggerWarnings="true"
         displayDetailsOnTestsThatTriggerErrors="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="e2e">
            <directory>tests/E2E</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory suffix=".php">src</directory>
        </include>
    </source>
    <php>
        <env name="RABBITMQ_HOST" value="localhost"/>
        <env name="RABBITMQ_PORT" value="5672"/>
        <env name="RABBITMQ_USER" value="guest"/>
        <env name="RABBITMQ_PASSWORD" value="guest"/>
        <env name="RABBITMQ_VHOST" value="/"/>
    </php>
</phpunit>
```

- [ ] **Step 3: Add test scripts to composer.json**

```json
{
    "scripts": {
        "test": "phpunit",
        "test-unit": "phpunit --testsuite unit",
        "test-e2e": "phpunit --testsuite e2e",
        "test-coverage": "phpunit --coverage-html coverage",
        "rabbitmq-start": "docker-compose -f docker-compose.test.yml up -d",
        "rabbitmq-stop": "docker-compose -f docker-compose.test.yml down",
        "rabbitmq-wait": "scripts/wait-for-rabbitmq.sh",
        "test-e2e-full": [
            "@rabbitmq-start",
            "@rabbitmq-wait",
            "@test-e2e",
            "@rabbitmq-stop"
        ]
    }
}
```

- [ ] **Step 4: Verify PHPUnit works**

Run: `vendor/bin/phpunit --version`
Expected: PHPUnit version output

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock phpunit.xml.dist
git commit -m "chore: add PHPUnit configuration"
```

---

## Task 2: Create Docker Compose for RabbitMQ

**Files:**
- Create: `docker-compose.test.yml`
- Create: `scripts/wait-for-rabbitmq.sh`

- [ ] **Step 1: Create docker-compose.test.yml**

```yaml
version: '3.8'

services:
    rabbitmq:
        image: rabbitmq:4.1-management-alpine
        container_name: the-consoomer-rabbitmq
        ports:
            - "5672:5672"
            - "15672:15672"
        environment:
            RABBITMQ_DEFAULT_USER: guest
            RABBITMQ_DEFAULT_PASS: guest
        healthcheck:
            test: ["CMD", "rabbitmq-diagnostics", "-q", "ping"]
            interval: 5s
            timeout: 10s
            retries: 5
        tmpfs:
            - /var/lib/rabbitmq
```

- [ ] **Step 2: Create wait-for-rabbitmq.sh**

```bash
#!/bin/bash

set -e

HOST="${RABBITMQ_HOST:-localhost}"
PORT="${RABBITMQ_PORT:-5672}"
MAX_RETRIES=30
RETRY_INTERVAL=2

echo "Waiting for RabbitMQ at $HOST:$PORT..."

for i in $(seq 1 $MAX_RETRIES); do
    if nc -z "$HOST" "$PORT" 2>/dev/null; then
        echo "RabbitMQ is ready!"
        exit 0
    fi
    
    echo "Attempt $i/$MAX_RETRIES - RabbitMQ not ready yet..."
    sleep $RETRY_INTERVAL
done

echo "ERROR: RabbitMQ did not become ready in time"
exit 1
```

- [ ] **Step 3: Make script executable**

```bash
chmod +x scripts/wait-for-rabbitmq.sh
```

- [ ] **Step 4: Test Docker setup**

Run: `composer rabbitmq-start && sleep 5 && composer rabbitmq-stop`
Expected: RabbitMQ starts and stops successfully

- [ ] **Step 5: Commit**

```bash
git add docker-compose.test.yml scripts/wait-for-rabbitmq.sh
git commit -m "chore: add Docker Compose for RabbitMQ testing"
```

---

## Task 3: Create .env.test file

**Files:**
- Create: `.env.test`

- [ ] **Step 1: Create .env.test**

```env
# RabbitMQ connection for E2E tests
RABBITMQ_HOST=localhost
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
```

- [ ] **Step 2: Add to .gitignore**

```bash
echo ".env.test.local" >> .gitignore
```

- [ ] **Step 3: Commit**

```bash
git add .env.test .gitignore
git commit -m "chore: add test environment configuration"
```

---

## Task 4: Unit tests for RawMessageStamp

**Files:**
- Create: `tests/Unit/RawMessageStampTest.php`

- [ ] **Step 1: Write unit test**

```php
<?php

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\RawMessageStamp;
use PHPUnit\Framework\TestCase;

class RawMessageStampTest extends TestCase
{
    public function testConstructorStoresMessage(): void
    {
        $envelope = $this->createMock(\AMQPEnvelope::class);
        $stamp = new RawMessageStamp($envelope);
        
        $this->assertSame($envelope, $stamp->amqpMessage);
    }
    
    public function testIsNonSendable(): void
    {
        $envelope = $this->createMock(\AMQPEnvelope::class);
        $stamp = new RawMessageStamp($envelope);
        
        $this->assertInstanceOf(\Symfony\Component\Messenger\Stamp\NonSendableStampInterface::class, $stamp);
    }
}
```

- [ ] **Step 2: Run test**

Run: `composer test-unit -- --filter RawMessageStampTest`
Expected: PASS (2 tests)

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/RawMessageStampTest.php
git commit -m "test: add unit tests for RawMessageStamp"
```

---

## Task 5: Unit tests for AmqpStamp

**Files:**
- Create: `tests/Unit/AmqpStampTest.php`

- [ ] **Step 1: Write unit test**

```php
<?php

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\AmqpStamp;
use PHPUnit\Framework\TestCase;

class AmqpStampTest extends TestCase
{
    public function testConstructorWithRoutingKey(): void
    {
        $stamp = new AmqpStamp('my.routing.key');
        
        $this->assertEquals('my.routing.key', $stamp->routingKey);
    }
    
    public function testConstructorWithNullRoutingKey(): void
    {
        $stamp = new AmqpStamp(null);
        
        $this->assertNull($stamp->routingKey);
    }
    
    public function testIsSendable(): void
    {
        $stamp = new AmqpStamp('test');
        
        // AmqpStamp should be sendable (not NonSendableStampInterface)
        $this->assertNotInstanceOf(\Symfony\Component\Messenger\Stamp\NonSendableStampInterface::class, $stamp);
    }
}
```

- [ ] **Step 2: Run test**

Run: `composer test-unit -- --filter AmqpStampTest`
Expected: PASS (3 tests)

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/AmqpStampTest.php
git commit -m "test: add unit tests for AmqpStamp"
```

---

## Task 6: Unit tests for Sender

**Files:**
- Create: `tests/Unit/SenderTest.php`

- [ ] **Step 1: Write unit test**

```php
<?php

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\AmqpStamp;
use CrazyGoat\TheConsoomer\Sender;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class SenderTest extends TestCase
{
    public function testSendPublishesToExchange(): void
    {
        $connection = $this->createMock(\AMQPConnection::class);
        $channel = $this->createMock(\AMQPChannel::class);
        $exchange = $this->createMock(\AMQPExchange::class);
        
        $connection->method('isConnected')->willReturn(true);
        
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('encode')->willReturn([
            'body' => '{"message":"test"}',
            'headers' => ['content-type' => 'application/json'],
        ]);
        
        $exchange->expects($this->once())
            ->method('publish')
            ->with(
                '{"message":"test"}',
                'test.routing.key',
                null,
                ['content-type' => 'application/json']
            );
        
        $sender = new Sender($connection, $serializer, [
            'exchange' => 'test_exchange',
            'routing_key' => 'test.routing.key',
        ]);
        
        // Inject mock exchange
        $reflection = new \ReflectionClass($sender);
        $property = $reflection->getProperty('exchange');
        $property->setAccessible(true);
        $property->setValue($sender, $exchange);
        
        $message = new \stdClass();
        $message->content = 'test';
        $envelope = new Envelope($message);
        
        $result = $sender->send($envelope);
        
        $this->assertSame($envelope, $result);
    }
    
    public function testSendUsesRoutingKeyFromStamp(): void
    {
        $connection = $this->createMock(\AMQPConnection::class);
        $channel = $this->createMock(\AMQPChannel::class);
        $exchange = $this->createMock(\AMQPExchange::class);
        
        $connection->method('isConnected')->willReturn(true);
        
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('encode')->willReturn([
            'body' => '{"message":"test"}',
            'headers' => [],
        ]);
        
        $exchange->expects($this->once())
            ->method('publish')
            ->with(
                '{"message":"test"}',
                'stamp.routing.key',
                null,
                []
            );
        
        $sender = new Sender($connection, $serializer, [
            'exchange' => 'test_exchange',
        ]);
        
        // Inject mock exchange
        $reflection = new \ReflectionClass($sender);
        $property = $reflection->getProperty('exchange');
        $property->setAccessible(true);
        $property->setValue($sender, $exchange);
        
        $message = new \stdClass();
        $envelope = new Envelope($message, [new AmqpStamp('stamp.routing.key')]);
        
        $sender->send($envelope);
    }
}
```

- [ ] **Step 2: Run test**

Run: `composer test-unit -- --filter SenderTest`
Expected: PASS (2 tests)

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/SenderTest.php
git commit -m "test: add unit tests for Sender"
```

---

## Task 7: Unit tests for Receiver

**Files:**
- Create: `tests/Unit/ReceiverTest.php`

- [ ] **Step 1: Write unit test**

```php
<?php

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\RawMessageStamp;
use CrazyGoat\TheConsoomer\Receiver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class ReceiverTest extends TestCase
{
    public function testGetReturnsEmptyArrayWhenNoMessage(): void
    {
        $connection = $this->createMock(\AMQPConnection::class);
        $connection->method('isConnected')->willReturn(true);
        
        $serializer = $this->createMock(SerializerInterface::class);
        
        $queue = $this->createMock(\AMQPQueue::class);
        $queue->method('getConsumerTag')->willReturn('test-consumer');
        $queue->method('consume')
            ->willThrowException(new \AMQPQueueException('Consumer timeout exceed'));
        
        $receiver = new Receiver($connection, $serializer, [
            'queue' => 'test_queue',
            'max_unacked_messages' => 10,
        ]);
        
        // Inject mock queue
        $reflection = new \ReflectionClass($receiver);
        $property = $reflection->getProperty('queue');
        $property->setAccessible(true);
        $property->setValue($receiver, $queue);
        
        $result = $receiver->get();
        
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
    
    public function testAckThrowsExceptionWithoutStamp(): void
    {
        $connection = $this->createMock(\AMQPConnection::class);
        $serializer = $this->createMock(SerializerInterface::class);
        
        $receiver = new Receiver($connection, $serializer, ['queue' => 'test_queue']);
        
        $message = new \stdClass();
        $envelope = new Envelope($message);
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No raw message stamp');
        
        $receiver->ack($envelope);
    }
    
    public function testRejectThrowsExceptionWithoutStamp(): void
    {
        $connection = $this->createMock(\AMQPConnection::class);
        $serializer = $this->createMock(SerializerInterface::class);
        
        $receiver = new Receiver($connection, $serializer, ['queue' => 'test_queue']);
        
        $message = new \stdClass();
        $envelope = new Envelope($message);
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No raw message stamp');
        
        $receiver->reject($envelope);
    }
    
    public function testMaxUnackedMessagesConfiguration(): void
    {
        $connection = $this->createMock(\AMQPConnection::class);
        $serializer = $this->createMock(SerializerInterface::class);
        
        $receiver = new Receiver($connection, $serializer, [
            'queue' => 'test_queue',
            'max_unacked_messages' => 50,
        ]);
        
        $reflection = new \ReflectionClass($receiver);
        $property = $reflection->getProperty('maxUnackedMessages');
        $property->setAccessible(true);
        
        $this->assertEquals(50, $property->getValue($receiver));
    }
}
```

- [ ] **Step 2: Run test**

Run: `composer test-unit -- --filter ReceiverTest`
Expected: PASS (4 tests)

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/ReceiverTest.php
git commit -m "test: add unit tests for Receiver"
```

---

## Task 8: Unit tests for AmqpTransport

**Files:**
- Create: `tests/Unit/AmqpTransportTest.php`

- [ ] **Step 1: Write unit test**

```php
<?php

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\AmqpTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

class AmqpTransportTest extends TestCase
{
    public function testSupportsAmqpConsoomerDsn(): void
    {
        $receiver = $this->createMock(ReceiverInterface::class);
        $sender = $this->createMock(SenderInterface::class);
        
        $transport = new AmqpTransport($receiver, $sender);
        
        $this->assertTrue($transport->supports('amqp-consoomer://localhost', []));
        $this->assertTrue($transport->supports('amqp-consoomer://user:pass@host:5672/vhost', []));
        $this->assertFalse($transport->supports('amqp://localhost', []));
        $this->assertFalse($transport->supports('redis://localhost', []));
    }
    
    public function testGetDelegatesToReceiver(): void
    {
        $receiver = $this->createMock(ReceiverInterface::class);
        $sender = $this->createMock(SenderInterface::class);
        
        $message = new \stdClass();
        $envelope = new Envelope($message);
        
        $receiver->expects($this->once())
            ->method('get')
            ->willReturn([$envelope]);
        
        $transport = new AmqpTransport($receiver, $sender);
        
        $result = iterator_to_array($transport->get());
        
        $this->assertCount(1, $result);
        $this->assertSame($envelope, $result[0]);
    }
    
    public function testAckDelegatesToReceiver(): void
    {
        $receiver = $this->createMock(ReceiverInterface::class);
        $sender = $this->createMock(SenderInterface::class);
        
        $message = new \stdClass();
        $envelope = new Envelope($message);
        
        $receiver->expects($this->once())
            ->method('ack')
            ->with($envelope);
        
        $transport = new AmqpTransport($receiver, $sender);
        
        $transport->ack($envelope);
    }
    
    public function testRejectDelegatesToReceiver(): void
    {
        $receiver = $this->createMock(ReceiverInterface::class);
        $sender = $this->createMock(SenderInterface::class);
        
        $message = new \stdClass();
        $envelope = new Envelope($message);
        
        $receiver->expects($this->once())
            ->method('reject')
            ->with($envelope);
        
        $transport = new AmqpTransport($receiver, $sender);
        
        $transport->reject($envelope);
    }
    
    public function testSendDelegatesToSender(): void
    {
        $receiver = $this->createMock(ReceiverInterface::class);
        $sender = $this->createMock(SenderInterface::class);
        
        $message = new \stdClass();
        $envelope = new Envelope($message);
        
        $sender->expects($this->once())
            ->method('send')
            ->with($envelope)
            ->willReturn($envelope);
        
        $transport = new AmqpTransport($receiver, $sender);
        
        $result = $transport->send($envelope);
        
        $this->assertSame($envelope, $result);
    }
}
```

- [ ] **Step 2: Run test**

Run: `composer test-unit -- --filter AmqpTransportTest`
Expected: PASS (5 tests)

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/AmqpTransportTest.php
git commit -m "test: add unit tests for AmqpTransport"
```

---

## Task 9: Create E2E test base class

**Files:**
- Create: `tests/E2E/TestCase.php`

- [ ] **Step 1: Write E2E base class**

```php
<?php

namespace CrazyGoat\TheConsoomer\Tests\E2E;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected ?\AMQPConnection $connection = null;
    protected ?\AMQPChannel $channel = null;
    
    protected function setUp(): void
    {
        $host = getenv('RABBITMQ_HOST') ?: 'localhost';
        $port = intval(getenv('RABBITMQ_PORT') ?: 5672);
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $password = getenv('RABBITMQ_PASSWORD') ?: 'guest';
        $vhost = getenv('RABBITMQ_VHOST') ?: '/';
        
        $this->connection = new \AMQPConnection();
        $this->connection->setHost($host);
        $this->connection->setPort($port);
        $this->connection->setLogin($user);
        $this->connection->setPassword($password);
        $this->connection->setVhost($vhost);
        $this->connection->connect();
        
        $this->channel = new \AMQPChannel($this->connection);
    }
    
    protected function tearDown(): void
    {
        if ($this->channel instanceof \AMQPChannel) {
            // Channel cleanup happens automatically
        }
        
        if ($this->connection instanceof \AMQPConnection && $this->connection->isConnected()) {
            $this->connection->disconnect();
        }
        
        $this->channel = null;
        $this->connection = null;
    }
    
    protected function declareQueue(string $name, bool $durable = true): void
    {
        $queue = new \AMQPQueue($this->channel);
        $queue->setName($name);
        $queue->setFlags($durable ? AMQP_DURABLE : AMQP_AUTODELETE);
        $queue->declareQueue();
    }
    
    protected function declareExchange(string $name, string $type = 'direct'): void
    {
        $exchange = new \AMQPExchange($this->channel);
        $exchange->setName($name);
        $exchange->setType($type);
        $exchange->setFlags(AMQP_DURABLE);
        $exchange->declareExchange();
    }
    
    protected function bindQueue(string $queueName, string $exchangeName, string $routingKey = ''): void
    {
        $queue = new \AMQPQueue($this->channel);
        $queue->setName($queueName);
        $queue->bind($exchangeName, $routingKey);
    }
    
    protected function purgeQueue(string $name): void
    {
        $queue = new \AMQPQueue($this->channel);
        $queue->setName($name);
        $queue->purge();
    }
    
    protected function deleteQueue(string $name): void
    {
        $queue = new \AMQPQueue($this->channel);
        $queue->setName($name);
        $queue->delete();
    }
    
    protected function deleteExchange(string $name): void
    {
        $exchange = new \AMQPExchange($this->channel);
        $exchange->setName($name);
        $exchange->delete();
    }
    
    protected function publishMessage(string $exchangeName, string $body, string $routingKey = ''): void
    {
        $exchange = new \AMQPExchange($this->channel);
        $exchange->setName($exchangeName);
        $exchange->publish($body, $routingKey);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add tests/E2E/TestCase.php
git commit -m "test: add E2E test base class"
```

---

## Task 10: Create E2E test for consume/produce

**Files:**
- Create: `tests/E2E/ConsumeProduceTest.php`

- [ ] **Step 1: Write E2E test**

```php
<?php

namespace CrazyGoat\TheConsoomer\Tests\E2E;

use CrazyGoat\TheConsoomer\AmqpTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

class ConsumeProduceTest extends TestCase
{
    private const QUEUE_NAME = 'test_consume_produce_queue';
    private const EXCHANGE_NAME = 'test_consume_produce_exchange';
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->declareExchange(self::EXCHANGE_NAME);
        $this->declareQueue(self::QUEUE_NAME);
        $this->bindQueue(self::QUEUE_NAME, self::EXCHANGE_NAME);
    }
    
    protected function tearDown(): void
    {
        $this->deleteQueue(self::QUEUE_NAME);
        $this->deleteExchange(self::EXCHANGE_NAME);
        
        parent::tearDown();
    }
    
    public function testProduceAndConsumeMessage(): void
    {
        $host = getenv('RABBITMQ_HOST') ?: 'localhost';
        $port = intval(getenv('RABBITMQ_PORT') ?: 5672);
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $password = getenv('RABBITMQ_PASSWORD') ?: 'guest';
        $vhost = getenv('RABBITMQ_VHOST') ?: '/';
        
        $dsn = sprintf(
            'amqp-consoomer://%s:%s@%s:%d/%s/%s?queue=%s',
            $user,
            $password,
            $host,
            $port,
            urlencode($vhost),
            self::EXCHANGE_NAME,
            self::QUEUE_NAME
        );
        
        $serializer = new PhpSerializer();
        $transport = AmqpTransport::create($dsn, [], $serializer);
        
        // Produce message
        $testMessage = new \stdClass();
        $testMessage->content = 'Hello E2E Test';
        $envelope = new Envelope($testMessage);
        
        $transport->send($envelope);
        
        // Consume message
        $messages = $transport->get();
        
        $this->assertIsIterable($messages);
        $messages = iterator_to_array($messages);
        
        $this->assertCount(1, $messages);
        
        /** @var Envelope $receivedEnvelope */
        $receivedEnvelope = $messages[0];
        $receivedMessage = $receivedEnvelope->getMessage();
        
        $this->assertInstanceOf(\stdClass::class, $receivedMessage);
        $this->assertEquals('Hello E2E Test', $receivedMessage->content);
        
        // Acknowledge message
        $transport->ack($receivedEnvelope);
    }
    
    public function testConsumeEmptyQueue(): void
    {
        $this->purgeQueue(self::QUEUE_NAME);
        
        $host = getenv('RABBITMQ_HOST') ?: 'localhost';
        $port = intval(getenv('RABBITMQ_PORT') ?: 5672);
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $password = getenv('RABBITMQ_PASSWORD') ?: 'guest';
        $vhost = getenv('RABBITMQ_VHOST') ?: '/';
        
        $dsn = sprintf(
            'amqp-consoomer://%s:%s@%s:%d/%s/%s?queue=%s&timeout=0.1',
            $user,
            $password,
            $host,
            $port,
            urlencode($vhost),
            self::EXCHANGE_NAME,
            self::QUEUE_NAME
        );
        
        $serializer = new PhpSerializer();
        $transport = AmqpTransport::create($dsn, [], $serializer);
        
        $messages = $transport->get();
        $messages = iterator_to_array($messages);
        
        $this->assertEmpty($messages);
    }
    
    public function testRejectMessage(): void
    {
        $host = getenv('RABBITMQ_HOST') ?: 'localhost';
        $port = intval(getenv('RABBITMQ_PORT') ?: 5672);
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $password = getenv('RABBITMQ_PASSWORD') ?: 'guest';
        $vhost = getenv('RABBITMQ_VHOST') ?: '/';
        
        $dsn = sprintf(
            'amqp-consoomer://%s:%s@%s:%d/%s/%s?queue=%s',
            $user,
            $password,
            $host,
            $port,
            urlencode($vhost),
            self::EXCHANGE_NAME,
            self::QUEUE_NAME
        );
        
        $serializer = new PhpSerializer();
        $transport = AmqpTransport::create($dsn, [], $serializer);
        
        // Produce message
        $testMessage = new \stdClass();
        $testMessage->content = 'To Reject';
        $envelope = new Envelope($testMessage);
        
        $transport->send($envelope);
        
        // Consume and reject
        $messages = iterator_to_array($transport->get());
        $this->assertCount(1, $messages);
        
        $receivedEnvelope = $messages[0];
        $transport->reject($receivedEnvelope);
        
        // Message should be gone (rejected, not requeued)
        $messages = iterator_to_array($transport->get());
        $this->assertEmpty($messages);
    }
}
```

- [ ] **Step 2: Run E2E test manually (requires RabbitMQ)**

Run: `composer rabbitmq-start && sleep 10 && composer test-e2e && composer rabbitmq-stop`
Expected: PASS (3 tests)

- [ ] **Step 3: Commit**

```bash
git add tests/E2E/ConsumeProduceTest.php
git commit -m "test: add E2E tests for consume/produce"
```

---

## Task 11: Run all tests and verify

- [ ] **Step 1: Run unit tests**

Run: `composer test-unit`
Expected: All unit tests pass

- [ ] **Step 2: Run E2E tests**

Run: `composer test-e2e-full`
Expected: All E2E tests pass

- [ ] **Step 3: Run all tests**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 4: Create test summary**

```bash
composer test -- --testdox
```

Expected: Summary showing all tests with checkmarks

---

## Summary

This plan establishes complete test infrastructure:

1. **PHPUnit** - Configuration and installation
2. **Docker Compose** - RabbitMQ for E2E testing
3. **Composer scripts** - Easy test execution
4. **Unit tests** - RawMessageStamp, AmqpStamp, Sender, Receiver, AmqpTransport
5. **E2E tests** - Real RabbitMQ integration tests
6. **Test utilities** - Base class for E2E tests

**Test coverage:**
- Unit: 5 test files, ~16 tests
- E2E: 1 test file, 3 tests

**Scripts available:**
- `composer test` - Run all tests
- `composer test-unit` - Run unit tests only
- `composer test-e2e` - Run E2E tests only
- `composer test-e2e-full` - Start RabbitMQ, run E2E tests, stop RabbitMQ
- `composer test-coverage` - Generate coverage report
