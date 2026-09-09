<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Policy;

use App\Domain\Policy\BackupDefaults;
use App\Domain\Policy\BackupMode;
use App\Domain\Policy\BackupPolicy;
use App\Domain\Policy\Compression;
use App\Domain\Policy\FailureNotificationRecipients;
use App\Domain\Policy\PolicyActivationBlocker;
use App\Domain\Policy\PolicyActivationFailed;
use App\Domain\Policy\PolicyId;
use App\Domain\Policy\PolicyPriority;
use App\Domain\Policy\PolicyResolver;
use App\Domain\Policy\PolicyRevision;
use App\Domain\Policy\PolicyStatus;
use App\Domain\Policy\PolicyThresholds;
use App\Domain\Policy\RetentionPolicy;
use App\Domain\Policy\Schedule;
use App\Domain\Target\BackupTargetId;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BackupPolicyTest extends TestCase
{
    public function testDraftsRemainIncompleteAndActivationReportsStableFailClosedBlockers(): void
    {
        $draft = BackupPolicy::draft(
            self::policyId(),
            new PolicyRevision(1),
            null,
            null,
            null,
            null,
            null,
            null,
            null,
        );

        $expected = [
            PolicyActivationBlocker::TargetUnconfigured,
            PolicyActivationBlocker::ModeUnconfigured,
            PolicyActivationBlocker::CompressionUnconfigured,
            PolicyActivationBlocker::RetentionUnconfigured,
            PolicyActivationBlocker::PriorityUnconfigured,
            PolicyActivationBlocker::ThresholdsUnconfigured,
            PolicyActivationBlocker::ScheduleUnconfigured,
            PolicyActivationBlocker::FailureNotificationRecipientsUnconfigured,
            PolicyActivationBlocker::UnsupportedPveMajor,
        ];
        self::assertSame($expected, $draft->activationBlockers(6));
        self::assertSame(
            array_slice($expected, 0, -1),
            $draft->activationBlockers(9),
        );

        try {
            $draft->activate(6);
            self::fail('Incomplete policy activation must fail.');
        } catch (PolicyActivationFailed $failure) {
            self::assertSame($expected, $failure->blockers);
            self::assertSame('The backup policy cannot be activated.', $failure->getMessage());
        }
    }

    public function testPveNineRejectsLegacyMaxfilesDuringActivation(): void
    {
        $draft = self::completeDraft(RetentionPolicy::legacyMaxFiles(3));

        self::assertSame(
            [PolicyActivationBlocker::RetentionIncompatible],
            $draft->activationBlockers(9),
        );
        $this->expectException(PolicyActivationFailed::class);
        $draft->activate(9);
    }

    public function testActivationRequiresAtLeastOnePveFailureMailRecipient(): void
    {
        $draft = BackupPolicy::draft(
            self::policyId(),
            new PolicyRevision(1),
            new BackupTargetId(str_repeat("\x02", 16)),
            BackupMode::Snapshot,
            Compression::Zstd,
            self::pruneRetention(),
            new PolicyPriority(500),
            new PolicyThresholds(3600, '1048576', 300),
            Schedule::CollectorCycle,
        );

        self::assertSame(
            [PolicyActivationBlocker::FailureNotificationRecipientsUnconfigured],
            $draft->activationBlockers(9),
        );
        $this->expectException(PolicyActivationFailed::class);
        $draft->activate(9);
    }

    public function testActivationAndDisableAreImmutableRevisionedTransitions(): void
    {
        $draft = self::completeDraft(self::pruneRetention());
        self::assertSame([], $draft->activationBlockers(9));
        self::assertSame(
            [PolicyActivationBlocker::UnsupportedPveMajor],
            $draft->activationBlockers(10),
        );

        $enabled = $draft->activate(9);
        self::assertSame(PolicyStatus::Draft, $draft->status);
        self::assertSame(1, $draft->revision->value);
        self::assertSame(PolicyStatus::Enabled, $enabled->status);
        self::assertSame(2, $enabled->revision->value);

        $disabled = $enabled->disable();
        self::assertSame(PolicyStatus::Disabled, $disabled->status);
        self::assertSame(3, $disabled->revision->value);
    }

    public function testRehydratePreservesStateAndDefaultsNotificationRecipients(): void
    {
        $policy = BackupPolicy::rehydrate(
            self::policyId(),
            new PolicyRevision(4),
            PolicyStatus::Disabled,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
        );

        self::assertSame(PolicyStatus::Disabled, $policy->status);
        self::assertSame(4, $policy->revision->value);
        self::assertSame([], $policy->failureNotificationRecipients->addresses);
    }

    public function testEnabledPolicyCannotBeActivatedAgain(): void
    {
        $enabled = self::completeDraft(self::pruneRetention())->activate(9);

        $this->expectException(DomainException::class);
        $enabled->activate(9);
    }

    public function testDraftCannotBeDisabled(): void
    {
        $this->expectException(DomainException::class);
        self::completeDraft(self::pruneRetention())->disable();
    }

    public function testResolverRequiresEnabledPolicy(): void
    {
        $this->expectException(DomainException::class);
        (new PolicyResolver())->resolve(
            self::completeDraft(self::pruneRetention()),
            9,
            null,
            null,
            null,
            false,
            true,
        );
    }

    public function testResolverKeepsDesiredRetentionSeparateFromDeletionApproval(): void
    {
        $enabled = self::completeDraft(self::pruneRetention())->activate(9);
        $resolved = (new PolicyResolver())->resolve($enabled, 9, null, null, null, false, true);

        self::assertSame(BackupMode::Snapshot, $resolved->mode);
        self::assertSame(Compression::Zstd, $resolved->compression);
        self::assertSame(self::pruneRetention()->signature(), $resolved->desiredRetention->signature());
        self::assertNull($resolved->approvedDeletionRetention);
        self::assertNull($resolved->snapshot()['approvedDeletionRetention']);
        self::assertSame(2, $resolved->snapshot()['version']);
        self::assertSame(['alerts@example.test', 'platform@example.test'], $resolved->snapshot()['failureNotificationRecipients']);
        self::assertSame(500, $resolved->snapshot()['policyPriority']);
        self::assertSame('collector_cycle', $resolved->snapshot()['schedule']);
        self::assertSame(64, strlen($resolved->snapshotHash()));
        self::assertSame($resolved->snapshotHash(), hash('sha256', $resolved->canonicalJson()));
    }

    public function testGuestOverridesAreExplicitAndCanReceiveSeparateDeletionApproval(): void
    {
        $enabled = self::completeDraft(self::pruneRetention())->activate(8);
        $guestRetention = RetentionPolicy::legacyMaxFiles(5);
        $resolved = (new PolicyResolver())->resolve(
            $enabled,
            8,
            BackupMode::Stop,
            Compression::Gzip,
            $guestRetention,
            true,
            true,
        );

        self::assertSame(BackupMode::Stop, $resolved->mode);
        self::assertSame(Compression::Gzip, $resolved->compression);
        self::assertSame(['maxfiles' => 5], $resolved->desiredRetention->signature());
        self::assertSame(['maxfiles' => 5], $resolved->approvedDeletionRetention?->signature());
        self::assertSame(['maxfiles' => 5], $resolved->snapshot()['approvedDeletionRetention']);
    }

    public function testPbsStorageTargetNeverReceivesApprovedDeletionRetention(): void
    {
        $enabled = self::completeDraft(self::pruneRetention())->activate(9);

        $resolved = (new PolicyResolver())->resolve($enabled, 9, null, null, null, true, false);

        self::assertSame(self::pruneRetention()->signature(), $resolved->desiredRetention->signature());
        self::assertNull($resolved->approvedDeletionRetention);
        self::assertNull($resolved->snapshot()['approvedDeletionRetention']);
    }

    public function testResolverNeverCarriesLegacyMaxfilesToPveNine(): void
    {
        $enabled = self::completeDraft(RetentionPolicy::legacyMaxFiles(2))->activate(7);

        $this->expectException(DomainException::class);
        (new PolicyResolver())->resolve($enabled, 9, null, null, null, false, true);
    }

    public function testGuestRetentionMustAlsoMatchThePveMajor(): void
    {
        $enabled = self::completeDraft(self::pruneRetention())->activate(9);

        $this->expectException(InvalidArgumentException::class);
        (new PolicyResolver())->resolve(
            $enabled,
            9,
            null,
            null,
            RetentionPolicy::legacyMaxFiles(2),
            false,
            true,
        );
    }

    public function testStorageDefaultsFillOnlyMissingPolicyValuesAndSurviveTransitions(): void
    {
        $defaults = new BackupDefaults(BackupMode::Stop, Compression::Gzip, self::pruneRetention());
        $policy = BackupPolicy::draft(
            self::policyId(), new PolicyRevision(1), new BackupTargetId(str_repeat("\x02", 16)),
            null, null, null, new PolicyPriority(500), new PolicyThresholds(3600, null, null),
            Schedule::CollectorCycle, new FailureNotificationRecipients(['alerts@example.test']), $defaults,
        );
        self::assertNull($policy->mode);
        self::assertNull($policy->compression);
        self::assertNull($policy->retention);
        self::assertSame([], $policy->configurationBlockers());
        $enabled = $policy->activate(9);
        self::assertSame($defaults, $enabled->targetDefaults);
        self::assertSame($defaults, $enabled->disable()->targetDefaults);
        $resolved = (new PolicyResolver())->resolve($enabled, 9, null, null, null, true, false);
        self::assertSame(BackupMode::Stop, $resolved->mode);
        self::assertSame(Compression::Gzip, $resolved->compression);
        self::assertSame($defaults->retention, $resolved->desiredRetention);
        self::assertNull($resolved->approvedDeletionRetention);
        $guest = (new PolicyResolver())->resolve($enabled, 9, BackupMode::Snapshot, Compression::Zstd,
            RetentionPolicy::prune(true, null, null, null, null, null, null), false, true);
        self::assertSame(BackupMode::Snapshot, $guest->mode);
        self::assertSame(Compression::Zstd, $guest->compression);
        self::assertTrue($guest->desiredRetention->keepAll);

        $override = BackupPolicy::rehydrate(self::policyId(), new PolicyRevision(2), PolicyStatus::Enabled,
            $policy->targetId, BackupMode::Suspend, Compression::Zstd, RetentionPolicy::legacyMaxFiles(7),
            $policy->priority, $policy->thresholds, $policy->schedule, $policy->failureNotificationRecipients, $defaults);
        $resolvedOverride = (new PolicyResolver())->resolve($override, 8, null, null, null, false, true);
        self::assertSame(BackupMode::Suspend, $resolvedOverride->mode);
        self::assertSame(Compression::Zstd, $resolvedOverride->compression);
        self::assertSame(7, $resolvedOverride->desiredRetention->legacyMaxFiles);
    }

    public function testInheritedLegacyRetentionStillBlocksPveNine(): void
    {
        $complete = self::completeDraft(self::pruneRetention());
        $policy = BackupPolicy::rehydrate($complete->id, $complete->revision, PolicyStatus::Draft,
            $complete->targetId, null, null, null, $complete->priority, $complete->thresholds,
            $complete->schedule, $complete->failureNotificationRecipients,
            new BackupDefaults(BackupMode::Snapshot, Compression::Zstd, RetentionPolicy::legacyMaxFiles(3)));
        self::assertSame([], $policy->activationBlockers(8));
        self::assertSame([PolicyActivationBlocker::RetentionIncompatible], $policy->activationBlockers(9));
    }

    private static function completeDraft(RetentionPolicy $retention): BackupPolicy
    {
        return BackupPolicy::draft(
            self::policyId(),
            new PolicyRevision(1),
            new BackupTargetId(str_repeat("\x02", 16)),
            BackupMode::Snapshot,
            Compression::Zstd,
            $retention,
            new PolicyPriority(500),
            new PolicyThresholds(3600, '1048576', 300),
            Schedule::CollectorCycle,
            new FailureNotificationRecipients(['platform@example.test', 'alerts@example.test']),
        );
    }

    private static function pruneRetention(): RetentionPolicy
    {
        return RetentionPolicy::prune(null, 3, null, null, null, null, null);
    }

    private static function policyId(): PolicyId
    {
        return new PolicyId(str_repeat("\x01", 16));
    }
}
