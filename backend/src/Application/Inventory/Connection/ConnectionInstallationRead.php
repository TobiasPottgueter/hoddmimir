<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

use App\Application\Proxmox\Pbs\PbsInstallationSnapshot;
use App\Application\Proxmox\Pve\PveInstallationSnapshot;

final readonly class ConnectionInstallationRead
{
    public function __construct(
        public ConnectionId $connectionId,
        public int $expectedRevision,
        public EndpointId $endpointId,
        public InstallationBinding $binding,
        public PveInstallationSnapshot|PbsInstallationSnapshot $snapshot,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->snapshot->isComplete();
    }
}
