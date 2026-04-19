<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

/**
 * Circuit breaker state enumeration.
 *
 * CLOSED: Normal operation, requests flow through
 * OPEN: Circuit tripped, requests blocked
 * HALF_OPEN: Testing recovery, limited requests allowed
 */
enum CircuitState: string
{
    case CLOSED = 'closed';
    case OPEN = 'open';
    case HALF_OPEN = 'half_open';
}
