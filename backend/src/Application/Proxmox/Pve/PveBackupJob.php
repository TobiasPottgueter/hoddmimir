<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class PveBackupJob
{
    /**
     * The PVE 7/8 API only promises `id`. PVE 9 fields represented here form
     * an explicit selected contract. The calendar expression remains raw and
     * is deliberately not presented as a locally validated schedule.
     */
    public function __construct(
        public string $id,
        public ?string $rawSchedule,
        public ?bool $enabled,
        public ?bool $repeatMissed,
        public ?string $comment,
        public ?int $nextRun,
        public ?string $node,
        public ?string $storage,
        public ?string $guestIds,
        public ?bool $allGuests,
        public ?string $mode,
        public ?string $compression,
        public ?int $legacyMaxFiles,
        public ?PvePruneBackups $pruneBackups,
    ) {
    }
}
