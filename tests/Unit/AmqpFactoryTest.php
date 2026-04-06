<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\AmqpFactory;
use PHPUnit\Framework\TestCase;

class AmqpFactoryTest extends TestCase
{
    public function testFactoryHasCreateConnectionMethod(): void
    {
        $factory = new AmqpFactory();
        $this->assertTrue(method_exists($factory, 'createConnection'));
    }

    public function testFactoryHasCreateChannelMethod(): void
    {
        $factory = new AmqpFactory();
        $this->assertTrue(method_exists($factory, 'createChannel'));
    }

    public function testFactoryHasCreateQueueMethod(): void
    {
        $factory = new AmqpFactory();
        $this->assertTrue(method_exists($factory, 'createQueue'));
    }

    public function testFactoryHasCreateExchangeMethod(): void
    {
        $factory = new AmqpFactory();
        $this->assertTrue(method_exists($factory, 'createExchange'));
    }
}
