<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

enum PbsMountStatus: string
{
    case Mounted = 'mounted';
    case NotMounted = 'notmounted';
    case NonRemovable = 'nonremovable';

    public function isAvailable(): bool
    {
        return self::NotMounted !== $this;
    }
}
