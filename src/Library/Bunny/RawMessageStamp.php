<?php

namespace CrazyGoat\TheConsoomer\Library\Bunny;

use Bunny\Message;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

class RawMessageStamp implements NonSendableStampInterface
{
    public function __construct(public readonly Message $amqpMessage)
    {
    }
}