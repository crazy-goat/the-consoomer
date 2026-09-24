<?php

declare(strict_types=1);

/*
 * Compatibility stub for Symfony Messenger's CloseableTransportInterface (#250).
 *
 * This interface lives in Symfony's namespace because PHP cannot alias an
 * interface into another namespace. It is loaded ONLY by
 * CrazyGoat\TheConsoomer\Compatibility\CloseableTransportPolyfill, which runs
 * before any transport class is autoloaded and refuses to declare this stub
 * when symfony/messenger already provides the real interface (>= 7.3).
 *
 * The directory is excluded from Composer's classmap
 * (`autoload.exclude-from-classmap`) so an optimized autoloader never indexes
 * this file under the Symfony FQCN and cannot collide with the real interface.
 *
 * Do not autoload this file directly and do not add it to a classmap.
 */

namespace Symfony\Component\Messenger\Transport;

interface CloseableTransportInterface
{
    public function close(): void;
}
