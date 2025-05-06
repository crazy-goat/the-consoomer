<?php

use CrazyGoat\TheConsoomer\AmqpTransportFactory;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/common.php';

class MyMessageHandler
{
    public function __invoke(MyMessage $message)
    {
        printf("message: %s\n", $message->content);
    }
}

$dsn = 'amqp-consoomer://guest:guest@localhost:5672/%2f/?queue=test';
$transport = new AmqpTransportFactory(new NullLogger())->createTransport($dsn, [], new PhpSerializer());

$bus = new MessageBus([
    new HandleMessageMiddleware(
        new HandlersLocator([MyMessage::class => [new MyMessageHandler()]]),
    )
]);

$worker = new \Symfony\Component\Messenger\Worker(
    [$transport],
    $bus,
);

$worker->run(['sleep' => 0]);