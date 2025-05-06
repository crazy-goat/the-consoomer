<?php

namespace App\Handler;

use App\VO\RawMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RawMessageHandler
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(RawMessage $message)
    {
        $this->logger->debug($message->content);
    }
}