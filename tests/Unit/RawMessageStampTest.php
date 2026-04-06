<?php

declare(strict_types=1);

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
