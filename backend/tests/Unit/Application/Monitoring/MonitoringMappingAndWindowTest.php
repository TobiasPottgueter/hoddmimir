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
use App\Application\Monitoring\MonitoringScopeStatus;
use App\Application\Monitoring\MonitoringSourceKind;
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
        $archiveError = null;
        foreach ($mixed->scopes as $scope) {
            if ('pve-a' === $scope->key && MonitoringSourceKind::Archive === $scope->source) {
                $archiveError = $scope->errorCode;
            }
        }
        self::assertSame('history_gap', $archiveError);
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
        self::assertSame('permission_denied', $commit->scopes[1]->errorCode);

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
        $errors = [];
        foreach ($partial->scopes as $scope) {
            $errors[$scope->filter ?? ''] = $scope->errorCode;
        }
        self::assertNull($errors['prune']);
        self::assertSame('acl_incomplete', $errors['sync']);
        self::assertNull($errors['verify']);
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
        $errors = [];
        foreach ($commit->scopes as $scope) {
            $errors[$scope->filter ?? ''] = $scope->errorCode;
        }
        self::assertSame('acl_incomplete', $errors['backup']);
        self::assertSame('page_cap_exceeded', $errors['prune']);
        self::assertSame('read_failed', $errors['syncjob']);
        self::assertSame('history_gap', $errors['verif']);
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
