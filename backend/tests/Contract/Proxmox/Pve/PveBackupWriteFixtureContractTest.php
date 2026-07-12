<?php

declare(strict_types=1);

namespace App\Tests\Contract\Proxmox\Pve;

use App\Application\Proxmox\Pve\BuildPveVzdumpPayload;
use App\Application\Proxmox\Pve\PveBackupCompression;
use App\Application\Proxmox\Pve\PveBackupMode;
use App\Application\Proxmox\Pve\PveBackupSubmission;
use App\Application\Proxmox\Pve\PveGuestType;
use App\Application\Proxmox\Pve\PvePruneBackups;
use App\Application\Proxmox\Pve\PveTaskLogQuery;
use App\Infrastructure\Proxmox\PveBackup\PveBackupJsonEnvelopeDecoder;
use App\Infrastructure\Proxmox\PveBackup\PveBackupSubmissionReader;
use App\Infrastructure\Proxmox\PveBackup\PveBackupTaskLogReader;
use App\Infrastructure\Proxmox\PveJsonEnvelopeDecoder;
use App\Infrastructure\Proxmox\PveVersionReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PveBackupWriteFixtureContractTest extends TestCase
{
    #[DataProvider('majorProvider')]
    public function testSanitizedWriteFixturesSatisfyTheVersionedContract(
        int $major,
        string $node,
        int $vmid,
    ): void {
        $version = (new PveVersionReader())->read(
            (new PveJsonEnvelopeDecoder())->decode($this->fixture($major, 'version')),
        );
        $submission = $this->submission($major, $node, $vmid);
        $decoder = new PveBackupJsonEnvelopeDecoder();
        $requestData = $decoder->decode($this->fixture($major, 'vzdump-submit-request'));

        self::assertInstanceOf(\stdClass::class, $requestData);
        $request = get_object_vars($requestData);
        self::assertSame($request, (new BuildPveVzdumpPayload())->build($version, $submission));
        self::assertSame([], array_diff(
            array_keys($request),
            ['vmid', 'storage', 'mode', 'compress', 'prune-backups', 'maxfiles'],
        ));
        foreach (['node', 'mailnotification', 'mailto', 'remove', 'script', 'tmpdir', 'bwlimit'] as $rootOnlyField) {
            self::assertArrayNotHasKey($rootOnlyField, $request);
        }
        if (9 === $major) {
            self::assertArrayNotHasKey('maxfiles', $request);
            self::assertStringNotContainsString('"maxfiles"', $this->fixture($major, 'vzdump-submit-request'));
        }

        $upid = (new PveBackupSubmissionReader())->read(
            $submission,
            $decoder->decode($this->fixture($major, 'vzdump-submit-response')),
        );
        self::assertSame($node, $upid->node);
        self::assertSame((string) $vmid, $upid->id);
        self::assertSame('vzdump', $upid->type);

        $query = new PveTaskLogQuery(start: 1, limit: 50);
        $log = (new PveBackupTaskLogReader())->read(
            $query,
            $decoder->decode($this->fixture($major, 'task-log-page')),
        );
        self::assertCount(2, $log->entries);
        self::assertSame([1, 2], array_map(static fn ($entry): int => $entry->number, $log->entries));
        self::assertTrue($log->isShort());

        self::assertNull($decoder->decode($this->fixture($major, 'task-stop-response')));
    }

    /** @return iterable<string, array{int, string, int}> */
    public static function majorProvider(): iterable
    {
        yield 'PVE 7 legacy maxfiles' => [7, 'pve7-a', 101];
        yield 'PVE 8 prune-backups' => [8, 'pve8-a', 301];
        yield 'PVE 9 without maxfiles' => [9, 'pve9-a', 401];
    }

    private function submission(int $major, string $node, int $vmid): PveBackupSubmission
    {
        return match ($major) {
            7 => new PveBackupSubmission(
                $node,
                $vmid,
                PveGuestType::Qemu,
                'backup-vault',
                PveBackupMode::Snapshot,
                PveBackupCompression::Zstd,
                legacyMaxFiles: 3,
            ),
            8 => new PveBackupSubmission(
                $node,
                $vmid,
                PveGuestType::Qemu,
                'pbs-archive',
                PveBackupMode::Suspend,
                PveBackupCompression::Zstd,
                pruneBackups: new PvePruneBackups(keepLast: 3, keepDaily: 7),
            ),
            9 => new PveBackupSubmission(
                $node,
                $vmid,
                PveGuestType::Qemu,
                'pbs-primary',
                PveBackupMode::Snapshot,
                PveBackupCompression::Zstd,
                pruneBackups: new PvePruneBackups(keepLast: 2, keepDaily: 7, keepWeekly: 4),
            ),
            default => throw new RuntimeException('Unsupported test major.'),
        };
    }

    private function fixture(int $major, string $name): string
    {
        $path = dirname(__DIR__, 3).sprintf('/Fixtures/Proxmox/Pve/%d/%s.json', $major, $name);
        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new RuntimeException('The sanitized PVE backup-write fixture is unavailable.');
        }

        return $contents;
    }
}
