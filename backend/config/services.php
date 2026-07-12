<?php

declare(strict_types=1);

use App\Domain\Shared\Clock;
use App\Application\Readiness\ReadinessAggregator;
use App\Application\Collector\CollectorHeartbeatStore;
use App\Application\Collector\CollectorCycleCoordinator;
use App\Application\Collector\CollectorCycleTokenFactory;
use App\Application\Collector\CollectorRuntimeLoop;
use App\Application\Collector\CollectorRuntimeWaiter;
use App\Application\Collector\CollectorScheduleStore;
use App\Application\Collector\CollectorWorkerIdentity;
use App\Application\Collector\CollectorWorkerIdentityReader;
use App\Application\Collector\CollectorWorkerRunner;
use App\Application\Collector\RunCollectorCycle;
use App\Application\Collector\RunCollectorWorker;
use App\Application\Collector\StopRequested;
use App\Application\Inventory\Connection\ConnectionScanCatalog;
use App\Application\Inventory\Connection\EndpointInstallationReader;
use App\Application\Inventory\Connection\InstallationBindingCatalog;
use App\Application\Inventory\Capability\CapabilitySnapshotStore;
use App\Application\Inventory\InventoryIdentifierGenerator;
use App\Application\Inventory\ReadModel\CollectorReadModel;
use App\Application\Inventory\ReadModel\InventoryReadModel;
use App\Application\Target\ReadModel\BackupTargetCandidateReadModel;
use App\Application\Inventory\Pve\PveCoreInventoryMapper;
use App\Application\Inventory\Pve\PveCoreInventoryStore;
use App\Application\Inventory\Pve\PveInventoryMapper;
use App\Application\Inventory\Connection\ExecuteClaimedInventoryCycle;
use App\Application\Inventory\Pbs\PbsInventoryMapper;
use App\Application\Inventory\Pbs\PbsInventoryStore;
use App\Application\Inventory\Pbs\MapPbsInventorySnapshot;
use App\Application\Inventory\PbsContent\PbsContentStore;
use App\Application\Inventory\PbsContent\RunSelectedEndpointPbsContent;
use App\Application\Inventory\PbsContent\SelectedEndpointPbsContent;
use App\Application\Inventory\PbsContent\SelectedEndpointPbsContentReader;
use App\Application\Monitoring\MonitoringCursorCatalog;
use App\Application\Monitoring\MonitoringRunStore;
use App\Application\Monitoring\MonitoringWindowPlanner;
use App\Application\Monitoring\RunSelectedEndpointMonitoring;
use App\Application\Monitoring\SelectedEndpointMonitoring;
use App\Application\Monitoring\SelectedEndpointMonitoringReader;
use App\Application\Proxmox\Pbs\PbsTasksAndJobsLimits;
use App\Application\Proxmox\Pbs\PbsContentLimits;
use App\Application\Proxmox\Pve\PveBackupInventoryLimits;
use App\Application\Security\ReferencedCredentialKeyIds;
use App\Application\Security\SecretCipher;
use App\Application\Scheduler\Shadow\ShadowEvaluationStore;
use App\Application\Worker\Sleeper;
use App\Application\Worker\MonotonicClock;
use App\Infrastructure\Logging\MonologRedactionProcessor;
use App\Infrastructure\Filesystem\AtomicFileMaterializer;
use App\Infrastructure\Filesystem\NativeAtomicFileMaterializer;
use App\Infrastructure\Process\NativeSleeper;
use App\Infrastructure\Process\PcntlStopRequested;
use App\Infrastructure\Process\PersistentCollectorWorkerIdentity;
use App\Infrastructure\Process\SignalAwareCollectorRuntimeWaiter;
use App\Infrastructure\Process\SystemCollectorCycleTokenFactory;
use App\Infrastructure\Readiness\DatabaseSchemaReadinessCheck;
use App\Infrastructure\Readiness\EncryptionKeyRingReadinessCheck;
use App\Infrastructure\Persistence\MariaDb\DbalReferencedCredentialKeyIds;
use App\Infrastructure\Persistence\MariaDb\DbalCollectorHeartbeatStore;
use App\Infrastructure\Persistence\MariaDb\DbalCollectorScheduleStore;
use App\Infrastructure\Persistence\MariaDb\DbalCapabilitySnapshotStore;
use App\Infrastructure\Persistence\MariaDb\DbalConnectionScanCatalog;
use App\Infrastructure\Persistence\MariaDb\DbalInstallationBindingCatalog;
use App\Infrastructure\Persistence\MariaDb\DbalInventoryReadModel;
use App\Infrastructure\Persistence\MariaDb\DbalBackupTargetCandidateReadModel;
use App\Infrastructure\Persistence\MariaDb\DbalPveCoreInventoryStore;
use App\Infrastructure\Persistence\MariaDb\DbalPveEndpointReadConfigurationSource;
use App\Infrastructure\Persistence\MariaDb\DbalPbsEndpointReadConfigurationSource;
use App\Infrastructure\Persistence\MariaDb\DbalPbsInventoryStore;
use App\Infrastructure\Persistence\MariaDb\DbalPbsContentStore;
use App\Infrastructure\Persistence\MariaDb\DbalMonitoringCursorCatalog;
use App\Infrastructure\Persistence\MariaDb\DbalMonitoringRunStore;
use App\Infrastructure\Persistence\MariaDb\DbalShadowEvaluationStore;
use App\Infrastructure\Persistence\MariaDb\SystemUuidV7InventoryIdentifierGenerator;
use App\Infrastructure\Proxmox\PveCoreEndpointInstallationReader;
use App\Infrastructure\Proxmox\NativeSelectedEndpointMonitoringReader;
use App\Infrastructure\Proxmox\NativeSelectedEndpointPbsContentReader;
use App\Infrastructure\Proxmox\PveCoreReadConnectorFactory;
use App\Infrastructure\Proxmox\PveEndpointReadConfigurationSource;
use App\Infrastructure\Proxmox\PveHttpClientFactory;
use App\Infrastructure\Proxmox\PveJitterSource;
use App\Infrastructure\Proxmox\PveNativeCoreReadConnectorFactory;
use App\Infrastructure\Proxmox\PveNativeHttpClientFactory;
use App\Infrastructure\Proxmox\PveRetryDelay;
use App\Infrastructure\Proxmox\PveSystemJitterSource;
use App\Infrastructure\Proxmox\PveExponentialJitterDelay;
use App\Infrastructure\Proxmox\DispatchingEndpointInstallationReader;
use App\Infrastructure\Proxmox\Pbs\PbsEndpointInstallationReader;
use App\Infrastructure\Proxmox\Pbs\PbsContentClientFactory;
use App\Infrastructure\Proxmox\Pbs\PbsEndpointReadConfigurationSource;
use App\Infrastructure\Proxmox\Pbs\PbsExponentialJitterDelay;
use App\Infrastructure\Proxmox\Pbs\PbsHttpClientFactory;
use App\Infrastructure\Proxmox\Pbs\PbsJitterSource;
use App\Infrastructure\Proxmox\Pbs\PbsNativeHttpClientFactory;
use App\Infrastructure\Proxmox\Pbs\PbsNativeReadConnectorFactory;
use App\Infrastructure\Proxmox\Pbs\PbsMonitoringClientFactory;
use App\Infrastructure\Proxmox\Pbs\PbsReadConnectorFactory;
use App\Infrastructure\Proxmox\Pbs\PbsRetryDelay;
use App\Infrastructure\Proxmox\Pbs\PbsSystemJitterSource;
use App\Application\Inventory\Pve\MapPveCoreInventorySnapshot;
use App\Application\Inventory\Pve\MapPveInventorySnapshot;
use App\Infrastructure\Security\DockerSecretKeyRingLoader;
use App\Infrastructure\Security\EncryptionKeyRing;
use App\Infrastructure\Security\EncryptionKeyRingProvider;
use App\Infrastructure\Security\NonceSource;
use App\Infrastructure\Security\SodiumSecretCipher;
use App\Infrastructure\Security\SystemNonceSource;
use App\Infrastructure\Time\SystemClock;
use App\Infrastructure\Time\SystemMonotonicClock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $container->parameters()
        ->set('env(COLLECTOR_GRID_WIDTH_SECONDS)', '120')
        ->set('env(PVE_STORAGE_MAX_NODE_FANOUT)', '128')
        ->set('env(PBS_MAX_DATASTORE_FANOUT)', '128')
        ->set('env(PBS_CONTENT_MAX_DATASTORES)', '128')
        ->set('env(PBS_CONTENT_MAX_NAMESPACES_PER_DATASTORE)', '1024')
        ->set('env(PBS_CONTENT_MAX_SNAPSHOTS_PER_NAMESPACE)', '65536')
        ->set('env(PBS_CONTENT_MAX_TOTAL_SNAPSHOTS)', '262144')
        ->set('env(PBS_CONTENT_NAMESPACE_BODY_BYTES)', '8388608')
        ->set('env(PBS_CONTENT_SNAPSHOT_BODY_BYTES)', '67108864')
        ->set('env(MONITOR_HISTORY_OVERLAP_SECONDS)', '300')
        ->set('env(PVE_MONITOR_PAGE_SIZE)', '100')
        ->set('env(PVE_MONITOR_MAX_NODES)', '128')
        ->set('env(PVE_MONITOR_ACTIVE_PAGE_CAP)', '2')
        ->set('env(PVE_MONITOR_ARCHIVE_PAGE_CAP)', '10')
        ->set('env(PVE_MONITOR_REQUEST_LIMIT)', '512')
        ->set('env(PVE_MONITOR_RAW_ROW_LIMIT)', '25000')
        ->set('env(PVE_MONITOR_DISTINCT_TASK_LIMIT)', '25000')
        ->set('env(PVE_MONITOR_HISTORY_WINDOW_SECONDS)', '86400')
        ->set('env(PBS_MONITOR_PAGE_SIZE)', '256')
        ->set('env(PBS_MONITOR_MAX_PAGES_PER_STREAM)', '16')
        ->set('env(PBS_MONITOR_MAX_ROWS_PER_STREAM)', '4096')
        ->set('env(PBS_MONITOR_MAX_JOBS_PER_KIND)', '4096')
        ->set('env(PBS_MONITOR_HISTORY_WINDOW_SECONDS)', '86400')
        ->set('env(APP_BUILD_VERSION)', 'development');

    $services = $container->services();

    $services
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services
        ->load('App\\', '../src/')
        ->exclude([
            '../src/Domain/',
            '../src/Application/Security/',
            '../src/Infrastructure/Proxmox/PveBackup/',
            '../src/Infrastructure/Security/',
            '../src/Kernel.php',
        ]);

    $services->set(Clock::class, SystemClock::class);
    $services->set(MonotonicClock::class, SystemMonotonicClock::class);
    $services->set(Sleeper::class, NativeSleeper::class);
    $services->set(NativeAtomicFileMaterializer::class);
    $services->alias(AtomicFileMaterializer::class, NativeAtomicFileMaterializer::class);

    $services->set(DbalCollectorScheduleStore::class);
    $services->alias(CollectorScheduleStore::class, DbalCollectorScheduleStore::class);
    $services->set(DbalCollectorHeartbeatStore::class);
    $services->alias(CollectorHeartbeatStore::class, DbalCollectorHeartbeatStore::class);
    $services->set(DbalCapabilitySnapshotStore::class);
    $services->alias(CapabilitySnapshotStore::class, DbalCapabilitySnapshotStore::class);
    $services
        ->set(CollectorCycleCoordinator::class)
        ->arg('$buildVersion', '%env(APP_BUILD_VERSION)%');

    $services->set(PersistentCollectorWorkerIdentity::class);
    $services->alias(CollectorWorkerIdentity::class, PersistentCollectorWorkerIdentity::class);
    $services->alias(CollectorWorkerIdentityReader::class, PersistentCollectorWorkerIdentity::class);
    $services->set(SystemCollectorCycleTokenFactory::class);
    $services->alias(CollectorCycleTokenFactory::class, SystemCollectorCycleTokenFactory::class);
    $services->set(PcntlStopRequested::class);
    $services->alias(StopRequested::class, PcntlStopRequested::class);
    $services->set(SignalAwareCollectorRuntimeWaiter::class);
    $services->alias(CollectorRuntimeWaiter::class, SignalAwareCollectorRuntimeWaiter::class);

    $services->set(DbalConnectionScanCatalog::class);
    $services->alias(ConnectionScanCatalog::class, DbalConnectionScanCatalog::class);
    $services->set(DbalInstallationBindingCatalog::class);
    $services->alias(InstallationBindingCatalog::class, DbalInstallationBindingCatalog::class);
    $services->set(DbalInventoryReadModel::class);
    $services->alias(InventoryReadModel::class, DbalInventoryReadModel::class);
    $services->alias(CollectorReadModel::class, DbalInventoryReadModel::class);
    $services->set(DbalBackupTargetCandidateReadModel::class);
    $services->alias(BackupTargetCandidateReadModel::class, DbalBackupTargetCandidateReadModel::class);
    $services->set(DbalShadowEvaluationStore::class);
    $services->alias(ShadowEvaluationStore::class, DbalShadowEvaluationStore::class);
    $services->set(DbalPveCoreInventoryStore::class);
    $services->alias(PveCoreInventoryStore::class, DbalPveCoreInventoryStore::class);
    $services->set(DbalPbsInventoryStore::class);
    $services->alias(PbsInventoryStore::class, DbalPbsInventoryStore::class);
    $services->set(DbalPbsContentStore::class);
    $services->alias(PbsContentStore::class, DbalPbsContentStore::class);
    $services->set(SystemUuidV7InventoryIdentifierGenerator::class);
    $services->alias(InventoryIdentifierGenerator::class, SystemUuidV7InventoryIdentifierGenerator::class);
    $services->set(MapPveCoreInventorySnapshot::class);
    $services->alias(PveCoreInventoryMapper::class, MapPveCoreInventorySnapshot::class);

    $services->set(DbalPveEndpointReadConfigurationSource::class);
    $services->alias(PveEndpointReadConfigurationSource::class, DbalPveEndpointReadConfigurationSource::class);
    $services->set(PveNativeHttpClientFactory::class);
    $services->alias(PveHttpClientFactory::class, PveNativeHttpClientFactory::class);
    $services->set(PveSystemJitterSource::class);
    $services->alias(PveJitterSource::class, PveSystemJitterSource::class);
    $services->set(PveExponentialJitterDelay::class);
    $services->alias(PveRetryDelay::class, PveExponentialJitterDelay::class);
    $services->set(PveNativeCoreReadConnectorFactory::class);
    $services->alias(PveCoreReadConnectorFactory::class, PveNativeCoreReadConnectorFactory::class);
    $services
        ->set(PveCoreEndpointInstallationReader::class)
        ->arg('$maximumStorageNodeFanout', '%env(int:PVE_STORAGE_MAX_NODE_FANOUT)%');
    $services->set(DbalPbsEndpointReadConfigurationSource::class);
    $services->alias(PbsEndpointReadConfigurationSource::class, DbalPbsEndpointReadConfigurationSource::class);
    $services->set(PbsNativeHttpClientFactory::class);
    $services->alias(PbsHttpClientFactory::class, PbsNativeHttpClientFactory::class);
    $services->set(PbsSystemJitterSource::class);
    $services->alias(PbsJitterSource::class, PbsSystemJitterSource::class);
    $services->set(PbsExponentialJitterDelay::class);
    $services->alias(PbsRetryDelay::class, PbsExponentialJitterDelay::class);
    $services->set(PbsNativeReadConnectorFactory::class);
    $services->alias(PbsReadConnectorFactory::class, PbsNativeReadConnectorFactory::class);
    $services->alias(PbsMonitoringClientFactory::class, PbsNativeReadConnectorFactory::class);
    $services->alias(PbsContentClientFactory::class, PbsNativeReadConnectorFactory::class);
    $services
        ->set(PbsEndpointInstallationReader::class)
        ->arg('$maximumDatastoreFanout', '%env(int:PBS_MAX_DATASTORE_FANOUT)%');
    $services->set(DispatchingEndpointInstallationReader::class);
    $services->alias(EndpointInstallationReader::class, DispatchingEndpointInstallationReader::class);
    $services->set(MapPveInventorySnapshot::class);
    $services->alias(PveInventoryMapper::class, MapPveInventorySnapshot::class);
    $services->set(MapPbsInventorySnapshot::class);
    $services->alias(PbsInventoryMapper::class, MapPbsInventorySnapshot::class);
    $services
        ->set(PveBackupInventoryLimits::class)
        ->args([
            '%env(int:PVE_MONITOR_PAGE_SIZE)%',
            '%env(int:PVE_MONITOR_MAX_NODES)%',
            '%env(int:PVE_MONITOR_ACTIVE_PAGE_CAP)%',
            '%env(int:PVE_MONITOR_ARCHIVE_PAGE_CAP)%',
            '%env(int:PVE_MONITOR_REQUEST_LIMIT)%',
            '%env(int:PVE_MONITOR_RAW_ROW_LIMIT)%',
            '%env(int:PVE_MONITOR_DISTINCT_TASK_LIMIT)%',
            '%env(int:PVE_MONITOR_HISTORY_WINDOW_SECONDS)%',
        ]);
    $services
        ->set(PbsTasksAndJobsLimits::class)
        ->args([
            '%env(int:PBS_MONITOR_PAGE_SIZE)%',
            '%env(int:PBS_MONITOR_MAX_PAGES_PER_STREAM)%',
            '%env(int:PBS_MONITOR_MAX_ROWS_PER_STREAM)%',
            '%env(int:PBS_MONITOR_MAX_JOBS_PER_KIND)%',
            '%env(int:PBS_MONITOR_HISTORY_WINDOW_SECONDS)%',
        ]);
    $services
        ->set(PbsContentLimits::class)
        ->args([
            '%env(int:PBS_CONTENT_MAX_DATASTORES)%',
            '%env(int:PBS_CONTENT_MAX_NAMESPACES_PER_DATASTORE)%',
            '%env(int:PBS_CONTENT_MAX_SNAPSHOTS_PER_NAMESPACE)%',
            '%env(int:PBS_CONTENT_MAX_TOTAL_SNAPSHOTS)%',
            '%env(int:PBS_CONTENT_NAMESPACE_BODY_BYTES)%',
            '%env(int:PBS_CONTENT_SNAPSHOT_BODY_BYTES)%',
        ]);
    $services->set(NativeSelectedEndpointPbsContentReader::class);
    $services->alias(SelectedEndpointPbsContentReader::class, NativeSelectedEndpointPbsContentReader::class);
    $services->set(RunSelectedEndpointPbsContent::class);
    $services->alias(SelectedEndpointPbsContent::class, RunSelectedEndpointPbsContent::class);
    $services->set(DbalMonitoringCursorCatalog::class);
    $services->alias(MonitoringCursorCatalog::class, DbalMonitoringCursorCatalog::class);
    $services
        ->set(MonitoringWindowPlanner::class)
        ->arg('$overlapSeconds', '%env(int:MONITOR_HISTORY_OVERLAP_SECONDS)%');
    $services->set(DbalMonitoringRunStore::class);
    $services->alias(MonitoringRunStore::class, DbalMonitoringRunStore::class);
    $services->set(NativeSelectedEndpointMonitoringReader::class);
    $services->alias(SelectedEndpointMonitoringReader::class, NativeSelectedEndpointMonitoringReader::class);
    $services
        ->set(RunSelectedEndpointMonitoring::class)
        ->args([
            service(SelectedEndpointMonitoringReader::class),
            service(MonitoringWindowPlanner::class),
            service(\App\Application\Monitoring\MapSelectedEndpointMonitoring::class),
            service(MonitoringRunStore::class),
            service(InventoryIdentifierGenerator::class),
            service(Clock::class),
            '%env(int:PVE_MONITOR_HISTORY_WINDOW_SECONDS)%',
            '%env(int:PBS_MONITOR_HISTORY_WINDOW_SECONDS)%',
        ]);
    $services->alias(SelectedEndpointMonitoring::class, RunSelectedEndpointMonitoring::class);
    $services->set(ExecuteClaimedInventoryCycle::class);
    $services->alias(RunCollectorCycle::class, ExecuteClaimedInventoryCycle::class);
    $services
        ->set(CollectorRuntimeLoop::class)
        ->arg('$gridWidthSeconds', '%env(int:COLLECTOR_GRID_WIDTH_SECONDS)%');
    $services->set(RunCollectorWorker::class);
    $services->alias(CollectorWorkerRunner::class, RunCollectorWorker::class);

    $services
        ->set(DatabaseSchemaReadinessCheck::class)
        ->tag('app.readiness_check');
    $services->set(DbalReferencedCredentialKeyIds::class);
    $services->alias(ReferencedCredentialKeyIds::class, DbalReferencedCredentialKeyIds::class);
    $services
        ->set(EncryptionKeyRingReadinessCheck::class)
        ->tag('app.readiness_check');
    $services
        ->set(ReadinessAggregator::class)
        ->args([tagged_iterator('app.readiness_check')]);

    $services
        ->set(DockerSecretKeyRingLoader::class)
        ->args([
            '%env(ENCRYPTION_KEY_FILE)%',
            '%env(ENCRYPTION_KEYRING_REVISION)%',
        ]);
    $services->alias(EncryptionKeyRingProvider::class, DockerSecretKeyRingLoader::class);

    $services
        ->set(EncryptionKeyRing::class)
        ->factory([service(DockerSecretKeyRingLoader::class), 'load']);

    $services->set(SystemNonceSource::class);
    $services->alias(NonceSource::class, SystemNonceSource::class);
    $services->set(SodiumSecretCipher::class);
    $services->alias(SecretCipher::class, SodiumSecretCipher::class);

    $services
        ->set(MonologRedactionProcessor::class)
        ->autowire()
        ->tag('monolog.processor');
};
