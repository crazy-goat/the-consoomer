<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\AmqpPriorityStamp;
use PHPUnit\Framework\TestCase;

class AmqpPriorityStampTest extends TestCase
{
    public function testConstructorWithPriority(): void
    {
        $stamp = new AmqpPriorityStamp(5);

        $this->assertSame(5, $stamp->getPriority());
    }

    public function testIsNonSendable(): void
    {
        $stamp = new AmqpPriorityStamp(0);

        $this->assertInstanceOf(\Symfony\Component\Messenger\Stamp\NonSendableStampInterface::class, $stamp);
    }

    public function testAllowsZeroPriority(): void
    {
        $stamp = new AmqpPriorityStamp(0);

        $this->assertSame(0, $stamp->getPriority());
    }

    public function testAllowsMaxPriority(): void
    {
        $stamp = new AmqpPriorityStamp(255);

        $this->assertSame(255, $stamp->getPriority());
    }

    public function testAllowsHighPriority(): void
    {
        $stamp = new AmqpPriorityStamp(10);

        $this->assertSame(10, $stamp->getPriority());
    }

    /** @dataProvider invalidPriorityProvider */
    public function testInvalidPriorityThrowsException(int $priority): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Priority must be between 0 and 255');

        new AmqpPriorityStamp($priority);
    }

    /** @return array<string, array{int}> */
    public static function invalidPriorityProvider(): array
    {
        return [
            'negative' => [-1],
            'too high' => [256],
        ];
    }
}
