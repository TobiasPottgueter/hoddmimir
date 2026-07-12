<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

use InvalidArgumentException;

final readonly class PbsTaskWindow
{
    public function __construct(public int $since, public int $until)
    {
        if ($since < 0 || $until < $since) {
            throw new InvalidArgumentException('The PBS task history window is invalid.');
        }
    }
}
