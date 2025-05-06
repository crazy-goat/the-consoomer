<?php

namespace App\VO;

readonly class RawMessage
{
    public function __construct(public string $content)
    {
    }
}