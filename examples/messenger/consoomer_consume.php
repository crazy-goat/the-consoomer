<?php

declare(strict_types=1);

use App\Message\RawMessage;
use CrazyGoat\TheConsoomer\AmqpTransportFactory;
use Symfony\Component\Messenger\Exception\StopWorkerException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Stopwatch\Stopwatch;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/common.php';

class RawMessageHandler
{
    public static int $counter = 0;

    public function __invoke(RawMessage $message): void
    {
        self::$counter++;

        if ($message->content === 'exit') {
            throw new StopWorkerException();
        }
    }
}

$dsn = 'amqp-consoomer://guest:guest@localhost:5672/%2f/messages?queue=test';
$transport = AmqpTransportFactory::create($dsn, [], new PhpSerializer());

$bus = new MessageBus([
    new HandleMessageMiddleware(
        new HandlersLocator([RawMessage::class => [new RawMessageHandler()]]),
    )
]);

$stopwatch = new Stopwatch();

$worker = new \Symfony\Component\Messenger\Worker(
    [$transport],
    $bus,
);

$stopwatch->start('consumer');
try {
    $worker->run(['sleep' => 0]);
} catch (\Throwable) {
    echo "Job finished." . PHP_EOL;
}

$time = $stopwatch->stop('consumer')->getDuration();
printf("Messages processed: %d, time: %.3fs, rate: %d msg/s", RawMessageHandler::$counter, $time / 1000.0, floor((RawMessageHandler::$counter / $time) * 1000));
