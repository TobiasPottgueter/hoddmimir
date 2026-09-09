<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsTaskFilterFamily;
use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use App\Application\Proxmox\Pbs\PbsTaskListQuery;
use App\Application\Proxmox\Pbs\PbsTaskObservation;
use App\Application\Proxmox\Pbs\PbsTaskOutcome;
use App\Application\Proxmox\Pbs\PbsTaskPage;
use App\Application\Proxmox\Pbs\PbsTaskPageSource;
use App\Application\Proxmox\Pbs\PbsTaskPass;
use App\Application\Proxmox\Pbs\PbsTaskScanIssueCode;
use App\Application\Proxmox\Pbs\PbsTaskScanner;
use App\Application\Proxmox\Pbs\PbsTaskStreamStatus;
use App\Application\Proxmox\Pbs\PbsTasksAndJobsLimits;
use App\Application\Proxmox\Pbs\PbsTaskWindow;
use App\Application\Proxmox\Pbs\PbsUpid;
use PHPUnit\Framework\TestCase;

final class PbsTaskScannerTest extends TestCase
{
    public function testScannerRejectsAHistoryWindowWiderThanTheConfiguredLimitBeforeIo(): void
    {
        $source = new ConfiguredPbsTaskPageSource();
        $scanner = new PbsTaskScanner($source, new PbsTasksAndJobsLimits(10, 2, 20, 10, 60));

        $this->expectException(\InvalidArgumentException::class);
        try {
            $scanner->scan(new PbsTaskWindow(100, 161));
        } finally {
            self::assertSame([], $source->queries);
        }
    }

    public function testScannerRuntimeNodeTypeBoundaryIsEnforced(): void
    {
        $scanner = new PbsTaskScanner(new ConfiguredPbsTaskPageSource());
        $this->expectException(\TypeError::class);
        (new \ReflectionMethod($scanner, 'scan'))->invokeArgs($scanner, [[], new PbsTaskWindow(1, 2)]);
    }

    public function testReadFailuresAreIsolatedAsFailedOrPartialStreams(): void
    {
        $source = new ConfiguredPbsTaskPageSource();
        $source->fail(PbsTaskFilterFamily::Backup, PbsTaskPass::Running, 0);
        $source->set(PbsTaskFilterFamily::Prune, PbsTaskPass::History, 0, new PbsTaskPage([
            $this->task('prunejob', 'kept', true),
        ], 2));
        $source->fail(PbsTaskFilterFamily::Prune, PbsTaskPass::History, 1);

        $snapshot = (new PbsTaskScanner($source, new PbsTasksAndJobsLimits(1, 3, 3, 10)))
            ->scan(new PbsTaskWindow(100, 200));

        self::assertContains(PbsTaskScanIssueCode::ReadFailed, array_column($snapshot->issues, 'code'));
        $failed = $this->stream($snapshot, PbsTaskFilterFamily::Backup, PbsTaskPass::Running);
        self::assertSame(PbsTaskStreamStatus::Failed, $failed->status);
        self::assertSame(0, $failed->pagesRead);
        $partial = $this->stream($snapshot, PbsTaskFilterFamily::Prune, PbsTaskPass::History);
        self::assertSame(PbsTaskStreamStatus::Partial, $partial->status);
        self::assertSame([1, 1, 1], [$partial->pagesRead, $partial->rowsRead, $partial->itemsSeen]);
        self::assertTrue($partial->historyGap);
        self::assertCount(1, $snapshot->tasks);
    }

    public function testConflictingTerminalDuplicatesAreRetainedDeterministicallyAndDiagnosed(): void
    {
        $source = new ConfiguredPbsTaskPageSource();
        $ok = $this->task('backup', 'conflict', true, 'localhost');
        $error = new PbsTaskObservation(
            $ok->upid,
            'pbs-four',
            true,
            false,
            PbsTaskOutcome::Error,
            102,
        );
        $source->set(PbsTaskFilterFamily::Backup, PbsTaskPass::Running, 0, new PbsTaskPage([$error], 1));
        $source->set(PbsTaskFilterFamily::Backup, PbsTaskPass::History, 0, new PbsTaskPage([$ok], 1));

        $snapshot = (new PbsTaskScanner($source, new PbsTasksAndJobsLimits(10, 2, 20, 10)))
            ->scan(new PbsTaskWindow(100, 200));

        self::assertSame(PbsTaskScanIssueCode::ConflictingTaskEvidence, $snapshot->issues[0]->code);
        self::assertCount(1, $snapshot->tasks);
        self::assertSame(PbsTaskOutcome::Error, $snapshot->tasks[0]->outcome);
        self::assertSame(
            PbsTaskStreamStatus::Partial,
            $this->stream($snapshot, PbsTaskFilterFamily::Backup, PbsTaskPass::History)->status,
        );
    }

