<?php

namespace CrazyGoat\TheConsoomer;

use Bunny\Message;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

class RawMessageStamp implements NonSendableStampInterface
{
    public function __construct(public readonly Message $amqpMessage)
    {
    }
}
