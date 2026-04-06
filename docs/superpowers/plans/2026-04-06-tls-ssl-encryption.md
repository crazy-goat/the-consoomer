# TLS/SSL Encryption Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add SSL/TLS support for RabbitMQ connections with `amqps://` scheme

**Architecture:** Extend DsnParser to recognize `amqps://`, add SSL options, configure AmqpFactory for SSL

**Tech Stack:** PHP, AMQP extension, PHPUnit

---

### Task 1: Update DsnParser for amqps:// scheme

**Files:**
- Modify: `src/DsnParser.php:1-111`

- [ ] **Step 1: Write the failing test**

```php
public function testParsesAmqpsScheme(): void
{
    $parser = new DsnParser();
    $result = $parser->parse('amqps://guest:guest@localhost/%2f/my_exchange');

    $this->assertEquals('localhost', $result['host']);
    $this->assertEquals(5671, $result['port']); // AMQPS default port
    $this->assertTrue($result['ssl']);
    $this->assertEquals('/', $result['vhost']);
    $this->assertEquals('my_exchange', $result['exchange']);
}

public function testAmqpsSchemeWithCustomPort(): void
{
    $parser = new DsnParser();
    $result = $parser->parse('amqps://guest:guest@localhost:5673/%2f/my_exchange');

    $this->assertEquals(5673, $result['port']);
    $this->assertTrue($result['ssl']);
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /home/decodo/work/the-consoomer && ./vendor/bin/phpunit tests/Unit/DsnParserTest.php --filter testParsesAmqpsScheme
```
Expected: FAIL (test not found or method returns wrong)

- [ ] **Step 3: Implement DsnParser changes**