    public function testScannerUsesEveryFamilyAndPassButKeepsOnlyTheExactAllowlist(): void
    {
        $source = new ConfiguredPbsTaskPageSource();
        $source->set(PbsTaskFilterFamily::Backup, PbsTaskPass::History, 0, new PbsTaskPage([
            $this->task('backup', 'backup-a', true),
        ], 2, 2, hash('sha256', 'backup-plus-disallowed-tape')));
        $source->set(PbsTaskFilterFamily::Verify, PbsTaskPass::Running, 0, new PbsTaskPage([
            $this->task('verify_snapshot', 'verify-a', false),
        ], 1));
        $scanner = new PbsTaskScanner($source, new PbsTasksAndJobsLimits(10, 2, 20, 10));

        $snapshot = $scanner->scan(new PbsTaskWindow(100, 200));

        self::assertTrue($snapshot->isComplete());
        self::assertCount(2, $snapshot->tasks);
        self::assertSame(['backup', 'verify_snapshot'], array_map(
            static fn (PbsTaskObservation $task): string => $task->upid->workerType,
            $snapshot->tasks,
        ));
        self::assertCount(8, $source->queries);
        self::assertCount(8, $snapshot->streams);
        self::assertSame(PbsTaskStreamStatus::Complete, $snapshot->streams[0]->status);
        foreach ($source->queries as $query) {
            self::assertSame(10, $query->limit);
            self::assertSame(PbsTaskPass::History === $query->pass, null !== $query->window);
        }
    }

    public function testScannerMergesRunningAndTerminalOverlap(): void
    {
        $source = new ConfiguredPbsTaskPageSource();
        $running = $this->task('backup', 'same', false, 'localhost');
        $terminal = $this->task('backup', 'same', true, 'pbs-four');
        $source->set(PbsTaskFilterFamily::Backup, PbsTaskPass::Running, 0, new PbsTaskPage([$running], null));
        $source->set(PbsTaskFilterFamily::Backup, PbsTaskPass::History, 0, new PbsTaskPage([$terminal], null));

        $snapshot = (new PbsTaskScanner($source, new PbsTasksAndJobsLimits(10, 2, 20, 10)))
            ->scan(new PbsTaskWindow(100, 200));

        self::assertCount(1, $snapshot->tasks);
        self::assertSame(PbsTaskOutcome::Ok, $snapshot->tasks[0]->outcome);
        self::assertSame('pbs-four', $snapshot->tasks[0]->reportedNode);
    }

    public function testTerminalObservationEnrichesOptionalEndTimeWithoutCreatingAConflict(): void
    {
        $source = new ConfiguredPbsTaskPageSource();
        $terminal = $this->task('backup', 'same-terminal', true, 'pbs-four');
        $withoutEnd = new PbsTaskObservation(
            $terminal->upid,
            'localhost',
            false,
            true,
            PbsTaskOutcome::Ok,
            null,
        );
        $source->set(PbsTaskFilterFamily::Backup, PbsTaskPass::Running, 0, new PbsTaskPage([$withoutEnd], 1));
        $source->set(PbsTaskFilterFamily::Backup, PbsTaskPass::History, 0, new PbsTaskPage([$terminal], 1));

        $snapshot = (new PbsTaskScanner($source, new PbsTasksAndJobsLimits(10, 2, 20, 10)))
            ->scan(new PbsTaskWindow(100, 200));

        self::assertSame([], $snapshot->issues);
        self::assertSame(101, $snapshot->tasks[0]->endTime);
        self::assertSame(
            PbsTaskStreamStatus::Complete,
            $this->stream($snapshot, PbsTaskFilterFamily::Backup, PbsTaskPass::History)->status,
        );
    }

    public function testRepeatedPageAndNoProgressAreDiagnosed(): void
    {
        $source = new ConfiguredPbsTaskPageSource();
        $task = $this->task('backup', 'same', true);
        $source->set(PbsTaskFilterFamily::Backup, PbsTaskPass::History, 0, new PbsTaskPage([$task], 3));
        $source->set(PbsTaskFilterFamily::Backup, PbsTaskPass::History, 1, new PbsTaskPage([$task], 3));
        $source->set(PbsTaskFilterFamily::Prune, PbsTaskPass::History, 0, new PbsTaskPage([], 1));

        $snapshot = (new PbsTaskScanner($source, new PbsTasksAndJobsLimits(1, 3, 3, 10)))
            ->scan(new PbsTaskWindow(100, 200));

        self::assertSame(
            [PbsTaskScanIssueCode::RepeatedPage, PbsTaskScanIssueCode::NoProgress],
            array_column($snapshot->issues, 'code'),
        );
        $partialStreams = array_values(array_filter(
            $snapshot->streams,
            static fn ($stream): bool => PbsTaskStreamStatus::Partial === $stream->status,
        ));
        self::assertCount(2, $partialStreams);
        self::assertTrue($partialStreams[0]->historyGap);
    }

