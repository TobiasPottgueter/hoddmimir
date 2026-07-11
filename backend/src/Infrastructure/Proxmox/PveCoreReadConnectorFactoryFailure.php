<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use RuntimeException;

final class PveCoreReadConnectorFactoryFailure extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The PVE TLS runtime could not be initialized.');
    }
}
