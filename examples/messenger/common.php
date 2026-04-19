<?php

declare(strict_types=1);

namespace App\Message;

readonly class RawMessage
{
    public function __construct(public string $content)
    {
    }
}
