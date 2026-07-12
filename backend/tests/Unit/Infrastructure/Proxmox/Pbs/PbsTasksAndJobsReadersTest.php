<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsAclIssueCode;
use App\Application\Proxmox\Pbs\PbsJobKind;
use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use App\Application\Proxmox\Pbs\PbsTaskFilterFamily;
use App\Application\Proxmox\Pbs\PbsTaskListQuery;
use App\Application\Proxmox\Pbs\PbsTaskPass;
use App\Application\Proxmox\Pbs\PbsTaskWindow;
use App\Infrastructure\Proxmox\Pbs\PbsApiEnvelope;
use App\Infrastructure\Proxmox\Pbs\PbsApiTransport;
use App\Infrastructure\Proxmox\Pbs\PbsHttpTasksAndJobsClient;
use App\Infrastructure\Proxmox\Pbs\PbsJobListReader;
use App\Infrastructure\Proxmox\Pbs\PbsPermissionReader;
use App\Infrastructure\Proxmox\Pbs\PbsRequest;
use App\Infrastructure\Proxmox\Pbs\PbsTaskPageReader;
use PHPUnit\Framework\TestCase;

final class PbsTasksAndJobsReadersTest extends TestCase
{
    public function testJobReaderNormalizesRootDefaultsAndOptionalLastRunEndtime(): void
    {
        $reader = new PbsJobListReader();
        $prune = $reader->read(new PbsApiEnvelope([(object) [
            'id' => 'prune_a', 'store' => 'store_a', 'ns' => '', 'schedule' => 'daily',
            'disable' => true, 'last-run-upid' => $this->upid('prunejob', 'prune_a'),
            'last-run-state' => 'OK', 'next-run' => 200,
        ]], str_repeat('a', 64)), PbsJobKind::Prune, 1);
        self::assertNull($prune->jobs[0]->localNamespace);
        self::assertTrue($prune->jobs[0]->disabled);
        self::assertNull($prune->jobs[0]->lastRunEndTime);

        $sync = $reader->read(new PbsApiEnvelope([(object) [
            'id' => 'sync_a', 'store' => 'store_a', 'remote-store' => 'remote_store',
        ]], str_repeat('b', 64)), PbsJobKind::Sync, 1);
        self::assertSame('pull', $sync->jobs[0]->syncDirection?->value);
        self::assertNull($sync->jobs[0]->remote);
        self::assertNull($sync->jobs[0]->schedule);

        $verify = $reader->read(new PbsApiEnvelope([(object) [
            'id' => 'verify_a', 'store' => 'store_a', 'ns' => 'a/b', 'schedule' => 'sun 03:00',
        ]], str_repeat('c', 64)), PbsJobKind::Verify, 1);
        self::assertSame('a/b', $verify->jobs[0]->localNamespace?->value);
    }

    public function testJobReaderRejectsMalformedAndOverLimitResponses(): void
    {
        $valid = (object) ['id' => 'prune_a', 'store' => 'store_a', 'schedule' => 'daily'];
        $cases = [
            [new PbsApiEnvelope((object) [], str_repeat('a', 64)), PbsJobKind::Prune, 1],
            [new PbsApiEnvelope([$valid, $valid], str_repeat('a', 64)), PbsJobKind::Prune, 1],
            [new PbsApiEnvelope([], null), PbsJobKind::Prune, 1],
            [new PbsApiEnvelope([[]], str_repeat('a', 64)), PbsJobKind::Prune, 1],
            [new PbsApiEnvelope([(object) ['store' => 'store_a', 'schedule' => 'daily']], str_repeat('a', 64)), PbsJobKind::Prune, 1],
            [new PbsApiEnvelope([(object) ['id' => 'bad/', 'store' => 'store_a', 'schedule' => 'daily']], str_repeat('a', 64)), PbsJobKind::Prune, 1],
            [new PbsApiEnvelope([(object) ['id' => 'prune_a', 'store' => 'bad/', 'schedule' => 'daily']], str_repeat('a', 64)), PbsJobKind::Prune, 1],
            [new PbsApiEnvelope([(object) ['id' => 'prune_a', 'store' => 'store_a']], str_repeat('a', 64)), PbsJobKind::Prune, 1],
            [new PbsApiEnvelope([(object) ['id' => 'prune_a', 'store' => 'store_a', 'schedule' => 1]], str_repeat('a', 64)), PbsJobKind::Prune, 1],
            [new PbsApiEnvelope([(object) ['id' => 'prune_a', 'store' => 'store_a', 'schedule' => 'daily', 'disable' => 1]], str_repeat('a', 64)), PbsJobKind::Prune, 1],
            [new PbsApiEnvelope([(object) ['id' => 'prune_a', 'store' => 'store_a', 'schedule' => 'daily', 'ns' => 'bad//ns']], str_repeat('a', 64)), PbsJobKind::Prune, 1],
            [new PbsApiEnvelope([(object) ['id' => 'sync_a', 'store' => 'store_a', 'remote-store' => 'remote_store', 'sync-direction' => 'sideways']], str_repeat('a', 64)), PbsJobKind::Sync, 1],
            [new PbsApiEnvelope([(object) ['id' => 'sync_a', 'store' => 'store_a']], str_repeat('a', 64)), PbsJobKind::Sync, 1],
            [new PbsApiEnvelope([(object) ['id' => 'sync_a', 'store' => 'store_a', 'remote-store' => 'remote_store', 'remote' => 'bad/']], str_repeat('a', 64)), PbsJobKind::Sync, 1],
            [new PbsApiEnvelope([(object) ['id' => 'verify_a', 'store' => 'store_a', 'last-run-upid' => $this->upid()]], str_repeat('a', 64)), PbsJobKind::Verify, 1],
            [new PbsApiEnvelope([(object) ['id' => 'verify_a', 'store' => 'store_a', 'last-run-state' => 'OK']], str_repeat('a', 64)), PbsJobKind::Verify, 1],
            [new PbsApiEnvelope([(object) ['id' => 'verify_a', 'store' => 'store_a', 'next-run' => -1]], str_repeat('a', 64)), PbsJobKind::Verify, 1],
            [new PbsApiEnvelope([(object) ['id' => 'verify_a', 'store' => 'store_a', 'last-run-endtime' => 'bad']], str_repeat('a', 64)), PbsJobKind::Verify, 1],
            [new PbsApiEnvelope([$valid, $valid], str_repeat('a', 64)), PbsJobKind::Prune, 2],
            [new PbsApiEnvelope([], 'bad'), PbsJobKind::Verify, 1],
        ];
        foreach ($cases as [$envelope, $kind, $limit]) {
            $this->assertReadFailure(static fn () => (new PbsJobListReader())->read($envelope, $kind, $limit));
        }
    }

