<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\InstallationBinding;
use App\Application\Inventory\Connection\InstallationBindingKind;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Proxmox\Pbs\PbsJobObservation;
use App\Application\Proxmox\Pbs\PbsTaskObservation;
use App\Application\Proxmox\Pve\PveBackupJob;
use App\Application\Proxmox\Pve\PveBackupTask;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class MonitoringCommit
{
    /** @var list<MonitoringScopeResult> */ public array $scopes;
    /** @var list<PveBackupJob> */ public array $pveJobs;
    /** @var list<PveBackupTask> */ public array $pveTasks;
    /** @var list<PbsJobObservation> */ public array $pbsJobs;
    /** @var list<PbsTaskObservation> */ public array $pbsTasks;
    public DateTimeImmutable $observedAt;

    /**
     * @param list<MonitoringScopeResult> $scopes
     * @param list<PveBackupJob>          $pveJobs
     * @param list<PveBackupTask>         $pveTasks
     * @param list<PbsJobObservation>     $pbsJobs
     * @param list<PbsTaskObservation>    $pbsTasks
     * @param list<\App\Application\Proxmox\Pbs\PbsTaskInspection> $pbsInspections
     */
    public function __construct(
        public InventoryIdentifier $runId,
        public InventoryIdentifier $parentRunId,
        public InventoryIdentifier $connectionId,
        public EndpointId $endpointId,
        public ProxmoxProduct $product,
        public InstallationBinding $binding,
        public MonitoringRunKind $kind,
        public int $expectedConnectionRevision,
        array $scopes,
        array $pveJobs,
        array $pveTasks,
        array $pbsJobs,
        array $pbsTasks,
        DateTimeImmutable $observedAt,
        public array $pbsInspections = [],
    ) {
        if ($this->expectedConnectionRevision < 1
            || $this->binding->product !== $this->product
            || InstallationBindingKind::PbsLegacyNode === $this->binding->kind
                && $this->binding->legacyEndpointId?->bytes !== $this->endpointId->bytes
            || [] === $scopes) {
            throw new InvalidArgumentException('The monitoring commit header is invalid.');
        }

        $scopeMap = [];
        foreach ($scopes as $scope) {
            // @phpstan-ignore instanceof.alwaysTrue (enforce the declared runtime boundary)
            if (!$scope instanceof MonitoringScopeResult) {
                throw new InvalidArgumentException('The monitoring scopes contain invalid values.');
            }
            $scopeIdentity = $scope->scope->value."\0".$scope->key."\0".$scope->source->value."\0".($scope->filter ?? '@none');
            if (isset($scopeMap[$scopeIdentity])) {
                throw new InvalidArgumentException('The monitoring scopes contain duplicates.');
            }
            $this->assertScopeMatchesHeader($scope);
            $scopeMap[$scopeIdentity] = $scope;
        }
        ksort($scopeMap, SORT_STRING);
        $this->scopes = array_values($scopeMap);

        $this->pveJobs = $this->uniquePveJobs($pveJobs);
        $this->pveTasks = $this->uniquePveTasks($pveTasks);
        $this->pbsJobs = $this->uniquePbsJobs($pbsJobs);
        $this->pbsTasks = $this->uniquePbsTasks($pbsTasks);
        $this->assertPayloadMatchesHeader();
        $inspected = [];
        $knownTasks = array_column(array_column($this->pbsTasks, 'upid'), 'value');
        foreach ($pbsInspections as $inspection) {
            if (!\in_array($inspection->upid->value, $knownTasks, true) || isset($inspected[$inspection->upid->value])) {
                throw new InvalidArgumentException('PBS inspection must belong to one observed task.');
            }
            $inspected[$inspection->upid->value] = true;
        }
        $this->observedAt = $observedAt->setTimezone(new DateTimeZone('UTC'));
    }

    public function status(): MonitoringRunStatus
    {
        $complete = 0;
        $partial = 0;
        $failed = 0;
        foreach ($this->scopes as $scope) {
            if (MonitoringScopeStatus::Complete === $scope->status) {
                ++$complete;
            } elseif (MonitoringScopeStatus::Partial === $scope->status) {
                ++$partial;
            } else {
                ++$failed;
            }
        }
        if (0 === $complete + $partial) {
            return MonitoringRunStatus::Failed;
        }
        if ($partial > 0 || $failed > 0) {
            return MonitoringRunStatus::Partial;
        }

        return MonitoringRunStatus::Succeeded;
    }

    public function pagesRead(): int
    {
        return array_sum(array_column($this->scopes, 'pagesRead'));
    }

    public function rowsRead(): int
    {
        return array_sum(array_column($this->scopes, 'rowsRead'));
    }

    public function itemsSeen(): int
    {
        if (ProxmoxProduct::Pve === $this->product) {
            return MonitoringRunKind::ExternalJobs === $this->kind
                ? count($this->pveJobs)
                : count($this->pveTasks);
        }
        return MonitoringRunKind::ExternalJobs === $this->kind
            ? count($this->pbsJobs)
            : count($this->pbsTasks);
    }

    private function assertScopeMatchesHeader(MonitoringScopeResult $scope): void
    {
        $isPve = MonitoringScopeType::PveBackupJobs === $scope->scope
            || MonitoringScopeType::PveTasksActive === $scope->scope
            || MonitoringScopeType::PveTasksArchive === $scope->scope;
        $isJobs = str_ends_with($scope->scope->value, '_jobs');
        if (($isPve !== (ProxmoxProduct::Pve === $this->product))
            || ($isJobs !== (MonitoringRunKind::ExternalJobs === $this->kind))) {
            throw new InvalidArgumentException('A monitoring scope does not match its run header.');
        }
    }

    private function assertPayloadMatchesHeader(): void
    {
        if (ProxmoxProduct::Pve === $this->product) {
            if ([] !== $this->pbsJobs || [] !== $this->pbsTasks
                || MonitoringRunKind::ExternalJobs === $this->kind && [] !== $this->pveTasks
                || MonitoringRunKind::ObservedTasks === $this->kind && [] !== $this->pveJobs) {
                throw new InvalidArgumentException('The PVE monitoring payload does not match its run header.');
            }
            return;
        }
        if ([] !== $this->pveJobs || [] !== $this->pveTasks
            || MonitoringRunKind::ExternalJobs === $this->kind && [] !== $this->pbsTasks
            || MonitoringRunKind::ObservedTasks === $this->kind && [] !== $this->pbsJobs) {
            throw new InvalidArgumentException('The PBS monitoring payload does not match its run header.');
        }
    }

    /**
     * @param list<PveBackupJob> $jobs
     * @return list<PveBackupJob>
     */
    private function uniquePveJobs(array $jobs): array
    {
        $map = [];
        foreach ($jobs as $job) {
            // @phpstan-ignore instanceof.alwaysTrue (enforce the declared runtime boundary)
            if (!$job instanceof PveBackupJob || isset($map[$job->id])) {
                throw new InvalidArgumentException('The PVE external jobs contain duplicates.');
            }
            $map[$job->id] = $job;
        }
        ksort($map, SORT_STRING);
        return array_values($map);
    }

    /**
     * @param list<PveBackupTask> $tasks
     * @return list<PveBackupTask>
     */
    private function uniquePveTasks(array $tasks): array
    {
        $map = [];
        foreach ($tasks as $task) {
            // @phpstan-ignore instanceof.alwaysTrue (enforce the declared runtime boundary)
            if (!$task instanceof PveBackupTask || isset($map[$task->upid->raw])) {
                throw new InvalidArgumentException('The PVE observed tasks contain duplicates.');
            }
            $map[$task->upid->raw] = $task;
        }
        ksort($map, SORT_STRING);
        return array_values($map);
    }

    /**
     * @param list<PbsJobObservation> $jobs
     * @return list<PbsJobObservation>
     */
    private function uniquePbsJobs(array $jobs): array
    {
        $map = [];
        foreach ($jobs as $job) {
            // @phpstan-ignore instanceof.alwaysTrue (enforce the declared runtime boundary)
            if (!$job instanceof PbsJobObservation || isset($map[$job->key()])) {
                throw new InvalidArgumentException('The PBS external jobs contain duplicates.');
            }
            $map[$job->key()] = $job;
        }
        ksort($map, SORT_STRING);
        return array_values($map);
    }

    /**
     * @param list<PbsTaskObservation> $tasks
     * @return list<PbsTaskObservation>
     */
    private function uniquePbsTasks(array $tasks): array
    {
        $map = [];
        foreach ($tasks as $task) {
            // @phpstan-ignore instanceof.alwaysTrue (enforce the declared runtime boundary)
            if (!$task instanceof PbsTaskObservation || isset($map[$task->upid->value])) {
                throw new InvalidArgumentException('The PBS observed tasks contain duplicates.');
            }
            $map[$task->upid->value] = $task;
        }
        ksort($map, SORT_STRING);
        return array_values($map);
    }
}
