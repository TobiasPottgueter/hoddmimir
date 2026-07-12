<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\EndpointReadFailure;
use App\Application\Inventory\Connection\EndpointReadFailureCode;
use App\Application\Inventory\Connection\PveEndpointReadFailureMapper;
use App\Application\Proxmox\Pbs\PbsAclEvidence;
use App\Application\Proxmox\Pbs\PbsEffectivePermission;
use App\Application\Proxmox\Pbs\PbsJobKind;
use App\Application\Proxmox\Pbs\PbsJobListSnapshot;
use App\Application\Proxmox\Pbs\PbsTaskListQuery;
use App\Application\Proxmox\Pbs\PbsTaskPage as PbsTaskPage;
use App\Application\Proxmox\Pbs\PbsTasksAndJobsLimits;
use App\Application\Proxmox\Pbs\PbsTaskWindow;
use App\Application\Proxmox\Pve\PveBackupInventoryLimits;
use App\Application\Proxmox\Pve\PveBackupJobCapabilities;
use App\Application\Proxmox\Pve\PveBackupJobInventory;
use App\Application\Proxmox\Pve\PveClusterTopology;
use App\Application\Proxmox\Pve\PveNodeStorageStatusSet;
use App\Application\Proxmox\Pve\PvePermissionAssessment;
use App\Application\Proxmox\Pve\PveReadClient;
use App\Application\Proxmox\Pve\PveReadConnector;
use App\Application\Proxmox\Pve\PveResourceInventory;
use App\Application\Proxmox\Pve\PveStorageConfigurationSet;
use App\Application\Proxmox\Pve\PveTaskArchiveWindow;
use App\Application\Proxmox\Pve\PveTaskPage;
use App\Application\Proxmox\Pve\PveTaskQuery;
use App\Application\Proxmox\Pve\PveTaskStatus;
use App\Application\Proxmox\Pve\PveUpid;
use App\Application\Proxmox\Pve\PveVersion;
use App\Application\Security\EncryptedSecret;
use App\Application\Security\SecretContext;
use App\Application\Security\SecretPurpose;
use App\Domain\Shared\Clock;
use App\Infrastructure\Proxmox\NativeSelectedEndpointMonitoringReader;
use App\Infrastructure\Proxmox\Pbs\PbsApiTokenIdentity;
use App\Infrastructure\Proxmox\Pbs\PbsEndpointReadConfiguration;
use App\Infrastructure\Proxmox\Pbs\PbsEndpointReadConfigurationSource;
use App\Infrastructure\Proxmox\Pbs\PbsMonitoringClient;
use App\Infrastructure\Proxmox\Pbs\PbsMonitoringClientFactory;
use App\Infrastructure\Proxmox\Pbs\PbsReadConnectorFactoryFailure;
use App\Infrastructure\Proxmox\Pbs\PbsTlsConfiguration;
use App\Infrastructure\Proxmox\PveApiTokenIdentity;
use App\Infrastructure\Proxmox\PveCoreReadConnectorFactory;
use App\Infrastructure\Proxmox\PveCoreReadConnectorFactoryFailure;
use App\Infrastructure\Proxmox\PveEndpointReadConfiguration;
use App\Infrastructure\Proxmox\PveEndpointReadConfigurationSource;
use App\Infrastructure\Proxmox\PveTlsConfiguration;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class NativeSelectedEndpointMonitoringReaderTest extends TestCase
{
    public function testPveUsesExactConfigurationAndSuppliedWindow(): void
    {
        $configuration = self::pveConfiguration();
        $source = new FixedNativePveConfigurationSource($configuration);
        $factory = new FixedNativePveConnectorFactory(new EmptyNativePveConnector());
        $reader = $this->reader($source, $factory);
        $window = new PveTaskArchiveWindow(100, 200);

        $snapshot = $reader->readPve(
            self::connectionId(), self::endpointId(), 7, ['pve-a'], $window, new NativeMonitoringCheckpoint(),
        );

        self::assertSame(1, $source->loads);
        self::assertSame(7, $source->revision);
        self::assertSame($configuration, $factory->configuration);
        self::assertSame($window, $snapshot->archiveWindow);
        self::assertCount(2, $snapshot->taskStreams);
    }

    public function testPbsUsesOneClientAndKeepsIndependentFamilyFailures(): void
    {
        $client = new FixedNativePbsMonitoringClient(failSync: true);
        $factory = new FixedNativePbsMonitoringFactory($client);
        $reader = $this->reader(pbsFactory: $factory);

        $snapshot = $reader->readPbs(
            self::connectionId(), self::endpointId(), 3, 'pbs-a', new PbsTaskWindow(100, 200),
            new NativeMonitoringCheckpoint(),
        );

        self::assertSame(1, $factory->creates);
        self::assertNotNull($snapshot->pruneJobs);
        self::assertNull($snapshot->syncJobs);
        self::assertNotNull($snapshot->verifyJobs);
        self::assertNotNull($snapshot->tasks);
        self::assertSame('invalid_response', $snapshot->errors['sync']);
        self::assertSame(8, $client->pages);
    }

    public function testConnectorConstructionFailuresMapToTls(): void
    {
        $pve = $this->reader(pveFactory: new FixedNativePveConnectorFactory(new PveCoreReadConnectorFactoryFailure()));
        try {
            $pve->readPve(
                self::connectionId(), self::endpointId(), 1, ['pve-a'], new PveTaskArchiveWindow(100, 200),
                new NativeMonitoringCheckpoint(),
            );
            self::fail('PVE connector failure was accepted.');
        } catch (EndpointReadFailure $failure) {
            self::assertSame(EndpointReadFailureCode::Tls, $failure->failureCode);
        }

        $pbs = $this->reader(pbsFactory: new FixedNativePbsMonitoringFactory(new PbsReadConnectorFactoryFailure()));
        $this->expectException(EndpointReadFailure::class);
        $pbs->readPbs(
            self::connectionId(), self::endpointId(), 1, 'pbs-a', new PbsTaskWindow(100, 200),
            new NativeMonitoringCheckpoint(),
        );
    }

    public function testPveReadFailureIsMappedAndEveryPbsFamilyFailureRemainsIsolated(): void
    {
        $pve = $this->reader(
            pveFactory: new FixedNativePveConnectorFactory(new FailingNativePveConnector()),
        );
        try {
            $pve->readPve(
                self::connectionId(), self::endpointId(), 1, ['pve-a'], new PveTaskArchiveWindow(100, 200),
                new NativeMonitoringCheckpoint(),
            );
            self::fail('PVE read failure was accepted.');
        } catch (EndpointReadFailure $failure) {
            self::assertSame(EndpointReadFailureCode::RootUnusable, $failure->failureCode);
        }

        $pbs = $this->reader(
            pbsFactory: new FixedNativePbsMonitoringFactory(new FixedNativePbsMonitoringClient(
                failures: ['acl', 'prune', 'verify'],
            )),
        )->readPbs(
            self::connectionId(), self::endpointId(), 1, 'pbs-a', new PbsTaskWindow(100, 200),
            new NativeMonitoringCheckpoint(),
        );
        self::assertNull($pbs->acl);
        self::assertNull($pbs->pruneJobs);
        self::assertNull($pbs->verifyJobs);
        self::assertSame(
            ['acl' => 'invalid_response', 'prune' => 'invalid_response', 'verify' => 'invalid_response'],
            $pbs->errors,
        );
    }

    private function reader(
        ?PveEndpointReadConfigurationSource $pveSource = null,
        ?PveCoreReadConnectorFactory $pveFactory = null,
        ?PbsMonitoringClientFactory $pbsFactory = null,
    ): NativeSelectedEndpointMonitoringReader {
        return new NativeSelectedEndpointMonitoringReader(
            $pveSource ?? new FixedNativePveConfigurationSource(self::pveConfiguration()),
            $pveFactory ?? new FixedNativePveConnectorFactory(new EmptyNativePveConnector()),
            new PveEndpointReadFailureMapper(),
            new FixedNativePbsConfigurationSource(self::pbsConfiguration()),
            $pbsFactory ?? new FixedNativePbsMonitoringFactory(new FixedNativePbsMonitoringClient()),
            new NativeMonitoringClock(),
            new PveBackupInventoryLimits(),
            new PbsTasksAndJobsLimits(),
        );
    }

    private static function pveConfiguration(): PveEndpointReadConfiguration
    {
        return new PveEndpointReadConfiguration(
            'pve.example.test', 8006, PveTlsConfiguration::systemCa(),
            PveApiTokenIdentity::fromUserAndTokenId('collector@pve', 'inventory'),
            EncryptedSecret::fromEncoded('opaque'),
            SecretContext::forBinaryCredentialId(str_repeat('d', 16), SecretPurpose::PveCollectorToken),
        );
    }

    private static function pbsConfiguration(): PbsEndpointReadConfiguration
    {
        return new PbsEndpointReadConfiguration(
            'pbs.example.test', 8007, PbsTlsConfiguration::systemCa(),
            PbsApiTokenIdentity::fromParts('collector', 'pbs', 'inventory'),
            EncryptedSecret::fromEncoded('opaque'),
            SecretContext::forCredential('pbs-credential', SecretPurpose::PbsCollectorToken),
        );
    }

    private static function connectionId(): ConnectionId
    {
        return new ConnectionId(str_repeat('c', 16));
    }

    private static function endpointId(): EndpointId
    {
        return new EndpointId(str_repeat('e', 16));
    }
}

