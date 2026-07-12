<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Inventory\Pve;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionInstallationRead;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\InstallationBinding;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\Pve\InventoryScopeStatus;
use App\Application\Inventory\Pve\MapPveCoreInventorySnapshot;
use App\Application\Inventory\Pve\PveCoreBindingKind;
use App\Application\Inventory\Pve\PveCoreInventoryMappingFailure;
use App\Application\Inventory\Pve\PveCoreInventoryMappingFailureCode;
use App\Application\Proxmox\Pbs\PbsDatastoreScanScope;
use App\Application\Proxmox\Pbs\PbsInstallationSnapshot;
use App\Application\Proxmox\Pbs\PbsVersion;
use App\Application\Proxmox\Pve\PveClusterMode;
use App\Application\Proxmox\Pve\PveClusterNode;
use App\Application\Proxmox\Pve\PveClusterTopology;
use App\Application\Proxmox\Pve\PveGuestResource;
use App\Application\Proxmox\Pve\PveGuestType;
use App\Application\Proxmox\Pve\PveInstallationSnapshot;
use App\Application\Proxmox\Pve\PveMissingPermission;
use App\Application\Proxmox\Pve\PveNodeResource;
use App\Application\Proxmox\Pve\PvePermissionAssessment;
use App\Application\Proxmox\Pve\PveRequiredPermission;
use App\Application\Proxmox\Pve\PveResourceInventory;
use App\Application\Proxmox\Pve\PveVersion;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MapPveCoreInventorySnapshotTest extends TestCase
{
    public function testItMapsACompleteClusterReadWithoutInventingFields(): void
    {
        $snapshot = self::clusterSnapshot(
            [
                new PveClusterNode('node-b', null, 2, false),
                new PveClusterNode('node-a', true, 1, true),
            ],
            [
                new PveNodeResource('node-b', null),
                new PveNodeResource('node-a', 'offline'),
            ],
            [
                new PveGuestResource(PveGuestType::Qemu, 100, 'node-a', 'vm-a', true, 'running'),
                new PveGuestResource(PveGuestType::Lxc, 200, 'node-b', null, null, 'stopped'),
            ],
        );
        $read = self::pveRead(
            InstallationBinding::pveCluster('forest', ['node-b', 'node-a']),
            $snapshot,
        );

        $commit = (new MapPveCoreInventorySnapshot())->map(
            self::id('r'),
            $read,
            new DateTimeImmutable('2026-07-11T15:00:00+02:00'),
        );

        self::assertSame(str_repeat('r', 16), $commit->runId->binary());
        self::assertSame(str_repeat('c', 16), $commit->connectionId->binary());
        self::assertSame(str_repeat('e', 16), $commit->endpointId->binary());
        self::assertSame(7, $commit->expectedConnectionRevision);
        self::assertSame(PveCoreBindingKind::Cluster, $commit->binding->kind);
        self::assertSame('forest', $commit->binding->value);
        self::assertSame(InventoryScopeStatus::Complete, $commit->topologyScope->status);
        self::assertSame(InventoryScopeStatus::Complete, $commit->guestScope->status);
        self::assertSame('succeeded', $commit->overallStatus());
        self::assertSame(['node-a', 'node-b'], array_column($commit->nodes, 'name'));
        self::assertSame(['offline', 'unknown'], array_column($commit->nodes, 'apiStatus'));
        self::assertSame(['lxc:200', 'qemu:100'], array_map(static fn ($guest): string => $guest->key(), $commit->guests));
        self::assertNull($commit->guests[0]->name);
        self::assertNull($commit->guests[0]->isTemplate);
        self::assertSame('vm-a', $commit->guests[1]->name);
        self::assertTrue($commit->guests[1]->isTemplate);
        self::assertSame('2026-07-11T13:00:00+00:00', $commit->observedAt->format('c'));
    }

    public function testItMapsACompleteStandaloneReadAndNormalizesUnknownNodeStatus(): void
    {
        $snapshot = self::standaloneSnapshot(
            new PveClusterNode('solo', false, 0, true),
            [new PveNodeResource('solo', 'maintenance')],
        );

        $commit = (new MapPveCoreInventorySnapshot())->map(
            self::id('r'),
            self::pveRead(InstallationBinding::pveStandalone('solo'), $snapshot),
            new DateTimeImmutable('2026-07-11T13:00:00+00:00'),
        );

        self::assertSame(PveCoreBindingKind::Standalone, $commit->binding->kind);
        self::assertSame('unknown', $commit->nodes[0]->apiStatus);
        self::assertTrue($commit->isFullyAuthoritative());
    }

    public function testItKeepsSafePositiveObservationsButNeverMarksAnIncompleteSnapshotComplete(): void
    {
        $snapshot = self::clusterSnapshot(
            [new PveClusterNode('node-a', true, 1, true)],
            [new PveNodeResource('node-a', 'online')],
            [new PveGuestResource(PveGuestType::Qemu, 100, 'node-a', 'vm-a', false, 'running')],
            false,
        );

        $commit = (new MapPveCoreInventorySnapshot())->map(
            self::id('r'),
            self::pveRead(InstallationBinding::pveCluster('forest', ['node-a']), $snapshot),
            new DateTimeImmutable('2026-07-11T13:00:00+00:00'),
        );

        self::assertSame(InventoryScopeStatus::Partial, $commit->topologyScope->status);
        self::assertSame(InventoryScopeStatus::Partial, $commit->guestScope->status);
        self::assertSame('partial', $commit->overallStatus());
        self::assertSame(['node-a'], array_column($commit->nodes, 'name'));
        self::assertSame(['qemu:100'], array_map(static fn ($guest): string => $guest->key(), $commit->guests));
    }

    public function testMappingLossesDowngradeBothScopesAndRetainOnlySafeObservations(): void
    {
        $snapshot = self::clusterSnapshot(
            [
                new PveClusterNode('node-a', true, 1, true),
                new PveClusterNode('node-a', false, 2, false),
            ],
            [
                new PveNodeResource('node-a', 'online'),
                new PveNodeResource('node-a', 'offline'),
                new PveNodeResource('/invalid', 'online'),
            ],
            [
                new PveGuestResource(PveGuestType::Qemu, 100, 'node-a', 'vm-a', false, 'running'),
                new PveGuestResource(PveGuestType::Qemu, 100, 'node-a', 'duplicate', true, 'stopped'),
                new PveGuestResource(PveGuestType::Lxc, 0, 'node-a', 'invalid-vmid', false, null),
                new PveGuestResource(PveGuestType::Lxc, 200, 'missing-node', 'orphan', false, null),
            ],
            true,
            2,
        );

        $commit = (new MapPveCoreInventorySnapshot())->map(
            self::id('r'),
            self::pveRead(InstallationBinding::pveCluster('forest', ['node-a']), $snapshot),
            new DateTimeImmutable('2026-07-11T13:00:00+00:00'),
        );

        self::assertSame(InventoryScopeStatus::Partial, $commit->topologyScope->status);
        self::assertSame(InventoryScopeStatus::Partial, $commit->guestScope->status);
        self::assertSame(['node-a'], array_column($commit->nodes, 'name'));
        self::assertSame(['online'], array_column($commit->nodes, 'apiStatus'));
        self::assertSame(['qemu:100'], array_map(static fn ($guest): string => $guest->key(), $commit->guests));
    }

    public function testItDropsAResourceFromAnotherStandaloneNodeAndFallsBackToTopologyStatus(): void
    {
        $snapshot = self::standaloneSnapshot(
            new PveClusterNode('solo', null, 0, true),
            [new PveNodeResource('other', 'online')],
        );

        $commit = (new MapPveCoreInventorySnapshot())->map(
            self::id('r'),
            self::pveRead(InstallationBinding::pveStandalone('solo'), $snapshot),
            new DateTimeImmutable('2026-07-11T13:00:00+00:00'),
        );

        self::assertSame(InventoryScopeStatus::Partial, $commit->topologyScope->status);
        self::assertSame(['solo'], array_column($commit->nodes, 'name'));
        self::assertSame('unknown', $commit->nodes[0]->apiStatus);
    }

    #[DataProvider('invalidReadProvider')]
    public function testItRejectsNonPveAndInconsistentReadsWithStableTypedFailures(
        ConnectionInstallationRead $read,
        PveCoreInventoryMappingFailureCode $expectedCode,
        string $expectedMessage,
    ): void {
        try {
            (new MapPveCoreInventorySnapshot())->map(
                self::id('r'),
                $read,
                new DateTimeImmutable('2026-07-11T13:00:00+00:00'),
            );
            self::fail('Expected the installation read to be rejected.');
        } catch (PveCoreInventoryMappingFailure $failure) {
            self::assertSame($expectedCode, $failure->failureCode);
            self::assertSame($expectedMessage, $failure->getMessage());
        }
    }

    /** @return iterable<string, array{ConnectionInstallationRead, PveCoreInventoryMappingFailureCode, string}> */
    public static function invalidReadProvider(): iterable
    {
        $pbs = new PbsInstallationSnapshot(
            new PbsVersion(3, 4, 0, '3.4.0', '3.4', 'repo'),
            'pbs-a',
            null,
            null,
            PbsDatastoreScanScope::installationWide(),
            null,
            null,
            [],
            [],
            [],
        );
        yield 'PBS snapshot and binding' => [
            self::read(InstallationBinding::pbsLegacyNode('pbs-a', new \App\Application\Inventory\Connection\EndpointId(str_repeat('l', 16))), $pbs),
            PveCoreInventoryMappingFailureCode::NonPveRead,
            'The installation read is not a PVE snapshot.',
        ];

        $pve = self::clusterSnapshot(
            [new PveClusterNode('node-a', true, 1, true)],
            [new PveNodeResource('node-a', 'online')],
            [],
        );
        yield 'PVE snapshot with PBS binding' => [
            self::read(InstallationBinding::pbsLegacyNode('pbs-a', new \App\Application\Inventory\Connection\EndpointId(str_repeat('l', 16))), $pve),
            PveCoreInventoryMappingFailureCode::NonPveRead,
            'The installation read is not a PVE snapshot.',
        ];
        yield 'different PVE binding' => [
            self::pveRead(InstallationBinding::pveCluster('other', ['node-a']), $pve),
            PveCoreInventoryMappingFailureCode::BindingMismatch,
            'The PVE installation read has an inconsistent binding.',
        ];

        $unusable = self::clusterSnapshot([], [], [], true, 1);
        yield 'unusable PVE identity' => [
            self::pveRead(InstallationBinding::pveCluster('forest', ['node-a']), $unusable),
            PveCoreInventoryMappingFailureCode::BindingMismatch,
            'The PVE installation read has an inconsistent binding.',
        ];
    }

    /**
     * @param list<PveClusterNode>  $topologyNodes
     * @param list<PveNodeResource> $resourceNodes
     * @param list<PveGuestResource> $guests
     */
    private static function clusterSnapshot(
        array $topologyNodes,
        array $resourceNodes,
        array $guests,
        bool $permissionsComplete = true,
        ?int $declaredNodeCount = null,
    ): PveInstallationSnapshot {
        return new PveInstallationSnapshot(
            new PveVersion(9, 2, 3, '9.2', '9.2.3', 'repo'),
            new PvePermissionAssessment($permissionsComplete ? [] : [
                new PveMissingPermission(PveRequiredPermission::SystemAudit),
            ]),
            new PveClusterTopology(
                PveClusterMode::Clustered,
                'forest',
                $declaredNodeCount ?? count($topologyNodes),
                1,
                true,
                $topologyNodes,
                [],
            ),
            new PveResourceInventory($resourceNodes, $guests, [], []),
        );
    }

    /** @param list<PveNodeResource> $resourceNodes */
    private static function standaloneSnapshot(
        PveClusterNode $topologyNode,
        array $resourceNodes,
    ): PveInstallationSnapshot {
        return new PveInstallationSnapshot(
            new PveVersion(8, 4, 0, '8.4', '8.4.0', 'repo'),
            new PvePermissionAssessment([]),
            new PveClusterTopology(PveClusterMode::Standalone, null, null, null, null, [$topologyNode], []),
            new PveResourceInventory($resourceNodes, [], [], []),
        );
    }

    private static function pveRead(
        InstallationBinding $binding,
        PveInstallationSnapshot $snapshot,
    ): ConnectionInstallationRead {
        return self::read($binding, $snapshot);
    }

    private static function read(
        InstallationBinding $binding,
        PveInstallationSnapshot|PbsInstallationSnapshot $snapshot,
    ): ConnectionInstallationRead {
        return new ConnectionInstallationRead(
            new ConnectionId(str_repeat('c', 16)),
            7,
            new EndpointId(str_repeat('e', 16)),
            $binding,
            $snapshot,
        );
    }

    private static function id(string $byte): InventoryIdentifier
    {
        return new InventoryIdentifier(str_repeat($byte, 16));
    }
}