    public function testTaskReaderAcceptsReportedLocalhostOrDifferentNodeAndOptionalLifecycle(): void
    {
        $runningRow = $this->taskRow('backup', 'store_a', false);
        $runningRow->node = 'other-node';
        $terminalRow = $this->taskRow('verify', '', true);
        $terminalRow->node = 'localhost';
        $terminalRow->worker_id = '';
        $page = (new PbsTaskPageReader())->read(
            new PbsApiEnvelope([$runningRow, $terminalRow], null, 3),
            PbsTaskPass::Running,
        );

        self::assertSame('other-node', $page->tasks[0]->reportedNode);
        self::assertTrue($page->tasks[0]->isRunning());
        self::assertSame('store_a', $page->tasks[0]->upid->workerId);
        self::assertNull($page->tasks[1]->upid->workerId);
        self::assertFalse($page->tasks[1]->isRunning());
        self::assertSame(3, $page->total);
        self::assertSame(2, $page->rawRowCount);
        self::assertTrue($page->tasks[1]->seenRunning);

        $nullWorker = $this->taskRow('verify', '', false);
        self::assertNull((new PbsTaskPageReader())->read(
            new PbsApiEnvelope([$nullWorker], null, 1), PbsTaskPass::Running,
        )->tasks[0]->upid->workerId);
    }

    public function testTaskReaderRejectsContradictoryRowsAndDuplicateUpids(): void
    {
        $valid = $this->taskRow('backup', 'store_a', false);
        $mutations = [
            static fn (\stdClass $row): mixed => $row->upid = 'bad',
            static fn (\stdClass $row): mixed => $row->node = "bad\n",
            static fn (\stdClass $row): mixed => $row->pid = 99,
            static fn (\stdClass $row): mixed => $row->pstart = 99,
            static fn (\stdClass $row): mixed => $row->starttime = 99,
            static fn (\stdClass $row): mixed => $row->worker_type = 'prune',
            static fn (\stdClass $row): mixed => $row->worker_id = 'other',
            static fn (\stdClass $row): mixed => $row->user = 'other@pam',
            static fn (\stdClass $row): mixed => $row->status = 1,
            static function (\stdClass $row): void { $row->endtime = 101; },
            static function (\stdClass $row): void { $row->endtime = -1; },
            static fn (\stdClass $row): mixed => $row->pid = -1,
            static fn (\stdClass $row): mixed => $row->worker_id = 1,
        ];
        foreach ($mutations as $mutate) {
            $row = clone $valid;
            $mutate($row);
            $this->assertReadFailure(static fn () => (new PbsTaskPageReader())->read(
                new PbsApiEnvelope([$row], null), PbsTaskPass::History,
            ));
        }
        foreach ([
            new PbsApiEnvelope((object) [], null),
            new PbsApiEnvelope([[]], null),
            new PbsApiEnvelope([(object) []], null),
            new PbsApiEnvelope([$valid, clone $valid], null),
        ] as $envelope) {
            $this->assertReadFailure(static fn () => (new PbsTaskPageReader())->read($envelope, PbsTaskPass::History));
        }
    }

