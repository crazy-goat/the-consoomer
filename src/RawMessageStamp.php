<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

final readonly class RawMessageStamp implements NonSendableStampInterface
{
    public function __construct(public \AMQPEnvelope $amqpMessage)
    {
    }
}
