<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use App\Application\Monitoring\PbsTaskReadModel;
use Doctrine\DBAL\Connection;

final readonly class DbalPbsTaskReadModel implements PbsTaskReadModel
{
    public function __construct(private Connection $connection) {}

    public function tasks(?ReadModelIdentifier $connectionId, int $offset): array
    {
        $where = null === $connectionId ? '' : ' WHERE t.connection_id = :connection';
        $params = null === $connectionId ? [] : ['connection' => $connectionId->binary()];
        $rows = $this->connection->fetchAllAssociative(
            'SELECT t.id, t.connection_id, t.worker_type, t.worker_id, t.lifecycle, t.remote_status, t.started_at, t.last_seen_at, t.inspected_at FROM pbs_observed_tasks t'
            .$where.' ORDER BY t.started_at DESC, t.id LIMIT 50 OFFSET '.max(0, $offset), $params,
        );
        /** @var int|string $total */
        $total = $this->connection->fetchOne('SELECT COUNT(*) FROM pbs_observed_tasks t'.$where, $params);
        return ['items' => array_map($this->summary(...), $rows), 'total' => (int) $total];
    }

    public function detail(ReadModelIdentifier $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM pbs_observed_tasks WHERE id = :id', ['id' => $id->binary()]);
        if (false === $row) return null;
        /** @var string|null $json */
        $json = $row['inspection_json'];
        return $this->summary($row) + [
            'upid' => $row['upid_raw'],
            'inspection' => null === $json ? null : json_decode($json, true, 32, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function summary(array $row): array
    {
        /** @var array<string, string|null> $row */
        return [
            'id' => $this->uuid((string) $row['id']), 'connectionId' => $this->uuid((string) $row['connection_id']),
            'workerType' => $row['worker_type'], 'workerId' => $row['worker_id'],
            'lifecycle' => $row['lifecycle'], 'remoteStatus' => $row['remote_status'],
            'startedAt' => $this->time($row['started_at']), 'lastSeenAt' => $this->time($row['last_seen_at']),
            'inspectedAt' => $this->time($row['inspected_at']),
        ];
    }
    private function uuid(string $binary): string
    {
        $hex = bin2hex($binary);
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
    private function time(?string $value): ?string { return null === $value ? null : (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'); }
}
