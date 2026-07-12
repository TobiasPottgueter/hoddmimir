<?php

declare(strict_types=1);

namespace App\Tests\Contract\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsJobKind;
use App\Application\Proxmox\Pbs\PbsSyncDirection;
use App\Application\Proxmox\Pbs\PbsTaskOutcome;
use App\Application\Proxmox\Pbs\PbsTaskPass;
use App\Infrastructure\Proxmox\Pbs\PbsJobListReader;
use App\Infrastructure\Proxmox\Pbs\PbsJsonEnvelopeDecoder;
use App\Infrastructure\Proxmox\Pbs\PbsTaskPageReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PbsTasksAndJobsFixtureContractTest extends TestCase
{
    #[DataProvider('versionProvider')]
    public function testPinnedVersionFixturesSatisfyThePositiveOnlyContract(
        string $version,
        PbsSyncDirection $direction,
        PbsTaskOutcome $historyOutcome,
        int $expectedTotal,
        bool $historyHasEndTime,
    ): void {
        $jobs = new PbsJobListReader();
        $prune = $jobs->read($this->fixture($version, 'admin-prune'), PbsJobKind::Prune, 4096);
        $sync = $jobs->read($this->fixture($version, 'admin-sync'), PbsJobKind::Sync, 4096);
        $verify = $jobs->read($this->fixture($version, 'admin-verify'), PbsJobKind::Verify, 4096);
        $history = (new PbsTaskPageReader())->read($this->fixture($version, 'tasks-history'), PbsTaskPass::History);
        $running = (new PbsTaskPageReader())->read($this->fixture($version, 'tasks-running'), PbsTaskPass::Running);

        self::assertSame(PbsJobKind::Prune, $prune->jobs[0]->kind);
        self::assertSame($direction, $sync->jobs[0]->syncDirection);
        self::assertSame(PbsJobKind::Verify, $verify->jobs[0]->kind);
        self::assertSame($historyOutcome, $history->tasks[0]->outcome);
        self::assertSame($historyHasEndTime, null !== $history->tasks[0]->endTime);
        self::assertTrue($running->tasks[0]->isRunning());
        self::assertTrue($running->tasks[0]->seenRunning);
        self::assertTrue($history->tasks[0]->seenHistory);
        self::assertNull($running->tasks[0]->endTime);
        self::assertContains($running->tasks[0]->reportedNode, ['localhost', 'pbs-four']);
        self::assertSame($expectedTotal, $history->total);
        self::assertGreaterThanOrEqual(count($history->tasks), $history->rawRowCount);
        if ('3' === $version) {
            self::assertSame(2, $history->rawRowCount);
            self::assertCount(1, $history->tasks);
        }
        if ('4.2' === $version) {
            self::assertSame('100000003', $history->tasks[0]->upid->processStartHex);
        }
    }

    /** @return iterable<string, array{string, PbsSyncDirection, PbsTaskOutcome, int, bool}> */
    public static function versionProvider(): iterable
    {
        yield 'PBS 3.4' => ['3', PbsSyncDirection::Pull, PbsTaskOutcome::Ok, 2, true];
        yield 'PBS 4.0' => ['4.0', PbsSyncDirection::Pull, PbsTaskOutcome::Ok, 1, false];
        yield 'PBS 4.1' => ['4.1', PbsSyncDirection::Push, PbsTaskOutcome::Warning, 1, true];
        yield 'PBS 4.2' => ['4.2', PbsSyncDirection::Pull, PbsTaskOutcome::Ok, 2, true];
    }

    private function fixture(string $version, string $name): \App\Infrastructure\Proxmox\Pbs\PbsApiEnvelope
    {
        $path = sprintf('%s/Fixtures/Proxmox/Pbs/%s/%s.json', dirname(__DIR__, 3), $version, $name);
        $json = file_get_contents($path);
        self::assertIsString($json);
        return (new PbsJsonEnvelopeDecoder())->decode($json, 4_194_304);
    }
}
