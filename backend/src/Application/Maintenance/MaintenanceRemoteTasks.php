<?php

declare(strict_types=1);

namespace App\Application\Maintenance;

use App\Application\Inventory\Connection\ConnectionScanTarget;

interface MaintenanceRemoteTasks
{
    /** @param list<string> $submissionNodes
     * @throws MaintenanceQuiescenceFailure
     */
    public function assertQuiet(ConnectionScanTarget $target, array $submissionNodes): void;
}