final class FixedNativePveConfigurationSource implements PveEndpointReadConfigurationSource
{
    public int $loads = 0;
    public int $revision = 0;
    public function __construct(private readonly PveEndpointReadConfiguration $configuration) {}
    public function load(ConnectionId $connectionId, EndpointId $endpointId, int $expectedRevision): PveEndpointReadConfiguration
    {
        ++$this->loads;
        $this->revision = $expectedRevision;
        return $this->configuration;
    }
}

final readonly class FixedNativePbsConfigurationSource implements PbsEndpointReadConfigurationSource
{
    public function __construct(private PbsEndpointReadConfiguration $configuration) {}
    public function load(ConnectionId $connectionId, EndpointId $endpointId, int $expectedRevision): PbsEndpointReadConfiguration
    {
        return $this->configuration;
    }
}

final class FixedNativePveConnectorFactory implements PveCoreReadConnectorFactory
{
    public ?PveEndpointReadConfiguration $configuration = null;
    public function __construct(private readonly PveReadConnector|\Throwable $outcome) {}
    public function create(PveEndpointReadConfiguration $configuration, ConnectionReadCheckpoint $checkpoint): PveReadConnector
    {
        $this->configuration = $configuration;
        if ($this->outcome instanceof \Throwable) {
            throw $this->outcome;
        }
        return $this->outcome;
    }
}

