<?php

namespace CrazyGoat\TheConsoomer;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

class AmqpStamp implements NonSendableStampInterface
{
    public function __construct(
        public readonly string $routingKey = '',
    ) {
    }
}
