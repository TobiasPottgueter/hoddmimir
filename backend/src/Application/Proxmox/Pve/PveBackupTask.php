<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class PveBackupTask
{
    public function __construct(
        public PveUpid $upid,
        public PveTaskSource $source,
        public ?int $endTime,
        public ?string $listStatus,
    ) {
    }

    /** @return array{string, ?int, ?string} */
    public function signature(): array
    {
        return [$this->upid->raw, $this->endTime, $this->listStatus];
    }
}
