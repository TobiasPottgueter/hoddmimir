<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

use App\Application\Inventory\Connection\ConnectionInstallationRead;
use App\Application\Inventory\Connection\InstallationBinding;
use App\Application\Inventory\Connection\InstallationBindingKind;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Proxmox\Pve\PveClusterNode;
use App\Application\Proxmox\Pve\PveGuestResource;
use App\Application\Proxmox\Pve\PveInstallationSnapshot;
use App\Application\Proxmox\Pve\PveNodeResource;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class MapPveCoreInventorySnapshot implements PveCoreInventoryMapper
{
    public function map(
        InventoryIdentifier $runId,
        ConnectionInstallationRead $read,
        DateTimeImmutable $observedAt,
    ): PveCoreInventoryCommit {
        if (!$read->snapshot instanceof PveInstallationSnapshot
            || ProxmoxProduct::Pve !== $read->binding->product) {
            throw PveCoreInventoryMappingFailure::nonPveRead();
        }

        $observedBinding = InstallationBinding::fromSnapshot($read->snapshot);
        if (null === $observedBinding || !$read->binding->equals($observedBinding)) {
            throw PveCoreInventoryMappingFailure::bindingMismatch();
        }

        $binding = $this->coreBinding($read->binding);
        [$nodes, $nodesAreLossless] = $this->nodes($read->snapshot, $binding);
        [$guests, $guestsAreLossless] = $this->guests($read->snapshot, $nodes);
        $complete = $read->snapshot->isComplete() && $nodesAreLossless && $guestsAreLossless;
        $status = $complete ? InventoryScopeStatus::Complete : InventoryScopeStatus::Partial;

        return new PveCoreInventoryCommit(
            $runId,
            new InventoryIdentifier($read->connectionId->bytes),
            new InventoryIdentifier($read->endpointId->bytes),
            $read->expectedRevision,
            $binding,
            new PveCoreScopeResult(PveCoreScope::Topology, $status),
            new PveCoreScopeResult(PveCoreScope::Guests, $status),
            array_values($nodes),
            array_values($guests),
            $observedAt,
        );
    }

    private function coreBinding(InstallationBinding $binding): PveCoreInstallationBinding
    {
        if (InstallationBindingKind::PveCluster === $binding->kind) {
            return new PveCoreInstallationBinding(
                PveCoreBindingKind::Cluster,
                $binding->identity,
            );
        }

        return new PveCoreInstallationBinding(PveCoreBindingKind::Standalone, $binding->identity);
    }

    /**
     * @return array{array<string, PveNodeObservation>, bool}
     */
    private function nodes(
        PveInstallationSnapshot $snapshot,
        PveCoreInstallationBinding $binding,
    ): array {
        $nodes = [];
        $topologyNames = [];
        $lossless = true;

        foreach ($snapshot->topology->nodes as $node) {
            if (isset($topologyNames[$node->name])) {
                $lossless = false;
                continue;
            }
            $topologyNames[$node->name] = true;
            $observation = $this->topologyNode($node);
            $nodes[$node->name] = $observation;
        }

        $resourceNames = [];
        foreach ($snapshot->resources->nodes as $node) {
            if (isset($resourceNames[$node->name])) {
                $lossless = false;
                continue;
            }
            $resourceNames[$node->name] = true;
            if (PveCoreBindingKind::Standalone === $binding->kind && $binding->value !== $node->name) {
                $lossless = false;
                continue;
            }
            $observation = $this->resourceNode($node);
            if (null === $observation) {
                $lossless = false;
                continue;
            }
            $nodes[$node->name] = $observation;
        }

        return [$nodes, $lossless];
    }

    private function topologyNode(PveClusterNode $node): PveNodeObservation
    {
        $status = 'unknown';
        if (true === $node->online) {
            $status = 'online';
        } elseif (false === $node->online) {
            $status = 'offline';
        }

        return new PveNodeObservation($node->name, $status);
    }

    private function resourceNode(PveNodeResource $node): ?PveNodeObservation
    {
        try {
            return new PveNodeObservation(
                $node->name,
                match ($node->status) {
                    'online' => 'online',
                    'offline' => 'offline',
                    default => 'unknown',
                },
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array<string, PveNodeObservation> $nodes
     *
     * @return array{array<string, PveGuestObservation>, bool}
     */
    private function guests(PveInstallationSnapshot $snapshot, array $nodes): array
    {
        $guests = [];
        $lossless = true;

        foreach ($snapshot->resources->guests as $guest) {
            $key = $guest->identity();
            if (isset($guests[$key]) || !isset($nodes[$guest->node])) {
                $lossless = false;
                continue;
            }
            $observation = $this->guest($guest);
            if (null === $observation) {
                $lossless = false;
                continue;
            }
            $guests[$key] = $observation;
        }

        return [$guests, $lossless];
    }

    private function guest(PveGuestResource $guest): ?PveGuestObservation
    {
        try {
            return new PveGuestObservation(
                $guest->type,
                $guest->vmid,
                $guest->node,
                $guest->name,
                $guest->template,
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
