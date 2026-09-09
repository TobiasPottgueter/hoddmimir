<?php

declare(strict_types=1);

namespace App\Infrastructure\Maintenance;

use App\Application\Maintenance\MaintenanceAccess;
use App\Application\Maintenance\MaintenanceActivity;
use App\Application\Maintenance\MaintenancePermit;
use App\Application\Maintenance\MaintenancePhase;
use App\Application\Maintenance\UnrestrictedMaintenanceAccess;

/** The directory is a read-only bind mount of host-owned, non-secret control files. */
final readonly class FileMaintenanceAccess implements MaintenanceAccess
{
    public function __construct(private string $directory) {}

    public function phase(): MaintenancePhase
    {
        if ('' === $this->directory) return MaintenancePhase::Open;
        clearstatcache(true);
        if (!is_dir($this->directory) || is_link($this->directory)
            || is_link($this->directory.'/state') || !is_file($this->directory.'/state')) {
            return MaintenancePhase::Frozen;
        }
        $state = @file_get_contents($this->directory.'/state', false, null, 0, 32);
        return match ($state) {
            "open\n" => MaintenancePhase::Open,
            "draining\n" => MaintenancePhase::Draining,
            default => MaintenancePhase::Frozen,
        };
    }

    public function acquire(MaintenanceActivity $activity): ?MaintenancePermit
    {
        if ('' === $this->directory) return new UnrestrictedMaintenanceAccess();
        clearstatcache(true);
        if (is_link($this->directory) || is_link($this->directory.'/operations.lock')) return null;
        $handle = @fopen($this->directory.'/operations.lock', 'rb');
        if (false === $handle) return null;
        if (!flock($handle, LOCK_SH | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        $permit = new FileMaintenancePermit($handle);
        if (!$this->phase()->allows($activity)) {
            $permit->release();
            return null;
        }
        return $permit;
    }
}
