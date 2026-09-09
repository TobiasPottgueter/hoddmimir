<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use App\Application\Backup\Operations\BackupRequestState;
use App\Application\Backup\Operations\BackupRunState;
use App\Application\Backup\Operations\BackupRunQuery;
use App\Infrastructure\Persistence\MariaDb\DbalOperationsReadModel;
use App\Infrastructure\Persistence\MariaDb\MariaDbQaFixtureSeeder;
use App\Tests\Fakes\FrozenClock;
use DateTimeImmutable;

final class DbalOperationsReadModelTest extends DatabaseTestCase
{
    private const string USER = 'readmodel-user01';

    public function testOperationsProjectionPaginatesAndKeepsSensitivePayloadsClosed(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-07-13T00:00:00Z'));
        $this->seedQaFixture($clock);
        $firstRequest = self::uuid('c0000000-0000-4000-8000-000000000002');
        $this->seedPendingRequest($firstRequest);
        $this->connection()->update('backup_runs', [
            'stop_attempt_claimed_at' => '2026-07-12 23:59:58.000000',
            'stop_attempt_status' => 'dispatch_unknown',
            'stop_attempt_resolved_at' => '2026-07-12 23:59:59.000000',
            'stop_failure_code' => 'worker_lost_after_stop_dispatch',
        ], ['id' => self::uuid('d0000000-0000-4000-8000-000000000001')]);
        $this->connection()->insert('audit_events', [
            'id' => self::uuid('f1000000-0000-4000-8000-000000000001'),
            'occurred_at' => '2026-07-13 00:00:00.000000', 'actor_user_id' => self::USER,
            'event_type' => 'manual_backup_requested', 'outcome' => 'succeeded',
            'subject_type' => 'backup_request', 'subject_id' => $firstRequest,
            'correlation_id' => self::uuid('f2000000-0000-4000-8000-000000000001'),
        ]);
        $readModel = new DbalOperationsReadModel($this->connection(), $clock);

        $firstPage = $readModel->queue(new PageRequest(1), null)->toArray();
        self::assertSame('c0000000-0000-4000-8000-000000000002', $firstPage['items'][0]['id'] ?? null);
        self::assertSame('80000000-0000-4000-8000-000000000001', $firstPage['items'][0]['policyId'] ?? null);
        self::assertSame('70000000-0000-4000-8000-000000000001', $firstPage['items'][0]['targetId'] ?? null);
        self::assertTrue($firstPage['page']['hasMore']);
        self::assertIsString($firstPage['page']['nextCursor']);
        $secondPage = $readModel->queue(
            new PageRequest(1, PageCursor::decode($firstPage['page']['nextCursor'])),
            null,
        )->toArray();
        self::assertSame('c0000000-0000-4000-8000-000000000001', $secondPage['items'][0]['id'] ?? null);
        self::assertFalse($secondPage['page']['hasMore']);

        $runs = $readModel->runs(new BackupRunQuery(new PageRequest(1)))->toArray();
        self::assertSame('d0000000-0000-4000-8000-000000000001', $runs['items'][0]['id'] ?? null);
        $detail = $readModel->run('d0000000-0000-4000-8000-000000000001');
        self::assertSame('UPID:qa-node-b:00000001:00000001:00000001:vzdump:201:qa@pve:', $detail['upid'] ?? null);
        self::assertSame('accepted', $detail['submissionProvenance'] ?? null);
        self::assertSame('2026-07-12T23:59:58.000000Z', $detail['stopAttemptClaimedAt'] ?? null);
        self::assertSame('dispatch_unknown', $detail['stopAttemptStatus'] ?? null);
        self::assertSame('2026-07-12T23:59:59.000000Z', $detail['stopAttemptResolvedAt'] ?? null);
        self::assertSame('worker_lost_after_stop_dispatch', $detail['stopFailureCode'] ?? null);
        self::assertNull($readModel->run('f0000000-0000-4000-8000-000000000099'));

        $logs = $readModel->runLogs('d0000000-0000-4000-8000-000000000001', new PageRequest(1))->toArray();
        self::assertSame('QA sanitized backup log', $logs['items'][0]['content'] ?? null);
        $notifications = $readModel->notifications(new PageRequest(1), 'recovery')->toArray();
        self::assertSame('recovery', $notifications['items'][0]['kind'] ?? null);
        self::assertArrayNotHasKey('payload_json', $notifications['items'][0]);
        self::assertArrayNotHasKey('payloadJson', $notifications['items'][0]);

        $closedDashboard = $readModel->dashboard(false)->toArray();
        self::assertFalse($closedDashboard['auditVisible']);
        self::assertSame([], $closedDashboard['recentAuditEvents']);
        $auditedDashboard = $readModel->dashboard(true)->toArray();
        self::assertTrue($auditedDashboard['auditVisible']);
        $auditEvents = $auditedDashboard['recentAuditEvents'];
        $firstAudit = $auditEvents[0] ?? null;
        self::assertIsArray($firstAudit);
        self::assertSame('manual_backup_requested', $firstAudit['eventType']);
        self::assertSame('degraded', $auditedDashboard['workers']['collector']['status'] ?? null);
        self::assertFalse($auditedDashboard['workers']['collector']['fresh'] ?? true);
        self::assertNull($auditedDashboard['workers']['backup'] ?? null);
        $resources = $auditedDashboard['resources'];
        self::assertSame(2, $resources['guests']);
        self::assertSame(2, $auditedDashboard['requestsByReason']['manual']);
        $lastSuccessfulRun = $auditedDashboard['lastSuccessfulRun'] ?? null;
        self::assertIsArray($lastSuccessfulRun);
        self::assertSame('qa-lxc-201', $lastSuccessfulRun['guestName'] ?? null);
    }

