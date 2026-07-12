<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorLeaseOwnershipLost;
use App\Application\Collector\CollectorShutdownRequested;
use App\Application\Inventory\Connection\ConnectionInstallationRead;
use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\InventoryIdentifierGenerator;
use App\Application\Proxmox\Pbs\PbsInstallationSnapshot;
use App\Application\Proxmox\Pve\PveInventorySnapshot;
use App\Domain\Shared\Clock;

final readonly class RunSelectedEndpointMonitoring implements SelectedEndpointMonitoring
{
    public function __construct(
        private SelectedEndpointMonitoringReader $reader,
        private MonitoringWindowPlanner $windows,
        private MapSelectedEndpointMonitoring $mapper,
        private MonitoringRunStore $store,
        private InventoryIdentifierGenerator $identifierGenerator,
        private Clock $clock,
        private int $pveMaximumWindowSeconds = 86_400,
        private int $pbsMaximumWindowSeconds = 86_400,
    ) {
        if ($this->pveMaximumWindowSeconds < 1 || $this->pveMaximumWindowSeconds > 86_400
            || $this->pbsMaximumWindowSeconds < 1 || $this->pbsMaximumWindowSeconds > 86_400) {
            throw new \InvalidArgumentException('A monitoring maximum window is invalid.');
        }
    }

    public function execute(
        CollectorLease $lease,
        InventoryIdentifier $parentRunId,
        ConnectionInstallationRead $read,
        ConnectionReadCheckpoint $checkpoint,
    ): ConnectionMonitoringResult {
        $checkpoint->checkpoint();
        $startedAt = $this->clock->now();
        $connectionId = new InventoryIdentifier($read->connectionId->bytes);
        $jobs = $this->start($parentRunId, $connectionId, $read, MonitoringRunKind::ExternalJobs, $startedAt);
        $tasks = $this->start($parentRunId, $connectionId, $read, MonitoringRunKind::ObservedTasks, $startedAt);
        $begun = [];
        try {
            $this->store->begin($lease, $jobs);
            $begun[] = $jobs;
            $checkpoint->checkpoint();
            $this->store->begin($lease, $tasks);
            $begun[] = $tasks;
            $checkpoint->checkpoint();
        } catch (CollectorLeaseOwnershipLost $critical) {
            throw $critical;
        } catch (CollectorShutdownRequested $shutdown) {
            $this->failBegun($lease, $begun, 'collector_shutdown_requested');
            throw $shutdown;
        } catch (\Throwable) {
            $this->failBegun($lease, $begun, 'monitoring_begin_failed');
            return new ConnectionMonitoringResult(MonitoringRunStatus::Failed, MonitoringRunStatus::Failed);
        }

        $cutoff = $this->clock->now();
        try {
            if (ProxmoxProduct::Pve === $read->binding->product) {
                if (!$read->snapshot instanceof PveInventorySnapshot) {
                    throw new \LogicException('A PVE monitoring run requires a PVE inventory snapshot.');
                }
                $scopeKeys = [] === $read->snapshot->topologyNodeNames
                    ? ['@installation'] : $read->snapshot->topologyNodeNames;
                $window = $this->windows->plan(
                    $read->connectionId,
                    MonitoringCursorKind::PveTasksArchive,
                    $scopeKeys,
                    $cutoff,
                    $this->pveMaximumWindowSeconds,
                );
                $snapshot = $this->reader->readPve(
                    $read->connectionId,
                    $read->endpointId,
                    $read->expectedRevision,
                    $read->snapshot->topologyNodeNames,
                    $window->pve(),
                    $checkpoint,
                );
                $observedAt = $this->clock->now();
                $jobsCommit = $this->mapper->pveJobs($jobs, $snapshot, $observedAt);
                $tasksCommit = $this->mapper->pveTasks($tasks, $snapshot, $observedAt);
            } else {
                if (!$read->snapshot instanceof PbsInstallationSnapshot) {
                    throw new \LogicException('A PBS monitoring run requires a PBS inventory snapshot.');
                }
                $window = $this->windows->plan(
                    $read->connectionId,
                    MonitoringCursorKind::PbsTasksWindow,
                    [$read->snapshot->node],
                    $cutoff,
                    $this->pbsMaximumWindowSeconds,
                );
                $snapshot = $this->reader->readPbs(
                    $read->connectionId,
                    $read->endpointId,
                    $read->expectedRevision,
                    $read->snapshot->node,
                    $window->pbs(),
                    $checkpoint,
                );
                $observedAt = $this->clock->now();
                $jobsCommit = $this->mapper->pbsJobs($jobs, $snapshot, $observedAt);
                $tasksCommit = $this->mapper->pbsTasks($tasks, $snapshot, $read->snapshot->node, $window, $observedAt);
            }
            $checkpoint->checkpoint();
        } catch (CollectorLeaseOwnershipLost $critical) {
            throw $critical;
        } catch (CollectorShutdownRequested $shutdown) {
            $this->failBegun($lease, $begun, 'collector_shutdown_requested');
            throw $shutdown;
        } catch (\Throwable) {
            $this->failBegun($lease, $begun, 'monitoring_read_failed');
            return new ConnectionMonitoringResult(MonitoringRunStatus::Failed, MonitoringRunStatus::Failed);
        }

        $pending = [$jobs, $tasks];
        try {
            $jobsStatus = $this->persist($lease, $jobsCommit);
            array_shift($pending);
            $checkpoint->checkpoint();
            $tasksStatus = $this->persist($lease, $tasksCommit);
            array_shift($pending);
            $checkpoint->checkpoint();
        } catch (CollectorShutdownRequested $shutdown) {
            $this->failBegun($lease, $pending, 'collector_shutdown_requested');
            throw $shutdown;
        }
        return new ConnectionMonitoringResult($jobsStatus, $tasksStatus);
    }

    private function start(
        InventoryIdentifier $parentRunId,
        InventoryIdentifier $connectionId,
        ConnectionInstallationRead $read,
        MonitoringRunKind $kind,
        \DateTimeImmutable $startedAt,
    ): MonitoringRunStart {
        return new MonitoringRunStart(
            $this->identifierGenerator->generate(),
            $parentRunId,
            $connectionId,
            $read->endpointId,
            $read->binding->product,
            $read->binding,
            $kind,
            $read->expectedRevision,
            $startedAt,
        );
    }

    private function persist(
        CollectorLease $lease,
        MonitoringCommit $commit,
    ): MonitoringRunStatus {
        try {
            if (MonitoringRunStatus::Failed === $commit->status()) {
                $this->store->finishFailedCommit($lease, $commit, 'all_scopes_failed');
                $status = MonitoringRunStatus::Failed;
            } else {
                $status = $this->store->apply($lease, $commit)->status;
            }
            return $status;
        } catch (CollectorLeaseOwnershipLost|CollectorShutdownRequested $critical) {
            throw $critical;
        } catch (\Throwable) {
            $this->failBegun($lease, [$this->startFromCommit($commit)], 'monitoring_apply_failed');
            return MonitoringRunStatus::Failed;
        }
    }

    /** @param list<MonitoringRunStart> $runs */
    private function failBegun(CollectorLease $lease, array $runs, string $errorCode): void
    {
        foreach ($runs as $run) {
            try {
                $this->store->fail($lease, new MonitoringRunFailure(
                    $run->runId,
                    $run->connectionId,
                    $errorCode,
                    $this->clock->now(),
                ));
            } catch (\Throwable) {
                // A store may already have terminalized the child before surfacing its failure.
            }
        }
    }

    private function startFromCommit(MonitoringCommit $commit): MonitoringRunStart
    {
        return new MonitoringRunStart(
            $commit->runId,
            $commit->parentRunId,
            $commit->connectionId,
            $commit->endpointId,
            $commit->product,
            $commit->binding,
            $commit->kind,
            $commit->expectedConnectionRevision,
            $commit->observedAt,
        );
    }
}
