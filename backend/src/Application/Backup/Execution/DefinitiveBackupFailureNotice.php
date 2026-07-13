<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

use App\Application\Proxmox\Pve\PveBackupApiFailureCode;
use App\Application\Proxmox\Pve\PveGuestType;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DefinitiveBackupFailureNotice
{
    public function __construct(
        public int $attempt,
        public string $guestId,
        public string $guestName,
        public int $vmid,
        public PveGuestType $guestType,
        public string $node,
        public string $targetId,
        public string $targetLabel,
        public PveBackupApiFailureCode $failureCode,
        public DateTimeImmutable $failedAt,
        public DateTimeImmutable $nextRetryAt,
    ) {
        if ($attempt < 1) {
            throw new InvalidArgumentException('Definitive backup failure notice is invalid.');
        }
        if (16 !== \strlen($guestId)) {
            throw new InvalidArgumentException('Definitive backup failure notice is invalid.');
        }
        if (16 !== \strlen($targetId)) {
            throw new InvalidArgumentException('Definitive backup failure notice is invalid.');
        }
        if (0 !== $failedAt->getOffset()) {
            throw new InvalidArgumentException('Definitive backup failure notice is invalid.');
        }
        if (0 !== $nextRetryAt->getOffset()) {
            throw new InvalidArgumentException('Definitive backup failure notice is invalid.');
        }
        if ($nextRetryAt <= $failedAt) {
            throw new InvalidArgumentException('Definitive backup failure notice is invalid.');
        }
        if ('' === \trim($node)) {
            throw new InvalidArgumentException('Definitive backup failure notice is invalid.');
        }
        if ('' === \trim($targetLabel)) {
            throw new InvalidArgumentException('Definitive backup failure notice is invalid.');
        }
        if ('' === \trim($guestName) || \mb_strlen($guestName) > 190) {
            throw new InvalidArgumentException('Definitive backup failure notice is invalid.');
        }
    }
}
