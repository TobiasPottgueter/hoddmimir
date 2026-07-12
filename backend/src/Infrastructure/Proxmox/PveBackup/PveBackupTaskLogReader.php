<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\PveBackup;

use App\Application\Proxmox\Pve\PveBackupApiFailure;
use App\Application\Proxmox\Pve\PveBackupApiFailureCode;
use App\Application\Proxmox\Pve\PveTaskLogEntry;
use App\Application\Proxmox\Pve\PveTaskLogPage;
use App\Application\Proxmox\Pve\PveTaskLogQuery;
use InvalidArgumentException;

final readonly class PveBackupTaskLogReader
{
    public function read(PveTaskLogQuery $query, mixed $data): PveTaskLogPage
    {
        if (!is_array($data) || !array_is_list($data) || count($data) > $query->limit) {
            throw PveBackupApiFailure::for(PveBackupApiFailureCode::InvalidResponse);
        }
        $entries = [];
        try {
            foreach ($data as $raw) {
                $row = $raw instanceof \stdClass ? get_object_vars($raw) : $raw;
                if (!is_array($row) || !is_int($row['n'] ?? null) || !is_string($row['t'] ?? null)) {
                    throw new InvalidArgumentException();
                }
                $entries[] = new PveTaskLogEntry($row['n'], $row['t']);
            }

            return new PveTaskLogPage($query, $entries);
        } catch (InvalidArgumentException) {
            throw PveBackupApiFailure::for(PveBackupApiFailureCode::InvalidResponse);
        }
    }
}
