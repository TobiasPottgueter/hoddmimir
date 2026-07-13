<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Backup\Monitoring;

use App\Application\Backup\Monitoring\AmbiguousSubmissionEvidence;
use App\Application\Backup\Monitoring\AmbiguousSubmissionIdentity;
use App\Application\Backup\Monitoring\AmbiguousSubmissionReconciliationStore;
use App\Application\Backup\Monitoring\AmbiguousSubmissionTaskSource;
use App\Application\Backup\Monitoring\ReconcileAmbiguousSubmission;
use App\Application\Backup\Monitoring\ReconcileAmbiguousSubmissionCommand;
use App\Application\Backup\Monitoring\ReconciliationStatus;
use App\Application\Proxmox\Pve\PveBackupTask;
use App\Application\Proxmox\Pve\PveBackupApiFailure;
use App\Application\Proxmox\Pve\PveBackupApiFailureCode;
use App\Application\Proxmox\Pve\PveTaskSource;
use App\Application\Proxmox\Pve\PveUpid;
use App\Domain\Backup\RecoveryOutcome;
use App\Domain\Backup\RecoveryOutcomeKind;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use App\Tests\Fakes\FrozenClock;

final class ReconcileAmbiguousSubmissionTest extends TestCase
{
    public function testNoPreparedAmbiguityPerformsNoPveRead(): void
    {
        $store = new ReconciliationStoreFake();
        $source = new ReconciliationSourceFake(new AmbiguousSubmissionEvidence(true, []));

        $status = (new ReconcileAmbiguousSubmission($store, $source, new FrozenClock($this->command()->now)))->execute($this->command());

        self::assertSame(ReconciliationStatus::NoWork, $status);
        self::assertSame(0, $source->reads);
        self::assertCount(0, $store->outcomes);
    }

    public function testExactlyOneCompleteIdentityMatchAdoptsItsUpid(): void
    {
        $store = $this->store();
        $source = new ReconciliationSourceFake(new AmbiguousSubmissionEvidence(true, [
            $this->task('pve-a', '101', 'backup@pve', '67000000'),
            $this->task('pve-a', '102', 'backup@pve', '67000000'),
        ]));

        $status = (new ReconcileAmbiguousSubmission($store, $source, new FrozenClock($this->command()->now)))->execute($this->command());

        self::assertSame(ReconciliationStatus::Matched, $status);
        self::assertSame(RecoveryOutcomeKind::Matched, $store->outcomes[0]->kind);
        self::assertSame($this->task('pve-a', '101', 'backup@pve', '67000000')->upid->raw, $store->outcomes[0]->upid?->value);
    }

    public function testCompleteEvidenceWithoutMatchProvesNoStartButNeverCreatesRetryHere(): void
    {
        $store = $this->store();
        $source = new ReconciliationSourceFake(new AmbiguousSubmissionEvidence(true, [
            $this->task('pve-b', '101', 'backup@pve', '67000000'),
        ]));

        $status = (new ReconcileAmbiguousSubmission($store, $source, new FrozenClock($this->command()->now)))->execute($this->command());

        self::assertSame(ReconciliationStatus::ProvenNotStarted, $status);
        $outcome = $store->outcomes[0] ?? null;
        self::assertInstanceOf(RecoveryOutcome::class, $outcome);
        self::assertSame(RecoveryOutcomeKind::ProvenNotStarted, $outcome->kind);
        self::assertNull($store->outcomes[0]->upid);
    }

    public function testTemporaryEvidenceReadFailureDoesNotCreateAnUnknownOutcome(): void
    {
        $store = $this->store();
        $source = new ReconciliationSourceFake(new AmbiguousSubmissionEvidence(true, []));
        $source->failure = PveBackupApiFailureCode::RemoteUnavailable;

        self::assertSame(
            ReconciliationStatus::TemporarilyUnavailable,
            (new ReconcileAmbiguousSubmission($store, $source, new FrozenClock($this->command()->now)))->execute($this->command()),
        );
        self::assertCount(0, $store->outcomes);
        $source->failure = null;
        self::assertSame(
            ReconciliationStatus::ProvenNotStarted,
            (new ReconcileAmbiguousSubmission($store, $source, new FrozenClock($this->command()->now)))->execute($this->command()),
        );
        $outcome = $store->outcomes[0] ?? null;
        self::assertInstanceOf(RecoveryOutcome::class, $outcome);
        self::assertSame(RecoveryOutcomeKind::ProvenNotStarted, $outcome->kind);
    }

    #[DataProvider('inconclusiveEvidenceProvider')]
    public function testIncompleteOrMultipleEvidenceRemainsInconclusive(AmbiguousSubmissionEvidence $evidence): void
    {
        $store = $this->store();

        $status = (new ReconcileAmbiguousSubmission(
            $store,
            new ReconciliationSourceFake($evidence),
            new FrozenClock($this->command()->now),
        ))->execute($this->command());

        self::assertSame(ReconciliationStatus::Inconclusive, $status);
        self::assertSame(
            $evidence->complete ? RecoveryOutcomeKind::MultipleMatches : RecoveryOutcomeKind::Inconclusive,
            $store->outcomes[0]->kind,
        );
    }

    /** @return iterable<string, array{AmbiguousSubmissionEvidence}> */
    public static function inconclusiveEvidenceProvider(): iterable
    {
        $self = new self('inconclusiveEvidenceProvider');
        $matching = $self->task('pve-a', '101', 'backup@pve', '67000000');
        yield 'incomplete even with one match' => [new AmbiguousSubmissionEvidence(false, [$matching])];
        yield 'multiple exact matches' => [new AmbiguousSubmissionEvidence(true, [
            $matching,
            $self->task('pve-a', '101', 'backup@pve', '67000001'),
        ])];
    }