    public function testPageAndRowCapsAreIndependent(): void
    {
        $pageCap = new ConfiguredPbsTaskPageSource();
        $pageCap->set(PbsTaskFilterFamily::Backup, PbsTaskPass::History, 0, new PbsTaskPage([
            $this->task('backup', 'one', true),
        ], 2));
        $pageSnapshot = (new PbsTaskScanner($pageCap, new PbsTasksAndJobsLimits(1, 1, 1, 10)))
            ->scan(new PbsTaskWindow(100, 200));
        self::assertSame(PbsTaskScanIssueCode::PageCapExceeded, $pageSnapshot->issues[0]->code);

        $rowCap = new ConfiguredPbsTaskPageSource();
        $rowCap->set(PbsTaskFilterFamily::Backup, PbsTaskPass::History, 0, new PbsTaskPage([
            $this->task('backup', 'one', true),
        ], 2));
        $rowCap->set(PbsTaskFilterFamily::Backup, PbsTaskPass::History, 1, new PbsTaskPage([
            $this->task('backup', 'two', true),
        ], 2));
        $rowSnapshot = (new PbsTaskScanner($rowCap, new PbsTasksAndJobsLimits(1, 2, 1, 10)))
            ->scan(new PbsTaskWindow(100, 200));
        self::assertSame(PbsTaskScanIssueCode::RowCapExceeded, $rowSnapshot->issues[0]->code);

        $runningCap = new ConfiguredPbsTaskPageSource();
        $runningCap->set(PbsTaskFilterFamily::Sync, PbsTaskPass::Running, 0, new PbsTaskPage([
            $this->task('syncjob', 'running-cap', false),
        ], 2));
        $runningSnapshot = (new PbsTaskScanner($runningCap, new PbsTasksAndJobsLimits(1, 1, 1, 10)))
            ->scan(new PbsTaskWindow(100, 200));
        self::assertFalse($this->stream(
            $runningSnapshot, PbsTaskFilterFamily::Sync, PbsTaskPass::Running,
        )->historyGap);
    }

    public function testRawRowCapacityBoundsTheNextRequestBeforeIo(): void
    {
        $source = new ConfiguredPbsTaskPageSource();
        $source->set(
            PbsTaskFilterFamily::Backup,
            PbsTaskPass::History,
            0,
            new PbsTaskPage([], 301, 256, hash('sha256', 'raw-page-256')),
        );
        $source->set(
            PbsTaskFilterFamily::Backup,
            PbsTaskPass::History,
            256,
            new PbsTaskPage([], 301, 44, hash('sha256', 'raw-page-44')),
        );

        $snapshot = (new PbsTaskScanner($source, new PbsTasksAndJobsLimits(256, 16, 300, 10)))
            ->scan(new PbsTaskWindow(100, 200));

        $queries = array_values(array_filter(
            $source->queries,
            static fn (PbsTaskListQuery $query): bool => PbsTaskFilterFamily::Backup === $query->family
                && PbsTaskPass::History === $query->pass,
        ));
        self::assertSame([256, 44], array_column($queries, 'limit'));
        self::assertSame([0, 256], array_column($queries, 'start'));
        self::assertSame(
            PbsTaskScanIssueCode::RowCapExceeded,
            $this->stream($snapshot, PbsTaskFilterFamily::Backup, PbsTaskPass::History)->issueCode,
        );
    }

    public function testServerCannotReturnMoreRawRowsThanTheRequestedRemainingCapacity(): void
    {
        $source = new ConfiguredPbsTaskPageSource();
        $source->set(
            PbsTaskFilterFamily::Backup,
            PbsTaskPass::History,
            0,
            new PbsTaskPage([], 301, 256, hash('sha256', 'raw-page-256')),
        );
        $source->set(
            PbsTaskFilterFamily::Backup,
            PbsTaskPass::History,
            256,
            new PbsTaskPage([], 301, 45, hash('sha256', 'raw-page-oversized')),
        );

        $snapshot = (new PbsTaskScanner($source, new PbsTasksAndJobsLimits(256, 16, 300, 10)))
            ->scan(new PbsTaskWindow(100, 200));
        $stream = $this->stream($snapshot, PbsTaskFilterFamily::Backup, PbsTaskPass::History);

        self::assertSame(PbsTaskScanIssueCode::RowCapExceeded, $stream->issueCode);
        self::assertSame(256, $stream->rowsRead);
        self::assertSame(2, $stream->pagesRead);
    }

