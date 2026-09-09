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
use App\Application\Inventory\Pve\MapPveInventorySnapshot;
use App\Application\Inventory\Pve\PveCoreInventoryMappingFailure;
use App\Application\Inventory\Pve\PveStorageCapacityStatus;
use App\Application\Proxmox\Pbs\PbsDatastoreScanScope;
use App\Application\Proxmox\Pbs\PbsInstallationSnapshot;
use App\Application\Proxmox\Pbs\PbsVersion;
use App\Application\Proxmox\Pve\PveClusterMode;
use App\Application\Proxmox\Pve\PveClusterNode;
use App\Application\Proxmox\Pve\PveClusterTopology;
use App\Application\Proxmox\Pve\PveInstallationSnapshot;
use App\Application\Proxmox\Pve\PveInventorySnapshot;
use App\Application\Proxmox\Pve\PveNodeResource;
use App\Application\Proxmox\Pve\PveNodeStorageObservation;
use App\Application\Proxmox\Pve\PvePbsStorageMapping;
use App\Application\Proxmox\Pve\PvePermissionAssessment;
use App\Application\Proxmox\Pve\PveResourceInventory;
use App\Application\Proxmox\Pve\PveStorageCapacity;
use App\Application\Proxmox\Pve\PveStorageCapacityState;
use App\Application\Proxmox\Pve\PveStorageConfiguration;
use App\Application\Proxmox\Pve\PveStorageConfigurationSet;
use App\Application\Proxmox\Pve\PveStorageContentSet;
use App\Application\Proxmox\Pve\PveStorageInventorySnapshot;
use App\Application\Proxmox\Pve\PveStorageIssue;
use App\Application\Proxmox\Pve\PveStorageIssueCode;
use App\Application\Proxmox\Pve\PveVersion;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MapPveInventorySnapshotTest extends TestCase
{
    private const string NOW = '2026-07-11T12:00:00+00:00';

    public function testItMapsACompleteCoreAndStorageSnapshotWithFullPbsTuple(): void
    {
        $pbs = $this->definition(
            'pbs-target',
            'pbs',
            ['backup'],
            null,
            false,
            true,
            new PvePbsStorageMapping('pbs.example.test', 8443, 'vault', 'tenant-a'),
        );
        $local = $this->definition('local-backup', 'dir', ['backup'], ['node-a'], false, false, null);
        $configuration = new PveStorageConfigurationSet('digest-a', [$pbs, $local], []);
        $snapshot = $this->snapshot(
            $configuration,
            $configuration,
            [
                $this->observation('node-b', 'pbs-target', PveStorageCapacityState::Fresh, new PveStorageCapacity(200, 50, 140), true),
                $this->observation('node-a', 'local-backup', PveStorageCapacityState::Fresh, new PveStorageCapacity(100, 20, 70)),
                $this->observation('node-a', 'pbs-target', PveStorageCapacityState::Fresh, new PveStorageCapacity(200, 40, 150), true),
            ],
        );

        $commit = $this->mapper()->map(
            $this->id('r'),
            $this->read($snapshot),
            new DateTimeImmutable('2026-07-11T14:00:00+02:00'),
        );

        self::assertTrue($commit->isFullyAuthoritative());
        self::assertSame('succeeded', $commit->overallStatus());
        self::assertSame(InventoryScopeStatus::Complete, $commit->storageScope->status);
        self::assertSame(['node-a', 'node-b'], array_column($commit->nodeStorageScopes, 'node'));
        self::assertSame(['local-backup', 'pbs-target'], array_column($commit->storages, 'storageId'));
        self::assertSame([
            'server' => 'pbs.example.test',
            'port' => 8443,
            'datastore' => 'vault',
            'namespace' => 'tenant-a',
        ], $commit->storages[1]->pbsMapping?->signature());
        self::assertSame([
            "node-a\0local-backup",
            "node-a\0pbs-target",
            "node-b\0pbs-target",
        ], array_map(static fn ($state): string => $state->key(), $commit->nodeStorageStates));
        self::assertSame('2026-07-11T12:00:00+00:00', $commit->storages[0]->observedAt->format('c'));
    }

    public function testDisabledBackupConfigurationIsPersistedWithoutInventedNodeStates(): void
    {
        $disabled = $this->definition('disabled-backup', 'dir', ['backup'], null, true, false, null);
        $configuration = new PveStorageConfigurationSet('digest', [$disabled], []);

        $commit = $this->mapper()->map(
            $this->id('r'),
            $this->read($this->snapshot($configuration, $configuration, [])),
            new DateTimeImmutable(self::NOW),
        );

        self::assertSame(['disabled-backup'], array_column($commit->storages, 'storageId'));
        self::assertTrue($commit->storages[0]->disabled);
        self::assertSame([], $commit->nodeStorageStates);
        self::assertTrue($commit->isFullyAuthoritative());
    }

    public function testInvalidAndUnavailableCapacityPersistFailClosedWithoutByteValues(): void
    {
        $invalid = $this->definition('invalid-capacity');
        $unavailable = $this->definition('unavailable-capacity');
        $configuration = new PveStorageConfigurationSet('digest', [$invalid, $unavailable], []);
        $invalidCapacity = new PveStorageIssue(
            PveStorageIssueCode::InvalidCapacity,
            '/nodes/node-a/storage',
            '/data/0/total',
            'invalid-capacity',
            'node-a',
        );

        $commit = $this->mapper()->map(
            $this->id('r'),
            $this->read($this->snapshot($configuration, $configuration, [
                $this->observation('node-a', 'invalid-capacity', PveStorageCapacityState::Invalid),
                $this->observation('node-a', 'unavailable-capacity', PveStorageCapacityState::Unavailable),
                $this->observation('node-b', 'invalid-capacity', PveStorageCapacityState::Invalid),
                $this->observation('node-b', 'unavailable-capacity', PveStorageCapacityState::Unavailable),
            ], [$invalidCapacity])),
            new DateTimeImmutable(self::NOW),
        );

        self::assertSame(InventoryScopeStatus::Partial, $commit->storageScope->status);
        self::assertSame(InventoryScopeStatus::Partial, $commit->nodeStorageScopes[0]->status);
        self::assertSame(InventoryScopeStatus::Complete, $commit->nodeStorageScopes[1]->status);
        self::assertSame(['invalid-capacity', 'unavailable-capacity'], array_column($commit->storages, 'storageId'));
        self::assertSame([
            PveStorageCapacityStatus::Invalid,
            PveStorageCapacityStatus::Unavailable,
            PveStorageCapacityStatus::Invalid,
            PveStorageCapacityStatus::Unavailable,
        ], array_column($commit->nodeStorageStates, 'capacityStatus'));
        foreach ($commit->nodeStorageStates as $state) {
            self::assertNull($state->totalBytes);
            self::assertNull($state->usedBytes);
            self::assertNull($state->availableBytes);
        }
        self::assertSame('partial', $commit->overallStatus());
    }

    public function testPartialStorageReadKeepsOnlyCompletelyEvidencedSafePositiveStorages(): void
    {
        $safe = $this->definition('safe', 'dir', ['backup'], ['node-a'], false, false, null);
        $missing = $this->definition('missing');
        $blocked = $this->definition('blocked');
        $nonBackup = $this->definition('images', 'dir', ['images'], null, false, false, null);
        $badPbs = $this->definition('pbs-without-tuple', 'pbs', ['backup'], null, false, true, null);
        $configuration = new PveStorageConfigurationSet('digest', [$safe, $missing, $blocked, $nonBackup, $badPbs], []);
        $issues = [
            new PveStorageIssue(PveStorageIssueCode::MissingExpectedObservation, '/nodes/node-a/storage', '/data', 'blocked', 'node-a'),
            new PveStorageIssue(PveStorageIssueCode::UnexpectedObservation, '/nodes/node-b/storage', '/data', 'other', 'node-b'),
        ];

        $commit = $this->mapper()->map(
            $this->id('r'),
            $this->read($this->snapshot($configuration, $configuration, [
                $this->observation('node-a', 'safe', PveStorageCapacityState::Fresh, new PveStorageCapacity(10, 1, 9)),
                $this->observation('node-a', 'blocked', PveStorageCapacityState::Fresh, new PveStorageCapacity(10, 1, 9)),
                $this->observation('node-b', 'blocked', PveStorageCapacityState::Fresh, new PveStorageCapacity(10, 1, 9)),
                $this->observation('node-a', 'pbs-without-tuple', PveStorageCapacityState::Unavailable),
                $this->observation('node-b', 'pbs-without-tuple', PveStorageCapacityState::Unavailable),
            ], $issues)),
            new DateTimeImmutable(self::NOW),
        );

        self::assertSame(['safe'], array_column($commit->storages, 'storageId'));
        self::assertSame(["node-a\0safe"], array_map(static fn ($state): string => $state->key(), $commit->nodeStorageStates));
        self::assertSame(InventoryScopeStatus::Partial, $commit->storageScope->status);
        self::assertSame(InventoryScopeStatus::Partial, $commit->nodeStorageScopes[0]->status);
        self::assertSame(InventoryScopeStatus::Partial, $commit->nodeStorageScopes[1]->status);
    }

    public function testIncompleteTopologyCannotInventACompleteExpectedNodeSet(): void
    {
        $global = $this->definition('global');
        $missingAllowlistNode = $this->definition('missing-node', nodes: ['node-a', 'node-c']);
        $knownAllowlist = $this->definition('known-nodes', nodes: ['node-a']);
        $disabled = $this->definition('disabled', nodes: null, disabled: true);
        $configuration = new PveStorageConfigurationSet(
            'digest',
            [$global, $missingAllowlistNode, $knownAllowlist, $disabled],
            [],
        );
        $issue = new PveStorageIssue(
            PveStorageIssueCode::IncompleteTopology,
            '/cluster/status',
            '/data',
        );

        $commit = $this->mapper()->map(
            $this->id('r'),
            $this->read($this->snapshot($configuration, $configuration, [
                $this->observation('node-a', 'global', PveStorageCapacityState::Unavailable),
                $this->observation('node-b', 'global', PveStorageCapacityState::Unavailable),
                $this->observation('node-a', 'missing-node', PveStorageCapacityState::Unavailable),
                $this->observation('node-a', 'known-nodes', PveStorageCapacityState::Unavailable),
            ], [$issue])),
            new DateTimeImmutable(self::NOW),
        );

        self::assertSame(['disabled', 'known-nodes'], array_column($commit->storages, 'storageId'));
        self::assertSame(
            ["node-a\0known-nodes"],
            array_map(static fn ($state): string => $state->key(), $commit->nodeStorageStates),
        );
        self::assertSame(InventoryScopeStatus::Partial, $commit->storageScope->status);
    }

    public function testConfigurationMismatchOrMissingBoundariesDiscardAllStorageObservations(): void
    {
        $configuration = new PveStorageConfigurationSet('digest', [$this->definition('safe')], []);
        $changed = new PveStorageConfigurationSet('changed', [$this->definition('different')], []);
        $observation = $this->observation('node-a', 'safe', PveStorageCapacityState::Unavailable);

        foreach ([
            [null, $configuration],
            [$configuration, null],
            [$configuration, $changed],
        ] as [$start, $end]) {
            $commit = $this->mapper()->map(
                $this->id('r'),
                $this->read($this->snapshot($start, $end, [$observation])),
                new DateTimeImmutable(self::NOW),
            );
            self::assertSame([], $commit->storages);
            self::assertSame([], $commit->nodeStorageStates);
            self::assertSame('partial', $commit->overallStatus());
        }
    }

    public function testGlobalReadFailuresProduceFailedStorageScopesWithoutLosingCoreEvidence(): void
    {
        foreach ([
            PveStorageIssueCode::ConfigurationReadFailed,
            PveStorageIssueCode::NodeFanoutExceeded,
        ] as $code) {
            $issue = new PveStorageIssue($code, '/storage', '/data');
            $commit = $this->mapper()->map(
                $this->id('r'),
                $this->read($this->snapshot(null, null, [], [$issue])),
                new DateTimeImmutable(self::NOW),
            );
            self::assertSame(InventoryScopeStatus::Failed, $commit->storageScope->status);
            self::assertSame('partial', $commit->overallStatus());
            $expectedNodeStatus = PveStorageIssueCode::NodeFanoutExceeded === $code
                ? InventoryScopeStatus::Failed
                : InventoryScopeStatus::Complete;
            self::assertSame([$expectedNodeStatus, $expectedNodeStatus], array_column($commit->nodeStorageScopes, 'status'));
        }

        $nodeFailure = new PveStorageIssue(
            PveStorageIssueCode::NodeReadFailed,
            '/nodes/node-a/storage',
            '/data',
            null,
            'node-a',
        );
        $commit = $this->mapper()->map(
            $this->id('r'),
            $this->read($this->snapshot(null, null, [], [$nodeFailure])),
            new DateTimeImmutable(self::NOW),
        );
        self::assertSame(InventoryScopeStatus::Partial, $commit->storageScope->status);
        self::assertSame(InventoryScopeStatus::Failed, $commit->nodeStorageScopes[0]->status);
        self::assertSame(InventoryScopeStatus::Complete, $commit->nodeStorageScopes[1]->status);
    }

    public function testIssueSearchContinuesAndNodeWideIssueBlocksPositiveStorage(): void
    {
        $definition = $this->definition('safe');
        $configuration = new PveStorageConfigurationSet('digest', [$definition], []);
        $issues = [
            new PveStorageIssue(PveStorageIssueCode::InvalidCapacity, '/nodes/node-b/storage', '/data', 'safe', 'node-b'),
            new PveStorageIssue(PveStorageIssueCode::ConfigurationReadFailed, '/storage', '/data'),
            new PveStorageIssue(PveStorageIssueCode::NodeReadFailed, '/nodes/node-a/storage', '/data', null, 'node-a'),
        ];
        $commit = $this->mapper()->map(
            $this->id('r'),
            $this->read($this->snapshot($configuration, $configuration, [
                $this->observation('node-a', 'safe', PveStorageCapacityState::Unavailable),
                $this->observation('node-b', 'safe', PveStorageCapacityState::Invalid),
            ], $issues)),
            new DateTimeImmutable(self::NOW),
        );

        self::assertSame(InventoryScopeStatus::Failed, $commit->storageScope->status);
        self::assertSame([], $commit->storages);
        self::assertSame([], $commit->nodeStorageStates);
        self::assertSame(InventoryScopeStatus::Failed, $commit->nodeStorageScopes[0]->status);
    }

    public function testItRejectsAReadThatDoesNotContainTheCombinedPveSnapshot(): void
    {
        $pbs = new PbsInstallationSnapshot(
            new PbsVersion(3, 4, 0, '3.4.0', '3.4', 'repo'),
            null,
            null,
            PbsDatastoreScanScope::installationWide(),
            null,
            null,
            [],
            [],
            [],
        );
        $read = new ConnectionInstallationRead(
            new ConnectionId(str_repeat('c', 16)),
            1,
            new EndpointId(str_repeat('e', 16)),
            InstallationBinding::pbsLegacyEndpoint(new \App\Application\Inventory\Connection\EndpointId(str_repeat('l', 16))),
            $pbs,
        );

        $this->expectException(PveCoreInventoryMappingFailure::class);
        $this->expectExceptionMessage('The installation read is not a PVE snapshot.');
        $this->mapper()->map($this->id('r'), $read, new DateTimeImmutable(self::NOW));
    }

    private function mapper(): MapPveInventorySnapshot
    {
        return new MapPveInventorySnapshot(new MapPveCoreInventorySnapshot());
    }

    private function read(PveInventorySnapshot $snapshot): ConnectionInstallationRead
    {
        return new ConnectionInstallationRead(
            new ConnectionId(str_repeat('c', 16)),
            3,
            new EndpointId(str_repeat('e', 16)),
            InstallationBinding::pveCluster('forest', ['node-a', 'node-b']),
            $snapshot,
        );
    }

    /**
     * @param list<PveNodeStorageObservation> $observations
     * @param list<PveStorageIssue>           $issues
     */
    private function snapshot(
        ?PveStorageConfigurationSet $start,
        ?PveStorageConfigurationSet $end,
        array $observations,
        array $issues = [],
    ): PveInventorySnapshot {
        $core = new PveInstallationSnapshot(
            new PveVersion(9, 2, 3, '9.2', '9.2.3', 'repo'),
            new PvePermissionAssessment([]),
            new PveClusterTopology(PveClusterMode::Clustered, 'forest', 2, 1, true, [
                new PveClusterNode('node-a', true, 1, true),
                new PveClusterNode('node-b', false, 2, false),
            ], []),
            new PveResourceInventory([
                new PveNodeResource('node-a', 'online'),
                new PveNodeResource('node-b', 'offline'),
            ], [], [], []),
        );

        return new PveInventorySnapshot(
            $core,
            new PveStorageInventorySnapshot($start, $end, $observations, $issues),
            ['node-a', 'node-b'],
        );
    }

    /**
     * @param list<string>      $content
     * @param null|list<string> $nodes
     */
    private function definition(
        string $id,
        string $type = 'dir',
        array $content = ['backup'],
        ?array $nodes = null,
        bool $disabled = false,
        bool $shared = false,
        ?PvePbsStorageMapping $pbs = null,
    ): PveStorageConfiguration {
        return new PveStorageConfiguration(
            $id,
            $type,
            new PveStorageContentSet($content),
            $nodes,
            $disabled,
            $shared,
            $pbs,
        );
    }

    private function observation(
        string $node,
        string $storage,
        PveStorageCapacityState $state,
        ?PveStorageCapacity $capacity = null,
        bool $shared = false,
    ): PveNodeStorageObservation {
        return new PveNodeStorageObservation(
            $node,
            $storage,
            'dir',
            new PveStorageContentSet(['backup']),
            true,
            true,
            $shared,
            $state,
            $capacity,
            new DateTimeImmutable(self::NOW),
        );
    }

    private function id(string $byte): InventoryIdentifier
    {
        return new InventoryIdentifier(str_repeat($byte, 16));
    }
}
