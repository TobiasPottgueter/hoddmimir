<?php

declare(strict_types=1);

namespace App\Application\Maintenance;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionScanTarget;

interface MaintenanceQuiescenceCatalog
{
    /** Include disabled connections: disabled scheduling is not remote quiescence.
     * @return list<ConnectionScanTarget>
     */
    public function targets(): array;
    /** @return list<string> */
    public function submissionNodes(ConnectionId $connection): array;
    /** @phpstan-impure */
    public function hasUnsettledLocalWork(): bool;
}
