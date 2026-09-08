<?php

declare(strict_types=1);

namespace App\Application\Backup\Notification;

use App\Application\Proxmox\Pve\PveGuestType;
use App\Domain\Backup\BackupProblemCode;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class BackupNotification
{
    public function __construct(
        public string $eventId,
        public BackupNotificationKind $kind,
        public ?int $attempt,
        public int $checkNumber,
        public string $guestName,
        public int $vmid,
        public PveGuestType $guestType,
        public string $node,
        public string $targetLabel,
        public BackupProblemCode $problemCode,
        public ?string $detailCode,
        public DateTimeImmutable $openedAt,
        public DateTimeImmutable $occurredAt,
        public ?DateTimeImmutable $nextRetryAt,
        public int $consecutiveFailures,
    ) {
        if (16 !== \strlen($eventId)
            || (null !== $attempt && $attempt < 1)
            || $checkNumber < 1
            || $vmid < 1
            || $vmid > 999_999_999
            || '' === \trim($guestName)
            || \mb_strlen($guestName) > 190
            || '' === \trim($node)
            || \mb_strlen($node) > 190
            || '' === \trim($targetLabel)
            || \mb_strlen($targetLabel) > 190
            || $consecutiveFailures < 1
            || 0 !== $openedAt->getOffset()
            || 0 !== $occurredAt->getOffset()
            || $openedAt > $occurredAt
            || (null !== $nextRetryAt && (0 !== $nextRetryAt->getOffset() || $nextRetryAt <= $occurredAt))
            || (null !== $detailCode && 1 !== \preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $detailCode))
            || (null === $attempt && !\in_array($problemCode, [
                BackupProblemCode::CapacityBlocked,
                BackupProblemCode::PermissionBlocked,
                BackupProblemCode::EvidenceStale,
                BackupProblemCode::PlacementChanged,
                BackupProblemCode::ConfigurationBlocked,
            ], true))
            || (BackupNotificationKind::Failure === $kind) !== (null !== $nextRetryAt)) {
            throw new InvalidArgumentException('The backup notification is invalid.');
        }
    }
}
