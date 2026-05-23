<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\AmqpDelayStamp;
use PHPUnit\Framework\TestCase;

class AmqpDelayStampTest extends TestCase
{
    public function testConstructorWithDelay(): void
    {
        $stamp = new AmqpDelayStamp(5000);

        $this->assertSame(5000, $stamp->getDelay());
    }

    public function testIsNonSendable(): void
    {
        $stamp = new AmqpDelayStamp(100);

        $this->assertInstanceOf(\Symfony\Component\Messenger\Stamp\NonSendableStampInterface::class, $stamp);
    }

    public function testPositiveDelayIsAccepted(): void
    {
        $stamp = new AmqpDelayStamp(1);

        $this->assertSame(1, $stamp->getDelay());
    }

    /** @dataProvider invalidDelayProvider */
    public function testInvalidDelayThrowsException(int $delay): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Delay must be a positive integer');

        new AmqpDelayStamp($delay);
    }

    /** @return array<string, array{int}> */
    public static function invalidDelayProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'large negative' => [-5000],
        ];
    }
}
