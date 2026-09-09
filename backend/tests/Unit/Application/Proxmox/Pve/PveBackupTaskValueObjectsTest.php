<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Proxmox\Pve;

use App\Application\Proxmox\Pve\PveBackupInventoryIssue;
use App\Application\Proxmox\Pve\PveBackupInventoryIssueCode;
use App\Application\Proxmox\Pve\PveBackupInventoryLimits;
use App\Application\Proxmox\Pve\PveBackupInventorySnapshot;
use App\Application\Proxmox\Pve\PveBackupJob;
use App\Application\Proxmox\Pve\PveBackupJobCapabilities;
use App\Application\Proxmox\Pve\PveBackupJobInventory;
use App\Application\Proxmox\Pve\PveBackupJobResponseContract;
use App\Application\Proxmox\Pve\PveBackupTask;
use App\Application\Proxmox\Pve\PvePruneBackups;
use App\Application\Proxmox\Pve\PvePruneResponseShape;
use App\Application\Proxmox\Pve\PveReadClient;
use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveReadFailureCode;
use App\Application\Proxmox\Pve\PveTaskLifecycle;
use App\Application\Proxmox\Pve\PveTaskArchiveWindow;
use App\Application\Proxmox\Pve\PveTaskPage;
use App\Application\Proxmox\Pve\PveTaskQuery;
use App\Application\Proxmox\Pve\PveTaskSource;
use App\Application\Proxmox\Pve\PveTaskStatus;
use App\Application\Proxmox\Pve\PveTaskStreamScanResult;
use App\Application\Proxmox\Pve\PveTaskStreamScanStatus;
use App\Application\Proxmox\Pve\PveUpid;
use App\Application\Proxmox\Pve\PveVersion;
use App\Application\Proxmox\Pve\ReadPveBackupInventory;
use App\Domain\Shared\Clock;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PveBackupTaskValueObjectsTest extends TestCase
{
    public function testVersionCapabilitiesAndPruneSignaturesAreExplicit(): void
    {
        $seven = PveBackupJobCapabilities::forMajor(7);
        $eight = PveBackupJobCapabilities::forMajor(8);
        $nine = PveBackupJobCapabilities::forMajor(9);

        self::assertSame(PveBackupJobResponseContract::BaselineIdOnly, $seven->responseContract);
        self::assertTrue($eight->supportsLegacyMaxFiles);
        self::assertSame(PvePruneResponseShape::LegacyStringOrObject, $eight->pruneResponseShape);
        self::assertSame(PveBackupJobResponseContract::SelectedTypedFields, $nine->responseContract);
        self::assertFalse($nine->supportsLegacyMaxFiles);
        self::assertSame(PvePruneResponseShape::Object, $nine->pruneResponseShape);

        $this->expectException(InvalidArgumentException::class);
        PveBackupJobCapabilities::forMajor(10);
    }

    public function testPruneSignatureIncludesOnlyObservedValues(): void
    {
        self::assertSame([], (new PvePruneBackups())->signature());
        self::assertSame([
            'keep-all' => false,
            'keep-last' => 1,
            'keep-hourly' => 2,
            'keep-daily' => 3,
            'keep-weekly' => 4,
            'keep-monthly' => 5,
            'keep-yearly' => 6,
        ], (new PvePruneBackups(false, 1, 2, 3, 4, 5, 6))->signature());
    }

    public function testTaskQueriesAlwaysEmitTheExactAllowedParameters(): void
    {
        $active = PveTaskQuery::active(start: 5, limit: 10);
        self::assertSame([
            'typefilter' => 'vzdump',
            'source' => 'active',
            'start' => 5,
            'limit' => 10,
        ], $active->parameters());
        self::assertSame(15, $active->nextPage()->start);

        $archive = PveTaskQuery::archive(100, 200, start: 10, limit: 20);
        self::assertSame([
            'typefilter' => 'vzdump',
            'source' => 'archive',
            'start' => 10,
            'limit' => 20,
            'since' => 100,
            'until' => 200,
        ], $archive->parameters());
        self::assertSame(30, $archive->nextPage()->start);
        self::assertSame(100, $archive->nextPage()->since);
        self::assertSame(200, $archive->nextPage()->until);
    }

    #[DataProvider('invalidQueryProvider')]
    public function testTaskQueryRejectsUnboundedOrUnsafePagination(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);
        $factory();
    }

    /** @return iterable<string, array{callable(): PveTaskQuery}> */
    public static function invalidQueryProvider(): iterable
    {
        yield 'negative start' => [static fn () => PveTaskQuery::active(-1)];
        yield 'zero limit' => [static fn () => PveTaskQuery::active(limit: 0)];
        yield 'oversized limit' => [static fn () => PveTaskQuery::active(limit: 101)];
        yield 'offset overflow' => [static fn () => PveTaskQuery::active(PHP_INT_MAX, 1)];
        yield 'negative since' => [static fn () => PveTaskQuery::archive(-1, 2)];
        yield 'reversed interval' => [static fn () => PveTaskQuery::archive(3, 2)];
    }

    public function testUpidParserPreservesAndDecodesTheOfficialGrammar(): void
    {
        $raw = 'UPID:pve9-a:0000003E:100000000:68800000:vzdump::backup-observer@pve:';
        $upid = PveUpid::parse($raw);

        self::assertSame($raw, $upid->raw);
        self::assertSame('pve9-a', $upid->node);
        self::assertSame(62, $upid->pid);
        self::assertSame(4294967296, $upid->processStart);
        self::assertSame(1753219072, $upid->startTime);
        self::assertSame('vzdump', $upid->type);
        self::assertSame('', $upid->id);
        self::assertSame('backup-observer@pve', $upid->user);
    }

    #[DataProvider('invalidUpidProvider')]
    public function testUpidParserRejectsMalformedOrUnsafeValues(string $raw): void
    {
        $this->expectException(InvalidArgumentException::class);
        PveUpid::parse($raw);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidUpidProvider(): iterable
    {
        $valid = 'UPID:pve:00000001:00000002:00000003:vzdump:100:user@pve:';
        yield 'oversize' => ['UPID:'.str_repeat('a', 1020)];
        yield 'prefix' => [str_replace('UPID:', 'TASK:', $valid)];
        yield 'node slash' => [str_replace('UPID:pve:', 'UPID:pve/a:', $valid)];
        yield 'node dot suffix' => [str_replace('UPID:pve:', 'UPID:pve.:', $valid)];
        yield 'node underscore suffix' => [str_replace('UPID:pve:', 'UPID:pve_:', $valid)];
        yield 'node hyphen suffix' => [str_replace('UPID:pve:', 'UPID:pve-:', $valid)];
        yield 'node dotted' => [str_replace('UPID:pve:', 'UPID:pve.test:', $valid)];
        yield 'short pid' => [str_replace('00000001', '0000001', $valid)];
        yield 'bad pstart' => [str_replace('00000002', '0000000G', $valid)];
        yield 'long pstart' => [str_replace('00000002', '0000000000', $valid)];
        yield 'short starttime' => [str_replace('00000003', '0000003', $valid)];
        yield 'wrong type' => [str_replace(':vzdump:', ':vzstart:', $valid)];
        yield 'id whitespace' => [str_replace(':100:', ':10 0:', $valid)];
        yield 'id slash' => [str_replace(':100:', ':10/0:', $valid)];
        yield 'user colon' => [str_replace(':user@pve:', ':user:realm:', $valid)];
        yield 'missing user' => [str_replace(':user@pve:', '::', $valid)];
        yield 'missing trailing colon' => [substr($valid, 0, -1)];
    }

    public function testPagesStatusesAndSnapshotsExposeConservativePredicates(): void
    {
        $query = PveTaskQuery::active(limit: 2);
        $task = $this->task(1, '100', PveTaskSource::Active);
        $issue = new PveBackupInventoryIssue(
            PveBackupInventoryIssueCode::InvalidField,
            '/nodes/pve/tasks',
            '/data/0/status',
        );
        self::assertTrue((new PveTaskPage($query, 1, [$task], []))->isShort());
        self::assertFalse((new PveTaskPage($query, 2, [$task], [$issue]))->isShort());
        self::assertFalse((new PveTaskPage($query, 2, [$task], [$issue]))->isComplete());

        $running = new PveTaskStatus($task->upid, PveTaskLifecycle::Running, null, 2, []);
        $ok = new PveTaskStatus($task->upid, PveTaskLifecycle::Stopped, 'OK', 2, []);
        $partialOk = new PveTaskStatus($task->upid, PveTaskLifecycle::Stopped, 'OK', 2, [$issue]);
        self::assertFalse($running->isSuccessful());
        self::assertTrue($ok->isSuccessful());
        self::assertFalse($partialOk->isComplete());
        self::assertFalse($partialOk->isSuccessful());

        $jobs = $this->jobs([]);
        $window = new PveTaskArchiveWindow(1, 2);
        $stream = new PveTaskStreamScanResult(
            'pve',
            PveTaskSource::Active,
            PveTaskStreamScanStatus::Complete,
            1,
            1,
        );
        $complete = new PveBackupInventorySnapshot($jobs, [$task], [], $window, [$stream], 1, 1);
        $partial = new PveBackupInventorySnapshot($jobs, [$task], [$issue], $window, [$stream], 1, 1);
        self::assertTrue($complete->isComplete());
        self::assertFalse($partial->isComplete());
        self::assertFalse($complete->permitsDeletionDecisions());
        self::assertSame($task->signature(), $this->task(1, '100', PveTaskSource::Archive)->signature());

        $incompleteStream = new PveTaskStreamScanResult(
            'pve',
            PveTaskSource::Archive,
            PveTaskStreamScanStatus::Partial,
            1,
            1,
        );
        self::assertFalse((new PveBackupInventorySnapshot(
            $jobs,
            [$task],
            [],
            $window,
            [$incompleteStream],
            1,
            1,
        ))->isComplete());

        $invalidCounters = 0;
        foreach ([[-1, 0], [0, -1]] as [$requests, $rows]) {
            try {
                new PveBackupInventorySnapshot($jobs, [], [], $window, [], $requests, $rows);
            } catch (InvalidArgumentException) {
                ++$invalidCounters;
            }
        }
        self::assertSame(2, $invalidCounters);
    }

    public function testPaginatorUsesOneFixedClockWindowAndMonotonicallyEnrichesDuplicateUpids(): void
    {
        $one = $this->task(1, '100', PveTaskSource::Active, null, 'RUNNING');
        $two = $this->task(2, '200', PveTaskSource::Active);
        $client = new BackupInventoryClient($this->jobs([]), [
            PveTaskSource::Active->value => [
                ['raw' => 100, 'tasks' => [$one], 'issues' => []],
                ['raw' => 1, 'tasks' => [$two], 'issues' => []],
            ],
            PveTaskSource::Archive->value => [
                ['raw' => 1, 'tasks' => [new PveBackupTask(
                    $one->upid,
                    PveTaskSource::Archive,
                    50,
                    'OK',
                )], 'issues' => []],
            ],
        ]);
        $clock = new BackupInventoryFixedClock(new DateTimeImmutable('1970-01-02T00:00:20+00:00'));

        $snapshot = (new ReadPveBackupInventory(
            $clock,
            new PveBackupInventoryLimits(archiveWindowSeconds: 10),
        ))->read($client, ['pve']);

        self::assertTrue($snapshot->isComplete());
        self::assertCount(2, $snapshot->tasks);
        self::assertSame(1, $clock->calls);
        self::assertSame(86_410, $snapshot->archiveWindow->since);
        self::assertSame(86_420, $snapshot->archiveWindow->until);
        self::assertSame(PveTaskSource::Archive, $snapshot->tasks[0]->source);
        self::assertTrue($snapshot->tasks[0]->seenActive);
        self::assertTrue($snapshot->tasks[0]->seenArchive);
        self::assertSame(50, $snapshot->tasks[0]->endTime);
        self::assertSame('OK', $snapshot->tasks[0]->listStatus);
        self::assertSame([
            ['pve', 'active', 0, 100, null, null],
            ['pve', 'active', 100, 100, null, null],
            ['pve', 'archive', 0, 100, 86_410, 86_420],
        ], $client->pageCalls);
    }

    public function testPlannerWindowBypassesClockAndAllNodesReuseItInBinaryOrder(): void
    {
        $client = new BackupInventoryClient($this->jobs([]), []);
        $clock = new BackupInventoryFixedClock(new DateTimeImmutable('@999'));
        $snapshot = (new ReadPveBackupInventory($clock))->read(
            $client,
            ['z-node', 'a-node'],
            new PveTaskArchiveWindow(10, 20, true),
        );

        self::assertTrue($snapshot->isComplete());
        self::assertSame(0, $clock->calls);
        self::assertTrue($snapshot->archiveWindow->historyGap);
        self::assertSame([
            ['a-node', 'active', 0, 100, null, null],
            ['a-node', 'archive', 0, 100, 10, 20],
            ['z-node', 'active', 0, 100, null, null],
            ['z-node', 'archive', 0, 100, 10, 20],
        ], $client->pageCalls);
    }

    public function testDuplicateMergeNeverRegressesAndFinalConflictsRemainPartial(): void
    {
        $task = $this->task(3, '300', PveTaskSource::Active, null, 'RUNNING');
        $final = new PveBackupTask($task->upid, PveTaskSource::Archive, 50, 'OK');
        $regression = new PveBackupTask($task->upid, PveTaskSource::Active, null, 'RUNNING');
        $enriched = $task->enrich($final)?->enrich($regression);
        self::assertNotNull($enriched);
        self::assertSame('OK', $enriched->listStatus);
        self::assertSame(50, $enriched->endTime);

        $conflict = new PveBackupTask($task->upid, PveTaskSource::Archive, 51, 'ERROR');
        $client = new BackupInventoryClient($this->jobs([]), [
            'active' => [['raw' => 1, 'tasks' => [$task], 'issues' => []]],
            'archive' => [['raw' => 2, 'tasks' => [$final, $conflict], 'issues' => []]],
        ]);

        $snapshot = $this->reader()->read($client, ['pve']);
        $codes = array_map(static fn (PveBackupInventoryIssue $issue) => $issue->code, $snapshot->issues);

        self::assertFalse($snapshot->isComplete());
        self::assertCount(1, $snapshot->tasks);
        self::assertSame('OK', $snapshot->tasks[0]->listStatus);
        self::assertSame(50, $snapshot->tasks[0]->endTime);
        self::assertContains(PveBackupInventoryIssueCode::ConflictingDuplicateTask, $codes);
    }

    public function testDuplicateMergeCoversEveryMonotonicStateTransition(): void
    {
        $activeNull = $this->task(20, '20', PveTaskSource::Active);
        $activeOk = $this->task(20, '20', PveTaskSource::Active, null, 'OK');
        $activeRunning = $this->task(20, '20', PveTaskSource::Active, null, 'RUNNING');
        $activeError = $this->task(20, '20', PveTaskSource::Active, null, 'ERROR');

        self::assertSame('OK', $activeNull->enrich($activeOk)?->listStatus);
        self::assertSame('OK', $activeOk->enrich($activeRunning)?->listStatus);
        self::assertSame('OK', $activeOk->enrich($activeNull)?->listStatus);
        self::assertSame(PveTaskSource::Active, $activeOk->enrich($activeOk)?->source);
        self::assertNull($activeOk->enrich($activeError));
        self::assertNull($activeOk->enrich($this->task(21, '21', PveTaskSource::Active)));

        $archiveOk = $this->task(20, '20', PveTaskSource::Archive, null, 'OK');
        $archiveRepeat = $archiveOk->enrich($archiveOk);
        self::assertNotNull($archiveRepeat);
        self::assertFalse($archiveRepeat->seenActive);
        self::assertTrue($archiveRepeat->seenArchive);
    }

    public function testInvalidDuplicateAndExcessTopologyNodesFailClosedBeforeTaskCalls(): void
    {
        $invalidClient = new BackupInventoryClient($this->jobs([]), []);
        $invalid = $this->reader()->read($invalidClient, ['pve', 'pve', 'bad.node']);
        self::assertFalse($invalid->isComplete());
        self::assertSame([], $invalidClient->pageCalls);
        self::assertSame(2, count(array_filter(
            $invalid->issues,
            static fn (PveBackupInventoryIssue $issue): bool => PveBackupInventoryIssueCode::InvalidNode === $issue->code,
        )));

        $limitedClient = new BackupInventoryClient($this->jobs([]), []);
        $limited = (new ReadPveBackupInventory(
            new BackupInventoryFixedClock(new DateTimeImmutable('@100')),
            new PveBackupInventoryLimits(nodeLimit: 1),
        ))->read($limitedClient, ['a', 'b']);
        self::assertSame([], $limitedClient->pageCalls);
        self::assertSame(PveBackupInventoryIssueCode::NodeLimitReached, $limited->issues[0]->code);

        $wrongTypeClient = new BackupInventoryClient($this->jobs([]), []);
        // @phpstan-ignore-next-line runtime boundary: array members are not PHP-enforced
        $wrongType = $this->reader()->read($wrongTypeClient, [123]);
        self::assertSame(PveBackupInventoryIssueCode::InvalidNode, $wrongType->issues[0]->code);
        self::assertSame([], $wrongTypeClient->pageCalls);
    }

    public function testPaginatorMapsReadFailuresAndUsesSmallPerStreamCaps(): void
    {
        $failure = PveReadFailure::for(PveReadFailureCode::RemoteUnavailable);
        $client = new BackupInventoryClient($failure, [
            'active' => [$failure],
            'archive' => [$failure],
        ]);
        $failed = $this->reader()->read($client, ['pve']);
        self::assertFalse($failed->jobs->isComplete());
        self::assertSame(PveBackupInventoryIssueCode::BackupJobReadFailed, $failed->jobs->issues[0]->code);
        self::assertSame(2, count(array_filter(
            $failed->issues,
            static fn (PveBackupInventoryIssue $issue): bool => PveBackupInventoryIssueCode::TaskStreamReadFailed === $issue->code,
        )));

        $cappedClient = new BackupInventoryClient($this->jobs([]), [], true);
        $capped = $this->reader()->read($cappedClient, ['pve']);
        self::assertSame(12, count($cappedClient->pageCalls));
        self::assertSame(2, count(array_filter(
            $capped->issues,
            static fn (PveBackupInventoryIssue $issue): bool => PveBackupInventoryIssueCode::PageCapReached === $issue->code,
        )));

        $empty = $this->reader()->read(
            new BackupInventoryClient($this->jobs([]), []),
            [],
        );
        self::assertTrue($empty->isComplete());
        self::assertSame([], $empty->tasks);
    }

    public function testGlobalRequestRawRowAndDistinctTaskLimitsStopRemainingStreams(): void
    {
        $requestClient = new BackupInventoryClient($this->jobs([]), [], true);
        $requestLimited = (new ReadPveBackupInventory(
            new BackupInventoryFixedClock(new DateTimeImmutable('@100')),
            new PveBackupInventoryLimits(
                pageSize: 1,
                requestLimit: 1,
                rawRowLimit: 10,
                distinctTaskLimit: 10,
            ),
        ))->read($requestClient, ['pve']);
        self::assertSame(1, $requestLimited->taskRequests);
        self::assertSame(PveBackupInventoryIssueCode::RequestLimitReached, $requestLimited->issues[0]->code);
        self::assertSame(PveTaskStreamScanStatus::NotScannedLimit, $requestLimited->taskStreams[1]->status);

        $rawClient = new BackupInventoryClient($this->jobs([]), [], true);
        $rawLimited = (new ReadPveBackupInventory(
            new BackupInventoryFixedClock(new DateTimeImmutable('@100')),
            new PveBackupInventoryLimits(
                pageSize: 2,
                rawRowLimit: 2,
                distinctTaskLimit: 2,
            ),
        ))->read($rawClient, ['pve']);
        self::assertSame(2, $rawLimited->rawTaskRows);
        self::assertSame(PveBackupInventoryIssueCode::RawRowLimitReached, $rawLimited->issues[0]->code);

        $distinctClient = new BackupInventoryClient($this->jobs([]), [
            'active' => [[
                'raw' => 2,
                'tasks' => [
                    $this->task(10, '10', PveTaskSource::Active),
                    $this->task(11, '11', PveTaskSource::Active),
                ],
                'issues' => [],
            ]],
        ]);
        $distinctLimited = (new ReadPveBackupInventory(
            new BackupInventoryFixedClock(new DateTimeImmutable('@100')),
            new PveBackupInventoryLimits(pageSize: 2, rawRowLimit: 2, distinctTaskLimit: 1),
        ))->read($distinctClient, ['pve']);
        self::assertCount(1, $distinctLimited->tasks);
        self::assertSame(PveBackupInventoryIssueCode::DistinctTaskLimitReached, $distinctLimited->issues[0]->code);

        $betweenStreamsClient = new BackupInventoryClient($this->jobs([]), []);
        $betweenStreams = (new ReadPveBackupInventory(
            new BackupInventoryFixedClock(new DateTimeImmutable('@100')),
            new PveBackupInventoryLimits(pageSize: 1, requestLimit: 1, rawRowLimit: 2, distinctTaskLimit: 2),
        ))->read($betweenStreamsClient, ['pve']);
        self::assertSame(PveTaskStreamScanStatus::Complete, $betweenStreams->taskStreams[0]->status);
        self::assertSame(PveTaskStreamScanStatus::NotScannedLimit, $betweenStreams->taskStreams[1]->status);

        $pageIssue = new PveBackupInventoryIssue(
            PveBackupInventoryIssueCode::InvalidField,
            '/nodes/pve/tasks',
            '/data/0/status',
        );
        $pageIssueClient = new BackupInventoryClient($this->jobs([]), [
            'active' => [['raw' => 1, 'tasks' => [], 'issues' => [$pageIssue]]],
        ]);
        $pageIssueSnapshot = $this->reader()->read($pageIssueClient, ['pve']);
        self::assertSame(PveTaskStreamScanStatus::Partial, $pageIssueSnapshot->taskStreams[0]->status);

        $failure = PveReadFailure::for(PveReadFailureCode::RemoteUnavailable);
        $lateFailureClient = new BackupInventoryClient($this->jobs([]), [
            'active' => [
                ['raw' => 1, 'tasks' => [$this->task(30, '30', PveTaskSource::Active)], 'issues' => []],
                $failure,
            ],
        ]);
        $lateFailure = (new ReadPveBackupInventory(
            new BackupInventoryFixedClock(new DateTimeImmutable('@100')),
            new PveBackupInventoryLimits(pageSize: 1),
        ))->read($lateFailureClient, ['pve']);
        self::assertSame(PveTaskStreamScanStatus::Partial, $lateFailure->taskStreams[0]->status);
        self::assertSame(2, $lateFailure->taskStreams[0]->requests);
    }

    public function testLimitsAndArchiveWindowsRejectUnsafeValues(): void
    {
        $rejections = 0;
        foreach ([
            static fn () => new PveBackupInventoryLimits(pageSize: 0),
            static fn () => new PveBackupInventoryLimits(pageSize: 101),
            static fn () => new PveBackupInventoryLimits(nodeLimit: 0),
            static fn () => new PveBackupInventoryLimits(nodeLimit: 129),
            static fn () => new PveBackupInventoryLimits(activePageCap: 0),
            static fn () => new PveBackupInventoryLimits(activePageCap: 3),
            static fn () => new PveBackupInventoryLimits(archivePageCap: 0),
            static fn () => new PveBackupInventoryLimits(archivePageCap: 11),
            static fn () => new PveBackupInventoryLimits(requestLimit: 0),
            static fn () => new PveBackupInventoryLimits(requestLimit: 513),
            static fn () => new PveBackupInventoryLimits(rawRowLimit: 99),
            static fn () => new PveBackupInventoryLimits(rawRowLimit: 25_001),
            static fn () => new PveBackupInventoryLimits(distinctTaskLimit: 0),
            static fn () => new PveBackupInventoryLimits(distinctTaskLimit: 25_001),
            static fn () => new PveBackupInventoryLimits(archiveWindowSeconds: 0),
            static fn () => new PveBackupInventoryLimits(archiveWindowSeconds: 86_401),
            static fn () => new PveTaskArchiveWindow(-1, 1),
            static fn () => new PveTaskArchiveWindow(0, 86_401),
        ] as $factory) {
            try {
                $factory();
                self::fail('Unsafe PVE backup inventory bounds must fail closed.');
            } catch (InvalidArgumentException) {
                ++$rejections;
            }
        }
        self::assertSame(18, $rejections);

        $client = new BackupInventoryClient($this->jobs([]), []);
        try {
            (new ReadPveBackupInventory(
                new BackupInventoryFixedClock(new DateTimeImmutable('@100')),
                new PveBackupInventoryLimits(archiveWindowSeconds: 10),
            ))->read($client, ['pve'], new PveTaskArchiveWindow(0, 11));
            self::fail('A planner window wider than the configured limit must fail closed.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $client->pageCalls);
        }

        $invalidFactories = [
            static fn () => PveTaskArchiveWindow::endingAt(new DateTimeImmutable('@1'), 0),
            static fn () => PveTaskArchiveWindow::endingAt(new DateTimeImmutable('@1'), 86_401),
            static fn () => PveTaskArchiveWindow::endingAt(new DateTimeImmutable('@-1'), 1),
            static fn () => new PveTaskArchiveWindow(2, 1),
        ];
        $invalidWindows = 0;
        foreach ($invalidFactories as $factory) {
            try {
                $factory();
            } catch (InvalidArgumentException) {
                ++$invalidWindows;
            }
        }
        self::assertSame(4, $invalidWindows);
        self::assertSame(0, PveTaskArchiveWindow::endingAt(new DateTimeImmutable('@10'), 10)->since);
    }

    public function testTaskObservationsMatchPersistenceBounds(): void
    {
        $upid = PveUpid::parse('UPID:pve:00000001:00000002:00000003:vzdump:1:user@pve:');
        $valid = new PveBackupTask(
            $upid,
            PveTaskSource::Archive,
            3,
            str_repeat('A', PveBackupTask::MAXIMUM_LIST_STATUS_LENGTH),
        );
        self::assertSame(PveUpid::MAXIMUM_LENGTH, 1024);
        self::assertSame(255, strlen($valid->listStatus ?? ''));

        $rejections = 0;
        foreach ([
            static fn () => new PveBackupTask($upid, PveTaskSource::Archive, 2, 'OK'),
            static fn () => new PveBackupTask($upid, PveTaskSource::Active, null, ''),
            static fn () => new PveBackupTask($upid, PveTaskSource::Active, null, "bad\nstatus"),
            static fn () => new PveBackupTask($upid, PveTaskSource::Active, null, str_repeat('A', 256)),
            static fn () => new PveBackupTask($upid, PveTaskSource::Active, null, 'OK', false, false),
            static fn () => new PveBackupTask($upid, PveTaskSource::Active, null, 'OK', false, true),
            static fn () => new PveBackupTask($upid, PveTaskSource::Archive, null, 'OK', true, false),
        ] as $factory) {
            try {
                $factory();
            } catch (InvalidArgumentException) {
                ++$rejections;
            }
        }
        self::assertSame(7, $rejections);
    }

    public function testPageAndStreamCursorDtosRejectInconsistentCounters(): void
    {
        $task = $this->task(40, '40', PveTaskSource::Active);
        $invalidPages = 0;
        foreach ([
            static fn () => new PveTaskPage(PveTaskQuery::active(limit: 1), -1, [], []),
            static fn () => new PveTaskPage(PveTaskQuery::active(limit: 1), 2, [], []),
            static fn () => new PveTaskPage(PveTaskQuery::active(limit: 1), 0, [$task], []),
        ] as $factory) {
            try {
                $factory();
            } catch (InvalidArgumentException) {
                ++$invalidPages;
            }
        }
        self::assertSame(3, $invalidPages);

        $invalidStreams = 0;
        foreach ([
            static fn () => new PveTaskStreamScanResult('', PveTaskSource::Active, PveTaskStreamScanStatus::Partial, 0, 0),
            static fn () => new PveTaskStreamScanResult('pve', PveTaskSource::Active, PveTaskStreamScanStatus::Partial, -1, 0),
            static fn () => new PveTaskStreamScanResult('pve', PveTaskSource::Active, PveTaskStreamScanStatus::Partial, 0, -1),
            static fn () => new PveTaskStreamScanResult('pve', PveTaskSource::Active, PveTaskStreamScanStatus::Complete, 0, 0),
            static fn () => new PveTaskStreamScanResult('pve', PveTaskSource::Active, PveTaskStreamScanStatus::NotScannedLimit, 1, 0),
            static fn () => new PveTaskStreamScanResult('pve', PveTaskSource::Active, PveTaskStreamScanStatus::NotScannedLimit, 0, 1),
        ] as $factory) {
            try {
                $factory();
            } catch (InvalidArgumentException) {
                ++$invalidStreams;
            }
        }
        self::assertSame(6, $invalidStreams);

        $partial = new PveTaskStreamScanResult(
            'pve',
            PveTaskSource::Active,
            PveTaskStreamScanStatus::Partial,
            1,
            0,
        );
        self::assertFalse($partial->isComplete());
    }

    /** @param list<PveBackupInventoryIssue> $issues */
    private function jobs(array $issues): PveBackupJobInventory
    {
        return new PveBackupJobInventory(PveBackupJobCapabilities::forMajor(9), [
            new PveBackupJob('job', null, null, null, null, null, null, null, null, null, null, null, null, null),
        ], $issues);
    }

    private function task(
        int $pid,
        string $id,
        PveTaskSource $source,
        ?int $endTime = null,
        ?string $status = null,
    ): PveBackupTask {
        $raw = sprintf('UPID:pve:%08X:%08X:%08X:vzdump:%s:user@pve:', $pid, $pid + 1, $pid + 2, $id);
        return new PveBackupTask(PveUpid::parse($raw), $source, $endTime, $status);
    }

    private function reader(): ReadPveBackupInventory
    {
        return new ReadPveBackupInventory(new BackupInventoryFixedClock(new DateTimeImmutable('@100')));
    }
}

/** @internal */
final class BackupInventoryClient implements PveReadClient
{
    /** @var array<string, list<array{raw: int, tasks: list<PveBackupTask>, issues: list<PveBackupInventoryIssue>}|PveReadFailure>> */
    private array $pages;

    /** @var list<array{string, string, int, int, ?int, ?int}> */
    public array $pageCalls = [];

    /**
     * @param PveBackupJobInventory|PveReadFailure $jobResult
     * @param array<string, list<array{raw: int, tasks: list<PveBackupTask>, issues: list<PveBackupInventoryIssue>}|PveReadFailure>> $pages
     */
    public function __construct(
        private readonly PveBackupJobInventory|PveReadFailure $jobResult,
        array $pages,
        private readonly bool $repeatFullPages = false,
    ) {
        $this->pages = $pages;
    }

    public function version(): PveVersion
    {
        return new PveVersion(9, 0, null, '9.0', '9.0', 'abcdef12');
    }

    public function backupJobs(): PveBackupJobInventory
    {
        if ($this->jobResult instanceof PveReadFailure) {
            throw $this->jobResult;
        }

        return $this->jobResult;
    }

    public function backupTaskPage(string $node, PveTaskQuery $query): PveTaskPage
    {
        $this->pageCalls[] = [$node, $query->source->value, $query->start, $query->limit, $query->since, $query->until];
        $result = isset($this->pages[$query->source->value])
            ? array_shift($this->pages[$query->source->value])
            : null;
        if ($result instanceof PveReadFailure) {
            throw $result;
        }
        if (null === $result && $this->repeatFullPages) {
            return new PveTaskPage($query, $query->limit, [], []);
        }
        if (!is_array($result)) {
            return new PveTaskPage($query, 0, [], []);
        }

        return new PveTaskPage($query, $result['raw'], $result['tasks'], $result['issues']);
    }

    public function permissions(): \App\Application\Proxmox\Pve\PvePermissionAssessment
    {
        throw new \LogicException('Not used by the backup inventory slice.');
    }

    public function topology(): \App\Application\Proxmox\Pve\PveClusterTopology
    {
        throw new \LogicException('Not used by the backup inventory slice.');
    }

    public function resources(): \App\Application\Proxmox\Pve\PveResourceInventory
    {
        throw new \LogicException('Not used by the backup inventory slice.');
    }

    public function storageConfigurations(): \App\Application\Proxmox\Pve\PveStorageConfigurationSet
    {
        throw new \LogicException('Not used by the backup inventory slice.');
    }

    public function nodeBackupStorages(string $node): \App\Application\Proxmox\Pve\PveNodeStorageStatusSet
    {
        throw new \LogicException('Not used by the backup inventory slice.');
    }

    public function backupTaskStatus(string $node, PveUpid $upid): PveTaskStatus
    {
        throw new \LogicException('Not used by the backup inventory slice.');
    }
}

/** @internal */
final class BackupInventoryFixedClock implements Clock
{
    public int $calls = 0;

    public function __construct(private readonly DateTimeImmutable $time)
    {
    }

    public function now(): DateTimeImmutable
    {
        ++$this->calls;

        return $this->time;
    }
}
