<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
