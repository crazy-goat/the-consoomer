<?php

declare(strict_types=1);

namespace Symfony\Component\Messenger\Transport;

interface CloseableTransportInterface
{
    public function close(): void;
}
