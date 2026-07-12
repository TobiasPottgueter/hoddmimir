<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Scheduler;

use App\Domain\Scheduler\AutomaticReasonInputs;
use App\Domain\Scheduler\BackupReason;
use App\Domain\Scheduler\ByteReasonEvidence;
use App\Domain\Scheduler\ReasonSelector;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReasonSelectorTest extends TestCase
{
    private const string BOUNDARY = '2026-07-12T10:00:00.000000+00:00';

    public function testNoSuccessfulRunAlwaysMeansNeverBackedUpAndNormalizesUtc(): void
    {
        $inputs = AutomaticReasonInputs::withoutSuccessfulBackup(
            new DateTimeImmutable('2026-07-12T12:00:00.000000+02:00'),
        );

        self::assertSame('+00:00', $inputs->now->format('P'));
        self::assertSame(BackupReason::NeverBackedUp, (new ReasonSelector())->select($inputs));
    }

    public function testMaximumAgeIsStrictAndWinsOverTheByteReason(): void
    {
        $selector = new ReasonSelector();
        $atBoundary = $this->backedUp(self::BOUNDARY, '2026-07-12T09:00:00.000000Z', 101, 0, 100);
        $aboveBoundary = $this->backedUp('2026-07-12T10:00:00.000001Z', '2026-07-12T09:00:00.000000Z', 101, 0, 100);

        self::assertSame(BackupReason::BytesWritten, $selector->select($atBoundary));
        self::assertSame(BackupReason::MaxAge, $selector->select($aboveBoundary));
    }

    public function testCooldownAndBytesMustBothBeStrictlyAboveTheirBoundaries(): void
    {
        $selector = new ReasonSelector();
        $maxAge = '2026-07-13T10:00:00.000000Z';

        self::assertNull($selector->select($this->backedUp(
            self::BOUNDARY,
            self::BOUNDARY,
            101,
            0,
            100,
            $maxAge,
        )));
        self::assertNull($selector->select($this->backedUp(
            '2026-07-12T10:00:00.000001Z',
            self::BOUNDARY,
            100,
            0,
            100,
            $maxAge,
        )));
        self::assertSame(BackupReason::BytesWritten, $selector->select($this->backedUp(
            '2026-07-12T10:00:00.000001Z',
            self::BOUNDARY,
            101,
            0,
            100,
            $maxAge,
        )));
    }

    public function testCompleteBackedUpInputsAreNormalizedToUtc(): void
    {
        $byteEvidence = ByteReasonEvidence::fromCounters(
            new DateTimeImmutable('2026-07-12T11:00:00+02:00'),
            20,
            10,
            5,
        );
        self::assertNotNull($byteEvidence);
        $inputs = AutomaticReasonInputs::withSuccessfulBackup(
            new DateTimeImmutable('2026-07-12T12:00:00+02:00'),
            new DateTimeImmutable('2026-07-11T12:00:00+02:00'),
            new DateTimeImmutable('2026-07-13T12:00:00+02:00'),
            $byteEvidence,
        );

        foreach ([$inputs->now, $inputs->lastSuccessAt, $inputs->maximumAgeBoundary] as $instant) {
            self::assertNotNull($instant);
            self::assertSame('+00:00', $instant->format('P'));
        }
        self::assertSame($byteEvidence, $inputs->byteReasonEvidence);
        self::assertSame('+00:00', $byteEvidence->cooldownBoundary->format('P'));
        self::assertSame([20, 10, 5], [
            $byteEvidence->currentBytes,
            $byteEvidence->baselineBytes,
            $byteEvidence->bytesThreshold,
        ]);
    }

    public function testMaximumAgeDoesNotDependOnAvailableOrMonotoneByteEvidence(): void
    {
        $selector = new ReasonSelector();
        $overdue = new DateTimeImmutable('2026-07-12T10:00:00.000001Z');
        $lastSuccess = new DateTimeImmutable('2026-07-01T00:00:00Z');
        $maximumAgeBoundary = new DateTimeImmutable(self::BOUNDARY);
        $cooldown = new DateTimeImmutable('2026-07-01T01:00:00Z');

        self::assertNull(ByteReasonEvidence::fromCounters($cooldown, 90, 100, 1));
        self::assertSame(BackupReason::MaxAge, $selector->select(
            AutomaticReasonInputs::withSuccessfulBackup(
                $overdue,
                $lastSuccess,
                $maximumAgeBoundary,
                null,
            ),
        ));
    }

    public function testMissingOrFallingByteEvidenceCannotProduceAByteReason(): void
    {
        $now = new DateTimeImmutable('2026-07-12T10:00:00.000001Z');
        $lastSuccess = new DateTimeImmutable('2026-07-01T00:00:00Z');
        $maximumAgeBoundary = new DateTimeImmutable('2026-07-13T10:00:00Z');
        $cooldown = new DateTimeImmutable(self::BOUNDARY);

        foreach ([null, ByteReasonEvidence::fromCounters($cooldown, 90, 100, 1)] as $evidence) {
            self::assertNull((new ReasonSelector())->select(
                AutomaticReasonInputs::withSuccessfulBackup(
                    $now,
                    $lastSuccess,
                    $maximumAgeBoundary,
                    $evidence,
                ),
            ));
        }
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function invalidInputs(): iterable
    {
        yield 'negative current bytes' => [-1, 0, 0];
        yield 'negative baseline bytes' => [0, -1, 0];
        yield 'negative threshold' => [0, 0, -1];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidByteInputsFailClosed(
        int $current,
        int $baseline,
        int $threshold,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be negative');

        ByteReasonEvidence::fromCounters(
            new DateTimeImmutable(self::BOUNDARY),
            $current,
            $baseline,
            $threshold,
        );
    }

    public function testMaximumAgeBoundaryCannotPrecedeLastSuccess(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maximum-age');

        AutomaticReasonInputs::withSuccessfulBackup(
            new DateTimeImmutable('2026-07-12T10:00:00Z'),
            new DateTimeImmutable('2026-07-11T10:00:00Z'),
            new DateTimeImmutable('2026-07-11T09:59:59Z'),
            null,
        );
    }

    private function backedUp(
        string $now,
        string $cooldownBoundary,
        int $current,
        int $baseline,
        int $threshold,
        string $maximumAgeBoundary = self::BOUNDARY,
    ): AutomaticReasonInputs {
        $byteEvidence = ByteReasonEvidence::fromCounters(
            new DateTimeImmutable($cooldownBoundary),
            $current,
            $baseline,
            $threshold,
        );
        self::assertNotNull($byteEvidence);

        return AutomaticReasonInputs::withSuccessfulBackup(
            new DateTimeImmutable($now),
            new DateTimeImmutable('2026-07-01T00:00:00Z'),
            new DateTimeImmutable($maximumAgeBoundary),
            $byteEvidence,
        );
    }
}
