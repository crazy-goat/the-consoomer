<?php

use CrazyGoat\TheConsoomer\AmqpStamp;
use CrazyGoat\TheConsoomer\AmqpTransportFactory;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/common.php';

$dsn = 'amqp-consoomer://guest:guest@localhost:5672/%2f';
$transport = new AmqpTransportFactory(new NullLogger());

$container = new Container();
$container->set('consoomer', $transport->createTransport($dsn, [], new PhpSerializer()));

$senders = new SendersLocator([MyMessage::class => ['consoomer']], $container);

$bus = new MessageBus([
    new SendMessageMiddleware($senders),
]);

foreach (range(1, intval($argv[1] ?? 1)) as $i) {
    $bus->dispatch(new Envelope(new MyMessage('hello'), [new AmqpStamp('test')]));
}
$bus->dispatch(new Envelope(new MyMessage('exit'), [new AmqpStamp('test')]));