    public function testTaskReaderPreservesDistinctRawFingerprintsWhenEveryRowIsDisallowed(): void
    {
        $reader = new PbsTaskPageReader();
        $first = $reader->read(
            new PbsApiEnvelope([$this->taskRow('tape-backup', 'tape-a', true)], null, 2),
            PbsTaskPass::History,
        );
        $second = $reader->read(
            new PbsApiEnvelope([$this->taskRow('tape-backup', 'tape-b', true)], null, 2),
            PbsTaskPass::History,
        );

        self::assertSame([], $first->tasks);
        self::assertSame(1, $first->rawRowCount);
        self::assertNotSame($first->rawFingerprint, $second->rawFingerprint);
    }

    public function testTaskReaderRuntimeEnvelopeTypeBoundaryIsEnforced(): void
    {
        $reader = new PbsTaskPageReader();
        $this->expectException(\TypeError::class);
        (new \ReflectionMethod($reader, 'read'))->invokeArgs($reader, [1, PbsTaskPass::History]);
    }

    public function testHttpClientUsesOnlyFixedGetDescriptorsAndReturnsAclDiagnostics(): void
    {
        $transport = new TasksJobsRecordingTransport();
        $client = new PbsHttpTasksAndJobsClient(
            $transport,
            new PbsPermissionReader(),
            new PbsJobListReader(),
            new PbsTaskPageReader(),
        );

        $acl = $client->aclEvidence();
        self::assertSame([PbsAclIssueCode::MissingRemoteAuditPropagation], $acl->issues);
        self::assertSame(PbsJobKind::Prune, $client->pruneJobs()->kind);
        self::assertSame(PbsJobKind::Sync, $client->syncJobs()->kind);
        self::assertSame(PbsJobKind::Verify, $client->verifyJobs()->kind);
        $page = $client->page('pbs-four', new PbsTaskListQuery(
            PbsTaskFilterFamily::Backup,
            PbsTaskPass::History,
            0,
            256,
            new PbsTaskWindow(100, 200),
        ));
        self::assertSame(0, $page->total);
        self::assertSame([
            '/access/permissions', '/access/permissions', '/access/permissions',
            '/admin/prune', '/admin/sync', '/admin/verify', '/nodes/pbs-four/tasks',
        ], $transport->paths);
    }

    private function taskRow(string $type, string $id, bool $terminal): \stdClass
    {
        $upid = $this->upid($type, $id);
        $row = (object) [
            'upid' => $upid,
            'node' => 'localhost',
            'pid' => 42,
            'pstart' => 1_000_000,
            'starttime' => 100,
            'worker_type' => $type,
            'worker_id' => '' === $id ? null : $id,
            'user' => 'root@pam',
        ];
        if ($terminal) {
            $row->status = 'OK';
            $row->endtime = 101;
        }
        return $row;
    }

    private function upid(string $type = 'backup', string $id = 'store_a'): string
    {
        return sprintf('UPID:pbs-four:0000002A:000F4240:00000064:00000064:%s:%s:root@pam:', $type, $id);
    }

    private function assertReadFailure(callable $operation): void
    {
        try {
            $operation();
            self::fail('The invalid PBS task/job response was accepted.');
        } catch (PbsReadFailure $failure) {
            self::assertSame(PbsReadFailureCode::InvalidResponse, $failure->failureCode);
        }
    }
}

final class TasksJobsRecordingTransport implements PbsApiTransport
{
    /** @var list<string> */ public array $paths = [];

    public function get(PbsRequest $request): PbsApiEnvelope
    {
        $path = '/'.implode('/', $request->pathSegments);
        $this->paths[] = $path;
        if (['access', 'permissions'] === $request->pathSegments) {
            $permissionPath = $request->query['path'];
            TestCase::assertIsString($permissionPath);
            $privilege = match ($permissionPath) {
                '/system/tasks' => ['Sys.Audit' => false],
                '/datastore' => ['Datastore.Audit' => true],
                '/remote' => [],
                default => TestCase::fail('Unexpected permission path.'),
            };
            return new PbsApiEnvelope((object) [$permissionPath => (object) $privilege], null);
        }
        if (['admin', 'prune'] === $request->pathSegments) {
            return new PbsApiEnvelope([], str_repeat('a', 64));
        }
        if (['admin', 'sync'] === $request->pathSegments) {
            TestCase::assertSame(['sync-direction' => 'all'], $request->query);
            return new PbsApiEnvelope([], str_repeat('b', 64));
        }
        if (['admin', 'verify'] === $request->pathSegments) {
            return new PbsApiEnvelope([], str_repeat('c', 64));
        }
        TestCase::assertSame([
            'start' => 0, 'limit' => 256, 'typefilter' => 'backup', 'since' => 100, 'until' => 200,
        ], $request->query);
        return new PbsApiEnvelope([], null, 0);
    }
}
