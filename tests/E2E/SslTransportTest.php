<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\E2E;

use CrazyGoat\TheConsoomer\AmqpTransport;
use CrazyGoat\TheConsoomer\AmqpTransportFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

class SslTransportTest extends SslTestCase
{
    private const EXCHANGE_NAME = 'test_ssl_exchange';
    private const QUEUE_NAME = 'test_ssl_queue';

    protected function setUp(): void
    {
        parent::setUp();
        $this->declareExchange(self::EXCHANGE_NAME, 'direct');
        $this->declareQueue(self::QUEUE_NAME);
        $this->bindQueue(self::QUEUE_NAME, self::EXCHANGE_NAME);
    }

    protected function tearDown(): void
    {
        $this->purgeQueue(self::QUEUE_NAME);
        $this->deleteQueue(self::QUEUE_NAME);
        $this->deleteExchange(self::EXCHANGE_NAME);
        parent::tearDown();
    }

    public function testAmqpsSchemeCreatesTransport(): void
    {
        $dsn = $this->buildAmqpsDsn(self::EXCHANGE_NAME);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create(
            $dsn,
            ['queue' => self::QUEUE_NAME],
            $serializer,
        );

        $this->assertInstanceOf(AmqpTransport::class, $transport);
    }

    public function testAmqpConsoomerWithSslOption(): void
    {
        $dsn = $this->buildSslDsnWithOptions(self::EXCHANGE_NAME, self::QUEUE_NAME, [
            'ssl' => 'true',
        ]);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create(
            $dsn,
            ['queue' => self::QUEUE_NAME],
            $serializer,
        );

        $this->assertInstanceOf(AmqpTransport::class, $transport);
    }

    public function testPublishConsumeWithAmqpsScheme(): void
    {
        $dsn = $this->buildSslDsn(self::EXCHANGE_NAME, self::QUEUE_NAME);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'SSL test message ' . uniqid();
        $testMessage->timestamp = time();

        $envelope = new Envelope($testMessage);
        $transport->send($envelope);

        $received = null;
        foreach ($transport->get() as $receivedEnvelope) {
            $received = $receivedEnvelope;
            break;
        }

        $this->assertNotNull($received);
        $transport->ack($received);
    }

    public function testSslWithCertificateFiles(): void
    {
        $dsn = $this->buildSslDsnWithOptions(self::EXCHANGE_NAME, self::QUEUE_NAME, [
            'ssl' => 'true',
            'ssl_verify' => 'false',
        ]);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create(
            $dsn,
            ['queue' => self::QUEUE_NAME],
            $serializer,
        );

        $this->assertInstanceOf(AmqpTransport::class, $transport);
    }
}
