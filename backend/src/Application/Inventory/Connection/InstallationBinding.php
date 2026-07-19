<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

use App\Application\Proxmox\Pbs\PbsInstallationSnapshot;
use App\Application\Proxmox\Pbs\PbsNodeRoute;
use App\Application\Proxmox\Pve\PveClusterMode;
use App\Application\Proxmox\Pve\PveClusterNode;
use App\Application\Proxmox\Pve\PveInstallationSnapshot;
use App\Application\Proxmox\Pve\PveInventorySnapshot;
use InvalidArgumentException;

final readonly class InstallationBinding
{
    /** @var list<string> */
    public array $knownMemberNodes;

    /** @param list<string> $knownMemberNodes */
    private function __construct(
        public ProxmoxProduct $product,
        public InstallationBindingKind $kind,
        public string $identity,
        array $knownMemberNodes = [],
        public ?EndpointId $legacyEndpointId = null,
    ) {
        if (!InstallationIdentityValidator::isValid($identity)) {
            throw new InvalidArgumentException('The installation binding identity is invalid.');
        }
        if (InstallationBindingKind::PveCluster === $kind) {
            if ([] === $knownMemberNodes) {
                throw new InvalidArgumentException('A clustered PVE binding requires known member nodes.');
            }
            $nodes = [];
            foreach ($knownMemberNodes as $node) {
                if (!InstallationIdentityValidator::isValid($node)) {
                    throw new InvalidArgumentException('A clustered PVE member node is invalid.');
                }
                $nodes[$node] = true;
            }
            ksort($nodes, SORT_STRING);
            $this->knownMemberNodes = array_keys($nodes);

            return;
        }
        $this->knownMemberNodes = [];
        if (InstallationBindingKind::PbsLegacyNode === $kind && null === $legacyEndpointId) {
            throw new InvalidArgumentException('A legacy PBS binding requires its exact endpoint.');
        }
        if (InstallationBindingKind::PbsLegacyNode === $kind
            && !hash_equals(PbsNodeRoute::Local->value, $identity)) {
            throw new InvalidArgumentException('A legacy PBS binding requires the canonical local identity.');
        }
        if (InstallationBindingKind::PbsLegacyNode !== $kind && null !== $legacyEndpointId) {
            throw new InvalidArgumentException('Only a legacy PBS binding may carry an endpoint.');
        }
    }

    /** @param non-empty-list<string> $knownMemberNodes */
    public static function pveCluster(string $clusterName, array $knownMemberNodes): self
    {
        return new self(ProxmoxProduct::Pve, InstallationBindingKind::PveCluster, $clusterName, $knownMemberNodes);
    }

    public static function pveStandalone(string $node): self
    {
        return new self(ProxmoxProduct::Pve, InstallationBindingKind::PveStandalone, $node);
    }

    public static function pbsInstance(string $instanceIdentity): self
    {
        if (32 !== strlen($instanceIdentity) || 32 !== strspn($instanceIdentity, '0123456789abcdef')) {
            throw new InvalidArgumentException('The PBS 4 instance identity is invalid.');
        }

        return new self(ProxmoxProduct::Pbs, InstallationBindingKind::PbsInstance, $instanceIdentity);
    }

    public static function pbsLegacyEndpoint(EndpointId $endpointId): self
    {
        return new self(
            ProxmoxProduct::Pbs,
            InstallationBindingKind::PbsLegacyNode,
            PbsNodeRoute::Local->value,
            legacyEndpointId: $endpointId,
        );
    }

    public static function fromSnapshot(
        PveInstallationSnapshot|PveInventorySnapshot|PbsInstallationSnapshot $snapshot,
        ?EndpointId $endpointId = null,
    ): ?self
    {
        if ($snapshot instanceof PveInventorySnapshot) {
            $snapshot = $snapshot->core;
        }

        if ($snapshot instanceof PveInstallationSnapshot) {
            if (PveClusterMode::Clustered === $snapshot->topology->mode) {
                $name = $snapshot->topology->clusterName;
                $nodes = array_map(
                    static fn (PveClusterNode $node): string => $node->name,
                    $snapshot->topology->nodes,
                );
                foreach ($nodes as $node) {
                    if (!InstallationIdentityValidator::isValid($node)) {
                        return null;
                    }
                }

                return null === $name || !InstallationIdentityValidator::isValid($name) || [] === $nodes
                    ? null
                    : self::pveCluster($name, $nodes);
            }

            if (1 !== count($snapshot->topology->nodes)) {
                return null;
            }
            $node = $snapshot->topology->nodes[0];

            return true === $node->local && 0 === $node->localNodeId && InstallationIdentityValidator::isValid($node->name)
                ? self::pveStandalone($node->name)
                : null;
        }

        if (3 !== $snapshot->version->major && 4 !== $snapshot->version->major) {
            return null;
        }
        if (!$snapshot->version->supportsInstanceIdentity()) {
            return null !== $endpointId
                ? self::pbsLegacyEndpoint($endpointId)
                : null;
        }
        if (null !== $snapshot->instanceIdentity) {
            return self::pbsInstance($snapshot->instanceIdentity->value);
        }

        return null;
    }

    public function equals(self $other): bool
    {
        return $this->product === $other->product
            && $this->kind === $other->kind
            && hash_equals($this->identity, $other->identity)
            && $this->knownMemberNodes === $other->knownMemberNodes
            && $this->legacyEndpointId?->bytes === $other->legacyEndpointId?->bytes;
    }

    public function matchesObservation(self $observed): bool
    {
        if (
            $this->product !== $observed->product
            || $this->kind !== $observed->kind
            || !hash_equals($this->identity, $observed->identity)
        ) {
            return false;
        }

        if (InstallationBindingKind::PbsLegacyNode === $this->kind) {
            return $this->legacyEndpointId?->bytes === $observed->legacyEndpointId?->bytes;
        }

        return InstallationBindingKind::PveCluster !== $this->kind
            || [] !== array_intersect($this->knownMemberNodes, $observed->knownMemberNodes);
    }

}
