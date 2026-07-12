<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

/**
 * One endpoint-scoped PVE read. Core and storage data must never be combined
 * from different connections, endpoints, sessions, or failover attempts.
 */
final readonly class PveInventorySnapshot
{
    /** @var list<string> */
    public array $topologyNodeNames;

    /** @param list<string> $topologyNodeNames Sorted, unique topology node names. */
    public function __construct(
        public PveInstallationSnapshot $core,
        public PveStorageInventorySnapshot $storage,
        array $topologyNodeNames,
    ) {
        $normalized = array_values(array_unique($topologyNodeNames));
        usort($normalized, static fn (string $left, string $right): int => strcmp($left, $right));
        if ($normalized !== $topologyNodeNames) {
            throw new \InvalidArgumentException('PVE topology node names must be unique and binary sorted.');
        }

        foreach ($topologyNodeNames as $nodeName) {
            if ('' === $nodeName) {
                throw new \InvalidArgumentException('PVE topology node names must not be empty.');
            }
        }

        $expected = [];
        foreach ($core->topology->nodes as $node) {
            if ('' !== $node->name) {
                $expected[$node->name] = true;
            }
        }
        ksort($expected, SORT_STRING);
        if (array_keys($expected) !== $topologyNodeNames) {
            throw new \InvalidArgumentException('PVE topology node names must match the core topology.');
        }

        $this->topologyNodeNames = $topologyNodeNames;
    }

    public function isComplete(): bool
    {
        return $this->core->isComplete() && $this->storage->isAuthoritative();
    }

    public function isAuthoritative(): bool
    {
        return $this->isComplete();
    }
}