    public function testShortPageWithoutTotalAndFullPageWithTotalTerminateDeterministically(): void
    {
        $short = new ConfiguredPbsTaskPageSource();
        $short->set(PbsTaskFilterFamily::Sync, PbsTaskPass::History, 0, new PbsTaskPage([
            $this->task('syncjob', 'sync-a', true),
        ], null));
        $shortSnapshot = (new PbsTaskScanner($short, new PbsTasksAndJobsLimits(2, 2, 4, 10)))
            ->scan(new PbsTaskWindow(100, 200));
        self::assertTrue($shortSnapshot->isComplete());

        $full = new ConfiguredPbsTaskPageSource();
        $full->set(PbsTaskFilterFamily::Prune, PbsTaskPass::Running, 0, new PbsTaskPage([
            $this->task('prunejob', 'prune-a', false),
        ], 1));
        $fullSnapshot = (new PbsTaskScanner($full, new PbsTasksAndJobsLimits(1, 2, 2, 10)))
            ->scan(new PbsTaskWindow(100, 200));
        self::assertTrue($fullSnapshot->isComplete());
    }

    public function testDifferentDisallowedOnlyRawPagesDoNotLookRepeated(): void
    {
        $source = new ConfiguredPbsTaskPageSource();
        $source->set(PbsTaskFilterFamily::Backup, PbsTaskPass::History, 0, new PbsTaskPage(
            [], 3, 1, hash('sha256', 'raw-tape-a'),
        ));
        $source->set(PbsTaskFilterFamily::Backup, PbsTaskPass::History, 1, new PbsTaskPage(
            [], 3, 1, hash('sha256', 'raw-tape-b'),
        ));
        $source->set(PbsTaskFilterFamily::Backup, PbsTaskPass::History, 2, new PbsTaskPage([
            $this->task('backup', 'relevant', true),
        ], 3));

        $snapshot = (new PbsTaskScanner($source, new PbsTasksAndJobsLimits(1, 4, 4, 10)))
            ->scan(new PbsTaskWindow(100, 200));

        self::assertTrue($snapshot->isComplete());
        self::assertCount(1, $snapshot->tasks);
        $stream = $this->stream($snapshot, PbsTaskFilterFamily::Backup, PbsTaskPass::History);
        self::assertSame([3, 3, 1], [$stream->pagesRead, $stream->rowsRead, $stream->itemsSeen]);
    }

    private function task(
        string $type,
        string $id,
        bool $terminal,
        ?string $reportedNode = 'localhost',
    ): PbsTaskObservation {
        $taskNumber = substr(hash('sha256', $type."\0".$id), 0, 16);
        $upid = new PbsUpid(sprintf(
            'UPID:pbs-four:0000002A:000F4240:%s:00000064:%s:%s:root@pam:',
            $taskNumber,
            $type,
            $id,
        ));
        return new PbsTaskObservation(
            $upid,
            $reportedNode,
            !$terminal,
            $terminal,
            $terminal ? PbsTaskOutcome::Ok : null,
            $terminal ? 101 : null,
        );
    }

    private function stream(
        \App\Application\Proxmox\Pbs\PbsTaskScanSnapshot $snapshot,
        PbsTaskFilterFamily $family,
        PbsTaskPass $pass,
    ): \App\Application\Proxmox\Pbs\PbsTaskStreamResult {
        foreach ($snapshot->streams as $stream) {
            if ($family === $stream->family && $pass === $stream->pass) {
                return $stream;
            }
        }
        self::fail('Expected PBS task stream is missing.');
    }
}

final class ConfiguredPbsTaskPageSource implements PbsTaskPageSource
{
    /** @var list<PbsTaskListQuery> */ public array $queries = [];
    /** @var array<string, PbsTaskPage> */ private array $pages = [];
    /** @var array<string, true> */ private array $failures = [];

    public function set(PbsTaskFilterFamily $family, PbsTaskPass $pass, int $start, PbsTaskPage $page): void
    {
        $this->pages[$this->key($family, $pass, $start)] = $page;
    }

    public function fail(PbsTaskFilterFamily $family, PbsTaskPass $pass, int $start): void
    {
        $this->failures[$this->key($family, $pass, $start)] = true;
    }

    public function page(PbsTaskListQuery $query): PbsTaskPage
    {
        $this->queries[] = $query;
        if (isset($this->failures[$this->key($query->family, $query->pass, $query->start)])) {
            throw PbsReadFailure::for(PbsReadFailureCode::Transport);
        }
        return $this->pages[$this->key($query->family, $query->pass, $query->start)] ?? new PbsTaskPage([], 0);
    }

    private function key(PbsTaskFilterFamily $family, PbsTaskPass $pass, int $start): string
    {
        return $family->value.'-'.$pass->value.'-'.$start;
    }
}
