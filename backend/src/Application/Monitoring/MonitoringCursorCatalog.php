<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use App\Application\Inventory\Connection\ConnectionId;
use DateTimeImmutable;

interface MonitoringCursorCatalog
{
    /** @param list<string> $scopeKeys */
    public function oldestCompletedUntil(
        ConnectionId $connectionId,
        MonitoringCursorKind $kind,
        array $scopeKeys,
    ): ?DateTimeImmutable;
}
