<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

use App\Application\Proxmox\Pbs\PbsInstallationSnapshot;
use App\Application\Proxmox\Pve\PveClusterMode;
use App\Application\Proxmox\Pve\PveClusterNode;
use App\Application\Proxmox\Pve\PveInstallationSnapshot;
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

    public static function pbs4Instance(string $instanceIdentity): self
    {
        if (32 !== strlen($instanceIdentity) || 32 !== strspn($instanceIdentity, '0123456789abcdef')) {
            throw new InvalidArgumentException('The PBS 4 instance identity is invalid.');
        }

        return new self(ProxmoxProduct::Pbs, InstallationBindingKind::Pbs4Instance, $instanceIdentity);
    }

    public static function pbs3Node(string $node): self
    {
        return new self(ProxmoxProduct::Pbs, InstallationBindingKind::Pbs3Node, $node);
    }

    public static function fromSnapshot(PveInstallationSnapshot|PbsInstallationSnapshot $snapshot): ?self
    {
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

        if (3 === $snapshot->version->major) {
            return InstallationIdentityValidator::isValid($snapshot->node) ? self::pbs3Node($snapshot->node) : null;
        }
        if (4 === $snapshot->version->major && null !== $snapshot->instanceIdentity) {
            return self::pbs4Instance($snapshot->instanceIdentity->value);
        }

        return null;
    }

    public function equals(self $other): bool
    {
        return $this->product === $other->product
            && $this->kind === $other->kind
            && hash_equals($this->identity, $other->identity)
            && $this->knownMemberNodes === $other->knownMemberNodes;
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

        return InstallationBindingKind::PveCluster !== $this->kind
            || [] !== array_intersect($this->knownMemberNodes, $observed->knownMemberNodes);
    }

}
