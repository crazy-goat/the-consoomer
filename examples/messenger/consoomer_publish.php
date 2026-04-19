<?php

declare(strict_types=1);

use App\Message\RawMessage;
use CrazyGoat\TheConsoomer\AmqpStamp;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/common.php';

$dsn = 'amqp-consoomer://guest:guest@localhost:5672/%2f/messages?queue=test';
$transport = AmqpTransportFactory::create($dsn, [], new PhpSerializer());

$container = new ServiceLocator(['consoomer' => fn() => $transport]);
$senders = new SendersLocator([RawMessage::class => ['consoomer']], $container);

$bus = new MessageBus([
    new SendMessageMiddleware($senders),
]);

foreach (range(1, intval($argv[1] ?? 1)) as $i) {
    $bus->dispatch(new Envelope(new RawMessage('hello'), [new AmqpStamp('test')]));
}
$bus->dispatch(new Envelope(new RawMessage('exit'), [new AmqpStamp('test')]));
