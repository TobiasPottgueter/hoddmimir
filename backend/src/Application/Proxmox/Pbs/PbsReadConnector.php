<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

interface PbsReadConnector
{
    /** Connect performs exactly the `/version` probe followed by `/ping`. */
    public function connect(): PbsReadClient;
}
