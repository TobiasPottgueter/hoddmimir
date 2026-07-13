<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Target;

use App\Domain\Target\AllowedNodes;
use App\Domain\Target\ActivationEvidenceObservation;
use App\Domain\Target\BackupTarget;
use App\Domain\Target\BackupTargetId;
use App\Domain\Target\ConcurrencyPolicy;
use App\Domain\Target\MinimumFreeBytes;
use App\Domain\Target\PbsTargetMapping;
use App\Domain\Target\TargetActivationBlocker;
use App\Domain\Target\TargetActivationEvidence;
use App\Domain\Target\TargetActivationFailed;
use App\Domain\Target\TargetRevision;
use App\Domain\Target\TargetStatus;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

final class BackupTargetAggregateTest extends TestCase
{
    public function testCompleteTargetCanEnableAndDisable(): void
    {
        $target = $this->complete();
        $now = new DateTimeImmutable('2026-07-12T12:00:00Z');
        self::assertTrue($target->assessActivation($this->evidence(true, $now), $now)->canEnable());
        $enabled = $target->enable($this->evidence(true, $now), $now);
        self::assertSame(TargetStatus::Enabled, $enabled->status);
        self::assertSame(2, $enabled->revision->value);
        self::assertSame(TargetStatus::Disabled, $enabled->disable()->status);
        $this->expectException(DomainException::class);
        $enabled->enable($this->evidence(true, $now), $now);
    }

    public function testActivationFailsClosedForEveryMissingConfigurationAndEvidence(): void
    {
        $target = new BackupTarget(new BackupTargetId(str_repeat('i', 16)), new TargetRevision(1),
            TargetStatus::Disabled, true, null, new AllowedNodes([]), null, null);
        $now = new DateTimeImmutable('2026-07-12T12:00:00Z');
        $assessment = $target->assessActivation($this->evidence(null, null), $now);
        self::assertSame([
            TargetActivationBlocker::MinimumFreeUnconfigured,
            TargetActivationBlocker::AllowedNodesEmpty,
            TargetActivationBlocker::ConcurrencyUnconfigured,
            TargetActivationBlocker::PbsMappingRequired,
            TargetActivationBlocker::CandidateEvidenceMissing,
            TargetActivationBlocker::InventoryEvidenceMissing,
            TargetActivationBlocker::CapacityEvidenceMissing,
            TargetActivationBlocker::ExecutorEvidenceMissing,
        ], $assessment->blockers);
        try {
            $target->enable($this->evidence(false, $now), $now);
            self::fail('Blocked target was enabled.');
        } catch (TargetActivationFailed $failure) {
            self::assertContains(TargetActivationBlocker::CandidateRejected, $failure->blockers);
            self::assertContains(TargetActivationBlocker::ExecutorUnauthorized, $failure->blockers);
        }
        $unexpected = new BackupTarget(new BackupTargetId(str_repeat('i', 16)), new TargetRevision(1),
            TargetStatus::Disabled, false, new MinimumFreeBytes('1'), new AllowedNodes([str_repeat('n', 16)]),
            new ConcurrencyPolicy(1), new PbsTargetMapping(str_repeat('c', 16), str_repeat('d', 16), null));
        self::assertSame([TargetActivationBlocker::PbsMappingUnexpected],
            $unexpected->assessActivation($this->evidence(true, $now), $now)->blockers);
        $this->expectException(DomainException::class);
        $target->disable();
    }

    public function testTargetPrimitivesRejectInvalidValuesAndNormalizeNodes(): void
    {
        self::assertSame([str_repeat('a', 16), str_repeat('b', 16)],
            (new AllowedNodes([str_repeat('b', 16), str_repeat('a', 16), str_repeat('b', 16)]))->ids);
        foreach ([
            static fn () => new AllowedNodes(['bad']),
            static fn () => new ConcurrencyPolicy(0),
            static fn () => new ConcurrencyPolicy(101),
            static fn () => new PbsTargetMapping('bad', str_repeat('d', 16), null),
            static fn () => new PbsTargetMapping(str_repeat('c', 16), str_repeat('d', 16), 'bad'),
        ] as $invalid) {
            try { $invalid(); self::fail('Invalid target value accepted.'); } catch (InvalidArgumentException) { self::addToAssertionCount(1); }
        }
    }

