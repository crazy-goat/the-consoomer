<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Enum;

/**
 * AMQP exchange type enumeration.
 *
 * DIRECT: Routes messages based on routing key
 * FANOUT: Broadcasts messages to all bound queues
 * TOPIC: Routes based on pattern matching of routing key
 * HEADERS: Routes based on message headers
 */
enum ExchangeType: string
{
    case DIRECT = 'direct';
    case FANOUT = 'fanout';
    case TOPIC = 'topic';
    case HEADERS = 'headers';
}
