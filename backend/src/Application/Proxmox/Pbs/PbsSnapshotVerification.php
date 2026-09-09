<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

use InvalidArgumentException;

final readonly class PbsSnapshotVerification
{
    public function __construct(
        public string $state,
        public PbsUpid $upid,
    ) {
        if ('ok' !== $state && 'failed' !== $state) {
            throw new InvalidArgumentException('The PBS snapshot verification state is invalid.');
        }
    }
}
