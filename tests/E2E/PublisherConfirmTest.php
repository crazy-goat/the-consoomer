<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\E2E;

use CrazyGoat\TheConsoomer\AmqpTransportFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

class PublisherConfirmTest extends TestCase
{
    private const QUEUE_NAME = 'test_pub_confirm_queue';
    private const EXCHANGE_NAME = 'test_pub_confirm_exchange';

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareExchange(self::EXCHANGE_NAME);
        $this->declareQueue(self::QUEUE_NAME);
        $this->bindQueue(self::QUEUE_NAME, self::EXCHANGE_NAME);
    }

    protected function tearDown(): void
    {
        $this->deleteQueue(self::QUEUE_NAME);
        $this->deleteExchange(self::EXCHANGE_NAME);

        parent::tearDown();
    }

    public function testPublishWithConfirmsDeliversMessage(): void
    {
        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME, [
            'confirm_timeout' => 5,
            'timeout' => 0.1,
            'auto_setup' => false,
        ]);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'Confirmed message';
        $envelope = new Envelope($testMessage);

        $transport->send($envelope);

        $messages = $transport->get();
        $messages = iterator_to_array($messages);

        $this->assertCount(1, $messages);

        /** @var Envelope $receivedEnvelope */
        $receivedEnvelope = $messages[0];
        $this->assertSame('Confirmed message', $receivedEnvelope->getMessage()->content);
        $transport->ack($receivedEnvelope);
    }

    public function testPublishWithConfirmsAndRetryDeliversMessage(): void
    {
        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME, [
            'confirm_timeout' => 5,
            'retry' => true,
            'timeout' => 0.1,
            'auto_setup' => false,
        ]);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'Confirmed with retry';
        $envelope = new Envelope($testMessage);

        $transport->send($envelope);

        $messages = $transport->get();
        $messages = iterator_to_array($messages);

        $this->assertCount(1, $messages);

        /** @var Envelope $receivedEnvelope */
        $receivedEnvelope = $messages[0];
        $this->assertSame('Confirmed with retry', $receivedEnvelope->getMessage()->content);
        $transport->ack($receivedEnvelope);
    }

    public function testPublishWithoutConfirmsStillWorks(): void
    {
        $dsn = $this->buildDsn(self::EXCHANGE_NAME, self::QUEUE_NAME, [
            'timeout' => 0.1,
        ]);

        $serializer = new PhpSerializer();
        $transport = AmqpTransportFactory::create($dsn, [], $serializer);

        $testMessage = new \stdClass();
        $testMessage->content = 'No confirm message';
        $envelope = new Envelope($testMessage);

        $transport->send($envelope);

        $messages = $transport->get();
        $messages = iterator_to_array($messages);

        $this->assertCount(1, $messages);

        /** @var Envelope $receivedEnvelope */
        $receivedEnvelope = $messages[0];
        $this->assertSame('No confirm message', $receivedEnvelope->getMessage()->content);
        $transport->ack($receivedEnvelope);
    }
}