    public function testEventLogAndNotificationCursorPathsUsePersistedRows(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-07-13T00:00:00Z'));
        $this->seedQaFixture($clock);
        $request = self::uuid('c0000000-0000-4000-8000-000000000001');
        $run = self::uuid('d0000000-0000-4000-8000-000000000001');
        $this->connection()->insert('backup_request_events', [
            'id'=>self::uuid('f0000000-0000-4000-8000-000000000011'),'request_id'=>$request,
            'sequence_no'=>2,'event_type'=>'cancelled','state'=>'cancelled','occurred_at'=>'2026-07-13 00:00:01.000000',
        ]);
        $this->connection()->insert('backup_run_events', [
            'id'=>self::uuid('f0000000-0000-4000-8000-000000000012'),'run_id'=>$run,
            'sequence_no'=>2,'event_type'=>'task_failed','state'=>'failed','occurred_at'=>'2026-07-13 00:00:01.000000','detail_code'=>'qa_failure',
        ]);
        $this->connection()->insert('backup_run_log_entries', [
            'run_id'=>$run,'line_no'=>1,'observed_at'=>'2026-07-13 00:00:01.000000','content'=>'Second sanitized line',
        ]);
        $payload = $this->connection()->fetchOne('SELECT payload_json FROM backup_notification_outbox WHERE id=:id', [
            'id'=>self::uuid('e0000000-0000-4000-8000-000000000001'),
        ]);
        self::assertIsString($payload);
        $this->connection()->insert('backup_notification_outbox', [
            'id'=>self::uuid('e0000000-0000-4000-8000-000000000002'),'obligation_id'=>$request,
            'occurrence_id'=>self::uuid('e0000000-0000-4000-8000-000000000002'),'root_request_id'=>$request,'request_id'=>$request,
            'run_id'=>$run,'notification_kind'=>'failure','event_key'=>'qa.failure','attempt'=>1,'check_number'=>1,'payload_json'=>$payload,
            'state'=>'pending','delivery_attempts'=>2,'last_error_code'=>'transport',
            'available_at'=>'2026-07-13 00:00:02.000000','created_at'=>'2026-07-13 00:00:02.000000',
        ]);
        $readModel = new DbalOperationsReadModel($this->connection(), $clock);

        foreach ([
            fn (PageRequest $page) => $readModel->requestEvents('c0000000-0000-4000-8000-000000000001', $page),
            fn (PageRequest $page) => $readModel->runEvents('d0000000-0000-4000-8000-000000000001', $page),
            fn (PageRequest $page) => $readModel->runLogs('d0000000-0000-4000-8000-000000000001', $page),
        ] as $projection) {
            $first = $projection(new PageRequest(1))->toArray();
            self::assertTrue($first['page']['hasMore']);
            self::assertIsString($first['page']['nextCursor']);
            $second = $projection(new PageRequest(1, PageCursor::decode($first['page']['nextCursor'])))->toArray();
            self::assertCount(1, $second['items']);
            self::assertFalse($second['page']['hasMore']);
        }

        $notificationPage = $readModel->notifications(new PageRequest(1), null)->toArray();
        self::assertSame('failure', $notificationPage['items'][0]['kind'] ?? null);
        self::assertTrue($notificationPage['page']['hasMore']);
        self::assertIsString($notificationPage['page']['nextCursor']);
        $older = $readModel->notifications(new PageRequest(1, PageCursor::decode($notificationPage['page']['nextCursor'])), null)->toArray();
        self::assertSame('recovery', $older['items'][0]['kind'] ?? null);
        self::assertSame('transport', $readModel->notificationHealth()->lastErrorCode);
        $this->expectException(\InvalidArgumentException::class);
        $readModel->notifications(new PageRequest(1), 'secret');
    }

