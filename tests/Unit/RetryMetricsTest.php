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

        $this->assertSame(0, $metrics->getTotalAttempts());
        $this->assertSame(0, $metrics->getSuccessfulRetries());
        $this->assertSame(0, $metrics->getFailedRetries());
        $this->assertSame(0, $metrics->getCircuitBreakerOpens());
    }

    public function testRecordAttempt(): void
    {
        $metrics = new RetryMetrics();

        $metrics->recordAttempt();
        $metrics->recordAttempt();

        $this->assertSame(2, $metrics->getTotalAttempts());
    }

    public function testRecordSuccess(): void
    {
        $metrics = new RetryMetrics();

        $metrics->recordAttempt();
        $metrics->recordSuccess();

        $this->assertSame(1, $metrics->getSuccessfulRetries());
    }

    public function testRecordFailure(): void
    {
        $metrics = new RetryMetrics();

        $metrics->recordFailure();

        $this->assertSame(1, $metrics->getFailedRetries());
    }

    public function testRecordCircuitBreakerOpen(): void
    {
        $metrics = new RetryMetrics();

        $metrics->recordCircuitBreakerOpen();

        $this->assertSame(1, $metrics->getCircuitBreakerOpens());
    }

    public function testSuccessRateCalculation(): void
    {
        $metrics = new RetryMetrics();

        $metrics->recordAttempt();
        $metrics->recordAttempt();
        $metrics->recordAttempt();
        $metrics->recordSuccess();
        $metrics->recordSuccess();

        $this->assertEqualsWithDelta(66.67, $metrics->getRetrySuccessRate(), 0.01);
    }

    public function testSuccessRateWithNoAttempts(): void
    {
        $metrics = new RetryMetrics();

        $this->assertSame(0.0, $metrics->getRetrySuccessRate());
    }

    public function testReset(): void
    {
        $metrics = new RetryMetrics();

        $metrics->recordAttempt();
        $metrics->recordSuccess();
        $metrics->recordFailure();
        $metrics->recordCircuitBreakerOpen();

        $metrics->reset();

        $this->assertSame(0, $metrics->getTotalAttempts());
        $this->assertSame(0, $metrics->getSuccessfulRetries());
        $this->assertSame(0, $metrics->getFailedRetries());
        $this->assertSame(0, $metrics->getCircuitBreakerOpens());
    }

    public function testToArray(): void
    {
        $metrics = new RetryMetrics();

        $metrics->recordAttempt();
        $metrics->recordSuccess();

        // The whole snapshot in one assertion: it pins every key *and* every
        // value, so a wrong counter or a renamed key fails here instead of only
        // being noticed by a consumer of the array.
        $this->assertSame([
            'total_attempts' => 1,
            'successful_retries' => 1,
            'failed_retries' => 0,
            'circuit_breaker_opens' => 0,
            'retry_success_rate' => 100.0,
            'successful_operations' => 0,
            'failed_operations' => 0,
            'operation_success_rate' => 0.0,
        ], $metrics->toArray());
    }

    /**
     * Regression test for issue #206: getRetrySuccessRate() must use a
     * denominator that includes failures, not just successful operations.
     */
    public function testMixedSuccessAndFailureSuccessRate(): void
    {
        $metrics = new RetryMetrics();

        $metrics->recordAttempt();
        $metrics->recordSuccess();

        $metrics->recordAttempt();
        $metrics->recordFailure();

        $this->assertSame(2, $metrics->getTotalAttempts());
        $this->assertSame(1, $metrics->getSuccessfulRetries());
        $this->assertSame(1, $metrics->getFailedRetries());
        $this->assertSame(50.0, $metrics->getRetrySuccessRate());
    }

    /**
     * Regression test for issue #206: all-failure sequences must still
     * contribute to totalAttempts so the denominator is non-zero.
     */
    public function testAllFailuresContributeToTotalAttempts(): void
    {
        $metrics = new RetryMetrics();

        $metrics->recordAttempt();
        $metrics->recordAttempt();
        $metrics->recordAttempt();
        $metrics->recordFailure();

        $this->assertSame(3, $metrics->getTotalAttempts());
        $this->assertSame(0, $metrics->getSuccessfulRetries());
        $this->assertSame(1, $metrics->getFailedRetries());
        $this->assertSame(0.0, $metrics->getRetrySuccessRate());
    }

    public function testOperationCountersRecordSuccessAndFailure(): void
    {
        $metrics = new RetryMetrics();

        $metrics->recordAttempt();
        $metrics->recordSuccessfulOperation();

        $metrics->recordAttempt();
        $metrics->recordFailedOperation();

        $this->assertSame(1, $metrics->getSuccessfulOperations());
        $this->assertSame(1, $metrics->getFailedOperations());
        $this->assertSame(50.0, $metrics->getOperationSuccessRate());
        $this->assertSame(2, $metrics->getTotalAttempts(), 'Operation counters are independent of attempts');
    }

    public function testOperationSuccessRateWithNoOperations(): void
    {
        $metrics = new RetryMetrics();

        $this->assertSame(0.0, $metrics->getOperationSuccessRate());
    }

    public function testResetClearsOperationCounters(): void
    {
        $metrics = new RetryMetrics();

        $metrics->recordSuccessfulOperation();
        $metrics->recordFailedOperation();

        $metrics->reset();

        $this->assertSame(0, $metrics->getSuccessfulOperations());
        $this->assertSame(0, $metrics->getFailedOperations());
    }

    public function testToArrayIncludesOperationCounters(): void
    {
        $metrics = new RetryMetrics();

        $metrics->recordSuccessfulOperation();
        $metrics->recordFailedOperation();

        $this->assertSame([
            'total_attempts' => 0,
            'successful_retries' => 0,
            'failed_retries' => 0,
            'circuit_breaker_opens' => 0,
            'retry_success_rate' => 0.0,
            'successful_operations' => 1,
            'failed_operations' => 1,
            'operation_success_rate' => 50.0,
        ], $metrics->toArray());
    }
}
