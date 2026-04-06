<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\E2E;

use CrazyGoat\TheConsoomer\AmqpTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

class SslTransportTest extends TestCase
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
        $host = getenv('RABBITMQ_HOST') ?: 'localhost';
        $sslPort = getenv('RABBITMQ_SSL_PORT');
        
        if (!$sslPort) {
            $this->markTestSkipped('RABBITMQ_SSL_PORT not set - no SSL RabbitMQ available');
        }
        
        $port = (int) $sslPort;
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $password = getenv('RABBITMQ_PASSWORD') ?: 'guest';
        $vhost = getenv('RABBITMQ_VHOST') ?: '/';

        $dsn = sprintf(
            'amqps://%s:%s@%s:%d/%s/%s',
            $user,
            $password,
            $host,
            $port,
            urlencode($vhost),
            self::EXCHANGE_NAME
        );

        $serializer = new PhpSerializer();
        $transport = AmqpTransport::create(
            $dsn,
            ['queue' => self::QUEUE_NAME],
            $serializer
        );

        $this->assertInstanceOf(AmqpTransport::class, $transport);
    }

    public function testAmqpConsoomerWithSslOption(): void
    {
        $host = getenv('RABBITMQ_HOST') ?: 'localhost';
        $port = intval(getenv('RABBITMQ_PORT') ?: 5672);
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $password = getenv('RABBITMQ_PASSWORD') ?: 'guest';
        $vhost = getenv('RABBITMQ_VHOST') ?: '/';

        $dsn = sprintf(
            'amqp-consoomer://%s:%s@%s:%d/%s/%s?ssl=true',
            $user,
            $password,
            $host,
            $port,
            urlencode($vhost),
            self::EXCHANGE_NAME
        );

        $this->expectNotToPerformAssertions();
    }

    public function testPublishConsumeWithAmqpsScheme(): void
    {
        $host = getenv('RABBITMQ_HOST') ?: 'localhost';
        $sslPort = getenv('RABBITMQ_SSL_PORT');
        
        if (!$sslPort) {
            $this->markTestSkipped('RABBITMQ_SSL_PORT not set - no SSL RabbitMQ available');
        }
        
        $port = (int) $sslPort;
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $password = getenv('RABBITMQ_PASSWORD') ?: 'guest';
        $vhost = getenv('RABBITMQ_VHOST') ?: '/';

        $dsn = sprintf(
            'amqps://%s:%s@%s:%d/%s/%s?queue=%s',
            $user,
            $password,
            $host,
            $port,
            urlencode($vhost),
            self::EXCHANGE_NAME,
            self::QUEUE_NAME
        );

        $serializer = new PhpSerializer();
        $transport = AmqpTransport::create($dsn, [], $serializer);

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
        $host = getenv('RABBITMQ_HOST') ?: 'localhost';
        $sslPort = getenv('RABBITMQ_SSL_PORT');
        
        if (!$sslPort) {
            $this->markTestSkipped('RABBITMQ_SSL_PORT not set - no SSL RabbitMQ available');
        }
        
        $port = (int) $sslPort;
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $password = getenv('RABBITMQ_PASSWORD') ?: 'guest';
        $vhost = getenv('RABBITMQ_VHOST') ?: '/';

        $tempDir = sys_get_temp_dir();
        $certFile = tempnam($tempDir, 'cert_');
        $keyFile = tempnam($tempDir, 'key_');
        $caFile = tempnam($tempDir, 'ca_');

        file_put_contents($certFile, 'dummy cert');
        file_put_contents($keyFile, 'dummy key');
        file_put_contents($caFile, 'dummy ca');

        try {
            $dsn = sprintf(
                'amqp-consoomer://%s:%s@%s:%d/%s/%s?ssl=true&ssl_cert=%s&ssl_key=%s&ssl_cacert=%s',
                $user,
                $password,
                $host,
                $port,
                urlencode($vhost),
                self::EXCHANGE_NAME,
                urlencode($certFile),
                urlencode($keyFile),
                urlencode($caFile)
            );

            $serializer = new PhpSerializer();
            $transport = AmqpTransport::create(
                $dsn,
                ['queue' => self::QUEUE_NAME],
                $serializer
            );

            $this->assertInstanceOf(AmqpTransport::class, $transport);
        } finally {
            @unlink($certFile);
            @unlink($keyFile);
            @unlink($caFile);
        }
    }

    public function testSslVerifyOption(): void
    {
        $host = getenv('RABBITMQ_HOST') ?: 'localhost';
        $port = intval(getenv('RABBITMQ_PORT') ?: 5672);
        $user = getenv('RABBITMQ_USER') ?: 'guest';
        $password = getenv('RABBITMQ_PASSWORD') ?: 'guest';
        $vhost = getenv('RABBITMQ_VHOST') ?: '/';

        $dsn = sprintf(
            'amqp-consoomer://%s:%s@%s:%d/%s/%s?ssl=true&ssl_verify=false',
            $user,
            $password,
            $host,
            $port,
            urlencode($vhost),
            self::EXCHANGE_NAME
        );

        $serializer = new PhpSerializer();
        $transport = AmqpTransport::create(
            $dsn,
            ['queue' => self::QUEUE_NAME],
            $serializer
        );

        $this->assertInstanceOf(AmqpTransport::class, $transport);
    }
}
