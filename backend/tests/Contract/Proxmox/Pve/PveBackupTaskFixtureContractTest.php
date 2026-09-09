<?php

declare(strict_types=1);

namespace App\Tests\Contract\Proxmox\Pve;

use App\Application\Proxmox\Pve\PvePruneResponseShape;
use App\Application\Proxmox\Pve\PveBackupJobResponseContract;
use App\Application\Proxmox\Pve\PveTaskQuery;
use App\Application\Proxmox\Pve\PveUpid;
use App\Infrastructure\Proxmox\PveBackupJobReader;
use App\Infrastructure\Proxmox\PveJsonEnvelopeDecoder;
use App\Infrastructure\Proxmox\PveTaskPageReader;
use App\Infrastructure\Proxmox\PveTaskStatusReader;
use App\Infrastructure\Proxmox\PveVersionReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PveBackupTaskFixtureContractTest extends TestCase
{
    #[DataProvider('tokenStatusMajorProvider')]
    public function testTokenAuthenticatedTaskStatusFixturesReconstructTheExactUpidPrincipal(int $major): void
    {
        $decoder = new PveJsonEnvelopeDecoder();
        $data = $decoder->decode($this->fixture($major, 'task-status-token-stopped-ok'));
        self::assertInstanceOf(\stdClass::class, $data);
        self::assertIsString($data->upid ?? null);
        $upid = PveUpid::parse($data->upid);

        $status = (new PveTaskStatusReader($this->fixtureVersion($major)))
            ->read($upid->node, $upid, $data);

        self::assertTrue($status->isComplete());
        self::assertTrue($status->isSuccessful());
        self::assertSame([], $status->issues);
    }

    #[DataProvider('tokenStatusMajorProvider')]
    public function testTokenAuthenticatedTaskListFixturesReconstructTheExactUpidPrincipal(int $major): void
    {
        $data = (new PveJsonEnvelopeDecoder())->decode($this->fixture($major, 'node-tasks-token-active'));
        $node = sprintf('pve%d-a', $major);

        $page = (new PveTaskPageReader())->read($node, PveTaskQuery::active(), $data);

        self::assertTrue($page->isComplete());
        self::assertCount(1, $page->tasks);
        self::assertSame('backup-observer@pve!inventory-token', $page->tasks[0]->upid->user);
        self::assertSame([], $page->issues);
    }

    #[DataProvider('tokenStatusMajorProvider')]
    public function testActiveTaskListFixturesAcceptTheCompleteTokenPrincipalWithoutTokenId(int $major): void
    {
        $data = (new PveJsonEnvelopeDecoder())->decode($this->fixture($major, 'node-tasks-token-principal-active'));
        $node = sprintf('pve%d-a', $major);

        $page = (new PveTaskPageReader())->read($node, PveTaskQuery::active(), $data);

        self::assertTrue($page->isComplete());
        self::assertCount(1, $page->tasks);
        self::assertSame('backup-observer@pve!inventory-token', $page->tasks[0]->upid->user);
        self::assertSame([], $page->issues);
    }

    #[DataProvider('majorProvider')]
    public function testAllConstructedFixturesSatisfyTheVersionedReadContract(
        int $major,
        string $node,
        int $expectedJobs,
    ): void {
        $decoder = new PveJsonEnvelopeDecoder();
        $version = (new PveVersionReader())->read($decoder->decode($this->fixture($major, 'version')));
        $jobs = (new PveBackupJobReader($version))->read(
            $decoder->decode($this->fixture($major, 'backup-jobs')),
        );
        $jobFixture = $decoder->decode($this->fixture($major, 'backup-jobs'));
        $pageReader = new PveTaskPageReader();
        $active = $pageReader->read(
            $node,
            PveTaskQuery::active(limit: 2),
            $decoder->decode($this->fixture($major, 'node-tasks-active')),
        );
        $archiveSince = max(0, $active->tasks[0]->upid->startTime - 1_000);
        $archiveUntil = $active->tasks[0]->upid->startTime + 1_000;
        $archive0 = $pageReader->read(
            $node,
            PveTaskQuery::archive($archiveSince, $archiveUntil, limit: 2),
            $decoder->decode($this->fixture($major, 'node-tasks-archive-page-0')),
        );
        $archiveNext = $pageReader->read(
            $node,
            PveTaskQuery::archive($archiveSince, $archiveUntil, start: 2, limit: 2),
            $decoder->decode($this->fixture($major, 'node-tasks-archive-page-next')),
        );

        self::assertSame($major, $version->major);
        self::assertCount($expectedJobs, $jobs->jobs);
        self::assertSame(
            9 === $major
                ? PveBackupJobResponseContract::SelectedTypedFields
                : PveBackupJobResponseContract::BaselineIdOnly,
            $jobs->capabilities->responseContract,
        );
        self::assertSame(9 !== $major, $jobs->capabilities->supportsLegacyMaxFiles);
        self::assertSame(
            9 === $major ? PvePruneResponseShape::Object : PvePruneResponseShape::LegacyStringOrObject,
            $jobs->capabilities->pruneResponseShape,
        );
        self::assertTrue($jobs->isComplete());
        if (9 !== $major) {
            self::assertNotNull($jobs->jobs[1]->legacyMaxFiles);
            self::assertIsArray($jobFixture);
            foreach ($jobFixture as $jobRow) {
                if ($jobRow instanceof \stdClass) {
                    $jobRow = get_object_vars($jobRow);
                }
                self::assertIsArray($jobRow);
                self::assertFalse(isset($jobRow['maxfiles'], $jobRow['prune-backups']));
            }
        } else {
            self::assertStringNotContainsString('"maxfiles"', $this->fixture($major, 'backup-jobs'));
        }

        self::assertTrue($active->isShort());
        self::assertTrue($active->isComplete());
        self::assertCount(1, $active->tasks);
        self::assertFalse($archive0->isShort());
        self::assertTrue($archive0->isComplete());
        self::assertCount(2, $archive0->tasks);
        self::assertFalse($archiveNext->isShort());
        self::assertTrue($archiveNext->isComplete());
        self::assertCount(2, $archiveNext->tasks);
        self::assertSame($active->tasks[0]->signature(), $archive0->tasks[0]->signature());
        self::assertSame($active->tasks[0]->signature(), $archiveNext->tasks[0]->signature());
        self::assertSame('', $archive0->tasks[1]->upid->id);

        $statusReader = new PveTaskStatusReader($version);
        $runningUpid = PveUpid::parse($active->tasks[0]->upid->raw);
        $running = $statusReader->read(
            $node,
            $runningUpid,
            $decoder->decode($this->fixture($major, 'task-status-running')),
        );
        $okUpid = $archive0->tasks[1]->upid;
        $ok = $statusReader->read(
            $node,
            $okUpid,
            $decoder->decode($this->fixture($major, 'task-status-stopped-ok')),
        );
        $errorUpid = $archiveNext->tasks[1]->upid;
        $error = $statusReader->read(
            $node,
            $errorUpid,
            $decoder->decode($this->fixture($major, 'task-status-stopped-error')),
        );

        self::assertTrue($running->isComplete());
        self::assertFalse($running->isSuccessful());
        self::assertSame(7 === $major ? null : $runningUpid->processStart, $running->reportedProcessStart);
        self::assertTrue($ok->isComplete());
        self::assertTrue($ok->isSuccessful());
        self::assertTrue($error->isComplete());
        self::assertFalse($error->isSuccessful());
    }

    /** @return iterable<string, array{int, string, int}> */
    public static function majorProvider(): iterable
    {
        yield 'PVE 7' => [7, 'pve7-a', 4];
        yield 'PVE 8' => [8, 'pve8-a', 4];
        yield 'PVE 9' => [9, 'pve9-a', 1];
    }

    /** @return iterable<string, array{int}> */
    public static function tokenStatusMajorProvider(): iterable
    {
        yield 'PVE 7 token status' => [7];
        yield 'PVE 8 token status' => [8];
        yield 'PVE 9 token status' => [9];
    }

    private function fixtureVersion(int $major): \App\Application\Proxmox\Pve\PveVersion
    {
        return new \App\Application\Proxmox\Pve\PveVersion(
            $major,
            0,
            null,
            $major.'.0',
            $major.'.0',
            'abcdef12',
        );
    }

    private function fixture(int $major, string $name): string
    {
        $path = dirname(__DIR__, 3).sprintf('/Fixtures/Proxmox/Pve/%d/%s.json', $major, $name);
        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new RuntimeException('The sanitized PVE backup-task fixture is unavailable.');
        }

        return $contents;
    }
}
