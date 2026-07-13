<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

use App\Application\Proxmox\Pve\PveBackupSubmission;
use InvalidArgumentException;

final readonly class ExecutorAclProbeCommand
{
    public function __construct(
        public string $connectionId,
        public string $clusterId,
        public string $guestId,
        public string $targetId,
        public string $nodeId,
        public string $storageId,
        public PveBackupSubmission $submission,
    ) {
        foreach ([$connectionId, $clusterId, $guestId, $targetId, $nodeId, $storageId] as $id) {
            if (16 !== strlen($id)) {
                throw new InvalidArgumentException('Executor ACL evidence identifiers must contain 16 bytes.');
            }
        }
    }
}
