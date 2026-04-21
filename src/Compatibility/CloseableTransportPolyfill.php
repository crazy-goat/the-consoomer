<?php

declare(strict_types=1);

if (!interface_exists(\Symfony\Component\Messenger\Transport\CloseableTransportInterface::class)) {
    eval('
        namespace Symfony\Component\Messenger\Transport;

        interface CloseableTransportInterface
        {
            public function close(): void;
        }
    ');
}