W `DsnParser::parse()` po `parse_url($dsn)` dodaj:
```php
if ($info['scheme'] ?? '' === 'amqps') {
    $result['ssl'] = true;
    $result['port'] ??= 5671;
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd /home/decodo/work/the-consoomer && ./vendor/bin/phpunit tests/Unit/DsnParserTest.php --filter testParsesAmqpsScheme
```
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/DsnParser.php tests/Unit/DsnParserTest.php
git commit -m "feat: add amqps:// scheme support in DsnParser"
```

---

### Task 2: Update AmqpTransport supports()

**Files:**
- Modify: `src/AmqpTransport.php:42-45`

- [ ] **Step 1: Write the failing test**

```php
public function testSupportsAmqpsScheme(): void
{
    $transport = new AmqpTransport(
        $this->createMock(ReceiverInterface::class),
        $this->createMock(SenderInterface::class)
    );
    
    $this->assertTrue($transport->supports('amqps://localhost/%2f/exchange', []));
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /home/decodo/work/the-consoomer && ./vendor/bin/phpunit tests/Unit/AmqpTransportTest.php --filter testSupportsAmqpsScheme
```
Expected: FAIL

- [ ] **Step 3: Update supports() method**

W `AmqpTransport::supports()` zmień:
```php
return str_starts_with($dsn, 'amqp-consoomer://') || str_starts_with($dsn, 'amqps://');
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd /home/decodo/work/the-consoomer && ./vendor/bin/phpunit tests/Unit/AmqpTransportTest.php --filter testSupportsAmqpsScheme
```
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/AmqpTransport.php tests/Unit/AmqpTransportTest.php
git commit -m "feat: add amqps:// support in AmqpTransport::supports()"
```

---

### Task 3: Add SSL configuration to AmqpFactory

**Files:**
- Modify: `src/AmqpFactory.php:1-28`
- Modify: `src/AmqpFactoryInterface.php`

- [ ] **Step 1: Write the failing test**

```php
public function testCreateConnectionWithSslOptions(): void
{
    $factory = new AmqpFactory();
    
    $connection = $factory->createConnection();
    $factory->configureSsl($connection, [
        'ssl' => true,
        'ssl_cert' => '/path/to/cert.pem',
        'ssl_key' => '/path/to/key.pem',
        'ssl_cacert' => '/path/to/ca.pem',
        'ssl_verify' => true,
    ]);
    
    // Verify SSL was configured (check method calls on mock)
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /home/decodo/work/the-consoomer && ./vendor/bin/phpunit tests/Unit/AmqpFactoryTest.php --filter testCreateConnectionWithSslOptions
```
Expected: FAIL (configureSsl method not defined)

- [ ] **Step 3: Add configureSsl method to interface**

W `AmqpFactoryInterface.php` dodaj:
```php
public function configureSsl(\AMQPConnection $connection, array $options): void;
```

- [ ] **Step 4: Implement configureSsl in AmqpFactory**

W `AmqpFactory.php` dodaj:
```php
public function configureSsl(\AMQPConnection $connection, array $options): void
{
    if (empty($options['ssl'])) {
        return;
    }

    if (!empty($options['ssl_cert'])) {
        $connection->setCert($options['ssl_cert']);
    }
    if (!empty($options['ssl_key'])) {
        $connection->setKey($options['ssl_key']);
    }
    if (!empty($options['ssl_cacert'])) {
        $connection->setCaCert($options['ssl_cacert']);
    }
    if (isset($options['ssl_verify'])) {
        $connection->setVerify($options['ssl_verify']);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

```bash
cd /home/decodo/work/the-consoomer && ./vendor/bin/phpunit tests/Unit/AmqpFactoryTest.php --filter testCreateConnectionWithSslOptions
```
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/AmqpFactory.php src/AmqpFactoryInterface.php tests/Unit/AmqpFactoryTest.php
git commit -m "feat: add SSL configuration to AmqpFactory"
```

---

### Task 4: Integrate SSL in AmqpTransport::create()

**Files:**
- Modify: `src/AmqpTransport.php:52-74`

- [ ] **Step 1: Write the failing test**

```php
public function testCreateWithAmqpsScheme(): void
{
    $serializer = new SerializerInterfaceStub();
    
    $transport = AmqpTransport::create(
        'amqps://guest:guest@localhost/%2f/my_exchange',
        [],
        $serializer
    );
    
    $this->assertInstanceOf(AmqpTransport::class, $transport);
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /home/decodo/work/the-consoomer && ./vendor/bin/phpunit tests/Unit/AmqpTransportTest.php --filter testCreateWithAmqpsScheme
```
Expected: FAIL (port not set correctly)

- [ ] **Step 3: Update AmqpTransport::create()**

W `AmqpTransport::create()` po `$connection->setPassword()` dodaj:
```php
$connection = $factory->createConnection();
$connection->setHost($parsedDsn['host']);
$connection->setPort($parsedDsn['port']);
$connection->setVhost($parsedDsn['vhost']);
$connection->setLogin($parsedDsn['user']);
$connection->setPassword($parsedDsn['password']);
$connection->setReadTimeout((float) ($parsedDsn['timeout'] ?? 0.1));

// Configure SSL if enabled
$factory->configureSsl($connection, $mergedOptions);

$connection->connect();
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd /home/decodo/work/the-consoomer && ./vendor/bin/phpunit tests/Unit/AmqpTransportTest.php --filter testCreateWithAmqpsScheme
```
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/AmqpTransport.php tests/Unit/AmqpTransportTest.php
git commit -m "feat: integrate SSL configuration in AmqpTransport::create()"
```

---

### Task 5: Add certificate file validation (optional)

**Files:**
- Modify: `src/AmqpFactory.php`

- [ ] **Step 1: Add validation in configureSsl**

Dodaj walidację plików certyfikatów przed ustawieniem:
```php
public function configureSsl(\AMQPConnection $connection, array $options): void
{
    if (empty($options['ssl'])) {
        return;
    }

    // Validate certificate files exist and are readable
    $certFiles = [
        'ssl_cert' => $options['ssl_cert'] ?? '',
        'ssl_key' => $options['ssl_key'] ?? '',
        'ssl_cacert' => $options['ssl_cacert'] ?? '',
    ];
    
    foreach ($certFiles as $type => $path) {
        if ($path !== '' && !file_exists($path)) {
            throw new \InvalidArgumentException("SSL {$type} file not found: {$path}");
        }
        if ($path !== '' && !is_readable($path)) {
            throw new \InvalidArgumentException("SSL {$type} file not readable: {$path}");
        }
    }

    if (!empty($options['ssl_cert'])) {
        $connection->setCert($options['ssl_cert']);
    }
    // ... rest of code
}
```

- [ ] **Step 2: Write test for validation**

```php
public function testConfigureSslThrowsForMissingCertFile(): void
{
    $factory = new AmqpFactory();
    $connection = new \AMQPConnection();
    
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('SSL ssl_cert file not found');
    
    $factory->configureSsl($connection, [
        'ssl' => true,
        'ssl_cert' => '/nonexistent/cert.pem',
    ]);
}
```

- [ ] **Step 3: Run test to verify it passes**

```bash
cd /home/decodo/work/the-consoomer && ./vendor/bin/phpunit tests/Unit/AmqpFactoryTest.php --filter testConfigureSslThrowsForMissingCertFile
```
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add src/AmqpFactory.php tests/Unit/AmqpFactoryTest.php
git commit -m "feat: add certificate file validation in AmqpFactory"
```

---

## Summary

Plan defines 5 tasks:
1. DsnParser - amqps:// scheme support
2. AmqpTransport::supports() - amqps:// recognition
3. AmqpFactory - SSL configuration methods
4. AmqpTransport::create() - integrate SSL
5. Certificate validation (optional)
