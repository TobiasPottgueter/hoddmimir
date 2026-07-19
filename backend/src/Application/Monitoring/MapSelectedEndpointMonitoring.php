<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use App\Application\Proxmox\Pbs\PbsJobKind;
use App\Application\Proxmox\Pbs\PbsNodeRoute;
use App\Application\Proxmox\Pbs\PbsJobListSnapshot;
use App\Application\Proxmox\Pbs\PbsTaskFilterFamily;
use App\Application\Proxmox\Pbs\PbsTaskPass;
use App\Application\Proxmox\Pbs\PbsTaskStreamStatus;
use App\Application\Proxmox\Pve\PveBackupInventorySnapshot;
use App\Application\Proxmox\Pve\PveTaskSource;
use App\Application\Proxmox\Pve\PveTaskStreamScanResult;
use App\Application\Proxmox\Pve\PveTaskStreamScanStatus;
use DateTimeImmutable;

final readonly class MapSelectedEndpointMonitoring
{
    public function pveJobs(
        MonitoringRunStart $run,
        PveBackupInventorySnapshot $snapshot,
        DateTimeImmutable $observedAt,
    ): MonitoringCommit {
        if ($snapshot->jobs->isComplete()) {
            $status = MonitoringScopeStatus::Complete;
        } elseif ([] === $snapshot->jobs->jobs) {
            $status = MonitoringScopeStatus::Failed;
        } else {
            $status = MonitoringScopeStatus::Partial;
        }
        $scope = new MonitoringScopeResult(
            MonitoringScopeType::PveBackupJobs,
            '@installation',
            MonitoringSourceKind::Jobs,
            null,
            $status,
            null,
            null,
            1,
            count($snapshot->jobs->jobs),
            count($snapshot->jobs->jobs),
            false,
            false,
            MonitoringScopeStatus::Complete === $status ? null : 'job_read_incomplete',
            $observedAt,
        );

        return $this->commit($run, [$scope], $snapshot->jobs->jobs, [], [], [], $observedAt);
    }

    public function pveTasks(
        MonitoringRunStart $run,
        PveBackupInventorySnapshot $snapshot,
        DateTimeImmutable $observedAt,
    ): MonitoringCommit {
        $scopes = [];
        foreach ($snapshot->taskStreams as $stream) {
            $scopes[] = $this->pveTaskScope($snapshot, $stream, $observedAt);
        }
        if ([] === $scopes) {
            foreach ([PveTaskSource::Active, PveTaskSource::Archive] as $source) {
                $scopes[] = new MonitoringScopeResult(
                    PveTaskSource::Active === $source
                        ? MonitoringScopeType::PveTasksActive : MonitoringScopeType::PveTasksArchive,
                    '@installation',
                    PveTaskSource::Active === $source
                        ? MonitoringSourceKind::Active : MonitoringSourceKind::Archive,
                    'vzdump',
                    MonitoringScopeStatus::Failed,
                    PveTaskSource::Archive === $source ? $this->at($snapshot->archiveWindow->since) : null,
                    PveTaskSource::Archive === $source ? $this->at($snapshot->archiveWindow->until) : null,
                    0,
                    0,
                    0,
                    true,
                    PveTaskSource::Archive === $source && $snapshot->archiveWindow->historyGap,
                    'invalid_topology',
                    $observedAt,
                );
            }
        }

        return $this->commit($run, $scopes, [], $snapshot->tasks, [], [], $observedAt);
    }

    public function pbsJobs(
        MonitoringRunStart $run,
        PbsExternalMonitoringSnapshot $snapshot,
        DateTimeImmutable $observedAt,
    ): MonitoringCommit {
        $scopes = [];
        $jobs = [];
        /** @var list<array{errorKey: string, scopeType: MonitoringScopeType, kind: PbsJobKind, list: ?PbsJobListSnapshot}> $families */
        $families = [
            ['errorKey' => 'prune', 'scopeType' => MonitoringScopeType::PbsPruneJobs,
                'kind' => PbsJobKind::Prune, 'list' => $snapshot->pruneJobs],
            ['errorKey' => 'sync', 'scopeType' => MonitoringScopeType::PbsSyncJobs,
                'kind' => PbsJobKind::Sync, 'list' => $snapshot->syncJobs],
            ['errorKey' => 'verify', 'scopeType' => MonitoringScopeType::PbsVerifyJobs,
                'kind' => PbsJobKind::Verify, 'list' => $snapshot->verifyJobs],
        ];
        foreach ($families as $family) {
            ['errorKey' => $errorKey, 'scopeType' => $scopeType, 'kind' => $kind, 'list' => $list] = $family;
            if (null !== $list) {
                foreach ($list->jobs as $job) {
                    $jobs[] = $job;
                }
            }
            $aclComplete = null !== $snapshot->acl
                && $snapshot->acl->hasDatastoreReadEvidence()
                && (PbsJobKind::Sync !== $kind || $snapshot->acl->hasRemoteReadEvidence());
            $status = null === $list
                ? MonitoringScopeStatus::Failed
                : ($aclComplete ? MonitoringScopeStatus::Complete : MonitoringScopeStatus::Partial);
            $errorCode = null;
            if (MonitoringScopeStatus::Partial === $status) {
                $errorCode = 'acl_incomplete';
            } elseif (MonitoringScopeStatus::Failed === $status) {
                $errorCode = $snapshot->errors[$errorKey];
            }
            $scopes[] = new MonitoringScopeResult(
                $scopeType,
                '@installation',
                MonitoringSourceKind::Jobs,
                $kind->value,
                $status,
                null,
                null,
                null === $list ? 0 : 1,
                null === $list ? 0 : count($list->jobs),
                null === $list ? 0 : count($list->jobs),
                false,
                false,
                $errorCode,
                $observedAt,
            );
        }

        return $this->commit($run, $scopes, [], [], $jobs, [], $observedAt);
    }

    public function pbsTasks(
        MonitoringRunStart $run,
        PbsExternalMonitoringSnapshot $snapshot,
        MonitoringWindowPlan $window,
        DateTimeImmutable $observedAt,
    ): MonitoringCommit {
        $node = PbsNodeRoute::Local->value;
        $scopes = [];
        if (null === $snapshot->tasks) {
            foreach (PbsTaskFilterFamily::cases() as $family) {
                foreach (PbsTaskPass::cases() as $pass) {
                    $history = PbsTaskPass::History === $pass;
                    $scopes[] = new MonitoringScopeResult(
                        $history ? MonitoringScopeType::PbsTasksWindow : MonitoringScopeType::PbsTasksRunning,
                        $node,
                        $history ? MonitoringSourceKind::History : MonitoringSourceKind::Running,
                        $family->value,
                        MonitoringScopeStatus::Failed,
                        $history ? $this->at($window->since) : null,
                        $history ? $this->at($window->until) : null,
                        0,
                        0,
                        0,
                        false,
                        $history && $window->historyGap,
                        $snapshot->errors['tasks'] ?? 'task_read_failed',
                        $observedAt,
                    );
                }
            }
            return $this->commit($run, $scopes, [], [], [], [], $observedAt);
        }

        $taskAclComplete = null !== $snapshot->acl && $snapshot->acl->hasTaskReadEvidence();
        foreach ($snapshot->tasks->streams as $stream) {
            $history = PbsTaskPass::History === $stream->pass;
            $gap = $history && ($window->historyGap || $stream->historyGap);
            if (PbsTaskStreamStatus::Complete === $stream->status) {
                $scopeStatus = $gap || !$taskAclComplete
                    ? MonitoringScopeStatus::Partial : MonitoringScopeStatus::Complete;
            } elseif (PbsTaskStreamStatus::Partial === $stream->status) {
                $scopeStatus = MonitoringScopeStatus::Partial;
            } else {
                $scopeStatus = MonitoringScopeStatus::Failed;
            }
            $scopes[] = new MonitoringScopeResult(
                $history ? MonitoringScopeType::PbsTasksWindow : MonitoringScopeType::PbsTasksRunning,
                $node,
                $history ? MonitoringSourceKind::History : MonitoringSourceKind::Running,
                $stream->family->value,
                $scopeStatus,
                $history ? $this->at($window->since) : null,
                $history ? $this->at($window->until) : null,
                $stream->pagesRead,
                $stream->rowsRead,
                $stream->itemsSeen,
                $stream->truncated,
                $gap,
                MonitoringScopeStatus::Complete === $scopeStatus
                    ? null : (null !== $stream->issueCode
                        ? $stream->issueCode->value
                        : ($gap ? 'history_gap' : 'acl_incomplete')),
                $observedAt,
            );
        }

        return $this->commit($run, $scopes, [], [], [], $snapshot->tasks->tasks, $observedAt);
    }

    private function pveTaskScope(
        PveBackupInventorySnapshot $snapshot,
        PveTaskStreamScanResult $stream,
        DateTimeImmutable $observedAt,
    ): MonitoringScopeResult {
        $archive = PveTaskSource::Archive === $stream->source;
        $gap = $archive && $snapshot->archiveWindow->historyGap;
        if (PveTaskStreamScanStatus::Complete === $stream->status) {
            $status = $gap ? MonitoringScopeStatus::Partial : MonitoringScopeStatus::Complete;
        } elseif (PveTaskStreamScanStatus::Partial === $stream->status) {
            $status = MonitoringScopeStatus::Partial;
        } else {
            $status = MonitoringScopeStatus::Failed;
        }
        $items = 0;
        foreach ($snapshot->tasks as $task) {
            if ($task->upid->node === $stream->node
                && ($archive ? $task->seenArchive : $task->seenActive)) {
                ++$items;
            }
        }
        return new MonitoringScopeResult(
            $archive ? MonitoringScopeType::PveTasksArchive : MonitoringScopeType::PveTasksActive,
            $stream->node,
            $archive ? MonitoringSourceKind::Archive : MonitoringSourceKind::Active,
            'vzdump',
            $status,
            $archive ? $this->at($snapshot->archiveWindow->since) : null,
            $archive ? $this->at($snapshot->archiveWindow->until) : null,
            $stream->requests,
            $stream->rawRows,
            $items,
            PveTaskStreamScanStatus::Partial === $stream->status
                || PveTaskStreamScanStatus::NotScannedLimit === $stream->status,
            $gap,
            MonitoringScopeStatus::Complete === $status
                ? null : ($gap ? 'history_gap' : $stream->status->value),
            $observedAt,
        );
    }

    /**
     * @param list<MonitoringScopeResult> $scopes
     * @param list<\App\Application\Proxmox\Pve\PveBackupJob> $pveJobs
     * @param list<\App\Application\Proxmox\Pve\PveBackupTask> $pveTasks
     * @param list<\App\Application\Proxmox\Pbs\PbsJobObservation> $pbsJobs
     * @param list<\App\Application\Proxmox\Pbs\PbsTaskObservation> $pbsTasks
     */
    private function commit(
        MonitoringRunStart $run,
        array $scopes,
        array $pveJobs,
        array $pveTasks,
        array $pbsJobs,
        array $pbsTasks,
        DateTimeImmutable $observedAt,
    ): MonitoringCommit {
        return new MonitoringCommit(
            $run->runId,
            $run->parentRunId,
            $run->connectionId,
            $run->endpointId,
            $run->product,
            $run->binding,
            $run->kind,
            $run->expectedConnectionRevision,
            $scopes,
            $pveJobs,
            $pveTasks,
            $pbsJobs,
            $pbsTasks,
            $observedAt,
        );
    }

    private function at(int $epoch): DateTimeImmutable
    {
        return new DateTimeImmutable('@'.$epoch);
    }
}
