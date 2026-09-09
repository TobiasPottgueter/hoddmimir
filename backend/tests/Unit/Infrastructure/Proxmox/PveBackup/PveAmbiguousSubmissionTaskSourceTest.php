<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox\PveBackup;

use App\Application\Backup\Monitoring\AmbiguousSubmissionIdentity;
use App\Application\Proxmox\Pve\PveBackupClient;
use App\Application\Proxmox\Pve\PveBackupClientProvider;
use App\Application\Proxmox\Pve\PveBackupSubmission;
use App\Application\Proxmox\Pve\PveBackupSubmissionResult;
use App\Application\Proxmox\Pve\PveTaskLogPage;
use App\Application\Proxmox\Pve\PveTaskLogQuery;
use App\Application\Proxmox\Pve\PveTaskPage;
use App\Application\Proxmox\Pve\PveTaskQuery;
use App\Application\Proxmox\Pve\PveBackupTask;
use App\Application\Proxmox\Pve\PveTaskSource;
use App\Application\Proxmox\Pve\PveTaskStatus;
use App\Application\Proxmox\Pve\PveTaskStopResult;
use App\Application\Proxmox\Pve\PveUpid;
use App\Domain\Shared\Clock;
use App\Infrastructure\Proxmox\PveBackup\PveAmbiguousSubmissionTaskSource;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PveAmbiguousSubmissionTaskSourceTest extends TestCase
{
    public function testTotalPageCapAndLeaseCallbackBoundTheSearch(): void
    {
        $client = new BoundedTaskClient();
        $source = new PveAmbiguousSubmissionTaskSource(
            $client,
            new SourceClock([$this->at(), $this->at(), $this->at(), $this->at()]),
            pageSize: 1,
            activePageCap: 2,
            archivePageCap: 2,
            totalPageCap: 2,
            maximumElapsedSeconds: 1_200,
        );
        $renewals = 0;

        $evidence = $source->read($this->identity(), static function () use (&$renewals): bool {
            ++$renewals;
            return true;
        });

        self::assertFalse($evidence->complete);
        self::assertSame(2, $client->pages);
        self::assertSame(2, $renewals);
    }

    public function testElapsedDeadlineAndLostLeaseStopBeforeAnotherPage(): void
    {
        $client = new BoundedTaskClient();
        $source = new PveAmbiguousSubmissionTaskSource(
            $client,
            new SourceClock([$this->at(), $this->at()->modify('+1106 seconds')]),
            pageSize: 1,
            maximumElapsedSeconds: 1_200,
        );
        self::assertFalse($source->read($this->identity(), static fn (): bool => true)->complete);
        self::assertSame(0, $client->pages);

        $client = new BoundedTaskClient();
        $source = new PveAmbiguousSubmissionTaskSource(
            $client,
            new SourceClock([$this->at(), $this->at()]),
            pageSize: 1,
        );
        self::assertFalse($source->read($this->identity(), static fn (): bool => false)->complete);
        self::assertSame(0, $client->pages);
    }

    public function testConfigurationHasHardUpperBounds(): void
    {
        foreach ([
            fn () => new PveAmbiguousSubmissionTaskSource(new BoundedTaskClient(), new SourceClock([$this->at()]), activePageCap: 17),
            fn () => new PveAmbiguousSubmissionTaskSource(new BoundedTaskClient(), new SourceClock([$this->at()]), archivePageCap: 17),
            fn () => new PveAmbiguousSubmissionTaskSource(new BoundedTaskClient(), new SourceClock([$this->at()]), totalPageCap: 17),
            fn () => new PveAmbiguousSubmissionTaskSource(new BoundedTaskClient(), new SourceClock([$this->at()]), maximumElapsedSeconds: 94),
            fn () => new PveAmbiguousSubmissionTaskSource(new BoundedTaskClient(), new SourceClock([$this->at()]), maximumElapsedSeconds: 3_601),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Unsafe reconciliation bounds were accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testShortActiveAndArchivePagesDeduplicateEnrichAndSortEvidence(): void
    {
        $first = PveUpid::parse('UPID:node-a:0000002A:000F4240:67000000:vzdump:100:backup@pve:');
        $second = PveUpid::parse('UPID:node-a:0000002B:000F4241:67000001:vzdump:101:backup@pve:');
        $client = new BoundedTaskClient();
        $client->shortPages = true;
        $client->tasks = [
            [new PveBackupTask($second, PveTaskSource::Active, null, 'RUNNING'), new PveBackupTask($first, PveTaskSource::Active, null, 'RUNNING')],
            [new PveBackupTask($first, PveTaskSource::Archive, $first->startTime + 30, 'OK')],
        ];
        $source = new PveAmbiguousSubmissionTaskSource(
            $client,
            new SourceClock([$this->at(), $this->at(), $this->at()]),
            pageSize: 3,
        );

        $evidence = $source->read($this->identity(), static fn (): bool => true);

        self::assertTrue($evidence->complete);
        self::assertSame([$first->raw, $second->raw], array_map(static fn (PveBackupTask $task): string => $task->upid->raw, $evidence->tasks));
        self::assertTrue($evidence->tasks[0]->seenActive);
        self::assertTrue($evidence->tasks[0]->seenArchive);
    }

    public function testConflictingDuplicateMarksEvidenceIncomplete(): void
    {
        $upid = PveUpid::parse('UPID:node-a:0000002A:000F4240:67000000:vzdump:100:backup@pve:');
        $client = new BoundedTaskClient();
        $client->shortPages = true;
        $client->tasks = [
            [new PveBackupTask($upid, PveTaskSource::Active, $upid->startTime + 30, 'ERROR')],
            [new PveBackupTask($upid, PveTaskSource::Archive, $upid->startTime + 31, 'OK')],
        ];
        $source = new PveAmbiguousSubmissionTaskSource($client, new SourceClock([$this->at(), $this->at(), $this->at()]), pageSize: 2);

        self::assertFalse($source->read($this->identity(), static fn (): bool => true)->complete);
    }

    private function identity(): AmbiguousSubmissionIdentity
    {
        return new AmbiguousSubmissionIdentity(str_repeat('q', 16), 'node-a', 100, 'backup@pve', $this->at(), $this->at()->modify('+30 seconds'));
    }

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-13T10:00:00Z');
    }
}

final class SourceClock implements Clock
{
    /** @param list<DateTimeImmutable> $times */
    public function __construct(private array $times) {}
    public function now(): DateTimeImmutable { return array_shift($this->times) ?? throw new \RuntimeException('Source clock exhausted.'); }
}

final class BoundedTaskClient implements PveBackupClient, PveBackupClientProvider
{
    public int $pages = 0;
    public bool $shortPages = false;
    /** @var list<list<PveBackupTask>> */ public array $tasks = [];
    public function forRequest(string $requestId): PveBackupClient { return $this; }
    public function taskPage(string $node, PveTaskQuery $query): PveTaskPage { $tasks=$this->tasks[$this->pages]??[]; ++$this->pages; return new PveTaskPage($query, $this->shortPages ? count($tasks) : $query->limit, $tasks, []); }
    public function submit(PveBackupSubmission $submission): PveBackupSubmissionResult { throw new \LogicException('unused'); }
    public function taskStatus(PveUpid $upid): PveTaskStatus { throw new \LogicException('unused'); }
    public function taskLog(PveUpid $upid, PveTaskLogQuery $query): PveTaskLogPage { throw new \LogicException('unused'); }
    public function stopTask(PveUpid $upid): PveTaskStopResult { throw new \LogicException('unused'); }
}
