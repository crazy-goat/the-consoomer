<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\DsnParser;
use PHPUnit\Framework\TestCase;

class DsnParserTest extends TestCase
{
    public function testParsesBasicDsn(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange');

        $this->assertEquals('localhost', $result['host']);
        $this->assertEquals(5672, $result['port']);
        $this->assertEquals('guest', $result['user']);
        $this->assertEquals('guest', $result['password']);
        $this->assertEquals('/', $result['vhost']);
        $this->assertEquals('my_exchange', $result['exchange']);
    }

    public function testParsesQueryOptions(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange?heartbeat=60&retry_count=3');

        $this->assertEquals(60, $result['heartbeat']);
        $this->assertEquals(3, $result['retry_count']);
    }

    public function testParsesSslOptions(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange?ssl_cert=/path/to/cert.pem&ssl_key=/path/to/key.pem&ssl_cacert=/path/to/ca.pem');

        $this->assertEquals('/path/to/cert.pem', $result['ssl_cert']);
        $this->assertEquals('/path/to/key.pem', $result['ssl_key']);
        $this->assertEquals('/path/to/ca.pem', $result['ssl_cacert']);
    }

    public function testParsesQueueOptions(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange?queue=my_queue&routing_key=my.key');

        $this->assertEquals('my_queue', $result['queue']);
        $this->assertEquals('my.key', $result['routing_key']);
    }

    public function testNormalizesQueueArguments(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange?queue_arguments[x-max-priority]=10&queue_arguments[x-message-ttl]=60000');

        $this->assertIsArray($result['queue_arguments']);
        $this->assertEquals(10, $result['queue_arguments']['x-max-priority']);
        $this->assertEquals(60000, $result['queue_arguments']['x-message-ttl']);
    }

    public function testValidatesOptionsAutomaticallyForValidDsn(): void
    {
        $parser = new DsnParser();
        // Should not throw - valid DSN with exchange
        $result = $parser->parse('amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange?queue=my_queue');

        $this->assertEquals('my_exchange', $result['exchange']);
    }

    public function testParseThrowsExceptionWhenExchangeMissing(): void
    {
        $parser = new DsnParser();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DSN is missing required exchange name');
        $parser->parse('amqp-consoomer://guest:guest@localhost:5672/%2f/');
    }

    public function testParseThrowsExceptionForInvalidExchangeType(): void
    {
        $parser = new DsnParser();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid exchange_type "invalid"');
        $parser->parse('amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange?exchange_type=invalid');
    }

    public function testValidatesOptionsAutomaticallyForValidExchangeType(): void
    {
        $parser = new DsnParser();
        // Should not throw - valid exchange_type
        $result = $parser->parse('amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange?exchange_type=fanout');

        $this->assertEquals('fanout', $result['exchange_type']);
    }

    public function testParsesMultipleQueues(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange?queues[queue1][binding_keys][0]=key1&queues[queue2][binding_keys][0]=key2');

        $this->assertIsArray($result['queues']);
        $this->assertArrayHasKey('queue1', $result['queues']);
        $this->assertArrayHasKey('queue2', $result['queues']);
    }

    public function testParsesTimeoutOptions(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange?read_timeout=5.5&write_timeout=3.0&connect_timeout=2.0');

        $this->assertEquals(5.5, $result['read_timeout']);
        $this->assertEquals(3.0, $result['write_timeout']);
        $this->assertEquals(2.0, $result['connect_timeout']);
    }

    public function testThrowsExceptionForMalformedDsn(): void
    {
        $parser = new DsnParser();
        $this->expectException(\InvalidArgumentException::class);
        $parser->parse(':');
    }

    public function testExchangeTypeEnumExists(): void
    {
        $this->assertTrue(enum_exists(\CrazyGoat\TheConsoomer\Enum\ExchangeType::class));
    }

    public function testExchangeTypeEnumHasCorrectValues(): void
    {
        $this->assertEquals('direct', \CrazyGoat\TheConsoomer\Enum\ExchangeType::DIRECT->value);
        $this->assertEquals('fanout', \CrazyGoat\TheConsoomer\Enum\ExchangeType::FANOUT->value);
        $this->assertEquals('topic', \CrazyGoat\TheConsoomer\Enum\ExchangeType::TOPIC->value);
        $this->assertEquals('headers', \CrazyGoat\TheConsoomer\Enum\ExchangeType::HEADERS->value);
    }

    public function testParsesAmqpsConsoomerScheme(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqps-consoomer://guest:guest@localhost/%2f/my_exchange');

        $this->assertEquals('localhost', $result['host']);
        $this->assertEquals(5671, $result['port']);
        $this->assertTrue($result['ssl']);
        $this->assertEquals('/', $result['vhost']);
        $this->assertEquals('my_exchange', $result['exchange']);
    }

    public function testAmqpsConsoomerSchemeWithCustomPort(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqps-consoomer://guest:guest@localhost:5673/%2f/my_exchange');

        $this->assertEquals(5673, $result['port']);
        $this->assertTrue($result['ssl']);
    }

    public function testParsesLegacyAmqpsScheme(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqps://guest:guest@localhost/%2f/my_exchange');

        $this->assertEquals('localhost', $result['host']);
        $this->assertEquals(5671, $result['port']);
        $this->assertEquals('guest', $result['user']);
        $this->assertEquals('guest', $result['password']);
        $this->assertTrue($result['ssl']);
        $this->assertEquals('/', $result['vhost']);
        $this->assertEquals('my_exchange', $result['exchange']);
    }
}
