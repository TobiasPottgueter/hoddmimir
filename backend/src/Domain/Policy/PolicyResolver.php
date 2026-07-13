<?php

declare(strict_types=1);

namespace App\Domain\Policy;

use DomainException;
use InvalidArgumentException;

final readonly class PolicyResolver
{
    public function resolve(
        BackupPolicy $policy,
        int $pveMajor,
        ?BackupMode $guestMode,
        ?Compression $guestCompression,
        ?RetentionPolicy $guestRetention,
        bool $deletionEffectApproved,
    ): ResolvedBackupPolicy {
        if (!$policy->status->executable()) {
            throw new DomainException('Only enabled backup policies can be resolved.');
        }
        if ([] !== $policy->activationBlockers($pveMajor)) {
            throw new DomainException('An enabled backup policy is not valid for the requested PVE major.');
        }

        $retention = $guestRetention ?? $policy->retention;
        /** @var \App\Domain\Target\BackupTargetId $targetId */
        $targetId = $policy->targetId;
        /** @var BackupMode $mode */
        $mode = $policy->mode;
        /** @var Compression $compression */
        $compression = $policy->compression;
        /** @var RetentionPolicy $retention */
        /** @var PolicyPriority $priority */
        $priority = $policy->priority;
        /** @var PolicyThresholds $thresholds */
        $thresholds = $policy->thresholds;
        /** @var Schedule $schedule */
        $schedule = $policy->schedule;
        if (!$retention->supportsPveMajor($pveMajor)) {
            throw new InvalidArgumentException('The resolved retention is incompatible with the PVE major.');
        }

        return new ResolvedBackupPolicy(
            $policy->id,
            $policy->revision,
            $targetId,
            $pveMajor,
            $guestMode ?? $mode,
            $guestCompression ?? $compression,
            $retention,
            $deletionEffectApproved ? $retention : null,
            $priority,
            $thresholds,
            $schedule,
            $policy->failureNotificationRecipients,
        );
    }
}