    public function testStateFiltersAndConstructorBoundsAreClosed(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-07-13T00:00:00Z'));
        $this->seedQaFixture($clock);
        $pending = self::uuid('c0000000-0000-4000-8000-000000000002');
        $this->seedPendingRequest($pending);
        $readModel = new DbalOperationsReadModel($this->connection(), $clock, 1);
        self::assertSame('pending', $readModel->queue(new PageRequest(10), BackupRequestState::Pending)->items[0]['state'] ?? null);
        self::assertSame('succeeded', $readModel->runs(new BackupRunQuery(new PageRequest(10), BackupRunState::Succeeded))->items[0]['state'] ?? null);
        self::assertSame([], $readModel->queue(new PageRequest(10), BackupRequestState::Running)->items);

        try { new DbalOperationsReadModel($this->connection(), $clock, 0); self::fail('Zero freshness was accepted.'); }
        catch (\InvalidArgumentException) { self::addToAssertionCount(1); }
        $this->expectException(\InvalidArgumentException::class);
        new DbalOperationsReadModel($this->connection(), $clock, 86_401);
    }

    public function testRunHistoryCombinesFiltersAcrossClustersAndPaginatesEqualTimestamps(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-07-13T00:00:00Z'));
        $this->seedQaFixture($clock);
        $guest = '50000000-0000-4000-8000-000000000201';
        $node = '40000000-0000-4000-8000-000000000002';
        $target = '70000000-0000-4000-8000-000000000001';
        $this->connection()->update('guests', ['name' => 'shared_%!guest'], ['id' => self::uuid($guest)]);
        $other = $this->cloneHistoryInventoryContext();
        $second = $this->seedHistoryRun(2, '2026-07-13 00:00:00.000000');
        $upper = $this->seedHistoryRun(3, '2026-07-13 00:00:00.000001');
        $otherRun = $this->seedHistoryRun(4, '2026-07-13 00:00:00.000000', $other);
        $qemu = self::uuid('50000000-0000-4000-8000-000000000101');
        $this->connection()->update('guests', ['name' => 'sharedXYZguest'], ['id' => $qemu]);
        $this->seedHistoryRun(5, '2026-07-13 00:00:00.000000', [self::uuid($guest) => $qemu]);
        $this->seedPendingRequest(self::uuid('c0000000-0000-4000-8000-000000000006'));
        // A moved, then archived guest and disabled target remain in history.
        $this->connection()->update('guest_placements', ['node_id' => self::uuid('40000000-0000-4000-8000-000000000001')], ['guest_id' => self::uuid($guest)]);
        $this->connection()->update('guests', ['inventory_state' => 'archived', 'archived_at' => '2026-07-14 00:00:00.000000'], ['id' => self::uuid($guest)]);
        $this->connection()->update('backup_targets', ['status' => 'disabled', 'disabled_at' => '2026-07-14 00:00:00.000000', 'updated_at' => '2026-07-14 00:00:00.000000'], ['id' => self::uuid($target)]);
        $model = new DbalOperationsReadModel($this->connection(), $clock);
        $filters = [
            'state' => BackupRunState::Succeeded, 'vmid' => 201, 'search' => 'SHARED_%!',
            'startedFrom' => '2026-07-13T00:00:00Z', 'startedBefore' => '2026-07-13T00:00:00.000001Z',
        ];
        $page = $model->runs(new BackupRunQuery(new PageRequest(1), ...$filters));
        self::assertSame($otherRun, $page->items[0]['id']);
        self::assertNotNull($page->nextCursor);
        $next = $model->runs(new BackupRunQuery(new PageRequest(1, $page->nextCursor), ...$filters));
        self::assertSame($second, $next->items[0]['id']);
        self::assertNotNull($next->nextCursor);
        $last = $model->runs(new BackupRunQuery(new PageRequest(1, $next->nextCursor), ...$filters));
        self::assertSame('d0000000-0000-4000-8000-000000000001', $last->items[0]['id']);
        self::assertNull($last->nextCursor);
        foreach (['guestId' => $guest, 'nodeId' => $node, 'targetId' => $target] as $key => $id) {
            $selected = $model->runs(new BackupRunQuery(new PageRequest(), ...[...$filters, $key => new ReadModelIdentifier($id)]));
            self::assertSame([$second, 'd0000000-0000-4000-8000-000000000001'], array_column($selected->items, 'id'));
        }
        $combined = $model->runs(new BackupRunQuery(new PageRequest(), ...[...$filters, 'guestId' => new ReadModelIdentifier($guest), 'nodeId' => new ReadModelIdentifier($node), 'targetId' => new ReadModelIdentifier($target)]));
        self::assertCount(2, $combined->items);
        self::assertSame('qa-node-b', $combined->items[0]['nodeName']);
        /** @var list<array{vmid?: int, search?: string, guestId?: ReadModelIdentifier, state?: BackupRunState}> $mismatches */
        $mismatches = [['vmid' => 101], ['search' => 'missing'], ['guestId' => new ReadModelIdentifier('50000000-0000-4000-8000-000000000999')], ['state' => BackupRunState::Failed]];
        foreach ($mismatches as $mismatch) {
            self::assertSame([], $model->runs(new BackupRunQuery(new PageRequest(), ...[...$filters, ...$mismatch]))->items);
        }
        self::assertSame([$upper], array_column($model->runs(new BackupRunQuery(new PageRequest(), startedFrom: '2026-07-13T00:00:00.000001Z'))->items, 'id'));
        self::assertSame([], $model->runs(new BackupRunQuery(new PageRequest(), startedBefore: '2026-07-13T00:00:00Z'))->items);
        self::assertCount(5, $model->runs(new BackupRunQuery(new PageRequest()))->items);
        $this->expectException(\InvalidArgumentException::class);
        $model->runs(new BackupRunQuery(new PageRequest(1, $page->nextCursor), ...[...$filters, 'vmid' => 101]));
    }

