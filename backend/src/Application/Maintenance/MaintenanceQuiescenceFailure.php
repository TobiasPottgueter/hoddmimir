<?php

declare(strict_types=1);

namespace App\Application\Maintenance;

final class MaintenanceQuiescenceFailure extends \RuntimeException
{
    public function __construct(public readonly bool $busy = false)
    {
        parent::__construct($busy ? 'Remote backups are active.' : 'Complete remote quiescence could not be established.');
    }
}
