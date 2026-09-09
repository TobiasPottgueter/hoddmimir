<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Proxmox\Pve;

use App\Application\Proxmox\Pve\BuildPveVzdumpPayload;
use App\Application\Proxmox\Pve\PveBackupApiFailure;
use App\Application\Proxmox\Pve\PveBackupApiFailureCode;
use App\Application\Proxmox\Pve\PveBackupCompression;
use App\Application\Proxmox\Pve\PveBackupFailureRecipients;
use App\Application\Proxmox\Pve\PveBackupMode;
use App\Application\Proxmox\Pve\PveBackupSubmission;
use App\Application\Proxmox\Pve\PveBackupSubmissionResult;
use App\Application\Proxmox\Pve\PveBackupSubmissionStatus;
use App\Application\Proxmox\Pve\PveGuestType;
use App\Application\Proxmox\Pve\PvePruneBackups;
use App\Application\Proxmox\Pve\PveTaskLogEntry;
use App\Application\Proxmox\Pve\PveTaskLogPage;
use App\Application\Proxmox\Pve\PveTaskLogQuery;
use App\Application\Proxmox\Pve\PveTaskStopResult;
use App\Application\Proxmox\Pve\PveTaskStopStatus;
use App\Application\Proxmox\Pve\PveUpid;
use App\Application\Proxmox\Pve\PveVersion;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PveBackupWriteContractsTest extends TestCase
{
    public function testVersionAwarePayloadsContainOnlyTheSupportedSafeParameters(): void
    {
        $builder = new BuildPveVzdumpPayload();
        $legacy = $builder->build($this->version(7), $this->submission(legacyMaxFiles: 3));
        self::assertSame([
            'vmid' => 101,
            'storage' => 'backup-store',
            'mode' => 'snapshot',
            'compress' => 'zstd',
            'maxfiles' => 3,
            'mailto' => 'ops@example.invalid',
            'mailnotification' => 'failure',
        ], $legacy);

        $prune = $builder->build($this->version(8), $this->submission(
            pruneBackups: new PvePruneBackups(false, 7, null, 3),
        ));
        self::assertSame('keep-all=0,keep-last=7,keep-daily=3', $prune['prune-backups']);
        self::assertSame('legacy-sendmail', $prune['notification-mode']);
        self::assertSame('ops@example.invalid', $prune['mailto']);
        self::assertSame('failure', $prune['mailnotification']);

        $pve9 = $builder->build($this->version(9), $this->submission(
            pruneBackups: new PvePruneBackups(true),
            failureNotificationRecipients: new PveBackupFailureRecipients([
                'backup-alerts@example.invalid',
                'platform@example.invalid',
            ]),
        ));
        self::assertArrayNotHasKey('maxfiles', $pve9);
        self::assertSame('keep-all=1', $pve9['prune-backups']);
        self::assertSame('backup-alerts@example.invalid,platform@example.invalid', $pve9['mailto']);
        self::assertSame('failure', $pve9['mailnotification']);
        self::assertSame('legacy-sendmail', $pve9['notification-mode']);
        self::assertArrayNotHasKey('notification-mode', $legacy);
        foreach (['job-id', 'tmpdir', 'dumpdir', 'script', 'fleecing'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $legacy);
            self::assertArrayNotHasKey($forbidden, $prune);
            self::assertArrayNotHasKey($forbidden, $pve9);
        }

        $pruneSerializer = new \ReflectionMethod($builder, 'prunePropertyString');
        self::assertSame('', $pruneSerializer->invoke($builder, new PvePruneBackups()));
    }

    /** @param array<string, string> $expectedNotificationFields */
    #[DataProvider('notificationPayloadProvider')]
    public function testNotificationPayloadIsVersionAware(
        int $major,
        array $expectedNotificationFields,
    ): void {
        $payload = (new BuildPveVzdumpPayload())->build(
            $this->version($major),
            $this->submission(),
        );

        self::assertSame(
            $expectedNotificationFields,
            array_intersect_key($payload, array_flip(['notification-mode', 'mailto', 'mailnotification'])),
        );
    }

    /** @return iterable<string, array{int, array<string, string>}> */
    public static function notificationPayloadProvider(): iterable
    {
        yield 'PVE 7' => [7, [
            'mailto' => 'ops@example.invalid',
            'mailnotification' => 'failure',
        ]];
        yield 'PVE 8' => [8, [
            'notification-mode' => 'legacy-sendmail',
            'mailto' => 'ops@example.invalid',
            'mailnotification' => 'failure',
        ]];
        yield 'PVE 9' => [9, [
            'notification-mode' => 'legacy-sendmail',
            'mailto' => 'ops@example.invalid',
            'mailnotification' => 'failure',
        ]];
    }

    public function testPve9RejectsLegacyMaxfilesAndUnsupportedMajorsLocally(): void
    {
        $builder = new BuildPveVzdumpPayload();
        $this->assertInvalid(fn () => $builder->build($this->version(9), $this->submission(legacyMaxFiles: 1)));
        $this->assertInvalid(fn () => $builder->build($this->version(6), $this->submission()));
    }

    #[DataProvider('invalidSubmissionProvider')]
    public function testSubmissionRejectsUnsafeOrAmbiguousValues(callable $factory): void
    {
        $this->assertInvalid($factory);
    }

    /** @return iterable<string, array{callable(): PveBackupSubmission}> */
    public static function invalidSubmissionProvider(): iterable
    {
        $recipients = new PveBackupFailureRecipients(['ops@example.invalid']);
        yield 'node' => [static fn () => new PveBackupSubmission('-bad', 101, PveGuestType::Qemu, 'store', PveBackupMode::Snapshot, PveBackupCompression::Zstd, $recipients)];
        yield 'vmid low' => [static fn () => new PveBackupSubmission('pve-a', 99, PveGuestType::Qemu, 'store', PveBackupMode::Snapshot, PveBackupCompression::Zstd, $recipients)];
        yield 'vmid high' => [static fn () => new PveBackupSubmission('pve-a', 1_000_000_000, PveGuestType::Qemu, 'store', PveBackupMode::Snapshot, PveBackupCompression::Zstd, $recipients)];
        yield 'storage' => [static fn () => new PveBackupSubmission('pve-a', 101, PveGuestType::Qemu, '_bad', PveBackupMode::Snapshot, PveBackupCompression::Zstd, $recipients)];
        yield 'maxfiles low' => [static fn () => new PveBackupSubmission('pve-a', 101, PveGuestType::Qemu, 'store', PveBackupMode::Snapshot, PveBackupCompression::Zstd, $recipients, legacyMaxFiles: 0)];
        yield 'maxfiles high' => [static fn () => new PveBackupSubmission('pve-a', 101, PveGuestType::Qemu, 'store', PveBackupMode::Snapshot, PveBackupCompression::Zstd, $recipients, legacyMaxFiles: 1_000_001)];
        yield 'empty prune' => [static fn () => new PveBackupSubmission('pve-a', 101, PveGuestType::Qemu, 'store', PveBackupMode::Snapshot, PveBackupCompression::Zstd, $recipients, new PvePruneBackups())];
        yield 'bad prune low' => [static fn () => new PveBackupSubmission('pve-a', 101, PveGuestType::Qemu, 'store', PveBackupMode::Snapshot, PveBackupCompression::Zstd, $recipients, new PvePruneBackups(keepLast: 0))];
        yield 'bad prune high' => [static fn () => new PveBackupSubmission('pve-a', 101, PveGuestType::Qemu, 'store', PveBackupMode::Snapshot, PveBackupCompression::Zstd, $recipients, new PvePruneBackups(keepLast: 1_000_001))];
        yield 'dual retention' => [static fn () => new PveBackupSubmission('pve-a', 101, PveGuestType::Qemu, 'store', PveBackupMode::Snapshot, PveBackupCompression::Zstd, $recipients, new PvePruneBackups(keepLast: 1), 1)];
        yield 'empty recipients' => [static fn () => new PveBackupSubmission('pve-a', 101, PveGuestType::Qemu, 'store', PveBackupMode::Snapshot, PveBackupCompression::Zstd, new PveBackupFailureRecipients([]))];
        yield 'invalid recipient' => [static fn () => new PveBackupSubmission('pve-a', 101, PveGuestType::Qemu, 'store', PveBackupMode::Snapshot, PveBackupCompression::Zstd, new PveBackupFailureRecipients(["ops@example.invalid\nBcc: leak@example.invalid"]))];
        yield 'padded recipient' => [static fn () => new PveBackupSubmission('pve-a', 101, PveGuestType::Qemu, 'store', PveBackupMode::Snapshot, PveBackupCompression::Zstd, new PveBackupFailureRecipients([' ops@example.invalid']))];
        yield 'blank recipient' => [static fn () => new PveBackupSubmission('pve-a', 101, PveGuestType::Qemu, 'store', PveBackupMode::Snapshot, PveBackupCompression::Zstd, new PveBackupFailureRecipients(['']))];
        yield 'oversized recipient' => [static fn () => new PveBackupSubmission('pve-a', 101, PveGuestType::Qemu, 'store', PveBackupMode::Snapshot, PveBackupCompression::Zstd, new PveBackupFailureRecipients([str_repeat('x', 243).'@example.invalid']))];
        yield 'duplicate recipients' => [static fn () => new PveBackupSubmission('pve-a', 101, PveGuestType::Qemu, 'store', PveBackupMode::Snapshot, PveBackupCompression::Zstd, new PveBackupFailureRecipients(['ops@example.invalid', 'OPS@example.invalid']))];
        yield 'too many recipients' => [static fn () => new PveBackupSubmission('pve-a', 101, PveGuestType::Qemu, 'store', PveBackupMode::Snapshot, PveBackupCompression::Zstd, new PveBackupFailureRecipients(array_fill(0, 33, 'ops@example.invalid')))];
    }

    public function testSingleFailureRecipientSerializesWithoutDelimiter(): void
    {
        self::assertSame(
            'ops@example.invalid',
            (new PveBackupFailureRecipients(['ops@example.invalid']))->parameterValue(),
        );
    }

    public function testSubmissionTypeRequiresNonNullableFailureRecipients(): void
    {
        $constructor = (new \ReflectionClass(PveBackupSubmission::class))->getConstructor();
        self::assertNotNull($constructor);
        $parameter = $constructor->getParameters()[6];

        self::assertSame('failureNotificationRecipients', $parameter->getName());
        self::assertFalse($parameter->allowsNull());
        self::assertFalse($parameter->isOptional());
        self::assertSame(PveBackupFailureRecipients::class, (string) $parameter->getType());
    }

    public function testSubmissionAndStopResultsEnforceTheirStateContracts(): void
    {
        $upid = $this->upid();
        self::assertSame(PveBackupSubmissionStatus::Accepted, PveBackupSubmissionResult::accepted($upid)->status);
        self::assertSame($upid, PveBackupSubmissionResult::accepted($upid)->upid);
        self::assertSame(PveBackupSubmissionStatus::Ambiguous, PveBackupSubmissionResult::ambiguous()->status);
        self::assertNull(PveBackupSubmissionResult::ambiguous()->upid);
        $this->assertInvalid(static fn () => new PveBackupSubmissionResult(PveBackupSubmissionStatus::Accepted, null));
        $this->assertInvalid(static fn () => new PveBackupSubmissionResult(PveBackupSubmissionStatus::Ambiguous, $upid));

        self::assertSame(PveTaskStopStatus::Requested, PveTaskStopResult::requested()->status);
        self::assertSame(PveTaskStopStatus::Ambiguous, PveTaskStopResult::ambiguous()->status);
    }

    public function testTaskLogQueryEntriesAndPagesAreBoundedAndOrdered(): void
    {
        $query = new PveTaskLogQuery(5, 2);
        self::assertSame(['start' => 5, 'limit' => 2], $query->parameters());
        self::assertSame(['start' => 7, 'limit' => 2], $query->nextPage()->parameters());
        $page = new PveTaskLogPage($query, [new PveTaskLogEntry(6, 'one'), new PveTaskLogEntry(7, 'two')]);
        self::assertFalse($page->isShort());
        self::assertTrue((new PveTaskLogPage($query, [new PveTaskLogEntry(6, 'one')]))->isShort());

        foreach ([[-1, 1], [0, 0], [0, 501], [PHP_INT_MAX, 1]] as [$start, $limit]) {
            $this->assertInvalid(static fn () => new PveTaskLogQuery($start, $limit));
        }
        $this->assertInvalid(static fn () => new PveTaskLogEntry(-1, 'bad'));
        $this->assertInvalid(static fn () => new PveTaskLogEntry(1, ''));
        $this->assertInvalid(static fn () => new PveTaskLogEntry(1, "bad\0log"));
        $this->assertInvalid(static fn () => new PveTaskLogEntry(1, str_repeat('x', 65_537)));
        self::assertSame(0, (new PveTaskLogEntry(0, str_repeat('x', 65_536)))->number);
        $this->assertInvalid(static fn () => new PveTaskLogPage(new PveTaskLogQuery(0, 1), [new PveTaskLogEntry(1, 'a'), new PveTaskLogEntry(2, 'b')]));
        $this->assertInvalid(static fn () => new PveTaskLogPage(new PveTaskLogQuery(0, 2), [new PveTaskLogEntry(2, 'a'), new PveTaskLogEntry(2, 'b')]));
        // Deliberately violate the documented generic type to prove the runtime boundary.
        // @phpstan-ignore argument.type
        $this->assertInvalid(static fn () => new PveTaskLogPage(new PveTaskLogQuery(0, 2), [new \stdClass()]));
    }

    public function testEveryBackupFailureUsesAStableSecretFreeMessage(): void
    {
        foreach (PveBackupApiFailureCode::cases() as $code) {
            $failure = PveBackupApiFailure::for($code);
            self::assertSame($code, $failure->failureCode);
            self::assertStringNotContainsString('TOKEN-SENTINEL', $failure->getMessage());
            self::assertNotSame('', $failure->getMessage());
        }
    }

    private function submission(
        ?PvePruneBackups $pruneBackups = null,
        ?int $legacyMaxFiles = null,
        ?PveBackupFailureRecipients $failureNotificationRecipients = null,
    ): PveBackupSubmission {
        return new PveBackupSubmission(
            'pve-a',
            101,
            PveGuestType::Qemu,
            'backup-store',
            PveBackupMode::Snapshot,
            PveBackupCompression::Zstd,
            $failureNotificationRecipients ?? new PveBackupFailureRecipients(['ops@example.invalid']),
            $pruneBackups,
            $legacyMaxFiles,
        );
    }

    private function version(int $major): PveVersion
    {
        return new PveVersion($major, 4, 1, '1', $major.'.4.1', 'abcdef12');
    }

    private function upid(): PveUpid
    {
        return PveUpid::parse('UPID:pve-a:0000002A:000F4240:67000000:vzdump:101:backup@pve:');
    }

    private function assertInvalid(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected invalid contract input.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }
}
