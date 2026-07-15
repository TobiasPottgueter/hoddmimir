<?php

declare(strict_types=1);

namespace App\Domain\Policy;

use App\Domain\Target\BackupTargetId;
use DomainException;

final readonly class BackupPolicy
{
    private function __construct(
        public PolicyId $id,
        public PolicyRevision $revision,
        public PolicyStatus $status,
        public ?BackupTargetId $targetId,
        public ?BackupMode $mode,
        public ?Compression $compression,
        public ?RetentionPolicy $retention,
        public ?PolicyPriority $priority,
        public ?PolicyThresholds $thresholds,
        public ?Schedule $schedule,
        public FailureNotificationRecipients $failureNotificationRecipients,
    ) {
    }

    public static function draft(
        PolicyId $id,
        PolicyRevision $revision,
        ?BackupTargetId $targetId,
        ?BackupMode $mode,
        ?Compression $compression,
        ?RetentionPolicy $retention,
        ?PolicyPriority $priority,
        ?PolicyThresholds $thresholds,
        ?Schedule $schedule,
        ?FailureNotificationRecipients $failureNotificationRecipients = null,
    ): self {
        return new self(
            $id,
            $revision,
            PolicyStatus::Draft,
            $targetId,
            $mode,
            $compression,
            $retention,
            $priority,
            $thresholds,
            $schedule,
            $failureNotificationRecipients ?? new FailureNotificationRecipients([]),
        );
    }

    public static function rehydrate(
        PolicyId $id,
        PolicyRevision $revision,
        PolicyStatus $status,
        ?BackupTargetId $targetId,
        ?BackupMode $mode,
        ?Compression $compression,
        ?RetentionPolicy $retention,
        ?PolicyPriority $priority,
        ?PolicyThresholds $thresholds,
        ?Schedule $schedule,
        ?FailureNotificationRecipients $failureNotificationRecipients = null,
    ): self {
        return new self($id, $revision, $status, $targetId, $mode, $compression, $retention, $priority, $thresholds, $schedule, $failureNotificationRecipients ?? new FailureNotificationRecipients([]));
    }

    public function activate(int $pveMajor): self
    {
        if ($this->status->executable()) {
            throw new DomainException('An enabled backup policy cannot be activated again.');
        }

        $blockers = $this->activationBlockers($pveMajor);
        if ([] !== $blockers) {
            throw new PolicyActivationFailed($blockers);
        }

        return new self(
            $this->id,
            $this->revision->next(),
            PolicyStatus::Enabled,
            $this->targetId,
            $this->mode,
            $this->compression,
            $this->retention,
            $this->priority,
            $this->thresholds,
            $this->schedule,
            $this->failureNotificationRecipients,
        );
    }

    public function disable(): self
    {
        if (!$this->status->executable()) {
            throw new DomainException('Only an enabled backup policy can be disabled.');
        }

        return new self(
            $this->id,
            $this->revision->next(),
            PolicyStatus::Disabled,
            $this->targetId,
            $this->mode,
            $this->compression,
            $this->retention,
            $this->priority,
            $this->thresholds,
            $this->schedule,
            $this->failureNotificationRecipients,
        );
    }

    /** @return list<PolicyActivationBlocker> */
    public function configurationBlockers(): array
    {
        $blockers = [];
        if (null === $this->targetId) {
            $blockers[] = PolicyActivationBlocker::TargetUnconfigured;
        }
        if (null === $this->mode) {
            $blockers[] = PolicyActivationBlocker::ModeUnconfigured;
        }
        if (null === $this->compression) {
            $blockers[] = PolicyActivationBlocker::CompressionUnconfigured;
        }
        if (null === $this->retention) {
            $blockers[] = PolicyActivationBlocker::RetentionUnconfigured;
        }
        if (null === $this->priority) {
            $blockers[] = PolicyActivationBlocker::PriorityUnconfigured;
        }
        if (null === $this->thresholds) {
            $blockers[] = PolicyActivationBlocker::ThresholdsUnconfigured;
        }
        if (null === $this->schedule) {
            $blockers[] = PolicyActivationBlocker::ScheduleUnconfigured;
        }

        return $blockers;
    }

    /** @return list<PolicyActivationBlocker> */
    public function activationBlockers(int $pveMajor): array
    {
        $blockers = $this->configurationBlockers();
        if ($pveMajor < 7 || $pveMajor > 9) {
            $blockers[] = PolicyActivationBlocker::UnsupportedPveMajor;
        } elseif (null !== $this->retention && !$this->retention->supportsPveMajor($pveMajor)) {
            $blockers[] = PolicyActivationBlocker::RetentionIncompatible;
        }

        return $blockers;
    }
}