final readonly class EmptyNativePveConnector implements PveReadConnector
{
    public function connect(): PveReadClient { return new EmptyNativePveClient(); }
}

final readonly class FailingNativePveConnector implements PveReadConnector
{
    public function connect(): PveReadClient
    {
        return new FailingNativePveClient();
    }
}

final readonly class FailingNativePveClient implements PveReadClient
{
    public function version(): PveVersion
    {
        throw \App\Application\Proxmox\Pve\PveReadFailure::for(
            \App\Application\Proxmox\Pve\PveReadFailureCode::InvalidResponse,
        );
    }
    public function backupJobs(): PveBackupJobInventory
    {
        throw \App\Application\Proxmox\Pve\PveReadFailure::for(
            \App\Application\Proxmox\Pve\PveReadFailureCode::InvalidResponse,
        );
    }
    public function backupTaskPage(string $node, PveTaskQuery $query): PveTaskPage { throw new \LogicException('unused'); }
    public function permissions(): PvePermissionAssessment { throw new \LogicException('unused'); }
    public function topology(): PveClusterTopology { throw new \LogicException('unused'); }
    public function resources(): PveResourceInventory { throw new \LogicException('unused'); }
    public function storageConfigurations(): PveStorageConfigurationSet { throw new \LogicException('unused'); }
    public function nodeBackupStorages(string $node): PveNodeStorageStatusSet { throw new \LogicException('unused'); }
    public function backupTaskStatus(string $node, PveUpid $upid): PveTaskStatus { throw new \LogicException('forbidden'); }
}

