<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

/**
 * Composite GET-only read for the core and storage inventory of one selected
 * PVE endpoint. The connector is opened exactly once for the whole snapshot.
 */
final readonly class ReadPveInventory
{
    public function __construct(
        private PveReadConnector $connector,
        private ReadPveStorageInventory $storageReader,
    ) {
    }

    public function read(): PveInventorySnapshot
    {
        $client = $this->connector->connect();
        $core = ReadPveInstallation::readClient($client);
        $nodeNames = $this->topologyNodeNames($core->topology);
        $storage = $this->storageReader->read($client, $core->permissions, $core->topology);

        return new PveInventorySnapshot($core, $storage, $nodeNames);
    }

    /** @return list<string> */
    private function topologyNodeNames(PveClusterTopology $topology): array
    {
        $names = [];
        foreach ($topology->nodes as $node) {
            if ('' !== $node->name) {
                $names[$node->name] = true;
            }
        }

        $nodeNames = array_keys($names);
        usort($nodeNames, static fn (string $left, string $right): int => strcmp($left, $right));

        return $nodeNames;
    }
}
