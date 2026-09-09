<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class PveClusterTopology
{
    /**
     * The cluster name is descriptive connection-scoped metadata. It is never a
     * globally unique identity.
     *
     * @param list<PveClusterNode>    $nodes
     * @param list<PveInventoryIssue> $issues
     */
    public function __construct(
        public PveClusterMode $mode,
        public ?string $clusterName,
        public ?int $declaredNodeCount,
        public ?int $configurationVersion,
        public ?bool $quorate,
        public array $nodes,
        public array $issues,
    ) {
    }

    public function isComplete(): bool
    {
        if ([] === $this->nodes || [] !== $this->issues) {
            return false;
        }

        if (PveClusterMode::Standalone === $this->mode) {
            return 1 === count($this->nodes)
                && true === $this->nodes[0]->local
                && 0 === $this->nodes[0]->localNodeId;
        }

        return null !== $this->clusterName
            && '' !== $this->clusterName
            && count($this->nodes) === $this->declaredNodeCount
            && null !== $this->configurationVersion
            && $this->configurationVersion > 0
            && true === $this->quorate;
    }
}