    /** @return array<string, string> Maps binary fixture IDs into a second connection/cluster. */
    private function cloneHistoryInventoryContext(): array
    {
        $tables = [
            'proxmox_connections' => '10000000-0000-4000-8000-000000000001',
            'inventory_sync_runs' => '20000000-0000-4000-8000-000000000001',
            'pve_clusters' => '30000000-0000-4000-8000-000000000001',
            'pve_nodes' => '40000000-0000-4000-8000-000000000002',
            'guests' => '50000000-0000-4000-8000-000000000201',
            'pve_storages' => '60000000-0000-4000-8000-000000000001',
            'backup_targets' => '70000000-0000-4000-8000-000000000001',
            'backup_policies' => '80000000-0000-4000-8000-000000000001',
        ];
        $mapping = [];
        foreach ($tables as $id) $mapping[self::uuid($id)] = self::uuid(substr_replace($id, '1', 1, 1));
        // As in the QA seeder, collector-cycle process evidence is outside this read fixture.
        $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
        foreach ($tables as $table => $id) {
            $row = $this->connection()->fetchAssociative('SELECT * FROM '.$table.' WHERE id = ?', [self::uuid($id)]);
            self::assertIsArray($row);
            foreach ($row as &$value) if (is_string($value) && isset($mapping[$value])) $value = $mapping[$value];
            unset($value);
            if (isset($row['display_name'])) { self::assertIsString($row['display_name']); $row['display_name'] .= ' Other'; }
            $this->connection()->insert($table, $row);
        }
        } finally { $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 1'); }
        return $mapping;
    }

