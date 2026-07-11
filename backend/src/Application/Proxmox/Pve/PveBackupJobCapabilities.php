<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use InvalidArgumentException;

final readonly class PveBackupJobCapabilities
{
    private function __construct(
        public PveBackupJobResponseContract $responseContract,
        public bool $supportsLegacyMaxFiles,
        public PvePruneResponseShape $pruneResponseShape,
    ) {
    }

    public static function forMajor(int $major): self
    {
        return match ($major) {
            7, 8 => new self(PveBackupJobResponseContract::BaselineIdOnly, true, PvePruneResponseShape::LegacyStringOrObject),
            9 => new self(PveBackupJobResponseContract::SelectedTypedFields, false, PvePruneResponseShape::Object),
            default => throw new InvalidArgumentException('Unsupported PVE major version.'),
        };
    }
}
