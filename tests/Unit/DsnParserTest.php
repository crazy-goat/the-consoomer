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

    public function testParsesDefaultPublishRoutingKey(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange?default_publish_routing_key=my.default.key');

        $this->assertSame('my.default.key', $result['default_publish_routing_key']);
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

    public function testThrowsExceptionForMalformedDsn(): void
    {
        $parser = new DsnParser();
        $this->expectException(\InvalidArgumentException::class);
        $parser->parse(':');
    }

    public function testMalformedDsnExceptionDoesNotLeakPassword(): void
    {
        $parser = new DsnParser();
        $password = 'S3cretPass';

        try {
            // Invalid port (-1) makes parse_url() return false, triggering the
            // malformed-DSN path while the userinfo still carries the cleartext password.
            $parser->parse('amqp-consoomer://user:' . $password . '@host:-1/vh/ex');
            $this->fail('Expected InvalidArgumentException for malformed DSN');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString(
                $password,
                $e->getMessage(),
                'Password must be redacted from the malformed-DSN exception message (#287)',
            );
            $this->assertStringContainsString('***', $e->getMessage());
            $this->assertStringContainsString('host:-1', $e->getMessage());
        }
    }

    public function testMalformedDsnRedactsBothUserAndPassword(): void
    {
        $parser = new DsnParser();
        $user = 'lea.ked.user';
        $password = 'lea.ked.pass';

        try {
            $parser->parse('amqp-consoomer://' . $user . ':' . $password . '@host:-1/vh/ex');
            $this->fail('Expected InvalidArgumentException for malformed DSN');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString($user, $e->getMessage());
            $this->assertStringNotContainsString($password, $e->getMessage());
            $this->assertStringContainsString('://***@', $e->getMessage());
        }
    }

    public function testMalformedDsnWithoutCredentialsStillReportsError(): void
    {
        $parser = new DsnParser();

        try {
            $parser->parse('amqp-consoomer://host:-1/vh/ex');
            $this->fail('Expected InvalidArgumentException for malformed DSN');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Malformed DSN:', $e->getMessage());
            $this->assertStringContainsString('host:-1', $e->getMessage());
        }
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

        $caught = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$caught): bool {
            if ($errno === \E_USER_DEPRECATED) {
                $caught = $errstr;
                return true;
            }
            return false;
        });

        try {
            $result = $parser->parse('amqps://guest:guest@localhost/%2f/my_exchange');
        } finally {
            restore_error_handler();
        }

        $this->assertSame('The amqps:// scheme is deprecated and will be removed in 1.0. Use amqps-consoomer:// instead.', $caught);

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

    public function testParsesUrlEncodedSpaceAndLiteralPlus(): void
    {
        $parser = new DsnParser();
        // %20 decodes to space; + is literal in URI userinfo (not a space)
        // rawurldecode correctly preserves +, unlike urldecode which converts it to space
        $result = $parser->parse('amqp-consoomer://user%20name:pass+word@localhost/%2f/my_exchange');

        $this->assertSame('user name', $result['user']);
        $this->assertSame('pass+word', $result['password']);
    }

    public function testNormalizeValueHandlesScientificNotationUpperCase(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqp-consoomer://guest:guest@localhost/%2f/my_exchange?timeout=1E5');

        $this->assertSame(100000.0, $result['timeout']);
    }

    public function testNormalizeValueHandlesScientificNotationLowerCase(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqp-consoomer://guest:guest@localhost/%2f/my_exchange?timeout=1e5');

        $this->assertSame(100000.0, $result['timeout']);
    }

    public function testNormalizeValueHandlesScientificNotationWithDecimal(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqp-consoomer://guest:guest@localhost/%2f/my_exchange?timeout=2.5e3');

        $this->assertSame(2500.0, $result['timeout']);
    }

    public function testNormalizeValueHandlesNegativeScientificNotation(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqp-consoomer://guest:guest@localhost/%2f/my_exchange?timeout=1e-2');

        $this->assertSame(0.01, $result['timeout']);
    }

    public function testNormalizeValueDoesNotAffectPlainIntegers(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqp-consoomer://guest:guest@localhost/%2f/my_exchange?heartbeat=60');

        $this->assertSame(60, $result['heartbeat']);
        $this->assertIsInt($result['heartbeat']);
    }

    public function testNormalizeValueDoesNotAffectPlainFloats(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqp-consoomer://guest:guest@localhost/%2f/my_exchange?timeout=5.5');

        $this->assertSame(5.5, $result['timeout']);
        $this->assertIsFloat($result['timeout']);
    }

    public function testNormalizeValueDoesNotAffectNonNumericStrings(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqp-consoomer://guest:guest@localhost/%2f/my_exchange?queue=my_queue');

        $this->assertSame('my_queue', $result['queue']);
        $this->assertIsString($result['queue']);
    }

    public function testDoubleSlashMeansDefaultVhostWithExchange(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqp-consoomer://guest:guest@localhost:5672//my_exchange');

        $this->assertSame('localhost', $result['host']);
        $this->assertSame(5672, $result['port']);
        $this->assertSame('/', $result['vhost']);
        $this->assertSame('my_exchange', $result['exchange']);
    }

    public function testDoubleSlashWithAmqpsScheme(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqps-consoomer://guest:guest@localhost//my_exchange');

        $this->assertSame('localhost', $result['host']);
        $this->assertSame(5671, $result['port']);
        $this->assertTrue($result['ssl']);
        $this->assertSame('/', $result['vhost']);
        $this->assertSame('my_exchange', $result['exchange']);
    }

    public function testHostLessDsnDefaultsToLocalhostAndDefaultVhost(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqp-consoomer:///my_exchange');

        $this->assertSame('localhost', $result['host']);
        $this->assertSame('/', $result['vhost']);
        $this->assertSame('my_exchange', $result['exchange']);
    }

    public function testHostLessDsnWithQueryParams(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqp-consoomer:///my_exchange?heartbeat=30&queue=my_queue');

        $this->assertSame('localhost', $result['host']);
        $this->assertSame('/', $result['vhost']);
        $this->assertSame('my_exchange', $result['exchange']);
        $this->assertSame(30, $result['heartbeat']);
        $this->assertSame('my_queue', $result['queue']);
    }

    public function testAmqpsConsoomerSchemeRejectsSslFalseInQuery(): void
    {
        $parser = new DsnParser();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Refusing to disable TLS via "?ssl=false"');
        $parser->parse('amqps-consoomer://guest:guest@localhost/%2f/my_exchange?ssl=false');
    }

    public function testAmqpsConsoomerSchemeRejectsSslZeroInQuery(): void
    {
        $parser = new DsnParser();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Refusing to disable TLS via "?ssl=false"');
        $parser->parse('amqps-consoomer://guest:guest@localhost/%2f/my_exchange?ssl=0');
    }

    public function testAmqpsConsoomerSchemeIgnoresRedundantSslTrueInQuery(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqps-consoomer://guest:guest@localhost/%2f/my_exchange?ssl=true');

        $this->assertTrue($result['ssl']);
        $this->assertSame(5671, $result['port']);
    }

    public function testAmqpsConsoomerSchemeKeepsSslTrueWhenSslQueryAbsent(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqps-consoomer://guest:guest@localhost/%2f/my_exchange?ssl_verify=false');

        $this->assertTrue($result['ssl']);
        $this->assertFalse($result['ssl_verify']);
    }

    public function testLegacyAmqpsSchemeRejectsSslFalseInQuery(): void
    {
        $parser = new DsnParser();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Refusing to disable TLS via "?ssl=false"');

        // Suppress the E_USER_DEPRECATED emitted for the legacy amqps:// scheme —
        // this test targets the security guard, not the deprecation notice (#342).
        set_error_handler(static fn(int $errno): bool => $errno === \E_USER_DEPRECATED);
        try {
            $parser->parse('amqps://guest:guest@localhost/%2f/my_exchange?ssl=false');
        } finally {
            restore_error_handler();
        }
    }

    public function testPlaintextSchemeAllowsSslTrueAsOptInUpgrade(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqp-consoomer://guest:guest@localhost/%2f/my_exchange?ssl=true');

        $this->assertTrue($result['ssl']);
    }

    public function testPlaintextSchemeAllowsSslFalse(): void
    {
        $parser = new DsnParser();
        $result = $parser->parse('amqp-consoomer://guest:guest@localhost/%2f/my_exchange?ssl=false');

        // On a plaintext scheme ?ssl=false is a redundant no-op (configureSsl early-returns
        // on empty($options['ssl'])), but it must not be refused — only the TLS scheme is locked.
        $this->assertFalse($result['ssl']);
    }

    public function testValidateOptionsReturnsTrueForValidOptions(): void
    {
        $parser = new DsnParser();
        $this->assertTrue($this->validateOptionsSuppressed($parser, [
            'host' => 'localhost',
            'port' => 5672,
            'user' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
            'exchange' => 'my_exchange',
        ]));
    }

    public function testValidateOptionsReturnsFalseForInvalidOptions(): void
    {
        $parser = new DsnParser();
        $this->assertFalse($this->validateOptionsSuppressed($parser, [
            'host' => 'localhost',
            'port' => 5672,
            'user' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
            'exchange' => '',
        ]));
    }

    public function testValidateOptionsReturnsFalseForInvalidExchangeType(): void
    {
        $parser = new DsnParser();
        $this->assertFalse($this->validateOptionsSuppressed($parser, [
            'host' => 'localhost',
            'port' => 5672,
            'user' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
            'exchange' => 'my_exchange',
            'exchange_type' => 'invalid',
        ]));
    }

    public function testValidateOptionsReturnsTrueForValidExchangeType(): void
    {
        $parser = new DsnParser();
        $this->assertTrue($this->validateOptionsSuppressed($parser, [
            'host' => 'localhost',
            'port' => 5672,
            'user' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
            'exchange' => 'my_exchange',
            'exchange_type' => 'fanout',
        ]));
    }

    /**
     * Calls validateOptions() while suppressing the E_USER_DEPRECATED
     * notice it emits (#342). Use this in tests that check the validation
     * result, not the deprecation itself.
     *
     * @param array<string, mixed> $options
     */
    private function validateOptionsSuppressed(DsnParser $parser, array $options): bool
    {
        set_error_handler(static fn(int $errno): bool => $errno === \E_USER_DEPRECATED);
        try {
            return $parser->validateOptions($options);
        } finally {
            restore_error_handler();
        }
    }

    public function testValidateOptionsEmitsDeprecation(): void
    {
        $parser = new DsnParser();

        $caught = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$caught): bool {
            if ($errno === \E_USER_DEPRECATED) {
                $caught = $errstr;
                return true;
            }
            return false;
        });

        try {
            $result = $parser->validateOptions([
                'host' => 'localhost',
                'port' => 5672,
                'user' => 'guest',
                'password' => 'guest',
                'vhost' => '/',
                'exchange' => 'my_exchange',
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame('DsnParser::validateOptions() is deprecated and will be removed in 1.0. Validation now happens automatically in parse().', $caught);
        $this->assertTrue($result);
    }

    public function testQueryHostDoesNotOverrideAuthorityHost(): void
    {
        $parser = new DsnParser();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved');
        $parser->parse('amqp-consoomer://guest:guest@realhost/%2f/my_exchange?host=evil');
    }

    public function testQueryPasswordDoesNotOverrideAuthorityPassword(): void
    {
        $parser = new DsnParser();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved');
        $parser->parse('amqp-consoomer://guest:guest@realhost/%2f/my_exchange?password=secret');
    }

    public function testQueryPortDoesNotOverrideAuthorityPort(): void
    {
        $parser = new DsnParser();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved');
        $parser->parse('amqp-consoomer://guest:guest@realhost/%2f/my_exchange?port=9999');
    }

    public function testQueryVhostDoesNotOverridePathVhost(): void
    {
        $parser = new DsnParser();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved');
        $parser->parse('amqp-consoomer://guest:guest@realhost/%2f/my_exchange?vhost=evil');
    }

    public function testQueryExchangeDoesNotOverridePathExchange(): void
    {
        $parser = new DsnParser();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved');
        $parser->parse('amqp-consoomer://guest:guest@realhost/%2f/my_exchange?exchange=evil');
    }

    public function testQueryUserDoesNotOverrideAuthorityUser(): void
    {
        $parser = new DsnParser();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved');
        $parser->parse('amqp-consoomer://guest:guest@realhost/%2f/my_exchange?user=evil');
    }

    public function testNonReservedQueryKeyKeepsAuthorityHost(): void
    {
        $parser = new DsnParser();
        // A non-reserved query key must not trip the guard; the authority host wins.
        $result = $parser->parse('amqp-consoomer://guest:guest@realhost/%2f/my_exchange?heartbeat=60');

        $this->assertSame('realhost', $result['host']);
        $this->assertSame(60, $result['heartbeat']);
    }

    public function testDsnRefusesAllowInsecureVerifyInQuery(): void
    {
        // #361: allow_insecure_verify is a programmatic opt-in for ssl_verify=false.
        // It must NOT be settable from the DSN query string — a DSN is config-file /
        // env-var content and must not self-authorize a TLS verification downgrade.
        $parser = new DsnParser();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('allow_insecure_verify');
        $parser->parse('amqps-consoomer://guest:guest@localhost/%2f/my_exchange?ssl_verify=false&allow_insecure_verify=true');
    }

    public function testDsnRefusesAllowInsecureVerifyEvenWithoutSslVerify(): void
    {
        // The guard fires on the key alone, regardless of other params.
        $parser = new DsnParser();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('allow_insecure_verify');
        $parser->parse('amqps-consoomer://guest:guest@localhost/%2f/my_exchange?allow_insecure_verify=true');
    }
}