    /** @param array<string, string> $mapping */
    private function seedHistoryRun(int $sequence, string $startedAt, array $mapping = []): string
    {
        $request = $this->connection()->fetchAssociative('SELECT * FROM backup_requests WHERE id = ?', [self::uuid('c0000000-0000-4000-8000-000000000001')]);
        $run = $this->connection()->fetchAssociative('SELECT * FROM backup_runs WHERE id = ?', [self::uuid('d0000000-0000-4000-8000-000000000001')]);
        self::assertIsArray($request); self::assertIsArray($run);
        foreach ($request as &$value) if (is_string($value) && isset($mapping[$value])) $value = $mapping[$value];
        unset($value);
        $requestId = self::uuid(sprintf('c0000000-0000-4000-8000-%012d', $sequence));
        $runId = sprintf('d0000000-0000-4000-8000-%012d', $sequence);
        $request['id'] = $requestId; $request['root_request_id'] = $requestId;
        $request['state'] = 'cancelled'; $request['run_id'] = null;
        $request['scheduled_at'] = sprintf('2026-07-13 00:00:00.%06d', $sequence);
        $request['available_at'] = $request['scheduled_at'];
        $this->connection()->insert('backup_requests', $request);
        $run['id'] = self::uuid($runId); $run['request_id'] = $requestId; $run['root_request_id'] = $requestId;
        $run['started_at'] = $startedAt; $run['finished_at'] = $startedAt;
        $run['upid'] = 'UPID:history-'.$sequence; $run['upid_hash'] = hash('sha256', $run['upid'], true);
        $this->connection()->insert('backup_runs', $run);
        $this->connection()->update('backup_requests', ['state' => 'succeeded', 'run_id' => self::uuid($runId)], ['id' => $requestId]);
        return $runId;
    }

    private function seedQaFixture(FrozenClock $clock): void
    {
        $now = '2026-07-13 00:00:00.000000';
        $this->connection()->insert('users', [
            'id' => self::USER, 'username' => 'qa-admin', 'display_name' => 'QA Admin',
            'password_hash' => '$argon2id$fixture', 'enabled' => 1, 'revision' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        (new MariaDbQaFixtureSeeder($this->connection(), $clock))->seed();
    }

    private function seedPendingRequest(string $id): void
    {
        $resolved = '{"version":2,"mode":"snapshot","compression":"zstd","desiredRetention":{"prune-backups":{"keep-last":7}},"failureNotificationRecipients":[]}';
        $this->connection()->insert('backup_requests', [
            'id' => $id, 'root_request_id' => $id, 'attempt' => 1, 'origin' => 'manual',
            'state' => 'pending', 'reason' => 'manual', 'priority' => 400,
            'scheduled_at' => '2026-07-12 23:59:59.000000', 'available_at' => '2026-07-12 23:59:59.000000',
            'connection_id' => self::uuid('10000000-0000-4000-8000-000000000001'),
            'cluster_id' => self::uuid('30000000-0000-4000-8000-000000000001'),
            'guest_id' => self::uuid('50000000-0000-4000-8000-000000000101'),
            'node_id' => self::uuid('40000000-0000-4000-8000-000000000001'),
            'placement_revision' => 1, 'placement_observed_at' => '2026-07-13 00:00:00.000000',
            'policy_id' => self::uuid('80000000-0000-4000-8000-000000000001'), 'policy_revision' => 1,
            'target_id' => self::uuid('70000000-0000-4000-8000-000000000001'), 'target_revision' => 1,
            'resolved_policy_json' => $resolved, 'resolved_policy_hash' => hash('sha256', $resolved, true),
            'expected_size_bytes' => 1073741824, 'retry_disposition' => 'not_applicable',
            'created_at' => '2026-07-12 23:59:59.000000', 'updated_at' => '2026-07-12 23:59:59.000000',
        ]);
    }

    private static function uuid(string $uuid): string
    {
        $binary = hex2bin(str_replace('-', '', $uuid));
        self::assertIsString($binary);

        return $binary;
    }
}
