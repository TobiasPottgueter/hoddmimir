<?php

declare(strict_types=1);

namespace App\Infrastructure\Maintenance;

use App\Application\Maintenance\MaintenancePermit;

final class FileMaintenancePermit implements MaintenancePermit
{
    /** @param resource $handle */
    public function __construct(private $handle) {}

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }

    public function __destruct() { $this->release(); }
}
