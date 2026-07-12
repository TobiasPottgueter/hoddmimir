<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Inventory\Pbs;

use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\InstallationBinding;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\Pbs\PbsCapacityObservation;
use App\Application\Inventory\Pbs\PbsDatastoreObservation;
use App\Application\Inventory\Pbs\PbsInventoryCommit;
use App\Application\Inventory\Pbs\PbsInventoryApplyResult;
use App\Application\Inventory\Pbs\PbsInventoryApplyStatus;
use App\Application\Inventory\Pbs\PbsInventoryScope;
use App\Application\Inventory\Pbs\PbsInventoryScopeResult;
use App\Application\Inventory\Pbs\PbsServerObservation;
use App\Application\Inventory\Pve\InventoryScopeStatus;
use App\Application\Proxmox\Pbs\PbsCapacitySemantics;
use App\Application\Proxmox\Pbs\PbsDatastoreBackendType;
use App\Application\Proxmox\Pbs\PbsDatastoreCapacity;
use App\Application\Proxmox\Pbs\PbsDatastoreDefinition;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsMaintenanceMode;
use App\Application\Proxmox\Pbs\PbsMountStatus;
use App\Application\Proxmox\Pbs\PbsNodeStatus;
use App\Application\Proxmox\Pbs\PbsVersion;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PbsInventoryModelTest extends TestCase
{
    public function testApplyResultUsesTypedStatusAndRejectsNegativeCounters(): void
    {
        $result = new PbsInventoryApplyResult(PbsInventoryApplyStatus::Partial, 1, 2, 3, false);
        self::assertSame('partial', $result->status);

        foreach ([[-1, 0, 0], [0, -1, 0], [0, 0, -1]] as [$created, $updated, $archived]) {
            try {
                new PbsInventoryApplyResult(
                    PbsInventoryApplyStatus::Failed,
                    $created,
                    $updated,
                    $archived,
                    true,
                );
                self::fail('Negative PBS apply counters were accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testScopeResultsEnforceTheirKeyDomains(): void
    {
        $system = new PbsInventoryScopeResult(
            PbsInventoryScope::System,
            '@installation',
            InventoryScopeStatus::Complete,
        );
        $datastores = new PbsInventoryScopeResult(
            PbsInventoryScope::Datastores,
            '@installation',
            InventoryScopeStatus::Partial,
        );
        $status = new PbsInventoryScopeResult(
            PbsInventoryScope::DatastoreStatus,
            'store_a',
            InventoryScopeStatus::Failed,
        );

        self::assertTrue($system->isComplete());
        self::assertFalse($datastores->isComplete());
        self::assertFalse($status->isComplete());

        foreach ([
            static fn (): PbsInventoryScopeResult => new PbsInventoryScopeResult(
                PbsInventoryScope::System,
                'store_a',
                InventoryScopeStatus::Complete,
            ),
            static fn (): PbsInventoryScopeResult => new PbsInventoryScopeResult(
                PbsInventoryScope::Datastores,
                'store_a',
                InventoryScopeStatus::Complete,
            ),
            static fn (): PbsInventoryScopeResult => new PbsInventoryScopeResult(
                PbsInventoryScope::DatastoreStatus,
                '@installation',
                InventoryScopeStatus::Complete,
            ),
            static fn (): PbsInventoryScopeResult => new PbsInventoryScopeResult(
                PbsInventoryScope::DatastoreStatus,
                'bad store',
                InventoryScopeStatus::Complete,
            ),
        ] as $invalidScope) {
            try {
                $invalidScope();
                self::fail('The invalid scope key was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testServerObservationAcceptsSupportedBoundedEvidence(): void
    {
        $status = self::nodeStatus();
        $observation = new PbsServerObservation('pbs-node', self::version(), $status);
        $withoutStatus = new PbsServerObservation('pbs-node', self::version(4), null);

        self::assertSame($status, $observation->status);
        self::assertNull($withoutStatus->status);
    }

    #[DataProvider('invalidServerCases')]
    public function testServerObservationRejectsInvalidIdentityVersionAndResourceBounds(
        string $node,
        PbsVersion $version,
        ?PbsNodeStatus $status,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        new PbsServerObservation($node, $version, $status);
    }

    /** @return iterable<string, array{string, PbsVersion, ?PbsNodeStatus}> */
    public static function invalidServerCases(): iterable
    {
        yield 'invalid node identity' => ['bad node', self::version(), null];
        yield 'unsupported version' => ['pbs-node', self::version(5), null];
        yield 'status belongs to another node' => ['pbs-node', self::version(), self::nodeStatus(node: 'other-node')];
        yield 'negative uptime' => ['pbs-node', self::version(), self::nodeStatus(uptime: -1)];
        yield 'negative memory total' => ['pbs-node', self::version(), self::nodeStatus(memoryTotal: -1)];
        yield 'negative memory used' => ['pbs-node', self::version(), self::nodeStatus(memoryUsed: -1)];
        yield 'negative root total' => ['pbs-node', self::version(), self::nodeStatus(rootTotal: -1)];
        yield 'negative root used' => ['pbs-node', self::version(), self::nodeStatus(rootUsed: -1)];
        yield 'negative root available' => ['pbs-node', self::version(), self::nodeStatus(rootAvailable: -1)];
        yield 'memory used beyond total' => ['pbs-node', self::version(), self::nodeStatus(memoryTotal: 10, memoryUsed: 11)];
        yield 'root used beyond total' => ['pbs-node', self::version(), self::nodeStatus(rootTotal: 10, rootUsed: 11, rootAvailable: 0)];
        yield 'root available beyond total' => ['pbs-node', self::version(), self::nodeStatus(rootTotal: 10, rootUsed: 0, rootAvailable: 11)];
    }

    public function testDatastoreObservationsDeriveBackendMountMaintenanceAndWriteability(): void
    {
        $mounted = new PbsDatastoreDefinition(
            new PbsDatastoreId('mounted_store'),
            PbsDatastoreBackendType::Filesystem,
            PbsMountStatus::Mounted,
            null,
        );
        $nonRemovable = new PbsDatastoreDefinition(
            new PbsDatastoreId('fixed_store'),
            PbsDatastoreBackendType::S3,
            PbsMountStatus::NonRemovable,
            null,
        );
        $notMounted = new PbsDatastoreDefinition(
            new PbsDatastoreId('offline_store'),
            PbsDatastoreBackendType::Filesystem,
            PbsMountStatus::NotMounted,
            null,
        );
        $maintenance = new PbsDatastoreDefinition(
            new PbsDatastoreId('maintenance_store'),
            PbsDatastoreBackendType::Filesystem,
            PbsMountStatus::Mounted,
            PbsMaintenanceMode::ReadOnly,
        );

        $observations = array_map(PbsDatastoreObservation::fromDefinition(...), [
            $mounted,
            $nonRemovable,
            $notMounted,
            $maintenance,
        ]);

        self::assertSame([true, true, false, false], array_column($observations, 'allowsBackupWrites'));
        self::assertSame(PbsDatastoreBackendType::S3, $observations[1]->backendType);
        self::assertSame(PbsMountStatus::NotMounted, $observations[2]->mountStatus);
        self::assertSame(PbsMaintenanceMode::ReadOnly, $observations[3]->maintenanceMode);
    }

    #[DataProvider('invalidWriteabilityCases')]
    public function testDatastoreObservationRejectsInconsistentWriteability(
        PbsMountStatus $mount,
        ?PbsMaintenanceMode $maintenance,
        bool $allowsWrites,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        new PbsDatastoreObservation(
            'store_a',
            PbsDatastoreBackendType::Filesystem,
            $mount,
            $maintenance,
            $allowsWrites,
        );
    }

    /** @return iterable<string, array{PbsMountStatus, ?PbsMaintenanceMode, bool}> */
    public static function invalidWriteabilityCases(): iterable
    {
        yield 'available without maintenance denied' => [PbsMountStatus::Mounted, null, false];
        yield 'not mounted allowed' => [PbsMountStatus::NotMounted, null, true];
        yield 'maintenance allowed' => [PbsMountStatus::Mounted, PbsMaintenanceMode::Offline, true];
    }

    public function testCapacityObservationUsesFilesystemAndS3LocalCacheSemantics(): void
    {
        $filesystemCapacity = new PbsDatastoreCapacity(
            new PbsDatastoreId('filesystem_store'),
            PbsDatastoreBackendType::Filesystem,
            100,
            20,
            80,
        );
        $s3Capacity = new PbsDatastoreCapacity(
            new PbsDatastoreId('s3_store'),
            PbsDatastoreBackendType::S3,
            200,
            50,
            150,
        );

        $filesystem = PbsCapacityObservation::fromCapacity($filesystemCapacity);
        $s3 = PbsCapacityObservation::fromCapacity($s3Capacity);

        self::assertSame(PbsCapacitySemantics::DatastoreFilesystem, $filesystem->semantics);
        self::assertSame(PbsCapacitySemantics::LocalCache, $s3->semantics);
        self::assertSame(200, $s3->totalBytes);
    }

    #[DataProvider('invalidCapacityCases')]
    public function testCapacityObservationRejectsInvalidSemanticsAndBounds(
        PbsDatastoreBackendType $backend,
        PbsCapacitySemantics $semantics,
        int $total,
        int $used,
        int $available,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        new PbsCapacityObservation('store_a', $backend, $semantics, $total, $used, $available);
    }

    /** @return iterable<string, array{PbsDatastoreBackendType, PbsCapacitySemantics, int, int, int}> */
    public static function invalidCapacityCases(): iterable
    {
        yield 'filesystem cannot be local cache' => [PbsDatastoreBackendType::Filesystem, PbsCapacitySemantics::LocalCache, 10, 1, 9];
        yield 's3 must be local cache' => [PbsDatastoreBackendType::S3, PbsCapacitySemantics::DatastoreFilesystem, 10, 1, 9];
        yield 'negative total' => [PbsDatastoreBackendType::Filesystem, PbsCapacitySemantics::DatastoreFilesystem, -1, 0, 0];
        yield 'negative used' => [PbsDatastoreBackendType::Filesystem, PbsCapacitySemantics::DatastoreFilesystem, 10, -1, 9];
        yield 'negative available' => [PbsDatastoreBackendType::Filesystem, PbsCapacitySemantics::DatastoreFilesystem, 10, 1, -1];
        yield 'used exceeds total' => [PbsDatastoreBackendType::Filesystem, PbsCapacitySemantics::DatastoreFilesystem, 10, 11, 0];
        yield 'available exceeds total' => [PbsDatastoreBackendType::Filesystem, PbsCapacitySemantics::DatastoreFilesystem, 10, 0, 11];
    }

    public function testObservationIdentifiersAreValidated(): void
    {
        foreach ([
            static fn (): PbsDatastoreObservation => new PbsDatastoreObservation(
                'bad store',
                PbsDatastoreBackendType::Filesystem,
                PbsMountStatus::Mounted,
                null,
                true,
            ),
            static fn (): PbsCapacityObservation => new PbsCapacityObservation(
                'bad store',
                PbsDatastoreBackendType::Filesystem,
                PbsCapacitySemantics::DatastoreFilesystem,
                10,
                1,
                9,
            ),
        ] as $invalidObservation) {
            try {
                $invalidObservation();
                self::fail('The invalid datastore identifier was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testCommitSortsExactEvidenceSetsAndRecognizesFullAuthority(): void
    {
        $storeA = $this->store('store_a');
        $storeB = $this->store('store_b', PbsDatastoreBackendType::S3);
        $commit = $this->commit(
            statusScopes: [$this->statusScope('store_b'), $this->statusScope('store_a')],
            datastores: [$storeB, $storeA],
            capacities: [
                $this->capacityObservation('store_b', PbsDatastoreBackendType::S3),
                $this->capacityObservation('store_a'),
            ],
        );

        self::assertSame(['store_a', 'store_b'], array_column($commit->statusScopes, 'key'));
        self::assertSame(['store_a', 'store_b'], array_column($commit->datastores, 'id'));
        self::assertSame(['store_a', 'store_b'], array_column($commit->capacities, 'datastoreId'));
        self::assertTrue($commit->isFullyAuthoritative());
        self::assertSame('succeeded', $commit->overallStatus());
    }

    #[DataProvider('nonAuthoritativeCommitCases')]
    public function testCommitRequiresEveryAuthorityCondition(string $case): void
    {
        $store = $this->store('store_a');
        $systemStatus = 'system-partial' === $case ? InventoryScopeStatus::Partial : InventoryScopeStatus::Complete;
        $datastoreStatus = 'datastores-partial' === $case ? InventoryScopeStatus::Partial : InventoryScopeStatus::Complete;
        $server = new PbsServerObservation(
            'pbs-node',
            self::version(),
            'missing-server-status' === $case ? null : self::nodeStatus(),
        );
        $scopes = 'missing-scope-id' === $case
            ? []
            : [$this->statusScope('store_a', 'status-partial' === $case ? InventoryScopeStatus::Partial : InventoryScopeStatus::Complete)];
        $capacities = 'missing-capacity-id' === $case ? [] : [$this->capacityObservation('store_a')];

        $commit = $this->commit(
            systemStatus: $systemStatus,
            datastoreStatus: $datastoreStatus,
            statusScopes: $scopes,
            server: $server,
            datastores: [$store],
            capacities: $capacities,
        );

        self::assertFalse($commit->isFullyAuthoritative());
        self::assertSame('partial', $commit->overallStatus());
    }

    /** @return iterable<string, array{string}> */
    public static function nonAuthoritativeCommitCases(): iterable
    {
        yield 'system scope incomplete' => ['system-partial'];
        yield 'datastore scope incomplete' => ['datastores-partial'];
        yield 'server status absent' => ['missing-server-status'];
        yield 'status ID set differs' => ['missing-scope-id'];
        yield 'capacity ID set differs' => ['missing-capacity-id'];
        yield 'individual status incomplete' => ['status-partial'];
    }

    public function testOverallStatusIsFailedOnlyForTwoFailedGlobalScopesWithoutSafePositives(): void
    {
        $failed = $this->commit(
            systemStatus: InventoryScopeStatus::Failed,
            datastoreStatus: InventoryScopeStatus::Failed,
        );
        $withPositive = $this->commit(
            systemStatus: InventoryScopeStatus::Failed,
            datastoreStatus: InventoryScopeStatus::Failed,
            statusScopes: [$this->statusScope('store_a', InventoryScopeStatus::Failed)],
            datastores: [$this->store('store_a')],
        );

        self::assertSame('failed', $failed->overallStatus());
        self::assertSame('partial', $withPositive->overallStatus());
    }

    #[DataProvider('invalidCommitCases')]
    public function testCommitRejectsInvalidHeadersAndEvidenceRelationships(string $case): void
    {
        $store = $this->store('store_a');
        $statusScopes = [$this->statusScope('store_a')];
        $datastores = [$store];
        $capacities = [$this->capacityObservation('store_a')];
        $revision = 1;
        $binding = InstallationBinding::pbsLegacyNode('pbs-node', new EndpointId(str_repeat('e', 16)));
        $systemScope = new PbsInventoryScopeResult(PbsInventoryScope::System, '@installation', InventoryScopeStatus::Complete);
        $datastoreScope = new PbsInventoryScopeResult(PbsInventoryScope::Datastores, '@installation', InventoryScopeStatus::Complete);

        match ($case) {
            'revision' => $revision = 0,
            'system-scope' => $systemScope = $datastoreScope,
            'datastore-scope' => $datastoreScope = $systemScope,
            'binding-product' => $binding = InstallationBinding::pveStandalone('pve-node'),
            'scope-type' => $statusScopes = [$systemScope],
            'duplicate-scope' => $statusScopes[] = $statusScopes[0],
            'duplicate-store' => $datastores[] = $store,
            'orphan-capacity' => $capacities = [$this->capacityObservation('other_store')],
            'backend-mismatch' => $capacities = [$this->capacityObservation('store_a', PbsDatastoreBackendType::S3)],
            'duplicate-capacity' => $capacities[] = $capacities[0],
            default => self::fail('Unknown invalid commit case.'),
        };

        $this->expectException(InvalidArgumentException::class);
        $this->newCommit(
            $revision,
            $binding,
            $systemScope,
            $datastoreScope,
            $statusScopes,
            new PbsServerObservation('pbs-node', self::version(), self::nodeStatus()),
            $datastores,
            $capacities,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCommitCases(): iterable
    {
        yield 'invalid revision' => ['revision'];
        yield 'wrong system scope' => ['system-scope'];
        yield 'wrong datastore scope' => ['datastore-scope'];
        yield 'non PBS binding' => ['binding-product'];
        yield 'wrong per-store scope' => ['scope-type'];
        yield 'duplicate per-store scope' => ['duplicate-scope'];
        yield 'duplicate datastore' => ['duplicate-store'];
        yield 'capacity without datastore' => ['orphan-capacity'];
        yield 'capacity backend differs' => ['backend-mismatch'];
        yield 'duplicate capacity' => ['duplicate-capacity'];
    }

    /**
     * @param list<PbsInventoryScopeResult> $statusScopes
     * @param list<PbsDatastoreObservation> $datastores
     * @param list<PbsCapacityObservation>  $capacities
     */
    private function commit(
        InventoryScopeStatus $systemStatus = InventoryScopeStatus::Complete,
        InventoryScopeStatus $datastoreStatus = InventoryScopeStatus::Complete,
        array $statusScopes = [],
        ?PbsServerObservation $server = null,
        array $datastores = [],
        array $capacities = [],
    ): PbsInventoryCommit {
        $endpoint = new EndpointId(str_repeat('e', 16));

        return $this->newCommit(
            1,
            InstallationBinding::pbsLegacyNode('pbs-node', $endpoint),
            new PbsInventoryScopeResult(PbsInventoryScope::System, '@installation', $systemStatus),
            new PbsInventoryScopeResult(PbsInventoryScope::Datastores, '@installation', $datastoreStatus),
            $statusScopes,
            $server ?? new PbsServerObservation('pbs-node', self::version(), self::nodeStatus()),
            $datastores,
            $capacities,
        );
    }

    /**
     * @param list<PbsInventoryScopeResult> $statusScopes
     * @param list<PbsDatastoreObservation> $datastores
     * @param list<PbsCapacityObservation>  $capacities
     */
    private function newCommit(
        int $revision,
        InstallationBinding $binding,
        PbsInventoryScopeResult $systemScope,
        PbsInventoryScopeResult $datastoreScope,
        array $statusScopes,
        PbsServerObservation $server,
        array $datastores,
        array $capacities,
    ): PbsInventoryCommit {
        return new PbsInventoryCommit(
            new InventoryIdentifier(str_repeat('r', 16)),
            new InventoryIdentifier(str_repeat('c', 16)),
            new InventoryIdentifier(str_repeat('e', 16)),
            $revision,
            $binding,
            $systemScope,
            $datastoreScope,
            $statusScopes,
            $server,
            $datastores,
            $capacities,
            new DateTimeImmutable('2026-07-11T12:00:00Z'),
        );
    }

    private function statusScope(
        string $id,
        InventoryScopeStatus $status = InventoryScopeStatus::Complete,
    ): PbsInventoryScopeResult {
        return new PbsInventoryScopeResult(PbsInventoryScope::DatastoreStatus, $id, $status);
    }

    private function store(
        string $id,
        PbsDatastoreBackendType $backend = PbsDatastoreBackendType::Filesystem,
    ): PbsDatastoreObservation {
        return new PbsDatastoreObservation($id, $backend, PbsMountStatus::Mounted, null, true);
    }

    private function capacityObservation(
        string $id,
        PbsDatastoreBackendType $backend = PbsDatastoreBackendType::Filesystem,
    ): PbsCapacityObservation {
        return new PbsCapacityObservation(
            $id,
            $backend,
            PbsDatastoreBackendType::S3 === $backend
                ? PbsCapacitySemantics::LocalCache
                : PbsCapacitySemantics::DatastoreFilesystem,
            100,
            20,
            80,
        );
    }

    private static function version(int $major = 3): PbsVersion
    {
        return new PbsVersion($major, 4, 2, $major.'.4.2', $major.'.4', 'repo');
    }

    private static function nodeStatus(
        string $node = 'pbs-node',
        int $uptime = 10,
        int $memoryTotal = 100,
        int $memoryUsed = 20,
        int $rootTotal = 200,
        int $rootUsed = 30,
        int $rootAvailable = 170,
    ): PbsNodeStatus {
        return new PbsNodeStatus(
            $node,
            $uptime,
            $memoryTotal,
            $memoryUsed,
            $rootTotal,
            $rootUsed,
            $rootAvailable,
        );
    }
}
