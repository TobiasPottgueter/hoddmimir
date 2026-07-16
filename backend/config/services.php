<?php

declare(strict_types=1);

use App\Domain\Shared\Clock;
use App\Application\Backup\Notification\BackupNotificationConfiguration;
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
use App\Application\Target\ReadModel\ConfiguredBackupTargetReadModel;
use App\Application\Policy\ReadModel\PolicyReadModel;
use App\Application\Configuration\Target\TargetCommandRepository;
use App\Application\Configuration\Target\TargetCandidateEvidenceProvider;
use App\Application\Configuration\Target\TargetExecutorEvidenceProvider;
use App\Application\Configuration\Policy\PolicyCommandRepository;
use App\Application\Configuration\Policy\PolicyActivationEvidenceProvider;
use App\Application\Configuration\Selection\SelectionCommandRepository;
use App\Application\Administration\ReadModel\AdministrationReadModel;
use App\Application\Administration\SecurityCommandRepository;
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
use App\Application\Security\Audit\AuditEventStore;
use App\Application\Security\Audit\SecurityAuditRecorder;
use App\Application\Security\Auth\AuthenticateSession;
use App\Application\Security\Auth\AuthenticationStore;
use App\Application\Security\Auth\CsrfTokenDeriver;
use App\Application\Security\Auth\CreateFirstAdmin;
use App\Application\Security\Auth\FirstAdminStore;
use App\Application\Security\Auth\FirstAdminCreator;
use App\Application\Security\Auth\LocalLogin;
use App\Application\Security\Auth\LoginThrottleStore;
use App\Application\Security\Auth\LogoutSession;
use App\Application\Security\Auth\OpaqueSecretHasher;
use App\Application\Security\Auth\OpaqueTokenGenerator;
use App\Application\Security\Auth\PasswordHasher;
use App\Application\Security\Auth\PermissionAuthorizer;
use App\Application\Security\Auth\SecurityIdentifierGenerator;
use App\Application\Security\Auth\SecurityTransaction;
use App\Application\Security\Auth\WebSessionStore;
use App\Application\Scheduler\Shadow\ShadowEvaluationStore;
use App\Application\Scheduler\Shadow\AutomaticShadowEvaluationSource;
use App\Application\Scheduler\Shadow\RunAutomaticShadowEvaluation;
use App\Application\Scheduler\Shadow\AutomaticShadowEvaluator;
use App\Application\Scheduler\Shadow\ReadModel\ShadowReadModel;
use App\Application\Backup\Queue\BackupQueueStore;
use App\Application\Backup\Queue\QueueClaimTokenSource;
use App\Application\Backup\Execution\BackupExecutionGate;
use App\Application\Backup\Execution\BackupSubmissionTransaction;
use App\Application\Backup\Execution\ExecutorEvidenceRefresh;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshSource;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshStore;
use App\Application\Backup\Execution\RefreshExecutorPermissionEvidence;
use App\Application\Backup\Monitoring\BackupMonitoringTransaction;
use App\Application\Backup\Monitoring\AmbiguousSubmissionReconciliationStore;
use App\Application\Backup\Monitoring\AmbiguousSubmissionTaskSource;
use App\Application\Backup\Worker\BackupRunIdentifierSource;
use App\Application\Backup\Worker\BackupNotificationDeliveryHook;
use App\Application\Backup\Worker\BackupWorkerRuntime;
use App\Application\Backup\Worker\BackupWorkerRunner;
use App\Application\Backup\Worker\BackupWorkerHeartbeatStore;
use App\Application\Proxmox\Pve\PveBackupClientProvider;
use App\Application\Backup\Notification\BackupNotificationDeliveryGate;
use App\Application\Backup\Notification\BackupNotificationDeliveryStore;
use App\Application\Backup\Notification\MatrixWebhook;
use App\Application\Backup\Operations\OperationsReadModel;
use App\Application\Backup\Operations\BackupOperationCommandRepository;
use App\Application\Qa\QaFixtureSeeder;
use App\Infrastructure\Persistence\MariaDb\DbalBackupQueueStore;
use App\Infrastructure\Persistence\MariaDb\DbalBackupWorkerHeartbeatStore;
use App\Infrastructure\Persistence\MariaDb\DbalBackupSubmissionStore;
use App\Infrastructure\Persistence\MariaDb\DbalExecutorEvidenceRefreshStore;
use App\Infrastructure\Persistence\MariaDb\DbalPveExecutorEvidenceConfigurationSource;
use App\Infrastructure\Persistence\MariaDb\DbalBackupMonitoringStore;
use App\Infrastructure\Persistence\MariaDb\DbalAmbiguousSubmissionReconciliationStore;
use App\Infrastructure\Persistence\MariaDb\DbalPveBackupClientProvider;
use App\Infrastructure\Proxmox\ExecutorEvidence\NativePveExecutorEvidenceRefreshSource;
use App\Infrastructure\Proxmox\ExecutorEvidence\PveExecutorEvidenceConfigurationSource;
use App\Infrastructure\Proxmox\ExecutorEvidence\PveExecutorEvidenceHttpClientFactory;
use App\Infrastructure\Proxmox\ExecutorEvidence\PveNativeExecutorEvidenceHttpClientFactory;
use App\Infrastructure\Persistence\MariaDb\DbalBackupNotificationDeliveryStore;
use App\Infrastructure\Persistence\MariaDb\DbalOperationsReadModel;
use App\Infrastructure\Persistence\MariaDb\DbalBackupOperationCommandRepository;
use App\Infrastructure\Notification\ConfiguredBackupNotificationDeliveryGate;
use App\Infrastructure\Notification\SecretFileMatrixWebhook;
use App\Infrastructure\Process\SystemQueueClaimTokenSource;
use App\Infrastructure\Process\SystemBackupRunIdentifierSource;
use App\Infrastructure\Notification\ApplicationBackupNotificationDeliveryHook;
use App\Infrastructure\Process\EnvironmentBackupExecutionGate;
use App\Infrastructure\Proxmox\PveBackup\PveBackupClientFactory;
use App\Infrastructure\Proxmox\PveBackup\PveNativeBackupClientFactory;
use App\Infrastructure\Proxmox\PveBackup\PveAmbiguousSubmissionTaskSource;
use App\Domain\Backup\ControlledRetryPolicy;
use App\Domain\Policy\PolicyResolver;
use App\Domain\Scheduler\EligibilityEvaluator;
use App\Domain\Scheduler\EvidenceFreshnessPolicy;
use App\Domain\Scheduler\PriorityResolver;
use App\Domain\Scheduler\ReasonSelector;
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
use App\Infrastructure\Persistence\MariaDb\DbalConfiguredBackupTargetReadModel;
use App\Infrastructure\Persistence\MariaDb\DbalPolicyReadModel;
use App\Infrastructure\Persistence\MariaDb\DbalConfigurationCommandRepository;
use App\Infrastructure\Persistence\MariaDb\DbalSecurityAdministration;
use App\Infrastructure\Persistence\MariaDb\DbalConnectionAdministration;
use App\Application\Configuration\Connection\ConnectionCommandRepository;
use App\Application\Configuration\Connection\ConnectionReadModel;
use App\Application\Configuration\Connection\Onboarding\OnboardingActivationRepository;
use App\Application\Configuration\Connection\Onboarding\OnboardingCustomCaValidator;
use App\Application\Configuration\Connection\Onboarding\OnboardingRemoteGateway;
use App\Infrastructure\Persistence\MariaDb\DbalOnboardingActivationRepository;
use App\Infrastructure\Proxmox\Onboarding\NativeOnboardingRemoteGateway;
use App\Infrastructure\Proxmox\Onboarding\NativeOnboardingCustomCaValidator;
use App\Infrastructure\Persistence\MariaDb\DbalActivationEvidenceProvider;
use App\Infrastructure\Persistence\MariaDb\DbalLocalAuthStore;
use App\Infrastructure\Persistence\MariaDb\DbalPveCoreInventoryStore;
use App\Infrastructure\Persistence\MariaDb\DbalPveEndpointReadConfigurationSource;
use App\Infrastructure\Persistence\MariaDb\DbalPbsEndpointReadConfigurationSource;
use App\Infrastructure\Persistence\MariaDb\DbalPbsInventoryStore;
use App\Infrastructure\Persistence\MariaDb\DbalPbsContentStore;
use App\Infrastructure\Persistence\MariaDb\DbalMonitoringCursorCatalog;
use App\Infrastructure\Persistence\MariaDb\DbalMonitoringRunStore;
use App\Infrastructure\Persistence\MariaDb\DbalShadowEvaluationStore;
use App\Infrastructure\Persistence\MariaDb\DbalAutomaticShadowEvaluationSource;
use App\Infrastructure\Persistence\MariaDb\MariaDbQaFixtureSeeder;
use App\Infrastructure\Persistence\MariaDb\DbalShadowReadModel;
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
use App\Infrastructure\Security\HmacCsrfTokenDeriver;
use App\Infrastructure\Security\NativeArgon2idPasswordHasher;
use App\Infrastructure\Security\Sha256OpaqueSecretHasher;
use App\Infrastructure\Security\SystemOpaqueTokenGenerator;
use App\Infrastructure\Security\SystemSecurityIdentifierGenerator;
use App\Infrastructure\Time\SystemClock;
use App\Infrastructure\Time\SystemMonotonicClock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\CurlHttpClient;
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
        ->set('env(EVIDENCE_FRESHNESS_SECONDS)', '300')
        ->set('env(EXECUTOR_EVIDENCE_REFRESH_CADENCE_SECONDS)', '120')
        ->set('env(EXECUTOR_EVIDENCE_REFRESH_LEASE_SECONDS)', '90')
        ->set('env(EXECUTOR_EVIDENCE_REFRESH_PAGE_SIZE)', '256')
        ->set('env(EXECUTOR_EVIDENCE_REFRESH_MAX_SUBJECTS)', '65536')
        ->set('env(BACKUP_QUEUE_DEFER_SECONDS)', '120')
        ->set('env(BACKUP_EXECUTION_ENABLED)', '0')
        ->set('env(BACKUP_WORKER_HEARTBEAT_TTL_SECONDS)', '150')
        ->set('env(PVE_MONITOR_PAGE_SIZE)', '100')
        ->set('env(PVE_MONITOR_MAX_NODES)', '128')
        ->set('env(PVE_MONITOR_ACTIVE_PAGE_CAP)', '2')
        ->set('env(PVE_MONITOR_ARCHIVE_PAGE_CAP)', '10')
        ->set('env(PVE_RECONCILIATION_TOTAL_PAGE_CAP)', '12')
        ->set('env(PVE_RECONCILIATION_MAX_ELAPSED_SECONDS)', '1200')
        ->set('env(PVE_MONITOR_REQUEST_LIMIT)', '512')
        ->set('env(PVE_MONITOR_RAW_ROW_LIMIT)', '25000')
        ->set('env(PVE_MONITOR_DISTINCT_TASK_LIMIT)', '25000')
        ->set('env(PVE_MONITOR_HISTORY_WINDOW_SECONDS)', '86400')
        ->set('env(PBS_MONITOR_PAGE_SIZE)', '256')
        ->set('env(PBS_MONITOR_MAX_PAGES_PER_STREAM)', '16')
        ->set('env(PBS_MONITOR_MAX_ROWS_PER_STREAM)', '4096')
        ->set('env(PBS_MONITOR_MAX_JOBS_PER_KIND)', '4096')
        ->set('env(PBS_MONITOR_HISTORY_WINDOW_SECONDS)', '86400')
        ->set('env(APP_BUILD_VERSION)', 'development')
        ->set('env(HODDMIMIR_TRUSTED_PROXY)', '')
        ->set('env(MATRIX_NOTIFICATION_ENABLED)', '0')
        ->set('env(MATRIX_WEBHOOK_URL_FILE)', '/run/secrets/matrix_webhook_url')
        ->set('env(MATRIX_WEBHOOK_CHANNEL)', 'proxmox-backup')
        ->set('env(MATRIX_WEBHOOK_TIMEOUT_SECONDS)', '10');

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
    $services->set(QaFixtureSeeder::class, MariaDbQaFixtureSeeder::class);
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
    $services
        ->set(DbalBackupTargetCandidateReadModel::class)
        ->arg('$evidenceFreshnessSeconds', '%env(int:EVIDENCE_FRESHNESS_SECONDS)%');
    $services->alias(BackupTargetCandidateReadModel::class, DbalBackupTargetCandidateReadModel::class);
    $services->set(DbalConfiguredBackupTargetReadModel::class);
    $services->alias(ConfiguredBackupTargetReadModel::class, DbalConfiguredBackupTargetReadModel::class);
    $services->set(DbalPolicyReadModel::class);
    $services->alias(PolicyReadModel::class, DbalPolicyReadModel::class);
    $services->set(DbalConfigurationCommandRepository::class);
    $services->alias(TargetCommandRepository::class, DbalConfigurationCommandRepository::class);
    $services->alias(PolicyCommandRepository::class, DbalConfigurationCommandRepository::class);
    $services->alias(SelectionCommandRepository::class, DbalConfigurationCommandRepository::class);
    $services->set(DbalSecurityAdministration::class);
    $services->alias(SecurityCommandRepository::class, DbalSecurityAdministration::class);
    $services->alias(AdministrationReadModel::class, DbalSecurityAdministration::class);
    $services->set(DbalConnectionAdministration::class);
    $services->alias(ConnectionCommandRepository::class, DbalConnectionAdministration::class);
    $services->alias(ConnectionReadModel::class, DbalConnectionAdministration::class);
    $services->set(DbalOnboardingActivationRepository::class);
    $services->alias(OnboardingActivationRepository::class, DbalOnboardingActivationRepository::class);
    $services->set(NativeOnboardingRemoteGateway::class);
    $services->alias(OnboardingRemoteGateway::class, NativeOnboardingRemoteGateway::class);
    $services->alias(OnboardingCustomCaValidator::class, NativeOnboardingCustomCaValidator::class);
    $services->set(DbalActivationEvidenceProvider::class);
    $services->alias(TargetCandidateEvidenceProvider::class, DbalActivationEvidenceProvider::class);
    $services->alias(TargetExecutorEvidenceProvider::class, DbalActivationEvidenceProvider::class);
    $services->alias(PolicyActivationEvidenceProvider::class, DbalActivationEvidenceProvider::class);
    $services->set(DbalShadowEvaluationStore::class);
    $services->alias(ShadowEvaluationStore::class, DbalShadowEvaluationStore::class);
    $services->set(DbalAutomaticShadowEvaluationSource::class);
    $services->alias(AutomaticShadowEvaluationSource::class, DbalAutomaticShadowEvaluationSource::class);
    $services->set(EligibilityEvaluator::class);
    $services
        ->set(EvidenceFreshnessPolicy::class)
        ->arg('$maximumAgeSeconds', '%env(int:EVIDENCE_FRESHNESS_SECONDS)%');
    $services->set(ReasonSelector::class);
    $services->set(PriorityResolver::class);
    $services->set(RunAutomaticShadowEvaluation::class);
    $services->alias(AutomaticShadowEvaluator::class, RunAutomaticShadowEvaluation::class);
    $services->set(DbalShadowReadModel::class);
    $services->alias(ShadowReadModel::class, DbalShadowReadModel::class);
    $services
        ->set(DbalBackupQueueStore::class)
        ->arg('$deferSeconds', '%env(int:BACKUP_QUEUE_DEFER_SECONDS)%');
    $services->alias(BackupQueueStore::class, DbalBackupQueueStore::class);
    $services->set(SystemQueueClaimTokenSource::class);
    $services->alias(QueueClaimTokenSource::class, SystemQueueClaimTokenSource::class);
    $services->set(DbalExecutorEvidenceRefreshStore::class)
        ->arg('$cadenceSeconds', '%env(int:EXECUTOR_EVIDENCE_REFRESH_CADENCE_SECONDS)%')
        ->arg('$leaseSeconds', '%env(int:EXECUTOR_EVIDENCE_REFRESH_LEASE_SECONDS)%')
        ->arg('$maximumSubjects', '%env(int:EXECUTOR_EVIDENCE_REFRESH_MAX_SUBJECTS)%');
    $services->alias(ExecutorEvidenceRefreshStore::class, DbalExecutorEvidenceRefreshStore::class);
    $services->set(DbalPveExecutorEvidenceConfigurationSource::class);
    $services->alias(PveExecutorEvidenceConfigurationSource::class, DbalPveExecutorEvidenceConfigurationSource::class);
    $services->set(PveNativeExecutorEvidenceHttpClientFactory::class);
    $services->alias(PveExecutorEvidenceHttpClientFactory::class, PveNativeExecutorEvidenceHttpClientFactory::class);
    $services->set(NativePveExecutorEvidenceRefreshSource::class);
    $services->alias(ExecutorEvidenceRefreshSource::class, NativePveExecutorEvidenceRefreshSource::class);
    $services->set(RefreshExecutorPermissionEvidence::class)
        ->arg('$subjectPageSize', '%env(int:EXECUTOR_EVIDENCE_REFRESH_PAGE_SIZE)%')
        ->arg('$maximumSubjects', '%env(int:EXECUTOR_EVIDENCE_REFRESH_MAX_SUBJECTS)%');
    $services->alias(ExecutorEvidenceRefresh::class, RefreshExecutorPermissionEvidence::class);
    $services->set(ControlledRetryPolicy::class);
    $services->set(PolicyResolver::class);
    $services->set(EnvironmentBackupExecutionGate::class)->arg('$executionEnabled', '%env(bool:BACKUP_EXECUTION_ENABLED)%');
    $services->alias(BackupExecutionGate::class, EnvironmentBackupExecutionGate::class);
    $services->set(SystemBackupRunIdentifierSource::class);
    $services->alias(BackupRunIdentifierSource::class, SystemBackupRunIdentifierSource::class);
    $services->set(BackupWorkerRunner::class);
    $services->alias(BackupWorkerRuntime::class, BackupWorkerRunner::class);
    $services->set(DbalBackupWorkerHeartbeatStore::class)
        ->arg('$ttlSeconds', '%env(int:BACKUP_WORKER_HEARTBEAT_TTL_SECONDS)%')
        ->arg('$buildVersion', '%env(APP_BUILD_VERSION)%');
    $services->alias(BackupWorkerHeartbeatStore::class, DbalBackupWorkerHeartbeatStore::class);
    $services->set(ApplicationBackupNotificationDeliveryHook::class);
    $services->alias(BackupNotificationDeliveryHook::class, ApplicationBackupNotificationDeliveryHook::class);
    $services->set(PveNativeBackupClientFactory::class);
    $services->alias(PveBackupClientFactory::class, PveNativeBackupClientFactory::class);
    $services->set(DbalPveBackupClientProvider::class);
    $services->alias(PveBackupClientProvider::class, DbalPveBackupClientProvider::class);
    $services->set(DbalBackupSubmissionStore::class)->arg('$freshnessSeconds', '%env(int:EVIDENCE_FRESHNESS_SECONDS)%');
    $services->alias(BackupSubmissionTransaction::class, DbalBackupSubmissionStore::class);
    $services->set(DbalBackupMonitoringStore::class)
        ->arg('$heartbeats', service(BackupWorkerHeartbeatStore::class));
    $services->alias(BackupMonitoringTransaction::class, DbalBackupMonitoringStore::class);
    $services->set(DbalAmbiguousSubmissionReconciliationStore::class)
        ->arg('$heartbeats', service(BackupWorkerHeartbeatStore::class));
    $services->alias(AmbiguousSubmissionReconciliationStore::class, DbalAmbiguousSubmissionReconciliationStore::class);
    $services
        ->set(PveAmbiguousSubmissionTaskSource::class)
        ->arg('$pageSize', '%env(int:PVE_MONITOR_PAGE_SIZE)%')
        ->arg('$activePageCap', '%env(int:PVE_MONITOR_ACTIVE_PAGE_CAP)%')
        ->arg('$archivePageCap', '%env(int:PVE_MONITOR_ARCHIVE_PAGE_CAP)%')
        ->arg('$totalPageCap', '%env(int:PVE_RECONCILIATION_TOTAL_PAGE_CAP)%')
        ->arg('$maximumElapsedSeconds', '%env(int:PVE_RECONCILIATION_MAX_ELAPSED_SECONDS)%');
    $services->alias(AmbiguousSubmissionTaskSource::class, PveAmbiguousSubmissionTaskSource::class);
    $services->set(DbalBackupNotificationDeliveryStore::class);
    $services->alias(BackupNotificationDeliveryStore::class, DbalBackupNotificationDeliveryStore::class);
    $services->set(DbalOperationsReadModel::class)->arg('$evidenceFreshnessSeconds', '%env(int:EVIDENCE_FRESHNESS_SECONDS)%');
    $services->alias(OperationsReadModel::class, DbalOperationsReadModel::class);
    $services->set(DbalBackupOperationCommandRepository::class);
    $services->alias(BackupOperationCommandRepository::class, DbalBackupOperationCommandRepository::class);
    $services->set(CurlHttpClient::class);
    $services
        ->set(SecretFileMatrixWebhook::class)
        ->args([
            service(CurlHttpClient::class),
            '%env(MATRIX_WEBHOOK_URL_FILE)%',
            '%env(MATRIX_WEBHOOK_CHANNEL)%',
            '%env(float:MATRIX_WEBHOOK_TIMEOUT_SECONDS)%',
        ]);
    $services->alias(MatrixWebhook::class, SecretFileMatrixWebhook::class);
    $services->alias(BackupNotificationConfiguration::class, SecretFileMatrixWebhook::class);
    $services
        ->set(ConfiguredBackupNotificationDeliveryGate::class)
        ->arg('$value', '%env(bool:MATRIX_NOTIFICATION_ENABLED)%');
    $services->alias(BackupNotificationDeliveryGate::class, ConfiguredBackupNotificationDeliveryGate::class);
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

    $services->set(NativeArgon2idPasswordHasher::class);
    $services->alias(PasswordHasher::class, NativeArgon2idPasswordHasher::class);
    $services->set(Sha256OpaqueSecretHasher::class);
    $services->alias(OpaqueSecretHasher::class, Sha256OpaqueSecretHasher::class);
    $services->set(SystemOpaqueTokenGenerator::class);
    $services->alias(OpaqueTokenGenerator::class, SystemOpaqueTokenGenerator::class);
    $services->set(SystemSecurityIdentifierGenerator::class);
    $services->alias(SecurityIdentifierGenerator::class, SystemSecurityIdentifierGenerator::class);
    $services->set(HmacCsrfTokenDeriver::class)->arg('$applicationSecret', '%kernel.secret%');
    $services->alias(CsrfTokenDeriver::class, HmacCsrfTokenDeriver::class);
    $services->set(DbalLocalAuthStore::class);
    $services->alias(AuthenticationStore::class, DbalLocalAuthStore::class);
    $services->alias(LoginThrottleStore::class, DbalLocalAuthStore::class);
    $services->alias(WebSessionStore::class, DbalLocalAuthStore::class);
    $services->alias(AuditEventStore::class, DbalLocalAuthStore::class);
    $services->alias(SecurityTransaction::class, DbalLocalAuthStore::class);
    $services->alias(FirstAdminStore::class, DbalLocalAuthStore::class);
    $services->set(SecurityAuditRecorder::class);
    $services->set(LocalLogin::class);
    $services->set(AuthenticateSession::class);
    $services->set(LogoutSession::class);
    $services->set(PermissionAuthorizer::class);
    $services->alias(\App\Presentation\Http\Auth\HttpRequestAuthenticator::class, \App\Presentation\Http\Auth\RequestAuthenticator::class);
    $services->set(CreateFirstAdmin::class);
    $services->alias(FirstAdminCreator::class, CreateFirstAdmin::class);
    $services->set(\App\Presentation\Http\Controller\AuthController::class)
        ->arg('$environment', '%kernel.environment%');

    $services
        ->set(MonologRedactionProcessor::class)
        ->autowire()
        ->tag('monolog.processor');
};
