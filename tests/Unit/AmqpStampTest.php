<?php

declare(strict_types=1);

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

    public function testConstructorWithDefaultRoutingKey(): void
    {
        $stamp = new AmqpStamp();

        $this->assertEquals('', $stamp->routingKey);
    }

    public function testIsNonSendable(): void
    {
        $stamp = new AmqpStamp('test');

        $this->assertInstanceOf(\Symfony\Component\Messenger\Stamp\NonSendableStampInterface::class, $stamp);
    }
}
