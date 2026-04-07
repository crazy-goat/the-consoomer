<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

final readonly class AmqpStamp implements NonSendableStampInterface
{
    public function __construct(
        public string $routingKey = '',
    ) {
    }
}
