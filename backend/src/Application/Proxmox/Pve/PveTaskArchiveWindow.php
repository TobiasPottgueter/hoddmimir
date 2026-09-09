<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use DateTimeImmutable;
use InvalidArgumentException;

/** One immutable inclusive archive window shared by every node and page in a read. */
final readonly class PveTaskArchiveWindow
{
    public function __construct(
        public int $since,
        public int $until,
        public bool $historyGap = false,
    ) {
        if ($since < 0 || $until < $since
            || $until - $since > PveBackupInventoryLimits::MAXIMUM_ARCHIVE_WINDOW_SECONDS) {
            throw new InvalidArgumentException('The PVE task archive window is invalid.');
        }
    }

    public static function endingAt(DateTimeImmutable $now, int $windowSeconds): self
    {
        if ($windowSeconds < 1 || $windowSeconds > PveBackupInventoryLimits::MAXIMUM_ARCHIVE_WINDOW_SECONDS) {
            throw new InvalidArgumentException('The PVE task archive window width is invalid.');
        }

        $until = $now->getTimestamp();
        if ($until < 0) {
            throw new InvalidArgumentException('The PVE task archive window cannot precede the UNIX epoch.');
        }

        $since = $until > $windowSeconds ? $until - $windowSeconds : 0;

        return new self($since, $until, false);
    }

    public function widthSeconds(): int
    {
        return $this->until - $this->since;
    }
}
