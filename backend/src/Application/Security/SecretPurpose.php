<?php

declare(strict_types=1);

namespace App\Application\Security;

enum SecretPurpose: string
{
    case PveCollectorToken = 'pve_collector_token';
    case PbsCollectorToken = 'pbs_collector_token';
    case PveBackupToken = 'pve_backup_token';

    public function kdfSubkeyId(): int
    {
        if ($this === self::PveCollectorToken) {
            return 1;
        }

        if ($this === self::PbsCollectorToken) {
            return 2;
        }

        return 3;
    }
}
