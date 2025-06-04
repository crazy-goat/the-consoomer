<?php

use CrazyGoat\TheConsoomer\AmqpTransportFactory;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Exception\StopWorkerException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Stopwatch\Stopwatch;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/common.php';

class MyMessageHandler
{
    public static $counter = 0;

    public function __invoke(MyMessage $message): void
    {
        self::$counter++;

        if ($message->content === 'exit') {
            throw new StopWorkerException();
        }
    }
}

$dsn = 'amqp-consoomer://guest:guest@localhost:5672/%2f/?queue=test';
$transport = new AmqpTransportFactory(new NullLogger())->createTransport($dsn, [], new PhpSerializer());

$bus = new MessageBus([
    new HandleMessageMiddleware(
        new HandlersLocator([MyMessage::class => [new MyMessageHandler()]]),
    )
]);

$stopwatch = new Stopwatch();

$worker = new \Symfony\Component\Messenger\Worker(
    [$transport],
    $bus,
);

$stopwatch->start('consomer');
try {
    $worker->run(['sleep' => 0]);
} catch (\Throwable $exception) {
    echo "Job finished." . PHP_EOL;
}

$time = $stopwatch->stop('consomer')->getDuration();
printf("Messages procesed: %d, time: %.3fs, rate: %d msg/s", MyMessageHandler::$counter, $time / 1000.0, floor((MyMessageHandler::$counter / $time) * 1000));
