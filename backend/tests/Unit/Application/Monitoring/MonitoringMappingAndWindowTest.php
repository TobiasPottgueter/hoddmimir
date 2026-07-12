<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Monitoring;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\InstallationBinding;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Monitoring\MapSelectedEndpointMonitoring;
use App\Application\Monitoring\MonitoringCursorCatalog;
use App\Application\Monitoring\MonitoringCursorKind;
use App\Application\Monitoring\MonitoringRunKind;
use App\Application\Monitoring\MonitoringRunStart;
use App\Application\Monitoring\MonitoringRunStatus;
use App\Application\Monitoring\MonitoringScopeResult;
use App\Application\Monitoring\MonitoringScopeStatus;
use App\Application\Monitoring\MonitoringWindowPlanner;
use App\Application\Monitoring\PbsExternalMonitoringSnapshot;
use App\Application\Proxmox\Pbs\PbsAclEvidence;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsEffectivePermission;
use App\Application\Proxmox\Pbs\PbsJobId;
use App\Application\Proxmox\Pbs\PbsJobKind;
use App\Application\Proxmox\Pbs\PbsJobListSnapshot;
use App\Application\Proxmox\Pbs\PbsJobObservation;
use App\Application\Proxmox\Pbs\PbsTaskScanIssueCode;
use App\Application\Proxmox\Pbs\PbsTaskFilterFamily;
use App\Application\Proxmox\Pbs\PbsTaskPass;
use App\Application\Proxmox\Pbs\PbsTaskScanSnapshot;
use App\Application\Proxmox\Pbs\PbsTaskStreamResult;
use App\Application\Proxmox\Pbs\PbsTaskStreamStatus;
use App\Application\Proxmox\Pbs\PbsTaskWindow;
use App\Application\Proxmox\Pve\PveBackupInventoryIssue;
use App\Application\Proxmox\Pve\PveBackupInventoryIssueCode;
use App\Application\Proxmox\Pve\PveBackupInventorySnapshot;
use App\Application\Proxmox\Pve\PveBackupJob;
use App\Application\Proxmox\Pve\PveBackupJobCapabilities;
use App\Application\Proxmox\Pve\PveBackupJobInventory;
use App\Application\Proxmox\Pve\PveBackupTask;
use App\Application\Proxmox\Pve\PveTaskArchiveWindow;
use App\Application\Proxmox\Pve\PveTaskSource;
use App\Application\Proxmox\Pve\PveTaskStreamScanResult;
use App\Application\Proxmox\Pve\PveTaskStreamScanStatus;
use App\Application\Proxmox\Pve\PveUpid;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MonitoringMappingAndWindowTest extends TestCase
{
    public function testPveJobMappingDistinguishesCompletePartialAndFailedEvidence(): void
    {
        $mapper = new MapSelectedEndpointMonitoring();
        $complete = $mapper->pveJobs(
            $this->monitoringRun(MonitoringRunKind::ExternalJobs, ProxmoxProduct::Pve),
            $this->pveSnapshot(new PveBackupJobInventory(
                PveBackupJobCapabilities::forMajor(9),
                [$this->pveJob()],
                [],
            )),
            new DateTimeImmutable('@200'),
        );
        self::assertSame(MonitoringRunStatus::Succeeded, $complete->status());
        self::assertCount(1, $complete->pveJobs);
        self::assertSame([
            'pve_backup_jobs', '@installation', 'jobs', null, 'complete', null, null,
            1, 1, 1, false, false, null, 200,
        ], $this->scopeProjection($complete->scopes[0]));

        $issue = new PveBackupInventoryIssue(
            PveBackupInventoryIssueCode::BackupJobReadFailed,
            '/cluster/backup',
            'data',
        );
        $partial = $mapper->pveJobs(
            $this->monitoringRun(MonitoringRunKind::ExternalJobs, ProxmoxProduct::Pve),
            $this->pveSnapshot(new PveBackupJobInventory(
                PveBackupJobCapabilities::forMajor(9),
                [$this->pveJob()],
                [$issue],
            )),
            new DateTimeImmutable('@200'),
        );
        self::assertSame(MonitoringRunStatus::Partial, $partial->status());
        self::assertSame([
            'pve_backup_jobs', '@installation', 'jobs', null, 'partial', null, null,
            1, 1, 1, false, false, 'job_read_incomplete', 200,
        ], $this->scopeProjection($partial->scopes[0]));

        $failed = $mapper->pveJobs(
            $this->monitoringRun(MonitoringRunKind::ExternalJobs, ProxmoxProduct::Pve),
            $this->pveSnapshot(new PveBackupJobInventory(
                PveBackupJobCapabilities::forMajor(9),
                [],
                [$issue],
            )),
            new DateTimeImmutable('@200'),
        );
        self::assertSame(MonitoringRunStatus::Failed, $failed->status());
        self::assertSame([
            'pve_backup_jobs', '@installation', 'jobs', null, 'failed', null, null,
            1, 0, 0, false, false, 'job_read_incomplete', 200,
        ], $this->scopeProjection($failed->scopes[0]));
    }

    public function testPveTaskMappingCoversEmptyTopologyAllStreamStatesAndHistoryGap(): void
    {
        $mapper = new MapSelectedEndpointMonitoring();
        $empty = $mapper->pveTasks(
            $this->monitoringRun(product: ProxmoxProduct::Pve),
            $this->pveSnapshot(
                new PveBackupJobInventory(PveBackupJobCapabilities::forMajor(9), [], []),
                [],
                [],
                true,
            ),
            new DateTimeImmutable('@200'),
        );
        self::assertSame(MonitoringRunStatus::Failed, $empty->status());
        self::assertCount(2, $empty->scopes);
        self::assertSame([
            ['pve_tasks_active', '@installation', 'active', 'vzdump', 'failed', null, null, 0, 0, 0, true, false, 'invalid_topology', 200],
            ['pve_tasks_archive', '@installation', 'archive', 'vzdump', 'failed', 100, 200, 0, 0, 0, true, true, 'invalid_topology', 200],
        ], array_map($this->scopeProjection(...), $empty->scopes));

        $upid = PveUpid::parse('UPID:pve-a:0000002A:000F4240:67000000:vzdump:101:observer@pve:');
        $tasks = [
            new PveBackupTask($upid, PveTaskSource::Archive, null, null, true, true),
        ];
        $streams = [
            new PveTaskStreamScanResult('pve-a', PveTaskSource::Active, PveTaskStreamScanStatus::Complete, 1, 1),
            new PveTaskStreamScanResult('pve-a', PveTaskSource::Archive, PveTaskStreamScanStatus::Complete, 1, 1),
            new PveTaskStreamScanResult('pve-b', PveTaskSource::Active, PveTaskStreamScanStatus::Partial, 1, 1),
            new PveTaskStreamScanResult('pve-b', PveTaskSource::Archive, PveTaskStreamScanStatus::Failed, 0, 0),
            new PveTaskStreamScanResult('pve-c', PveTaskSource::Archive, PveTaskStreamScanStatus::NotScannedLimit, 0, 0),
        ];
        $mixed = $mapper->pveTasks(
            $this->monitoringRun(product: ProxmoxProduct::Pve),
            $this->pveSnapshot(
                new PveBackupJobInventory(PveBackupJobCapabilities::forMajor(9), [], []),
                $tasks,
                $streams,
                true,
            ),
            new DateTimeImmutable('@200'),
        );
        self::assertSame(MonitoringRunStatus::Partial, $mixed->status());
        self::assertSame(1, $mixed->itemsSeen());
        self::assertSame([
            ['pve_tasks_active', 'pve-a', 'active', 'vzdump', 'complete', null, null, 1, 1, 1, false, false, null, 200],
            ['pve_tasks_active', 'pve-b', 'active', 'vzdump', 'partial', null, null, 1, 1, 0, true, false, 'partial', 200],
            ['pve_tasks_archive', 'pve-a', 'archive', 'vzdump', 'partial', 100, 200, 1, 1, 1, false, true, 'history_gap', 200],
            ['pve_tasks_archive', 'pve-b', 'archive', 'vzdump', 'failed', 100, 200, 0, 0, 0, false, true, 'history_gap', 200],
            ['pve_tasks_archive', 'pve-c', 'archive', 'vzdump', 'failed', 100, 200, 0, 0, 0, true, true, 'history_gap', 200],
        ], array_map($this->scopeProjection(...), $mixed->scopes));
    }

    public function testMissingPbsTaskSnapshotMapsEveryFamilyAndPassExactly(): void
    {
        $commit = (new MapSelectedEndpointMonitoring())->pbsTasks(
            $this->monitoringRun(),
            new PbsExternalMonitoringSnapshot(
                null,
                null,
                null,
                null,
                null,
                [
                    'acl' => 'not_read',
                    'prune' => 'not_read',
                    'sync' => 'not_read',
                    'verify' => 'not_read',
                    'tasks' => 'permission_denied',
                ],
            ),
            'pbs-a',
            new \App\Application\Monitoring\MonitoringWindowPlan(100, 200, true),
            new DateTimeImmutable('@250'),
        );

        self::assertSame(MonitoringRunStatus::Failed, $commit->status());
        self::assertSame(0, $commit->pagesRead());
        self::assertSame(0, $commit->rowsRead());
        self::assertSame(0, $commit->itemsSeen());

        $actual = array_map($this->scopeProjection(...), $commit->scopes);
        self::assertSame([
            ['pbs_tasks_running', 'pbs-a', 'running', 'backup', 'failed', null, null, 0, 0, 0, false, false, 'permission_denied', 250],
            ['pbs_tasks_running', 'pbs-a', 'running', 'prune', 'failed', null, null, 0, 0, 0, false, false, 'permission_denied', 250],
            ['pbs_tasks_running', 'pbs-a', 'running', 'syncjob', 'failed', null, null, 0, 0, 0, false, false, 'permission_denied', 250],
            ['pbs_tasks_running', 'pbs-a', 'running', 'verif', 'failed', null, null, 0, 0, 0, false, false, 'permission_denied', 250],
            ['pbs_tasks_window', 'pbs-a', 'history', 'backup', 'failed', 100, 200, 0, 0, 0, false, true, 'permission_denied', 250],
            ['pbs_tasks_window', 'pbs-a', 'history', 'prune', 'failed', 100, 200, 0, 0, 0, false, true, 'permission_denied', 250],
            ['pbs_tasks_window', 'pbs-a', 'history', 'syncjob', 'failed', 100, 200, 0, 0, 0, false, true, 'permission_denied', 250],
            ['pbs_tasks_window', 'pbs-a', 'history', 'verif', 'failed', 100, 200, 0, 0, 0, false, true, 'permission_denied', 250],
        ], $actual);
    }

    public function testPbsJobMappingSeparatesAclPartialReadFailureAndCompleteFamilies(): void
    {
        $mapper = new MapSelectedEndpointMonitoring();
        $acl = $this->acl(true);
        $prune = new PbsJobListSnapshot(PbsJobKind::Prune, str_repeat('a', 64), [$this->pbsJob()]);
        $verify = new PbsJobListSnapshot(PbsJobKind::Verify, str_repeat('b', 64), []);
        $snapshot = new PbsExternalMonitoringSnapshot(
            $acl,
            $prune,
            null,
            $verify,
            null,
            ['sync' => 'permission_denied', 'tasks' => 'not_read'],
        );
        $commit = $mapper->pbsJobs(
            $this->monitoringRun(MonitoringRunKind::ExternalJobs),
            $snapshot,
            new DateTimeImmutable('@200'),
        );
        self::assertSame(MonitoringRunStatus::Partial, $commit->status());
        self::assertCount(1, $commit->pbsJobs);
        self::assertSame([
            ['pbs_prune_jobs', '@installation', 'jobs', 'prune', 'complete', null, null, 1, 1, 1, false, false, null, 200],
            ['pbs_sync_jobs', '@installation', 'jobs', 'sync', 'failed', null, null, 0, 0, 0, false, false, 'permission_denied', 200],
            ['pbs_verify_jobs', '@installation', 'jobs', 'verify', 'complete', null, null, 1, 0, 0, false, false, null, 200],
        ], array_map($this->scopeProjection(...), $commit->scopes));

        $incompleteAcl = new PbsExternalMonitoringSnapshot(
            $this->acl(false),
            $prune,
            new PbsJobListSnapshot(PbsJobKind::Sync, str_repeat('c', 64), []),
            $verify,
            null,
            ['tasks' => 'not_read'],
        );
        $partial = $mapper->pbsJobs(
            $this->monitoringRun(MonitoringRunKind::ExternalJobs),
            $incompleteAcl,
            new DateTimeImmutable('@200'),
        );
        self::assertSame(MonitoringRunStatus::Partial, $partial->status());
        self::assertSame([
            ['pbs_prune_jobs', '@installation', 'jobs', 'prune', 'complete', null, null, 1, 1, 1, false, false, null, 200],
            ['pbs_sync_jobs', '@installation', 'jobs', 'sync', 'partial', null, null, 1, 0, 0, false, false, 'acl_incomplete', 200],
            ['pbs_verify_jobs', '@installation', 'jobs', 'verify', 'complete', null, null, 1, 0, 0, false, false, null, 200],
        ], array_map($this->scopeProjection(...), $partial->scopes));
    }

    public function testPbsTaskMappingCoversFailedPartialGapAndAclDerivedErrors(): void
    {
        $streams = [
            new PbsTaskStreamResult(
                PbsTaskFilterFamily::Backup,
                PbsTaskPass::Running,
                PbsTaskStreamStatus::Complete,
                1,
                1,
                0,
                false,
                false,
                null,
            ),
            new PbsTaskStreamResult(
                PbsTaskFilterFamily::Prune,
                PbsTaskPass::History,
                PbsTaskStreamStatus::Partial,
                1,
                1,
                0,
                true,
                true,
                PbsTaskScanIssueCode::PageCapExceeded,
            ),
            new PbsTaskStreamResult(
                PbsTaskFilterFamily::Sync,
                PbsTaskPass::Running,
                PbsTaskStreamStatus::Failed,
                0,
                0,
                0,
                false,
                false,
                PbsTaskScanIssueCode::ReadFailed,
            ),
            new PbsTaskStreamResult(
                PbsTaskFilterFamily::Verify,
                PbsTaskPass::History,
                PbsTaskStreamStatus::Complete,
                1,
                0,
                0,
                false,
                false,
                null,
            ),
        ];
        $snapshot = new PbsExternalMonitoringSnapshot(
            $this->acl(false),
            null,
            null,
            null,
            new PbsTaskScanSnapshot(new PbsTaskWindow(100, 200), [], [], $streams),
            ['prune' => 'not_read', 'sync' => 'not_read', 'verify' => 'not_read'],
        );
        $commit = (new MapSelectedEndpointMonitoring())->pbsTasks(
            $this->monitoringRun(),
            $snapshot,
            'pbs-a',
            new \App\Application\Monitoring\MonitoringWindowPlan(100, 200, true),
            new DateTimeImmutable('@200'),
        );
        self::assertSame(MonitoringRunStatus::Partial, $commit->status());
        self::assertSame([
            ['pbs_tasks_running', 'pbs-a', 'running', 'backup', 'partial', null, null, 1, 1, 0, false, false, 'acl_incomplete', 200],
            ['pbs_tasks_running', 'pbs-a', 'running', 'syncjob', 'failed', null, null, 0, 0, 0, false, false, 'read_failed', 200],
            ['pbs_tasks_window', 'pbs-a', 'history', 'prune', 'partial', 100, 200, 1, 1, 0, true, true, 'page_cap_exceeded', 200],
            ['pbs_tasks_window', 'pbs-a', 'history', 'verif', 'partial', 100, 200, 1, 0, 0, false, true, 'history_gap', 200],
        ], array_map($this->scopeProjection(...), $commit->scopes));
    }

    public function testPbsHistoryScopesRemainPartialWithoutTaskAuditEvidence(): void
    {
        $commit = (new MapSelectedEndpointMonitoring())->pbsTasks(
            $this->monitoringRun(),
            $this->snapshot(false),
            'pbs-a',
            new \App\Application\Monitoring\MonitoringWindowPlan(100, 200, false),
            new DateTimeImmutable('@200'),
        );

        self::assertCount(8, $commit->scopes);
        foreach ($commit->scopes as $scope) {
            self::assertSame(MonitoringScopeStatus::Partial, $scope->status);
            self::assertSame('acl_incomplete', $scope->errorCode);
        }
    }

    public function testPbsTaskAuditEvidenceAllowsCompleteTaskScopes(): void
    {
        $commit = (new MapSelectedEndpointMonitoring())->pbsTasks(
            $this->monitoringRun(),
            $this->snapshot(true),
            'pbs-a',
            new \App\Application\Monitoring\MonitoringWindowPlan(100, 200, false),
            new DateTimeImmutable('@200'),
        );

        self::assertSame(MonitoringRunStatus::Succeeded, $commit->status());
        $filters = array_column($commit->scopes, 'filter');
        sort($filters, SORT_STRING);
        self::assertSame(
            ['backup', 'backup', 'prune', 'prune', 'syncjob', 'syncjob', 'verif', 'verif'],
            $filters,
        );
    }

    public function testWindowPlannerStartsBoundedAndClipsStaleCursorWithGapEvidence(): void
    {
        $connection = new ConnectionId(self::bytes('connection'));
        $initial = (new MonitoringWindowPlanner(new FixedMonitoringCursor(null), 300))->plan(
            $connection,
            MonitoringCursorKind::PbsTasksWindow,
            ['pbs-a'],
            new DateTimeImmutable('@1000'),
            600,
        );
        self::assertSame([400, 1000, false], [$initial->since, $initial->until, $initial->historyGap]);

        $stale = (new MonitoringWindowPlanner(new FixedMonitoringCursor(new DateTimeImmutable('@100')), 300))->plan(
            $connection,
            MonitoringCursorKind::PbsTasksWindow,
            ['pbs-a'],
            new DateTimeImmutable('@1000'),
            600,
        );
        self::assertSame([400, 1000, true], [$stale->since, $stale->until, $stale->historyGap]);

        $overlap = (new MonitoringWindowPlanner(new FixedMonitoringCursor(new DateTimeImmutable('@900')), 300))->plan(
            $connection,
            MonitoringCursorKind::PbsTasksWindow,
            ['pbs-a'],
            new DateTimeImmutable('@1000'),
            600,
        );
        self::assertSame([600, 1000, false], [$overlap->since, $overlap->until, $overlap->historyGap]);

        $cursorWithinHorizon = (new MonitoringWindowPlanner(
            new FixedMonitoringCursor(new DateTimeImmutable('@699')),
            300,
        ))->plan(
            $connection,
            MonitoringCursorKind::PbsTasksWindow,
            ['pbs-a'],
            new DateTimeImmutable('@1000'),
            600,
        );
        self::assertSame(
            [400, 1000, false],
            [$cursorWithinHorizon->since, $cursorWithinHorizon->until, $cursorWithinHorizon->historyGap],
        );
    }

    private function snapshot(bool $taskAudit): PbsExternalMonitoringSnapshot
    {
        $streams = [];
        foreach (PbsTaskFilterFamily::cases() as $family) {
            foreach (PbsTaskPass::cases() as $pass) {
                $streams[] = new PbsTaskStreamResult(
                    $family,
                    $pass,
                    PbsTaskStreamStatus::Complete,
                    1,
                    0,
                    0,
                    false,
                    false,
                    null,
                );
            }
        }
        $acl = new PbsAclEvidence(
            new PbsEffectivePermission('/system/tasks', $taskAudit ? ['Sys.Audit' => false] : []),
            new PbsEffectivePermission('/datastore', ['Datastore.Audit' => true]),
            new PbsEffectivePermission('/remote', ['Remote.Audit' => true]),
        );
        return new PbsExternalMonitoringSnapshot(
            $acl,
            null,
            null,
            null,
            new PbsTaskScanSnapshot(new PbsTaskWindow(100, 200), [], [], $streams),
            ['prune' => 'not_read', 'sync' => 'not_read', 'verify' => 'not_read'],
        );
    }

    private function monitoringRun(
        MonitoringRunKind $kind = MonitoringRunKind::ObservedTasks,
        ProxmoxProduct $product = ProxmoxProduct::Pbs,
    ): MonitoringRunStart
    {
        $endpoint = new EndpointId(self::bytes('endpoint'));
        return new MonitoringRunStart(
            new InventoryIdentifier(self::bytes('run')),
            new InventoryIdentifier(self::bytes('parent')),
            new InventoryIdentifier(self::bytes('connection')),
            $endpoint,
            $product,
            ProxmoxProduct::Pve === $product
                ? InstallationBinding::pveStandalone('pve-a')
                : InstallationBinding::pbsLegacyNode('pbs-a', $endpoint),
            $kind,
            1,
            new DateTimeImmutable('@100'),
        );
    }

    /**
     * @param list<PveBackupTask> $tasks
     * @param list<PveTaskStreamScanResult> $streams
     */
    private function pveSnapshot(
        PveBackupJobInventory $jobs,
        array $tasks = [],
        array $streams = [],
        bool $gap = false,
    ): PveBackupInventorySnapshot {
        return new PveBackupInventorySnapshot(
            $jobs,
            $tasks,
            [],
            new PveTaskArchiveWindow(100, 200, $gap),
            $streams,
            array_sum(array_column($streams, 'requests')),
            array_sum(array_column($streams, 'rawRows')),
        );
    }

    private function pveJob(): PveBackupJob
    {
        return new PveBackupJob(
            'job-a', 'daily', true, true, null, 300,
            'pve-a', 'backup', '101', false, 'snapshot', 'zstd', null, null,
        );
    }

    private function pbsJob(): PbsJobObservation
    {
        return new PbsJobObservation(
            PbsJobKind::Prune,
            new PbsJobId('prune-a'),
            new PbsDatastoreId('store-a'),
            null,
            'daily',
            false,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
        );
    }

    private function acl(bool $complete): PbsAclEvidence
    {
        return new PbsAclEvidence(
            new PbsEffectivePermission('/system/tasks', $complete ? ['Sys.Audit' => false] : []),
            new PbsEffectivePermission('/datastore', ['Datastore.Audit' => true]),
            new PbsEffectivePermission('/remote', $complete ? ['Remote.Audit' => true] : []),
        );
    }

    /** @return array{string, string, string, ?string, string, ?int, ?int, int, int, int, bool, bool, ?string, int} */
    private function scopeProjection(MonitoringScopeResult $scope): array
    {
        return [
            $scope->scope->value,
            $scope->key,
            $scope->source->value,
            $scope->filter,
            $scope->status->value,
            null === $scope->windowSince ? null : $scope->windowSince->getTimestamp(),
            null === $scope->windowUntil ? null : $scope->windowUntil->getTimestamp(),
            $scope->pagesRead,
            $scope->rowsRead,
            $scope->itemsSeen,
            $scope->truncated,
            $scope->historyGap,
            $scope->errorCode,
            $scope->observedAt->getTimestamp(),
        ];
    }

    private static function bytes(string $seed): string
    {
        return substr(hash('sha256', $seed, true), 0, 16);
    }
}

final readonly class FixedMonitoringCursor implements MonitoringCursorCatalog
{
    public function __construct(private ?DateTimeImmutable $cursor)
    {
    }

    public function oldestCompletedUntil(
        ConnectionId $connectionId,
        MonitoringCursorKind $kind,
        array $scopeKeys,
    ): ?DateTimeImmutable {
        return $this->cursor;
    }
}
