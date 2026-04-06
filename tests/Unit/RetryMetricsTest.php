<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\RetryMetrics;
use PHPUnit\Framework\TestCase;

class RetryMetricsTest extends TestCase
{
    public function testInitialMetricsAreZero(): void
    {
        $metrics = new RetryMetrics();

        $this->assertEquals(0, $metrics->getTotalAttempts());
        $this->assertEquals(0, $metrics->getSuccessfulRetries());
        $this->assertEquals(0, $metrics->getFailedRetries());
        $this->assertEquals(0, $metrics->getCircuitBreakerOpens());
    }

    public function testRecordAttempt(): void
    {
        $metrics = new RetryMetrics();

        $metrics->recordAttempt();
        $metrics->recordAttempt();

        $this->assertEquals(2, $metrics->getTotalAttempts());
    }

    public function testRecordSuccess(): void
    {
        $metrics = new RetryMetrics();

        $metrics->recordAttempt();
        $metrics->recordSuccess();

        $this->assertEquals(1, $metrics->getSuccessfulRetries());
    }

    public function testRecordFailure(): void
    {
        $metrics = new RetryMetrics();

        $metrics->recordFailure();

        $this->assertEquals(1, $metrics->getFailedRetries());
    }

    public function testRecordCircuitBreakerOpen(): void
    {
        $metrics = new RetryMetrics();

        $metrics->recordCircuitBreakerOpen();

        $this->assertEquals(1, $metrics->getCircuitBreakerOpens());
    }

    public function testSuccessRateCalculation(): void
    {
        $metrics = new RetryMetrics();

        $metrics->recordAttempt();
        $metrics->recordAttempt();
        $metrics->recordAttempt();
        $metrics->recordSuccess();
        $metrics->recordSuccess();

        $this->assertEqualsWithDelta(66.67, $metrics->getSuccessRate(), 0.01);
    }

    public function testSuccessRateWithNoAttempts(): void
    {
        $metrics = new RetryMetrics();

        $this->assertEquals(0.0, $metrics->getSuccessRate());
    }

    public function testReset(): void
    {
        $metrics = new RetryMetrics();

        $metrics->recordAttempt();
        $metrics->recordSuccess();
        $metrics->recordFailure();
        $metrics->recordCircuitBreakerOpen();

        $metrics->reset();

        $this->assertEquals(0, $metrics->getTotalAttempts());
        $this->assertEquals(0, $metrics->getSuccessfulRetries());
        $this->assertEquals(0, $metrics->getFailedRetries());
        $this->assertEquals(0, $metrics->getCircuitBreakerOpens());
    }

    public function testToArray(): void
    {
        $metrics = new RetryMetrics();

        $metrics->recordAttempt();
        $metrics->recordSuccess();

        $array = $metrics->toArray();

        $this->assertArrayHasKey('total_attempts', $array);
        $this->assertArrayHasKey('successful_retries', $array);
        $this->assertArrayHasKey('failed_retries', $array);
        $this->assertArrayHasKey('circuit_breaker_opens', $array);
        $this->assertArrayHasKey('success_rate', $array);
    }
}
