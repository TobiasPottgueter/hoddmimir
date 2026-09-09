<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Monitoring\MonitoringCursorCatalog;
use App\Application\Monitoring\MonitoringCursorKind;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use RuntimeException;

final readonly class DbalMonitoringCursorCatalog implements MonitoringCursorCatalog
{
    public function __construct(private Connection $connection)
    {
    }

    public function oldestCompletedUntil(
        ConnectionId $connectionId,
        MonitoringCursorKind $kind,
        array $scopeKeys,
    ): ?DateTimeImmutable {
        $unique = [];
        foreach ($scopeKeys as $scopeKey) {
            if ('' === $scopeKey || strlen($scopeKey) > 255) {
                throw new \InvalidArgumentException('A monitoring cursor scope is invalid.');
            }
            $unique[$scopeKey] = true;
        }
        $scopeKeys = array_keys($unique);
        sort($scopeKeys, SORT_STRING);
        if ([] === $scopeKeys) {
            throw new \InvalidArgumentException('At least one monitoring cursor scope is required.');
        }
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT COUNT(*) AS cursor_count, MIN(completed_until) AS completed_until
                FROM proxmox_monitoring_cursors
                WHERE connection_id = :connection AND cursor_kind = :kind AND scope_key IN (:scopes)
                SQL,
            ['connection' => $connectionId->bytes, 'kind' => $kind->value, 'scopes' => $scopeKeys],
            ['scopes' => ArrayParameterType::STRING],
        );
        if (false === $row) {
            throw new RuntimeException('MariaDB did not return the monitoring cursor aggregate.');
        }
        $count = $row['cursor_count'] ?? null;
        if ((is_int($count) ? $count : (is_string($count) && ctype_digit($count) ? (int) $count : -1))
            !== count($scopeKeys)) {
            return null;
        }
        $value = $row['completed_until'] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException('MariaDB returned an invalid monitoring cursor.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (false === $date) {
            throw new RuntimeException('MariaDB returned an invalid monitoring cursor timestamp.');
        }
        return $date;
    }
}
