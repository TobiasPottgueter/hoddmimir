<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Scheduler;

use App\Domain\Scheduler\BackupResourceEvidence;
use App\Domain\Scheduler\BackupStartRules;
use App\Domain\Scheduler\EvidenceFreshnessPolicy;
use App\Domain\Shared\UInt64Decimal;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BackupStartRulesTest extends TestCase
{
    public function testEveryMissingResourceBlocksTheSameSharedDecision(): void
    {
        $valid = [true, true, true, false, true, true, true, true, true, false, true, true, true, true, true, true, false, true, true];
        self::assertTrue(BackupStartRules::resourcesEligible(new BackupResourceEvidence(...$valid)));
        foreach (array_keys($valid) as $index) {
            $invalid = $valid; $invalid[$index] = !$invalid[$index];
            self::assertFalse(BackupStartRules::resourcesEligible(new BackupResourceEvidence(...$invalid)), 'Evidence field '.$index);
        }
        self::assertFalse(BackupStartRules::guestEligible(true, null));
        self::assertFalse(BackupStartRules::storageEnabled(true, null, true, true));
    }

    public function testPbsRequiresEveryPositiveEvidenceBit(): void
    {
        self::assertTrue(BackupStartRules::pbsTargetEligible(true, true, true, true, true, true));
        foreach (range(0, 5) as $offset) {
            $flags = array_fill(0, 6, true); $flags[$offset] = false;
            self::assertFalse(BackupStartRules::pbsTargetEligible(...$flags));
        }
    }

    public function testSnapshotsCannotChangeNodePlacementPolicyOrTarget(): void
    {
        self::assertTrue(BackupStartRules::snapshotMatches('node', 2, 3, 4, 'node', 2, 3, 4));
        self::assertFalse(BackupStartRules::snapshotMatches('node', 2, 3, 4, 'other', 2, 3, 4));
        self::assertFalse(BackupStartRules::snapshotMatches('node', 2, 3, 4, 'node', 3, 3, 4));
        self::assertFalse(BackupStartRules::snapshotMatches('node', 2, 3, 4, 'node', 2, 4, 4));
        self::assertFalse(BackupStartRules::snapshotMatches('node', 2, 3, 4, 'node', 2, 3, 5));
    }

    public function testNotificationValidationIsIdenticalAtEveryStage(): void
    {
        foreach ([null, [], ['named' => 'a@example.test'], [1], ['bad'], ['a@example.test', 'A@example.test'], array_fill(0, 33, 'a@example.test')] as $invalid) {
            self::assertFalse(BackupStartRules::notificationRecipientsConfigured($invalid));
        }
        self::assertTrue(BackupStartRules::notificationRecipientsConfigured(['ops@example.test', 'other@example.test']));
    }

    public function testCapacityUsesTheLimitingStorageAndNeverOverflows(): void
    {
        $zero = new UInt64Decimal('0'); $ten = new UInt64Decimal('10'); $twenty = new UInt64Decimal('20');
        self::assertSame($ten, BackupStartRules::effectiveCapacity($ten, false, null));
        self::assertNull(BackupStartRules::effectiveCapacity(null, true, $ten));
        self::assertNull(BackupStartRules::effectiveCapacity($ten, true, null));
        self::assertSame($ten, BackupStartRules::effectiveCapacity($twenty, true, $ten));
        self::assertSame($ten, BackupStartRules::effectiveCapacity($ten, true, $twenty));
        self::assertTrue(BackupStartRules::capacityAvailable($twenty, $ten, $zero, $ten));
        self::assertTrue(BackupStartRules::capacityAvailable($twenty, $ten, $ten, $zero));
        self::assertFalse(BackupStartRules::capacityAvailable($ten, $twenty, $zero, $zero));
        self::assertFalse(BackupStartRules::capacityAvailable($ten, $zero, $twenty, $zero));
        self::assertFalse(BackupStartRules::capacityAvailable($ten, $zero, $zero, $twenty));
        foreach (range(0, 3) as $offset) {
            $values = [$twenty, $zero, $zero, $zero]; $values[$offset] = null;
            self::assertFalse(BackupStartRules::capacityAvailable(...$values));
        }
        $maximum = new UInt64Decimal(UInt64Decimal::MAXIMUM);
        self::assertFalse(BackupStartRules::capacityAvailable($maximum, $maximum, $maximum, $maximum));
        self::assertTrue(BackupStartRules::capacityAvailable($maximum, new UInt64Decimal('18446744073709551600'), $ten, new UInt64Decimal('5')));
    }

    public function testSlotChecksDistinguishNewClaimsFromExistingReservations(): void
    {
        foreach ([[null, 0, 1], [1, null, 1], [0, 0, 0], [2, 0, 1], [1, -1, 1]] as [$limit, $used, $configured]) {
            self::assertFalse(BackupStartRules::slotAvailable($limit, $used, $configured, false));
        }
        self::assertTrue(BackupStartRules::slotAvailable(1, 0, 1, false));
        self::assertFalse(BackupStartRules::slotAvailable(1, 1, 1, false));
        self::assertFalse(BackupStartRules::slotAvailable(1, 0, 1, true));
        self::assertTrue(BackupStartRules::slotAvailable(1, 1, 1, true));
        self::assertFalse(BackupStartRules::slotAvailable(1, 2, 1, true));
    }

    public function testFreshnessIncludesTheBoundaryButRejectsMissingAndFutureTimes(): void
    {
        $now = new DateTimeImmutable('2026-09-08T12:00:00Z'); $policy = new EvidenceFreshnessPolicy(300);
        self::assertFalse($policy->isFresh($now, null));
        self::assertFalse($policy->isFresh($now, $now->modify('+1 microsecond')));
        self::assertFalse($policy->isFresh($now, $now->modify('-300 seconds -1 microsecond')));
        self::assertTrue($policy->isFresh($now, $now->modify('-300 seconds')));
        self::assertTrue($policy->isFresh($now, $now));
    }

    public function testUnsignedSubtractionHasExactBorrowAndZeroSemantics(): void
    {
        self::assertSame('9999999999999999999', (new UInt64Decimal('10000000000000000000'))->minus(new UInt64Decimal('1'))->value);
        self::assertSame('0', (new UInt64Decimal('20'))->minus(new UInt64Decimal('20'))->value);
        self::assertSame('18446744073709551615', (new UInt64Decimal(UInt64Decimal::MAXIMUM))->minus(new UInt64Decimal('0'))->value);
        $this->expectException(InvalidArgumentException::class);
        (new UInt64Decimal('0'))->minus(new UInt64Decimal('1'));
    }
}
