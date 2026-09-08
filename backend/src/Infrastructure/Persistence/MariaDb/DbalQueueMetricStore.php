<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Backup\Metrics\QueueMetricStore;
use App\Application\Backup\Metrics\QueueMetricWindow;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class DbalQueueMetricStore implements QueueMetricStore
{
    public function __construct(private Connection $connection) {}

    public function sample(DateTimeImmutable $at, DateTimeImmutable $retainSince): void
    {
        $this->connection->transactional(function () use ($at, $retainSince): void {
            $observed = $this->sqlTime($at);
            $inserted = $this->connection->executeStatement('INSERT IGNORE INTO queue_metric_ticks (observed_at) VALUES (:at)', ['at' => $observed]);
            if (0 === $inserted) return;
            $this->connection->executeStatement(<<<'SQL'
INSERT INTO queue_metric_samples (scope_id, observed_at, waiting_count, active_count, unresolved_count, oldest_wait_seconds)
SELECT scopes.id, :at, COALESCE(counts.waiting,0), COALESCE(counts.active,0), COALESCE(counts.unresolved,0), COALESCE(counts.oldest,0)
FROM (SELECT UNHEX(REPEAT('00',16)) AS id UNION ALL SELECT id FROM backup_targets) scopes
LEFT JOIN (
    SELECT target_id,
        SUM(state IN ('pending','retry_wait')) AS waiting,
        SUM(state IN ('leased','starting','running')) AS active,
        SUM(state = 'reconcile_required') AS unresolved,
        MAX(CASE WHEN state IN ('pending','retry_wait') THEN GREATEST(0,TIMESTAMPDIFF(SECOND,scheduled_at,:at)) ELSE 0 END) AS oldest
    FROM backup_requests
    WHERE state IN ('pending','retry_wait','leased','starting','running','reconcile_required')
    GROUP BY target_id WITH ROLLUP
) counts ON scopes.id=COALESCE(counts.target_id,UNHEX(REPEAT('00',16)))
SQL, ['at' => $observed]);
            $this->connection->executeStatement('DELETE FROM queue_metric_ticks WHERE observed_at < :cutoff', ['cutoff' => $this->sqlTime($retainSince)]);
        });
    }

    public function history(QueueMetricWindow $window, ?string $targetId): array
    {
        $scope = null === $targetId ? str_repeat("\0", 16) : (new ReadModelIdentifier($targetId))->binary();
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT FLOOR(UNIX_TIMESTAMP(observed_at)/:width)*:width AS bucket,
    COUNT(*) AS samples, AVG(waiting_count) AS waiting_average, MAX(waiting_count) AS waiting_peak,
    MAX(active_count) AS active_peak, MAX(unresolved_count) AS unresolved_peak,
    MAX(oldest_wait_seconds) AS oldest_wait_seconds
FROM queue_metric_samples
WHERE scope_id=:scope AND observed_at >= :since AND observed_at < :until
GROUP BY bucket ORDER BY bucket LIMIT 721
SQL, ['width' => $window->bucketSeconds, 'scope' => $scope, 'since' => $this->sqlTime($window->since), 'until' => $this->sqlTime($window->until)], ['scope' => ParameterType::BINARY]);
        return array_map(static function (array $row): array {
            /** @var array<string, int|string> $row */
            return [
                'observedAt' => (new DateTimeImmutable('@'.(int) $row['bucket']))->format('Y-m-d\TH:i:s\Z'),
                'samples' => (int) $row['samples'],
                'waitingAverage' => (float) $row['waiting_average'],
                'waitingPeak' => (int) $row['waiting_peak'],
                'activePeak' => (int) $row['active_peak'],
                'unresolvedPeak' => (int) $row['unresolved_peak'],
                'oldestWaitSeconds' => (int) $row['oldest_wait_seconds'],
            ];
        }, $rows);
    }

    private function sqlTime(DateTimeImmutable $at): string { return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'); }
}
