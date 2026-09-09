<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class PveResourceInventory
{
    /**
     * @param list<PveNodeResource>    $nodes
     * @param list<PveGuestResource>   $guests
     * @param list<PveStorageResource> $storages
     * @param list<PveInventoryIssue>  $issues
     */
    public function __construct(
        public array $nodes,
        public array $guests,
        public array $storages,
        public array $issues,
    ) {
    }

    public function isComplete(): bool
    {
        return [] !== $this->nodes && [] === $this->issues;
    }
}
