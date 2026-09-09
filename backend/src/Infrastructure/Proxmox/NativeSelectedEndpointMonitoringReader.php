<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\EndpointReadFailure;
use App\Application\Inventory\Connection\EndpointReadFailureCode;
use App\Application\Inventory\Connection\PveEndpointReadFailureMapper;
use App\Application\Monitoring\PbsExternalMonitoringSnapshot;
use App\Application\Monitoring\SelectedEndpointMonitoringReader;
use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsTaskScanner;
use App\Application\Proxmox\Pbs\PbsTasksAndJobsLimits;
use App\Application\Proxmox\Pbs\PbsTaskWindow;
use App\Application\Proxmox\Pve\PveBackupInventoryLimits;
use App\Application\Proxmox\Pve\PveBackupInventorySnapshot;
use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveTaskArchiveWindow;
use App\Application\Proxmox\Pve\ReadPveBackupInventory;
use App\Domain\Shared\Clock;
use App\Infrastructure\Proxmox\Pbs\PbsEndpointReadConfigurationSource;
use App\Infrastructure\Proxmox\Pbs\PbsMonitoringClientFactory;
use App\Infrastructure\Proxmox\Pbs\PbsReadConnectorFactoryFailure;

final readonly class NativeSelectedEndpointMonitoringReader implements SelectedEndpointMonitoringReader
{
    public function __construct(
        private PveEndpointReadConfigurationSource $pveConfigurationSource,
        private PveCoreReadConnectorFactory $pveConnectorFactory,
        private PveEndpointReadFailureMapper $pveFailureMapper,
        private PbsEndpointReadConfigurationSource $pbsConfigurationSource,
        private PbsMonitoringClientFactory $pbsConnectorFactory,
        private Clock $clock,
        private PveBackupInventoryLimits $pveLimits,
        private PbsTasksAndJobsLimits $pbsLimits,
    ) {
    }

    public function readPve(
        ConnectionId $connectionId,
        EndpointId $endpointId,
        int $expectedRevision,
        array $topologyNodes,
        PveTaskArchiveWindow $window,
        ConnectionReadCheckpoint $checkpoint,
    ): PveBackupInventorySnapshot {
        $configuration = $this->pveConfigurationSource->load($connectionId, $endpointId, $expectedRevision);
        try {
            $client = $this->pveConnectorFactory->create($configuration, $checkpoint)->connect();
            return (new ReadPveBackupInventory($this->clock, $this->pveLimits))
                ->read($client, $topologyNodes, $window);
        } catch (PveCoreReadConnectorFactoryFailure) {
            throw EndpointReadFailure::for(EndpointReadFailureCode::Tls);
        } catch (PveReadFailure $failure) {
            throw $this->pveFailureMapper->map($failure);
        }
    }

    public function readPbs(
        ConnectionId $connectionId,
        EndpointId $endpointId,
        int $expectedRevision,
        PbsTaskWindow $window,
        ConnectionReadCheckpoint $checkpoint,
    ): PbsExternalMonitoringSnapshot {
        $configuration = $this->pbsConfigurationSource->load($connectionId, $endpointId, $expectedRevision);
        try {
            $client = $this->pbsConnectorFactory->createMonitoringClient($configuration, $checkpoint, $this->pbsLimits);
        } catch (PbsReadConnectorFactoryFailure) {
            throw EndpointReadFailure::for(EndpointReadFailureCode::Tls);
        }

        $errors = [];
        try { $acl = $client->aclEvidence(); } catch (PbsReadFailure $failure) {
            $acl = null; $errors['acl'] = $failure->failureCode->value;
        }
        try { $prune = $client->pruneJobs(); } catch (PbsReadFailure $failure) {
            $prune = null; $errors['prune'] = $failure->failureCode->value;
        }
        try { $sync = $client->syncJobs(); } catch (PbsReadFailure $failure) {
            $sync = null; $errors['sync'] = $failure->failureCode->value;
        }
        try { $verify = $client->verifyJobs(); } catch (PbsReadFailure $failure) {
            $verify = null; $errors['verify'] = $failure->failureCode->value;
        }
        $tasks = (new PbsTaskScanner($client, $this->pbsLimits))->scan($window);

        $inspections = (new \App\Application\Proxmox\Pbs\CollectPbsTaskInspections())->collect($client, $tasks->tasks);
        return new PbsExternalMonitoringSnapshot($acl, $prune, $sync, $verify, $tasks, $errors, $inspections);
    }
}
