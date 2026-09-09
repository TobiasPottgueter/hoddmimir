<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class PveInstallationSnapshot
{
    public function __construct(
        public PveVersion $version,
        public PvePermissionAssessment $permissions,
        public PveClusterTopology $topology,
        public PveResourceInventory $resources,
    ) {
    }

    public function isComplete(): bool
    {
        if (
            !$this->permissions->isComplete()
            || !$this->topology->isComplete()
            || !$this->resources->isComplete()
        ) {
            return false;
        }

        $topologyNodes = array_map(static fn (PveClusterNode $node): string => $node->name, $this->topology->nodes);
        $resourceNodes = array_map(static fn (PveNodeResource $node): string => $node->name, $this->resources->nodes);
        sort($topologyNodes, SORT_STRING);
        sort($resourceNodes, SORT_STRING);
        if ($topologyNodes !== $resourceNodes) {
            return false;
        }

        $knownNodes = array_fill_keys($topologyNodes, true);
        foreach ($this->resources->guests as $guest) {
            if (!isset($knownNodes[$guest->node])) {
                return false;
            }
        }

        foreach ($this->resources->storages as $storage) {
            if (!isset($knownNodes[$storage->node])) {
                return false;
            }
        }

        return true;
    }
}
