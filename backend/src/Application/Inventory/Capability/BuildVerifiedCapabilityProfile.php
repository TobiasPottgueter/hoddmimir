<?php

declare(strict_types=1);

namespace App\Application\Inventory\Capability;

use App\Application\Inventory\Connection\ConnectionInstallationRead;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Proxmox\Pbs\PbsInstallationSnapshot;
use App\Application\Proxmox\Pve\PveBackupJobCapabilities;
use App\Application\Proxmox\Pve\PveInstallationSnapshot;
use App\Application\Proxmox\Pve\PveInventorySnapshot;
use InvalidArgumentException;

final readonly class BuildVerifiedCapabilityProfile
{
    public function build(ConnectionInstallationRead $read): CapabilityProfile
    {
        $snapshot = $read->snapshot;
        if ($snapshot instanceof PbsInstallationSnapshot) {
            $version = $snapshot->version;
            $versionContract = match ($version->major) {
                3 => 'pbs_3',
                4 => 'pbs_4',
                default => throw new InvalidArgumentException('The PBS capability version is unsupported.'),
            };
            return new CapabilityProfile(
                ProxmoxProduct::Pbs,
                $version->major,
                $version->minor,
                $version->patch,
                $version->release,
                $version->version,
                [
                    'instanceIdentitySupported' => $version->supportsInstanceIdentity(),
                    'versionContract' => $versionContract,
                ],
            );
        }

        $core = $snapshot instanceof PveInventorySnapshot ? $snapshot->core : $snapshot;
        $version = $core->version;
        $capabilities = PveBackupJobCapabilities::forMajor($version->major);
        return new CapabilityProfile(
            ProxmoxProduct::Pve,
            $version->major,
            $version->minor,
            $version->patch,
            $version->release,
            $version->version,
            [
                'backupJobResponseContract' => $capabilities->responseContract->value,
                'legacyMaxFilesSupported' => $capabilities->supportsLegacyMaxFiles,
                'pruneResponseShape' => $capabilities->pruneResponseShape->value,
            ],
        );
    }
}
