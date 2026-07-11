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
use App\Application\Inventory\InventoryIdentifierGenerator;
use App\Application\Inventory\Pve\PveCoreInventoryMapper;
use App\Application\Inventory\Pve\PveCoreInventoryStore;
use App\Application\Inventory\Pve\ExecuteClaimedPveCoreInventoryCycle;
use App\Application\Security\ReferencedCredentialKeyIds;
use App\Application\Security\SecretCipher;
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
use App\Infrastructure\Persistence\MariaDb\DbalConnectionScanCatalog;
use App\Infrastructure\Persistence\MariaDb\DbalInstallationBindingCatalog;
use App\Infrastructure\Persistence\MariaDb\DbalPveCoreInventoryStore;
use App\Infrastructure\Persistence\MariaDb\DbalPveEndpointReadConfigurationSource;
use App\Infrastructure\Persistence\MariaDb\SystemUuidV7InventoryIdentifierGenerator;
use App\Infrastructure\Proxmox\PveCoreEndpointInstallationReader;
use App\Infrastructure\Proxmox\PveCoreReadConnectorFactory;
use App\Infrastructure\Proxmox\PveEndpointReadConfigurationSource;
use App\Infrastructure\Proxmox\PveHttpClientFactory;
use App\Infrastructure\Proxmox\PveJitterSource;
use App\Infrastructure\Proxmox\PveNativeCoreReadConnectorFactory;
use App\Infrastructure\Proxmox\PveNativeHttpClientFactory;
use App\Infrastructure\Proxmox\PveRetryDelay;
use App\Infrastructure\Proxmox\PveSystemJitterSource;
use App\Infrastructure\Proxmox\PveExponentialJitterDelay;
use App\Application\Inventory\Pve\MapPveCoreInventorySnapshot;
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
    $services->set(DbalPveCoreInventoryStore::class);
    $services->alias(PveCoreInventoryStore::class, DbalPveCoreInventoryStore::class);
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
    $services->set(PveCoreEndpointInstallationReader::class);
    $services->alias(EndpointInstallationReader::class, PveCoreEndpointInstallationReader::class);
    $services->set(ExecuteClaimedPveCoreInventoryCycle::class);
    $services->alias(RunCollectorCycle::class, ExecuteClaimedPveCoreInventoryCycle::class);
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
