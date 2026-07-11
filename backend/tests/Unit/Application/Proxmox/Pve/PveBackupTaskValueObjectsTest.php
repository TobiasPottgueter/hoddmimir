<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Proxmox\Pve;

use App\Application\Proxmox\Pve\PveBackupInventoryIssue;
use App\Application\Proxmox\Pve\PveBackupInventoryIssueCode;
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
use App\Application\Proxmox\Pve\PveTaskPage;
use App\Application\Proxmox\Pve\PveTaskQuery;
use App\Application\Proxmox\Pve\PveTaskSource;
use App\Application\Proxmox\Pve\PveTaskStatus;
use App\Application\Proxmox\Pve\PveUpid;
use App\Application\Proxmox\Pve\PveVersion;
use App\Application\Proxmox\Pve\ReadPveBackupInventory;
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
        $complete = new PveBackupInventorySnapshot($jobs, [$task], []);
        $partial = new PveBackupInventorySnapshot($jobs, [$task], [$issue]);
        self::assertTrue($complete->isComplete());
        self::assertFalse($partial->isComplete());
        self::assertFalse($complete->permitsDeletionDecisions());
        self::assertSame($task->signature(), $this->task(1, '100', PveTaskSource::Archive)->signature());
    }

    public function testPaginatorSeparatesStreamsPaginatesAndDeduplicatesIdenticalUpids(): void
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
                    null,
                    'RUNNING',
                )], 'issues' => []],
            ],
        ]);

        $snapshot = (new ReadPveBackupInventory())->read($client, ['pve', 'pve'], 10, 20);

        self::assertTrue($snapshot->isComplete());
        self::assertCount(2, $snapshot->tasks);
        self::assertSame([
            ['pve', 'active', 0, 100, null, null],
            ['pve', 'active', 100, 100, null, null],
            ['pve', 'archive', 0, 100, 10, 20],
        ], $client->pageCalls);
    }

    public function testPaginatorKeepsPositiveRowsButMarksConflictsAndInvalidNodesPartial(): void
    {
        $task = $this->task(3, '300', PveTaskSource::Active, null, 'RUNNING');
        $conflict = new PveBackupTask($task->upid, PveTaskSource::Archive, 50, 'OK');
        $pageIssue = new PveBackupInventoryIssue(
            PveBackupInventoryIssueCode::InvalidField,
            '/nodes/pve/tasks',
            '/data/0/status',
        );
        $client = new BackupInventoryClient($this->jobs([]), [
            'active' => [['raw' => 1, 'tasks' => [$task], 'issues' => [$pageIssue]]],
            'archive' => [['raw' => 1, 'tasks' => [$conflict], 'issues' => []]],
        ]);

        $snapshot = (new ReadPveBackupInventory())->read(
            $client,
            ['', '-bad', 'pve.', 'pve_', 'pve-', 'pve.test', str_repeat('a', 64), "bad\nnode", 'pve'],
            0,
            1,
        );
        $codes = array_map(static fn (PveBackupInventoryIssue $issue) => $issue->code, $snapshot->issues);

        self::assertFalse($snapshot->isComplete());
        self::assertCount(1, $snapshot->tasks);
        self::assertSame(8, count(array_filter(
            $codes,
            static fn (PveBackupInventoryIssueCode $code): bool => PveBackupInventoryIssueCode::InvalidNode === $code,
        )));
        self::assertContains(PveBackupInventoryIssueCode::InvalidField, $codes);
        self::assertContains(PveBackupInventoryIssueCode::ConflictingDuplicateTask, $codes);
    }

    public function testPaginatorMapsReadFailuresAndCapsEveryFullStream(): void
    {
        $failure = PveReadFailure::for(PveReadFailureCode::RemoteUnavailable);
        $client = new BackupInventoryClient($failure, [
            'active' => [$failure],
            'archive' => [$failure],
        ]);
        $failed = (new ReadPveBackupInventory())->read($client, ['pve'], 0, 1);
        self::assertFalse($failed->jobs->isComplete());
        self::assertSame(PveBackupInventoryIssueCode::BackupJobReadFailed, $failed->jobs->issues[0]->code);
        self::assertSame(2, count(array_filter(
            $failed->issues,
            static fn (PveBackupInventoryIssue $issue): bool => PveBackupInventoryIssueCode::TaskStreamReadFailed === $issue->code,
        )));

        $cappedClient = new BackupInventoryClient($this->jobs([]), [], true);
        $capped = (new ReadPveBackupInventory())->read($cappedClient, ['pve'], 0, 1);
        self::assertSame(200, count($cappedClient->pageCalls));
        self::assertSame(2, count(array_filter(
            $capped->issues,
            static fn (PveBackupInventoryIssue $issue): bool => PveBackupInventoryIssueCode::PageCapReached === $issue->code,
        )));

        $empty = (new ReadPveBackupInventory())->read(
            new BackupInventoryClient($this->jobs([]), []),
            [],
            0,
            1,
        );
        self::assertTrue($empty->isComplete());
        self::assertSame([], $empty->tasks);
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
