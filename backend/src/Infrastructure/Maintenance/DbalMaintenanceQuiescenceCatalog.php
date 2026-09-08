<?php

declare(strict_types=1);

namespace App\Infrastructure\Maintenance;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Maintenance\MaintenanceQuiescenceCatalog;
use App\Infrastructure\Persistence\MariaDb\DbalConnectionScanCatalog;
use Doctrine\DBAL\Connection;

final readonly class DbalMaintenanceQuiescenceCatalog implements MaintenanceQuiescenceCatalog
{
    public function __construct(private Connection $connection) {}

    public function targets(): array
    {
        return (new DbalConnectionScanCatalog($this->connection, includeDisabled: true))->enabledTargets();
    }

    public function submissionNodes(ConnectionId $connection): array
    {
        $values = $this->connection->fetchFirstColumn(<<<'SQL'
SELECT DISTINCT run.submission_node
FROM backup_runs run JOIN backup_requests request ON request.id=run.request_id
WHERE request.connection_id=:connection AND run.submission_node IS NOT NULL
  AND request.state IN ('starting','running','reconcile_required')
ORDER BY run.submission_node
SQL, ['connection' => $connection->bytes]);
        $nodes = [];
        foreach ($values as $value) {
            if (!is_string($value)) throw new \RuntimeException('Invalid maintenance submission node.');
            $nodes[] = $value;
        }
        return $nodes;
    }

    public function hasUnsettledLocalWork(): bool
    {
        $count = $this->connection->fetchOne(<<<'SQL'
SELECT COUNT(*) FROM backup_requests request
LEFT JOIN backup_runs run ON run.id=request.run_id
WHERE request.state IN ('starting','running')
   OR (request.state='reconcile_required' AND
       (run.recovery_outcome IS NULL OR run.recovery_outcome NOT IN ('proven_not_started','multiple_matches')))
SQL);
        if (!is_int($count) && !is_string($count)) throw new \RuntimeException('Invalid maintenance work count.');
        return 0 !== (int) $count;
    }
}
