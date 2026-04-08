<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\AmqpTransportFactory;
use PHPUnit\Framework\TestCase;

class AmqpTransportFactoryTest extends TestCase
{
    public function testSupportsReturnsTrueForAmqpConsoomerDsn(): void
    {
        $factory = new AmqpTransportFactory();

        $result = $factory->supports('amqp-consoomer://localhost:5672/%2f/messages', []);

        $this->assertTrue($result);
    }

    public function testSupportsReturnsTrueForAmqpsConsoomerScheme(): void
    {
        $factory = new AmqpTransportFactory();

        $result = $factory->supports('amqps-consoomer://localhost:5672/%2f/messages', []);

        $this->assertTrue($result);
    }

    public function testSupportsReturnsFalseForOtherDsn(): void
    {
        $factory = new AmqpTransportFactory();

        $result = $factory->supports('doctrine://default', []);

        $this->assertFalse($result);
    }
}
