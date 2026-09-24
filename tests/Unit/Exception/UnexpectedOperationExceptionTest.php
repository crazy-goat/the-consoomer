<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit\Exception;

use CrazyGoat\TheConsoomer\Exception\UnexpectedOperationException;
use PHPUnit\Framework\TestCase;

class UnexpectedOperationExceptionTest extends TestCase
{
    /**
     * The wrapper must report a stable code (0) regardless of the wrapped
     * failure, so getCode() does not mean different things per wrapping path;
     * the original code stays reachable via getPrevious() (#251).
     */
    public function testFromPreviousDoesNotPropagateTheCode(): void
    {
        $previous = new \RuntimeException('serializer exploded', 4242);

        $exception = UnexpectedOperationException::fromPrevious($previous);

        $this->assertSame('serializer exploded', $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame(4242, $exception->getPrevious()?->getCode());
    }
}
