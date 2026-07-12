<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pbs;

use App\Application\Inventory\Pve\InventoryScopeStatus;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use InvalidArgumentException;

final readonly class PbsInventoryScopeResult
{
    public function __construct(
        public PbsInventoryScope $scope,
        public string $key,
        public InventoryScopeStatus $status,
    ) {
        if ((PbsInventoryScope::DatastoreStatus === $scope) === ('@installation' === $key)) {
            throw new InvalidArgumentException('The PBS inventory scope key is invalid.');
        }
        if (PbsInventoryScope::DatastoreStatus === $scope) {
            new PbsDatastoreId($key);
        }
    }

    public function isComplete(): bool
    {
        return InventoryScopeStatus::Complete === $this->status;
    }
}
