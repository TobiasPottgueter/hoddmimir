<?php

declare(strict_types=1);

namespace App\Domain\Policy;

use App\Domain\Target\BackupTargetId;
use JsonException;

final readonly class ResolvedBackupPolicy
{
    public const int SNAPSHOT_VERSION = 2;

    public FailureNotificationRecipients $failureNotificationRecipients;

    public function __construct(
        public PolicyId $policyId,
        public PolicyRevision $policyRevision,
        public BackupTargetId $targetId,
        public int $pveMajor,
        public BackupMode $mode,
        public Compression $compression,
        public RetentionPolicy $desiredRetention,
        public ?RetentionPolicy $approvedDeletionRetention,
        public PolicyPriority $priority,
        public PolicyThresholds $thresholds,
        public Schedule $schedule,
        ?FailureNotificationRecipients $failureNotificationRecipients = null,
    ) {
        $this->failureNotificationRecipients = $failureNotificationRecipients ?? new FailureNotificationRecipients([]);
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'version' => self::SNAPSHOT_VERSION,
            'policyId' => bin2hex($this->policyId->binary()),
            'policyRevision' => $this->policyRevision->value,
            'targetId' => $this->targetId->toHex(),
            'pveMajor' => $this->pveMajor,
            'mode' => $this->mode->value,
            'compression' => $this->compression->value,
            'desiredRetention' => $this->desiredRetention->signature(),
            'approvedDeletionRetention' => $this->approvedDeletionRetention?->signature(),
            'policyPriority' => $this->priority->value,
            'thresholds' => $this->thresholds->snapshot(),
            'schedule' => $this->schedule->value,
            'failureNotificationRecipients' => $this->failureNotificationRecipients->addresses,
        ];
    }

    /** @throws JsonException */
    public function canonicalJson(): string
    {
        return json_encode($this->snapshot(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @throws JsonException */
    public function snapshotHash(): string
    {
        return hash('sha256', $this->canonicalJson());
    }
}
