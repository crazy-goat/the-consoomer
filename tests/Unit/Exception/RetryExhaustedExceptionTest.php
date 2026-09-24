<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit\Exception;

use CrazyGoat\TheConsoomer\Exception\RetryExhaustedException;
use PHPUnit\Framework\TestCase;

class RetryExhaustedExceptionTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $exception = new RetryExhaustedException();

        $this->assertSame('Operation failed with no retries configured', $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }

    /**
     * The wrapped AMQP code (0 or a librabbitmq errno) is meaningless, so the
     * wrapper must not propagate it; the original stays reachable via the
     * previous exception (#251).
     */
    public function testFromPreviousDoesNotPropagateTheCode(): void
    {
        $previous = new \AMQPException('Queue not found', 404);

        $exception = RetryExhaustedException::fromPrevious($previous);

        $this->assertSame('Queue not found', $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame(404, $exception->getPrevious()?->getCode());
    }

    public function testFromPreviousZerosANonZeroCode(): void
    {
        $exception = RetryExhaustedException::fromPrevious(new \RuntimeException('boom', 12345));

        $this->assertSame(0, $exception->getCode());
    }
}