final readonly class EmptyNativePveClient implements PveReadClient
{
    public function version(): PveVersion { return new PveVersion(9, 0, 0, '9.0', '9.0.0', 'repo'); }
    public function backupJobs(): PveBackupJobInventory
    {
        return new PveBackupJobInventory(PveBackupJobCapabilities::forMajor(9), [], []);
    }
    public function backupTaskPage(string $node, PveTaskQuery $query): PveTaskPage
    {
        return new PveTaskPage($query, 0, [], []);
    }
    public function permissions(): PvePermissionAssessment { throw new \LogicException('unused'); }
    public function topology(): PveClusterTopology { throw new \LogicException('unused'); }
    public function resources(): PveResourceInventory { throw new \LogicException('unused'); }
    public function storageConfigurations(): PveStorageConfigurationSet { throw new \LogicException('unused'); }
    public function nodeBackupStorages(string $node): PveNodeStorageStatusSet { throw new \LogicException('unused'); }
    public function backupTaskStatus(string $node, PveUpid $upid): PveTaskStatus { throw new \LogicException('forbidden'); }
}

final class FixedNativePbsMonitoringFactory implements PbsMonitoringClientFactory
{
    public int $creates = 0;
    public function __construct(private readonly PbsMonitoringClient|\Throwable $outcome) {}
    public function createMonitoringClient(
        PbsEndpointReadConfiguration $configuration,
        ConnectionReadCheckpoint $checkpoint,
        PbsTasksAndJobsLimits $limits,
    ): PbsMonitoringClient {
        ++$this->creates;
        if ($this->outcome instanceof \Throwable) {
            throw $this->outcome;
        }
        return $this->outcome;
    }
}

final class FixedNativePbsMonitoringClient implements PbsMonitoringClient
{
    public int $pages = 0;
    /** @param list<string> $failures */
    public function __construct(
        private readonly bool $failSync = false,
        private readonly array $failures = [],
    ) {}
    public function aclEvidence(): PbsAclEvidence
    {
        $this->failWhenConfigured('acl');
        return new PbsAclEvidence(
            new PbsEffectivePermission('/system/tasks', ['Sys.Audit' => false]),
            new PbsEffectivePermission('/datastore', ['Datastore.Audit' => true]),
            new PbsEffectivePermission('/remote', ['Remote.Audit' => true]),
        );
    }
    public function pruneJobs(): PbsJobListSnapshot
    {
        $this->failWhenConfigured('prune');
        return $this->jobs(PbsJobKind::Prune);
    }
    public function syncJobs(): PbsJobListSnapshot
    {
        if ($this->failSync) {
            throw \App\Application\Proxmox\Pbs\PbsReadFailure::for(
                \App\Application\Proxmox\Pbs\PbsReadFailureCode::InvalidResponse,
            );
        }
        return $this->jobs(PbsJobKind::Sync);
    }
    public function verifyJobs(): PbsJobListSnapshot
    {
        $this->failWhenConfigured('verify');
        return $this->jobs(PbsJobKind::Verify);
    }
    public function page(string $node, PbsTaskListQuery $query): PbsTaskPage
    {
        ++$this->pages;
        return new PbsTaskPage([], 0, 0);
    }
    private function jobs(PbsJobKind $kind): PbsJobListSnapshot
    {
        return new PbsJobListSnapshot($kind, hash('sha256', $kind->value), []);
    }

    private function failWhenConfigured(string $family): void
    {
        if (in_array($family, $this->failures, true)) {
            throw \App\Application\Proxmox\Pbs\PbsReadFailure::for(
                \App\Application\Proxmox\Pbs\PbsReadFailureCode::InvalidResponse,
            );
        }
    }
}

final class NativeMonitoringCheckpoint implements ConnectionReadCheckpoint
{
    public int $calls = 0;
    public function checkpoint(): void { ++$this->calls; }
}

final readonly class NativeMonitoringClock implements Clock
{
    public function now(): DateTimeImmutable { return new DateTimeImmutable('@200'); }
}
