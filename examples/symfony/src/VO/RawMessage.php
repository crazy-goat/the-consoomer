<?php

declare(strict_types=1);

namespace App\VO;

readonly class RawMessage
{
    public function __construct(public string $content)
    {
    }
}
