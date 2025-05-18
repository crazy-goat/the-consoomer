<?php

namespace CrazyGoat\TheConsoomer\Library\PhpAmqpLib;

use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

class RawMessageStamp implements NonSendableStampInterface
{
    public function __construct(public readonly AMQPMessage $amqpMessage)
    {
    }
}
