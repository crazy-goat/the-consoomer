<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

enum CircuitState: string
{
    case CLOSED = 'closed';
    case OPEN = 'open';
    case HALF_OPEN = 'half_open';
}