    #[DataProvider('identityMismatchProvider')]
    public function testEveryIdentityDimensionMustMatch(
        string $node,
        string $vmid,
        string $user,
        string $startHex,
    ): void {
        $store = $this->store();
        $source = new ReconciliationSourceFake(new AmbiguousSubmissionEvidence(true, [
            $this->task($node, $vmid, $user, $startHex),
        ]));

        self::assertSame(
            ReconciliationStatus::ProvenNotStarted,
            (new ReconcileAmbiguousSubmission($store, $source, new FrozenClock($this->command()->now)))->execute($this->command()),
        );
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function identityMismatchProvider(): iterable
    {
        yield 'node' => ['pve-b', '101', 'backup@pve', '67000000'];
        yield 'vmid' => ['pve-a', '102', 'backup@pve', '67000000'];
        yield 'user' => ['pve-a', '101', 'other@pve', '67000000'];
        yield 'before window' => ['pve-a', '101', 'backup@pve', '66FFFFFF'];
        yield 'after window' => ['pve-a', '101', 'backup@pve', '67000003'];
    }

    public function testReconciliationDtosValidateBoundsAndDuplicateEvidence(): void
    {
        $start = $this->time('67000000');
        $end = $this->time('67000002');
        $task = $this->task('pve-a', '101', 'backup@pve', '67000000');
        foreach ([
            fn () => new ReconcileAmbiguousSubmissionCommand('bad', self::id('r'), self::id('t'), 1, $start),
            fn () => new ReconcileAmbiguousSubmissionCommand(self::id('q'), self::id('r'), self::id('t'), 0, $start),
            fn () => new ReconcileAmbiguousSubmissionCommand(self::id('q'), self::id('r'), self::id('t'), 1, new DateTimeImmutable('2026-07-13T12:00:00+02:00')),
            fn () => new AmbiguousSubmissionIdentity('bad', 'pve-a', 101, 'backup@pve', $start, $end),
            fn () => new AmbiguousSubmissionIdentity(self::id('q'), '-bad', 101, 'backup@pve', $start, $end),
            fn () => new AmbiguousSubmissionIdentity(self::id('q'), 'pve-a', 0, 'backup@pve', $start, $end),
            fn () => new AmbiguousSubmissionIdentity(self::id('q'), 'pve-a', 101, 'bad user', $start, $end),
            fn () => new AmbiguousSubmissionIdentity(self::id('q'), 'pve-a', 101, '', $start, $end),
            fn () => new AmbiguousSubmissionIdentity(self::id('q'), 'pve-a', 101, 'backup@pve', $end, $start),
            fn () => new AmbiguousSubmissionIdentity(self::id('q'), 'pve-a', 101, 'backup@pve', $start, $start->modify('+301 seconds')),
            fn () => new AmbiguousSubmissionEvidence(true, [$task, $task]),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Invalid reconciliation state accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function store(): ReconciliationStoreFake
    {
        $store = new ReconciliationStoreFake();
        $store->identity = new AmbiguousSubmissionIdentity(
            self::id('q'),
            'pve-a',
            101,
            'backup@pve',
            $this->time('67000000'),
            $this->time('67000002'),
        );

        return $store;
    }

    private function command(): ReconcileAmbiguousSubmissionCommand
    {
        return new ReconcileAmbiguousSubmissionCommand(
            self::id('q'),
            self::id('r'),
            self::id('t'),
            1,
            $this->time('67000004'),
        );
    }

    private function task(string $node, string $vmid, string $user, string $startHex): PveBackupTask
    {
        return new PveBackupTask(
            PveUpid::parse(sprintf(
                'UPID:%s:0000002A:000F4240:%s:vzdump:%s:%s:',
                $node,
                $startHex,
                $vmid,
                $user,
            )),
            PveTaskSource::Active,
            null,
            'RUNNING',
        );
    }

    private function time(string $hex): DateTimeImmutable
    {
        return (new DateTimeImmutable('@'.hexdec($hex)))->setTimezone(new DateTimeZone('UTC'));
    }

    private static function id(string $byte): string
    {
        return str_repeat($byte, 16);
    }
}

final class ReconciliationStoreFake implements AmbiguousSubmissionReconciliationStore
{
    public ?AmbiguousSubmissionIdentity $identity = null;
    /** @var list<RecoveryOutcome> */ public array $outcomes = [];

    public function renew(ReconcileAmbiguousSubmissionCommand $command): bool
    {
        return true;
    }

    public function prepare(ReconcileAmbiguousSubmissionCommand $command): ?AmbiguousSubmissionIdentity
    {
        return $this->identity;
    }

    public function record(ReconcileAmbiguousSubmissionCommand $command, RecoveryOutcome $outcome): void
    {
        $this->outcomes[] = $outcome;
    }
}

final class ReconciliationSourceFake implements AmbiguousSubmissionTaskSource
{
    public int $reads = 0;
    public ?PveBackupApiFailureCode $failure = null;

    public function __construct(private readonly AmbiguousSubmissionEvidence $evidence)
    {
    }

    public function read(AmbiguousSubmissionIdentity $identity, callable $beforePage): AmbiguousSubmissionEvidence
    {
        if (!$beforePage()) {
            return new AmbiguousSubmissionEvidence(false, []);
        }
        ++$this->reads;
        if (null !== $this->failure) {
            throw PveBackupApiFailure::for($this->failure);
        }

        return $this->evidence;
    }
}
