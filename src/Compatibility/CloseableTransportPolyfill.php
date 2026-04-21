<?php

declare(strict_types=1);

if (!interface_exists(\Symfony\Component\Messenger\Transport\CloseableTransportInterface::class)) {
    require_once __DIR__ . '/stub/CloseableTransportInterface.php';
}
