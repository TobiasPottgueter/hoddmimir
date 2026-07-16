<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\ExecutorEvidence;

enum PveExecutorEvidenceRequest
{
    case BackupPermissions;
    case ScanAcl;

    /** @return list<string> */
    public function pathSegments(): array
    {
        if (self::BackupPermissions === $this) {
            return ['access', 'permissions'];
        }
        return ['access', 'acl'];
    }
}
