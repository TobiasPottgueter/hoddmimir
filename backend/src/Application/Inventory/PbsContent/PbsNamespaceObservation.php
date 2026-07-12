<?php

declare(strict_types=1);

namespace App\Application\Inventory\PbsContent;

use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsNamespace;

final readonly class PbsNamespaceObservation
{
    public function __construct(
        public PbsDatastoreId $datastore,
        public PbsNamespace $namespace,
    ) {}

    public function key(): string
    {
        return $this->datastore->value."\0".$this->namespace->value;
    }
}
