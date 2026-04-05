<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

class RawMessageStamp implements NonSendableStampInterface
{
    public function __construct(public readonly \AMQPEnvelope $amqpMessage)
    {
    }
}
