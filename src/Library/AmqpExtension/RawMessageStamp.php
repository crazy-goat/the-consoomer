<?php

namespace CrazyGoat\TheConsoomer\Library\AmqpExtension;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

class RawMessageStamp implements NonSendableStampInterface
{
    public function __construct(public readonly \AMQPEnvelope $amqpMessage)
    {
    }
}
