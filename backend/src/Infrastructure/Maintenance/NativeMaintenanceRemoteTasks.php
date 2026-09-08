<?php

declare(strict_types=1);

namespace App\Infrastructure\Maintenance;

use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Inventory\Connection\ConnectionScanTarget;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Maintenance\MaintenanceQuiescenceFailure;
use App\Application\Maintenance\MaintenanceRemoteTasks;
use App\Application\Proxmox\Pbs\PbsTaskFilterFamily;
use App\Application\Proxmox\Pbs\PbsTaskListQuery;
use App\Application\Proxmox\Pbs\PbsTaskPass;
use App\Application\Proxmox\Pbs\PbsTasksAndJobsLimits;
use App\Application\Proxmox\Pve\PveTaskQuery;
use App\Infrastructure\Persistence\MariaDb\DbalPbsEndpointReadConfigurationSource;
use App\Infrastructure\Persistence\MariaDb\DbalPveEndpointReadConfigurationSource;
use App\Infrastructure\Proxmox\Pbs\PbsMonitoringClientFactory;
use App\Infrastructure\Proxmox\PveCoreReadConnectorFactory;
use Doctrine\DBAL\Connection;

/** Deployment-only reader, using the existing TLS-verified, typed adapters. */
final readonly class NativeMaintenanceRemoteTasks implements MaintenanceRemoteTasks
{
    public function __construct(
        private Connection $connection,
        private PveCoreReadConnectorFactory $pve,
        private PbsMonitoringClientFactory $pbs,
    ) {}

    public function assertQuiet(ConnectionScanTarget $target, array $submissionNodes): void
    {
        $checkpoint = new class implements ConnectionReadCheckpoint { public function checkpoint(): void {} };
        foreach ($target->endpoints as $endpoint) {
            try {
                if (ProxmoxProduct::Pve === $target->product) {
                    $configuration = (new DbalPveEndpointReadConfigurationSource($this->connection, includeDisabled: true))
                        ->load($target->connectionId, $endpoint->endpointId, $target->expectedRevision);
                    $client = $this->pve->create($configuration, $checkpoint)->connect();
                    if (!$client->permissions()->isComplete()) throw new MaintenanceQuiescenceFailure();
                    $topology = $client->topology();
                    if (!$topology->isComplete()) throw new MaintenanceQuiescenceFailure();
                    $nodes = $submissionNodes;
                    foreach ($topology->nodes as $node) {
                        if (true !== $node->online) throw new MaintenanceQuiescenceFailure();
                        $nodes[] = $node->name;
                    }
                    foreach (array_unique($nodes) as $node) {
                        $page = $client->backupTaskPage($node, PveTaskQuery::active());
                        if (!$page->isComplete()) throw new MaintenanceQuiescenceFailure();
                        if ([] !== $page->tasks) throw new MaintenanceQuiescenceFailure(true);
                        if (0 !== $page->rawRowCount || !$page->isShort()) throw new MaintenanceQuiescenceFailure();
                    }
                } else {
                    $configuration = (new DbalPbsEndpointReadConfigurationSource($this->connection, includeDisabled: true))
                        ->load($target->connectionId, $endpoint->endpointId, $target->expectedRevision);
                    $client = $this->pbs->createMonitoringClient($configuration, $checkpoint, new PbsTasksAndJobsLimits());
                    if (!$client->aclEvidence()->hasTaskReadEvidence()) throw new MaintenanceQuiescenceFailure();
                    $page = $client->page(new PbsTaskListQuery(PbsTaskFilterFamily::Backup, PbsTaskPass::Running, 0, 100, null));
                    if ([] !== $page->tasks) throw new MaintenanceQuiescenceFailure(true);
                    if (0 !== $page->rawRowCount || (null !== $page->total && 0 !== $page->total)) throw new MaintenanceQuiescenceFailure();
                }
                return;
            } catch (MaintenanceQuiescenceFailure $failure) {
                // A positive busy observation cannot be overridden by another endpoint.
                if ($failure->busy) throw $failure;
            } catch (\Throwable) {
                // Try the next endpoint without surfacing addresses or credential data.
            }
        }
        throw new MaintenanceQuiescenceFailure();
    }
}
