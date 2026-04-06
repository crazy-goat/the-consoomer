# Connection Retry on Failure (#10)

> **Phase:** Phase 1: Foundation & DX  
> **Backlog:** [missing-features.md](../missing-features.md)

## Overview

**What it does:** Automatically retries operations when connection fails.

**Business value:** Resilience to temporary network issues. Less manual intervention.

## Status

✅ **Implemented**

## Implementation Details

### Components Created

| Component | File | Description |
|-----------|------|-------------|
| `ConnectionRetryInterface` | `src/ConnectionRetryInterface.php` | Interface for retry operations |
| `ConnectionRetry` | `src/ConnectionRetry.php` | Main retry logic with backoff and jitter |
| `CircuitBreaker` | `src/CircuitBreaker.php` | Circuit breaker pattern implementation |
| `RetryMetrics` | `src/RetryMetrics.php` | Metrics collection for retry operations |
| `RetryTest` | `tests/E2E/RetryTest.php` | E2E tests for retry functionality |

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `retry` | `false` | Enable retry functionality |
| `retry_count` | `3` | Maximum number of retry attempts |
| `retry_delay` | `100000` | Base delay in microseconds (100ms) |
| `retry_backoff` | `false` | Enable exponential backoff |
| `retry_max_delay` | `30000000` | Maximum delay in microseconds (30s) |
| `retry_jitter` | `true` | Add random variation to delay (±25%) |
| `retry_circuit_breaker` | `false` | Enable circuit breaker |
| `retry_circuit_breaker_threshold` | `10` | Number of failures to open circuit |
| `retry_circuit_breaker_timeout` | `60` | Seconds before trying half-open |

### Usage

**DSN Configuration:**
```
amqp-consoomer://user:pass@host:5672/vhost/exchange?queue=test&retry=true&retry_count=3&retry_delay=100000&retry_backoff=true&retry_jitter=true
```

**Code Usage:**
```php
$transport = AmqpTransport::create($dsn, [], $serializer);

// Messages are automatically retried on connection failure
$transport->send($envelope);
$transport->ack($envelope);
$transport->reject($envelope);
```

### Metrics

```php
$retry = new ConnectionRetry(retryCount: 3, retryDelay: 100000);
$retry->withRetry(fn() => $operation());

$metrics = $retry->getMetrics();
$metrics->toArray();
// [
//   'total_attempts' => 1,
//   'successful_retries' => 0,
//   'failed_retries' => 0,
//   'circuit_breaker_opens' => 0,
//   'success_rate' => 100.0,
// ]
```

### Testing

**Unit Tests:** 18 tests covering retry logic, backoff, jitter, circuit breaker, metrics

**E2E Tests:** Integration tests with real RabbitMQ

```bash
# Run unit tests
composer test-unit

# Run E2E tests (requires RabbitMQ)
composer test-e2e
```