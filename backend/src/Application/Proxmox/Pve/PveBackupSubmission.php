<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use InvalidArgumentException;

final readonly class PveBackupSubmission
{
    public function __construct(
        public string $node,
        public int $vmid,
        public PveGuestType $guestType,
        public string $storage,
        public PveBackupMode $mode,
        public PveBackupCompression $compression,
        public PveBackupFailureRecipients $failureNotificationRecipients,
        public ?PvePruneBackups $pruneBackups = null,
        public ?int $legacyMaxFiles = null,
    ) {
        if (!PveTaskNodeNameValidator::isValid($node)) {
            throw new InvalidArgumentException('The PVE backup node is invalid.');
        }
        if ($vmid < 100 || $vmid > 999_999_999) {
            throw new InvalidArgumentException('The PVE backup VMID is invalid.');
        }
        if (!PveStorageIdValidator::isValid($storage)) {
            throw new InvalidArgumentException('The PVE backup storage is invalid.');
        }
        if (null !== $legacyMaxFiles && ($legacyMaxFiles < 1 || $legacyMaxFiles > 1_000_000)) {
            throw new InvalidArgumentException('The legacy PVE maxfiles value is invalid.');
        }
        if (null !== $pruneBackups && [] === $pruneBackups->signature()) {
            throw new InvalidArgumentException('The PVE prune-backups value must not be empty.');
        }
        if (null !== $pruneBackups && null !== $legacyMaxFiles) {
            throw new InvalidArgumentException('PVE prune-backups and legacy maxfiles are mutually exclusive.');
        }
        foreach ($pruneBackups?->signature() ?? [] as $value) {
            if (is_int($value) && ($value < 1 || $value > 1_000_000)) {
                throw new InvalidArgumentException('The PVE prune-backups value is invalid.');
            }
        }
    }
}
