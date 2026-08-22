<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use CrazyGoat\TheConsoomer\DsnParser;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for #291: every copy-pasteable DSN snippet in README.md
 * and examples/symfony/.env must parse without throwing.
 *
 * The DSNs are duplicated here verbatim from the docs so that any future edit
 * which drops the required exchange path segment is caught by CI.
 */
class ReadmeDsnRegressionTest extends TestCase
{
    private DsnParser $parser;

    protected function setUp(): void
    {
        $this->parser = new DsnParser();
    }

    /**
     * @dataProvider readmeDsnSnippets
     * @dataProvider symfonyEnvDsnSnippets
     */
    public function testReadmeAndEnvDsnSnippetsParseSuccessfully(string $dsn, string $expectedExchange): void
    {
        $result = $this->parser->parse($dsn);

        $this->assertSame('/', $result['vhost'], 'vhost should be default "/" for all documented DSNs');
        $this->assertSame($expectedExchange, $result['exchange'], 'documented DSN must include the exchange segment');
        $this->assertNotEmpty($result['exchange'], 'exchange segment must not be empty (the #291 regression)');
    }

    /**
     * Verbatim DSN strings extracted from README.md.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function readmeDsnSnippets(): array
    {
        return [
            // README.md "Messenger configuration" section (primary DSN)
            'README primary messenger config' => [
                'amqp-consoomer://guest:guest@localhost:5672/%2f/messages?queue=my_queue',
                'messages',
            ],
            // README.md "DSN format" example
            'README dsn format example' => [
                'amqp-consoomer://guest:guest@localhost:5672/%2f/my_exchange/?queue=test',
                'my_exchange',
            ],
            // README.md "Heartbeat" section
            'README heartbeat snippet' => [
                'amqp-consoomer://guest:guest@localhost:5672/%2f/messages?queue=my_queue&heartbeat=60',
                'messages',
            ],
            // README.md "Retry Configuration" section
            'README retry snippet' => [
                'amqp-consoomer://guest:guest@localhost:5672/%2f/messages?queue=my_queue&retry=1&retry_count=3&retry_delay=500000&retry_backoff=1&retry_jitter=1&retry_circuit_breaker=1',
                'messages',
            ],
            // README.md "Publish Reliability" section
            'README publish reliability snippet' => [
                'amqp-consoomer://guest:guest@localhost:5672/%2f/messages?queue=my_queue&confirm_timeout=5&retry=1',
                'messages',
            ],
        ];
    }

    /**
     * Verbatim DSN strings extracted from examples/symfony/.env.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function symfonyEnvDsnSnippets(): array
    {
        return [
            'examples/symfony/.env CONSOOMER_TRANSPORT_DSN' => [
                'amqp-consoomer://guest:guest@localhost:5672/%2f/messages?queue=test&routing_key=test&timeout=5.0',
                'messages',
            ],
            'examples/symfony/.env CONSOOMER_EXTRA_TRANSPORT_DSN' => [
                'amqp-consoomer://guest:guest@localhost:5672/%2f/messages?queue=test-stream&timeout=5.0',
                'messages',
            ],
        ];
    }

    /**
     * The heartbeat snippet must also carry the heartbeat option through.
     */
    public function testHeartbeatSnippetParsesHeartbeatOption(): void
    {
        $result = $this->parser->parse(
            'amqp-consoomer://guest:guest@localhost:5672/%2f/messages?queue=my_queue&heartbeat=60',
        );

        $this->assertSame(60, $result['heartbeat']);
    }

    /**
     * The retry snippet must also carry the retry options through.
     */
    public function testRetrySnippetParsesRetryOptions(): void
    {
        $result = $this->parser->parse(
            'amqp-consoomer://guest:guest@localhost:5672/%2f/messages?queue=my_queue&retry=1&retry_count=3&retry_delay=500000&retry_backoff=1&retry_jitter=1&retry_circuit_breaker=1',
        );

        // DSN uses retry=1 / retry_backoff=1 etc.; normalizeValue() converts
        // numeric strings to int, so these arrive as 1 rather than true.
        $this->assertSame(1, $result['retry']);
        $this->assertSame(3, $result['retry_count']);
        $this->assertSame(500000, $result['retry_delay']);
        $this->assertSame(1, $result['retry_backoff']);
        $this->assertSame(1, $result['retry_jitter']);
        $this->assertSame(1, $result['retry_circuit_breaker']);
    }

    /**
     * The publish-reliability snippet must carry confirm_timeout through.
     */
    public function testPublishReliabilitySnippetParsesConfirmTimeout(): void
    {
        $result = $this->parser->parse(
            'amqp-consoomer://guest:guest@localhost:5672/%2f/messages?queue=my_queue&confirm_timeout=5&retry=1',
        );

        $this->assertSame(5, $result['confirm_timeout']);
        $this->assertSame(1, $result['retry']);
    }
}