    public function testEvidenceFreshnessBoundariesAreClosed(): void
    {
        $now = new DateTimeImmutable('2026-07-12T12:00:00Z');
        self::assertSame(\App\Domain\Target\EvidenceObservationFreshness::Fresh,
            (new ActivationEvidenceObservation(true, new DateTimeImmutable('2026-07-12T11:55:00Z')))->freshness($now));
        self::assertSame(\App\Domain\Target\EvidenceObservationFreshness::Fresh,
            (new ActivationEvidenceObservation(true, new DateTimeImmutable('2026-07-12T11:55:00.000000Z')))->freshness(new DateTimeImmutable('2026-07-12T12:00:00.000000Z')));
        self::assertSame(\App\Domain\Target\EvidenceObservationFreshness::Stale,
            (new ActivationEvidenceObservation(true, new DateTimeImmutable('2026-07-12T11:54:59.999999Z')))->freshness(new DateTimeImmutable('2026-07-12T12:00:00.000000Z')));
        self::assertSame(\App\Domain\Target\EvidenceObservationFreshness::Stale,
            (new ActivationEvidenceObservation(true, new DateTimeImmutable('2026-07-12T11:54:59Z')))->freshness($now));
        self::assertSame(\App\Domain\Target\EvidenceObservationFreshness::Future,
            (new ActivationEvidenceObservation(true, new DateTimeImmutable('2026-07-12T12:00:01Z')))->freshness($now));
        self::assertSame(\App\Domain\Target\EvidenceObservationFreshness::Missing,
            (new ActivationEvidenceObservation(null, $now))->freshness($now));
        self::assertSame(\App\Domain\Target\EvidenceObservationFreshness::Missing,
            (new ActivationEvidenceObservation(true, null))->freshness($now));
        self::assertSame(\App\Domain\Target\EvidenceObservationFreshness::Stale,
            (new ActivationEvidenceObservation(true, new DateTimeImmutable('2026-07-12T11:57:59.999999Z')))->freshness($now, 120));
        foreach ([0, 86401] as $invalidWindow) {
            try {
                (new ActivationEvidenceObservation(true, $now))->freshness($now, $invalidWindow);
                self::fail('Invalid freshness window accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $target = $this->complete();
        foreach (['candidate', 'inventory', 'capacity', 'executor'] as $field) {
            foreach ([new DateTimeImmutable('2026-07-12T11:54:59Z'), new DateTimeImmutable('2026-07-12T12:00:01Z')] as $observed) {
                $fresh = new ActivationEvidenceObservation(true, $now);
                $values = ['candidate' => $fresh, 'inventory' => $fresh, 'capacity' => $fresh, 'executor' => $fresh];
                $values[$field] = new ActivationEvidenceObservation(true, $observed);
                $assessment = $target->assessActivation(new TargetActivationEvidence($values['candidate'], $values['inventory'], $values['capacity'], $values['executor']), $now);
                self::assertFalse($assessment->canEnable());
            }
        }
    }

    private function complete(): BackupTarget
    {
        return new BackupTarget(new BackupTargetId(str_repeat('i', 16)), new TargetRevision(1), TargetStatus::Disabled,
            false, new MinimumFreeBytes('1'), new AllowedNodes([str_repeat('n', 16)]), new ConcurrencyPolicy(2), null);
    }

    private function evidence(?bool $accepted, ?DateTimeImmutable $observed): TargetActivationEvidence
    {
        $observation = new ActivationEvidenceObservation($accepted, $observed);
        return new TargetActivationEvidence($observation, $observation, $observation, $observation);
    }
}
