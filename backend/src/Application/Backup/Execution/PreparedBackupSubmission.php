<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

use App\Application\Proxmox\Pve\PveBackupSubmission;
use InvalidArgumentException;

final readonly class PreparedBackupSubmission
{
    public function __construct(
        public PveBackupSubmission $payload,
        public int $attempt,
        public string $guestId,
        public string $targetId,
        public string $targetLabel,
        public string $guestName,
    ) {
        if ($attempt < 1) {
            throw new InvalidArgumentException('Prepared backup context is invalid.');
        }
        if (16 !== \strlen($guestId)) {
            throw new InvalidArgumentException('Prepared backup context is invalid.');
        }
        if (16 !== \strlen($targetId)) {
            throw new InvalidArgumentException('Prepared backup context is invalid.');
        }
        if ('' === \trim($targetLabel)) {
            throw new InvalidArgumentException('Prepared backup context is invalid.');
        }
        if (\mb_strlen($targetLabel) > 190) {
            throw new InvalidArgumentException('Prepared backup context is invalid.');
        }
        if ('' === \trim($guestName) || \mb_strlen($guestName) > 190) {
            throw new InvalidArgumentException('Prepared backup context is invalid.');
        }
    }
}
