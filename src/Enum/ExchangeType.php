<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Enum;

enum ExchangeType: string
{
    case DIRECT = 'direct';
    case FANOUT = 'fanout';
    case TOPIC = 'topic';
    case HEADERS = 'headers';
}
