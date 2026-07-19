<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Inventory\Pbs;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionInstallationRead;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\InstallationBinding;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\Pbs\MapPbsInventorySnapshot;
use App\Application\Inventory\Pbs\PbsInventoryMappingFailure;
use App\Application\Inventory\Pbs\PbsInventoryScope;
use App\Application\Inventory\Pve\InventoryScopeStatus;
use App\Application\Proxmox\Pbs\PbsCapacitySemantics;
use App\Application\Proxmox\Pbs\PbsDatastoreBackendType;
use App\Application\Proxmox\Pbs\PbsDatastoreCapacity;
use App\Application\Proxmox\Pbs\PbsDatastoreConfigurationSnapshot;
use App\Application\Proxmox\Pbs\PbsDatastoreDefinition;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsDatastoreScanScope;
use App\Application\Proxmox\Pbs\PbsInstallationSnapshot;
use App\Application\Proxmox\Pbs\PbsInventoryIssue;
use App\Application\Proxmox\Pbs\PbsInventoryIssueCode;
use App\Application\Proxmox\Pbs\PbsMaintenanceMode;
use App\Application\Proxmox\Pbs\PbsMountStatus;
use App\Application\Proxmox\Pbs\PbsNodeRoute;
use App\Application\Proxmox\Pbs\PbsNodeStatus;
use App\Application\Proxmox\Pbs\PbsVersion;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MapPbsInventorySnapshotTest extends TestCase
{
    public function testItMapsCompleteFilesystemAndS3EvidenceAndNormalizesTheResult(): void
    {
        $filesystem = $this->definition('alpha_store', PbsDatastoreBackendType::Filesystem);
        $s3 = $this->definition(
            'zeta_store',
            PbsDatastoreBackendType::S3,
            PbsMountStatus::Mounted,
            PbsMaintenanceMode::ReadOnly,
        );
        $configuration = $this->configuration([$filesystem->id, $s3->id]);
        $snapshot = $this->snapshot(
            $configuration,
            $configuration,
            [$s3, $filesystem],
            [
                $this->capacity($s3->id, PbsDatastoreBackendType::S3, 200, 80, 120),
                $this->capacity($filesystem->id, PbsDatastoreBackendType::Filesystem, 100, 20, 80),
            ],
        );

        $commit = (new MapPbsInventorySnapshot())->map(
            $this->inventoryId('r'),
            $this->read($snapshot),
            new DateTimeImmutable('2026-07-11T14:00:00+02:00'),
        );

        self::assertSame(InventoryScopeStatus::Complete, $commit->systemScope->status);
        self::assertSame(InventoryScopeStatus::Complete, $commit->datastoreScope->status);
        self::assertSame(['alpha_store', 'zeta_store'], array_column($commit->datastores, 'id'));
        self::assertSame(['alpha_store', 'zeta_store'], array_column($commit->statusScopes, 'key'));
        self::assertSame(['alpha_store', 'zeta_store'], array_column($commit->capacities, 'datastoreId'));
        self::assertSame(
            [PbsCapacitySemantics::DatastoreFilesystem, PbsCapacitySemantics::LocalCache],
            array_column($commit->capacities, 'semantics'),
        );
        self::assertTrue($commit->datastores[0]->allowsBackupWrites);
        self::assertFalse($commit->datastores[1]->allowsBackupWrites);
        self::assertSame('2026-07-11T12:00:00+00:00', $commit->observedAt->format('c'));
        self::assertTrue($commit->isFullyAuthoritative());
        self::assertSame('succeeded', $commit->overallStatus());
    }

    #[DataProvider('systemStatusCases')]
    public function testItDerivesTheSystemScopeWithoutTreatingDatastoreIssuesAsSystemFailures(
        ?PbsNodeStatus $status,
        ?PbsInventoryIssueCode $issueCode,
        InventoryScopeStatus $expected,
    ): void {
        $snapshot = $this->snapshot(
            null,
            null,
            [],
            [],
            null === $issueCode ? [] : [new PbsInventoryIssue($issueCode, '/api2/json/status/datastore')],
            $status,
        );

        $commit = (new MapPbsInventorySnapshot())->map(
            $this->inventoryId('r'),
            $this->read($snapshot),
            new DateTimeImmutable('2026-07-11T12:00:00Z'),
        );

        self::assertSame($expected, $commit->systemScope->status);
    }

    /** @return iterable<string, array{?PbsNodeStatus, ?PbsInventoryIssueCode, InventoryScopeStatus}> */
    public static function systemStatusCases(): iterable
    {
        yield 'complete observation' => [self::nodeStatus(), null, InventoryScopeStatus::Complete];
        yield 'missing observation' => [null, null, InventoryScopeStatus::Partial];
        yield 'missing permission' => [null, PbsInventoryIssueCode::MissingSystemStatusPermission, InventoryScopeStatus::Partial];
        yield 'node status failure' => [null, PbsInventoryIssueCode::NodeStatusReadFailed, InventoryScopeStatus::Failed];
        yield 'identity failure' => [self::nodeStatus(), PbsInventoryIssueCode::IdentityReadFailed, InventoryScopeStatus::Failed];
        yield 'datastore issue is unrelated' => [self::nodeStatus(), PbsInventoryIssueCode::MissingDatastorePermission, InventoryScopeStatus::Complete];
    }

    #[DataProvider('datastoreStatusCases')]
    public function testItDerivesTheInstallationDatastoreScope(
        PbsInventoryIssueCode $issueCode,
        InventoryScopeStatus $expected,
    ): void {
        $commit = (new MapPbsInventorySnapshot())->map(
            $this->inventoryId('r'),
            $this->read($this->snapshot(
                null,
                null,
                [],
                [],
                [new PbsInventoryIssue($issueCode, '/api2/json/config/datastore')],
            )),
            new DateTimeImmutable('2026-07-11T12:00:00Z'),
        );

        self::assertSame($expected, $commit->datastoreScope->status);
    }

    /** @return iterable<string, array{PbsInventoryIssueCode, InventoryScopeStatus}> */
    public static function datastoreStatusCases(): iterable
    {
        yield 'configuration read failed' => [PbsInventoryIssueCode::ConfigurationReadFailed, InventoryScopeStatus::Failed];
        yield 'definition list failed' => [PbsInventoryIssueCode::DatastoreListReadFailed, InventoryScopeStatus::Failed];
        yield 'fanout exceeded' => [PbsInventoryIssueCode::DatastoreFanoutExceeded, InventoryScopeStatus::Failed];
        yield 'missing datastore permission' => [PbsInventoryIssueCode::MissingDatastorePermission, InventoryScopeStatus::Partial];
        yield 'configuration changed' => [PbsInventoryIssueCode::ConfigurationChanged, InventoryScopeStatus::Partial];
        yield 'system permission is unrelated' => [PbsInventoryIssueCode::MissingSystemStatusPermission, InventoryScopeStatus::Complete];
        yield 'node status is unrelated' => [PbsInventoryIssueCode::NodeStatusReadFailed, InventoryScopeStatus::Complete];
        yield 'identity is unrelated' => [PbsInventoryIssueCode::IdentityReadFailed, InventoryScopeStatus::Complete];
    }

    public function testPartialEvidenceKeepsOnlyStableExpectedSafePositives(): void
    {
        $safe = $this->definition('safe_store', PbsDatastoreBackendType::Filesystem);
        $missingCapacity = $this->definition('missing_capacity', PbsDatastoreBackendType::Filesystem);
        $targetFailure = $this->definition('target_failure', PbsDatastoreBackendType::Filesystem);
        $otherFailure = $this->definition('other_failure', PbsDatastoreBackendType::Filesystem);
        $unexpected = $this->definition('unexpected_store', PbsDatastoreBackendType::Filesystem);
        $configuration = $this->configuration([
            $safe->id,
            $missingCapacity->id,
            $targetFailure->id,
            $otherFailure->id,
        ]);
        $snapshot = $this->snapshot(
            $configuration,
            $configuration,
            [$unexpected, $otherFailure, $targetFailure, $missingCapacity, $safe],
            [
                $this->capacity($unexpected->id, PbsDatastoreBackendType::Filesystem),
                $this->capacity($safe->id, PbsDatastoreBackendType::Filesystem),
            ],
            [new PbsInventoryIssue(
                PbsInventoryIssueCode::DatastoreStatusReadFailed,
                '/api2/json/status/datastore/target_failure',
                'target_failure',
            )],
        );

        $commit = (new MapPbsInventorySnapshot())->map(
            $this->inventoryId('r'),
            $this->read($snapshot),
            new DateTimeImmutable('2026-07-11T12:00:00Z'),
        );

        self::assertSame(
            ['missing_capacity', 'other_failure', 'safe_store', 'target_failure'],
            array_column($commit->datastores, 'id'),
        );
        self::assertSame(['safe_store'], array_column($commit->capacities, 'datastoreId'));
        self::assertSame([
            InventoryScopeStatus::Partial,
            InventoryScopeStatus::Partial,
            InventoryScopeStatus::Complete,
            InventoryScopeStatus::Failed,
        ], array_column($commit->statusScopes, 'status'));
        self::assertSame('partial', $commit->overallStatus());
        self::assertFalse($commit->isFullyAuthoritative());
    }

    public function testFanoutFailureMarksEveryObservedStoreStatusFailedWithoutInventingCapacity(): void
    {
        $store = $this->definition('safe_store', PbsDatastoreBackendType::Filesystem);
        $configuration = $this->configuration([$store->id]);
        $snapshot = $this->snapshot(
            $configuration,
            $configuration,
            [$store],
            [],
            [new PbsInventoryIssue(PbsInventoryIssueCode::DatastoreFanoutExceeded, '/status/datastore')],
        );

        $commit = (new MapPbsInventorySnapshot())->map(
            $this->inventoryId('r'),
            $this->read($snapshot),
            new DateTimeImmutable('2026-07-11T12:00:00Z'),
        );

        self::assertSame(InventoryScopeStatus::Failed, $commit->datastoreScope->status);
        self::assertSame(InventoryScopeStatus::Failed, $commit->statusScopes[0]->status);
        self::assertSame([], $commit->capacities);
    }

    public function testMissingOrChangedConfigurationBoundariesDiscardAllDatastoreEvidence(): void
    {
        $store = $this->definition('safe_store', PbsDatastoreBackendType::Filesystem);
        $configuration = $this->configuration([$store->id], 'digest-a');
        $changed = $this->configuration([$store->id], 'digest-b');

        foreach ([
            [null, $configuration, PbsInventoryIssueCode::MissingDatastoreConfiguration],
            [$configuration, null, PbsInventoryIssueCode::MissingDatastoreConfiguration],
            [$configuration, $changed, PbsInventoryIssueCode::ConfigurationChanged],
        ] as [$start, $end, $issueCode]) {
            $commit = (new MapPbsInventorySnapshot())->map(
                $this->inventoryId('r'),
                $this->read($this->snapshot(
                    $start,
                    $end,
                    [$store],
                    [$this->capacity($store->id, PbsDatastoreBackendType::Filesystem)],
                    [new PbsInventoryIssue($issueCode, '/api2/json/config/datastore')],
                )),
                new DateTimeImmutable('2026-07-11T12:00:00Z'),
            );

            self::assertSame([], $commit->datastores);
            self::assertSame([], $commit->statusScopes);
            self::assertSame([], $commit->capacities);
            self::assertSame('partial', $commit->overallStatus());
        }
    }

    public function testItRejectsAnExplicitDatastoreScope(): void
    {
        $store = new PbsDatastoreId('safe_store');
        $snapshot = $this->snapshot(null, null, [], [], [], scope: PbsDatastoreScanScope::explicit([$store]));

        $this->expectException(PbsInventoryMappingFailure::class);
        (new MapPbsInventorySnapshot())->map(
            $this->inventoryId('r'),
            $this->read($snapshot),
            new DateTimeImmutable('2026-07-11T12:00:00Z'),
        );
    }

    public function testItWrapsAnUnsupportedVersion(): void
    {
        $snapshot = new PbsInstallationSnapshot(
            new PbsVersion(5, 0, 0, '5.0.0', '5.0', 'repo'),
            null,
            null,
            PbsDatastoreScanScope::installationWide(),
            null,
            null,
            [],
            [],
            [],
        );

        $this->expectException(PbsInventoryMappingFailure::class);
        (new MapPbsInventorySnapshot())->map(
            $this->inventoryId('r'),
            $this->read($snapshot),
            new DateTimeImmutable('2026-07-11T12:00:00Z'),
        );
    }

    /**
     * @param list<PbsDatastoreDefinition> $definitions
     * @param list<PbsDatastoreCapacity>   $capacities
     * @param list<PbsInventoryIssue>      $issues
     */
    private function snapshot(
        ?PbsDatastoreConfigurationSnapshot $start,
        ?PbsDatastoreConfigurationSnapshot $end,
        array $definitions,
        array $capacities,
        array $issues = [],
        PbsNodeStatus|false|null $status = false,
        ?PbsDatastoreScanScope $scope = null,
    ): PbsInstallationSnapshot {
        return new PbsInstallationSnapshot(
            self::version(),
            false === $status ? self::nodeStatus() : $status,
            null,
            $scope ?? PbsDatastoreScanScope::installationWide(),
            $start,
            $end,
            $definitions,
            $capacities,
            $issues,
        );
    }

    private function read(PbsInstallationSnapshot $snapshot): ConnectionInstallationRead
    {
        $endpoint = new EndpointId(str_repeat('e', 16));

        return new ConnectionInstallationRead(
            new ConnectionId(str_repeat('c', 16)),
            7,
            $endpoint,
            InstallationBinding::pbsLegacyEndpoint($endpoint),
            $snapshot,
        );
    }

    private function definition(
        string $id,
        PbsDatastoreBackendType $backend,
        PbsMountStatus $mount = PbsMountStatus::Mounted,
        ?PbsMaintenanceMode $maintenance = null,
    ): PbsDatastoreDefinition {
        return new PbsDatastoreDefinition(new PbsDatastoreId($id), $backend, $mount, $maintenance);
    }

    /** @param list<PbsDatastoreId> $ids */
    private function configuration(array $ids, string $digest = 'digest'): PbsDatastoreConfigurationSnapshot
    {
        return new PbsDatastoreConfigurationSnapshot($digest, $ids);
    }

    private function capacity(
        PbsDatastoreId $id,
        PbsDatastoreBackendType $backend,
        int $total = 100,
        int $used = 20,
        int $available = 80,
    ): PbsDatastoreCapacity {
        return new PbsDatastoreCapacity($id, $backend, $total, $used, $available);
    }

    private static function version(): PbsVersion
    {
        return new PbsVersion(3, 4, 2, '3.4.2', '3.4', 'repo');
    }

    private static function nodeStatus(): PbsNodeStatus
    {
        return new PbsNodeStatus(PbsNodeRoute::Local->value, 10, 100, 20, 200, 30, 170);
    }

    private function inventoryId(string $byte): InventoryIdentifier
    {
        return new InventoryIdentifier(str_repeat($byte, 16));
    }
}
