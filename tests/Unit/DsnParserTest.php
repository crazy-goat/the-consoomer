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

        $this->assertSame('localhost', $result['host']);
        $this->assertSame(5672, $result['port']);
        $this->assertSame('guest', $result['user']);
        $this->assertSame('guest', $result['password']);
        $this->assertSame('/', $result['vhost']);
        $this->assertSame('my_exchange', $result['exchange']);
    }

    public function testNonSslDsnDoesNotIncludeSslKey(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange');

        $this->assertArrayNotHasKey('ssl', $result);
        $this->assertSame(5672, $result['port']);
    }

    public function testParsesQueryOptions(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange?heartbeat=60&retry_count=3');

        $this->assertSame(60, $result['heartbeat']);
        $this->assertSame(3, $result['retry_count']);
    }

    public function testParsesSslOptions(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange?ssl_cert=/path/to/cert.pem&ssl_key=/path/to/key.pem&ssl_cacert=/path/to/ca.pem');

        $this->assertSame('/path/to/cert.pem', $result['ssl_cert']);
        $this->assertSame('/path/to/key.pem', $result['ssl_key']);
        $this->assertSame('/path/to/ca.pem', $result['ssl_cacert']);
    }

    public function testParsesQueueOptions(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange?queue=my_queue&routing_key=my.key');

        $this->assertSame('my_queue', $result['queue']);
        $this->assertSame('my.key', $result['routing_key']);
    }

    public function testNormalizesQueueArguments(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange?queue_arguments[x-max-priority]=10&queue_arguments[x-message-ttl]=60000');

        $this->assertIsArray($result['queue_arguments']);
        $this->assertSame(10, $result['queue_arguments']['x-max-priority']);
        $this->assertSame(60000, $result['queue_arguments']['x-message-ttl']);
    }

    public function testValidatesOptionsAutomaticallyForValidDsn(): void
    {
        $parser = new DsnParser();
        // Should not throw - valid DSN with exchange
        $result = $parser->parse('amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange?queue=my_queue');

        $this->assertSame('my_exchange', $result['exchange']);
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

        $this->assertSame('fanout', $result['exchange_type']);
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

        $this->assertSame(5.5, $result['read_timeout']);
        $this->assertSame(3.0, $result['write_timeout']);
        $this->assertSame(2.0, $result['connect_timeout']);
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
        $this->assertSame('direct', \CrazyGoat\TheConsoomer\Enum\ExchangeType::DIRECT->value);
        $this->assertSame('fanout', \CrazyGoat\TheConsoomer\Enum\ExchangeType::FANOUT->value);
        $this->assertSame('topic', \CrazyGoat\TheConsoomer\Enum\ExchangeType::TOPIC->value);
        $this->assertSame('headers', \CrazyGoat\TheConsoomer\Enum\ExchangeType::HEADERS->value);
    }

    public function testParsesAmqpsConsoomerScheme(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqps-consoomer://guest:guest@localhost/%2f/my_exchange');

        $this->assertSame('localhost', $result['host']);
        $this->assertSame(5671, $result['port']);
        $this->assertTrue($result['ssl']);
        $this->assertSame('/', $result['vhost']);
        $this->assertSame('my_exchange', $result['exchange']);
    }

    public function testAmqpsConsoomerSchemeWithCustomPort(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqps-consoomer://guest:guest@localhost:5673/%2f/my_exchange');

        $this->assertSame(5673, $result['port']);
        $this->assertTrue($result['ssl']);
    }

    public function testParsesLegacyAmqpsScheme(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqps://guest:guest@localhost/%2f/my_exchange');

        $this->assertSame('localhost', $result['host']);
        $this->assertSame(5671, $result['port']);
        $this->assertSame('guest', $result['user']);
        $this->assertSame('guest', $result['password']);
        $this->assertTrue($result['ssl']);
        $this->assertSame('/', $result['vhost']);
        $this->assertSame('my_exchange', $result['exchange']);
    }

    public function testParsesUrlEncodedUserAndPassword(): void
    {
        $parser = new DsnParser();
        // User: user@domain.com (URL-encoded as user%40domain.com)
        // Password: pass#word (URL-encoded as pass%23word)
        $result = $parser->parse('amqp-consoomer://user%40domain.com:pass%23word@localhost:5672/%2f/my_exchange');

        $this->assertSame('user@domain.com', $result['user']);
        $this->assertSame('pass#word', $result['password']);
    }

    public function testParsesUrlEncodedSpecialCharactersInCredentials(): void
    {
        $parser = new DsnParser();
        // Test various URL-encoded special characters
        // %2B = +, %2F = /, %3A = :, %3D = =, %26 = &
        $result = $parser->parse('amqp-consoomer://user%2Bname:pass%2Fword%3Atest@localhost/%2f/my_exchange');

        $this->assertSame('user+name', $result['user']);
        $this->assertSame('pass/word:test', $result['password']);
    }

    public function testParsesNonEncodedCredentials(): void
    {
        $parser = new DsnParser();
        // Verify that plain credentials (without URL encoding) still work correctly
        $result = $parser->parse('amqp-consoomer://simpleuser:simplepass@localhost/%2f/my_exchange');

        $this->assertSame('simpleuser', $result['user']);
        $this->assertSame('simplepass', $result['password']);
    }

    public function testParsesDefaultCredentials(): void
    {
        $parser = new DsnParser();
        // Verify that default 'guest' credentials work after urldecode
        $result = $parser->parse('amqp-consoomer://localhost/%2f/my_exchange');

        $this->assertSame('guest', $result['user']);
        $this->assertSame('guest', $result['password']);
    }

    public function testParsesUrlEncodedPercentSign(): void
    {
        $parser = new DsnParser();
        // Test percent-encoded percent sign (%25)
        // Password: pass%word (URL-encoded as pass%25word)
        $result = $parser->parse('amqp-consoomer://user:pass%25word@localhost/%2f/my_exchange');

        $this->assertSame('user', $result['user']);
        $this->assertSame('pass%word', $result['password']);
    }

    public function testParsesUrlEncodedSpaces(): void
    {
        $parser = new DsnParser();
        // Test spaces encoded as %20 and +
        // Note: urldecode() treats + as space, which is correct for URL-encoded form data
        $result = $parser->parse('amqp-consoomer://user%20name:pass+word@localhost/%2f/my_exchange');

        $this->assertSame('user name', $result['user']);
        $this->assertSame('pass word', $result['password']);
    }
}
