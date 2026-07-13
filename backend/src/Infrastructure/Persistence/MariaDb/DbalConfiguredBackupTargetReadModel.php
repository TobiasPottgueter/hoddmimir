<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use App\Application\Target\ReadModel\ConfiguredBackupTarget;
use App\Application\Target\ReadModel\ConfiguredBackupTargetAllowedNode;
use App\Application\Target\ReadModel\ConfiguredBackupTargetBlockerCode;
use App\Application\Target\ReadModel\ConfiguredBackupTargetPage;
use App\Application\Target\ReadModel\ConfiguredBackupTargetQuery;
use App\Application\Target\ReadModel\ConfiguredBackupTargetReadModel;
use App\Domain\Shared\UInt64Decimal;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use RuntimeException;

final readonly class DbalConfiguredBackupTargetReadModel implements ConfiguredBackupTargetReadModel
{
    public function __construct(private Connection $connection)
    {
    }

    public function targets(ConfiguredBackupTargetQuery $query): ConfiguredBackupTargetPage
    {
        $where = [];
        $parameters = [];
        $types = ['limit' => ParameterType::INTEGER];
        if (null !== $query->search) {
            $where[] = 'target.display_name LIKE :search ESCAPE \'\\\\\'';
            $parameters['search'] = '%'.$this->escapeLike($query->search).'%';
        }
        if (null !== $query->enabled) {
            $where[] = 'target.status = :status';
            $parameters['status'] = $query->enabled ? 'enabled' : 'disabled';
        }
        if (null !== $query->page->cursor) {
            $where[] = '(BINARY target.display_name > BINARY :cursor_name'
                .' OR (BINARY target.display_name = BINARY :cursor_name AND target.id > :cursor_id))';
            $parameters['cursor_name'] = $query->page->cursor->first;
            $parameters['cursor_id'] = (new ReadModelIdentifier($query->page->cursor->second))->binary();
        }
        $parameters['limit'] = $query->page->limit + 1;

        $sql = <<<'SQL'
            SELECT target.id, target.connection_id, target.cluster_id, target.storage_id,
                   target.display_name, target.status, target.revision,
                   target.minimum_free_bytes, target.fixed_parallel_limit,
                   target.pbs_connection_id, target.pbs_datastore_id, target.pbs_namespace_id,
                   target.disabled_at,
                   connection.display_name AS connection_name,
                   cluster.external_name AS cluster_name,
                   storage.storage_name, storage.storage_type
            FROM backup_targets AS target
            JOIN proxmox_connections AS connection ON connection.id = target.connection_id
            JOIN pve_clusters AS cluster ON cluster.id = target.cluster_id
            JOIN pve_storages AS storage
              ON storage.connection_id = target.connection_id
             AND storage.cluster_id = target.cluster_id
             AND storage.id = target.storage_id
            SQL;
        if ([] !== $where) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }
        $sql .= ' ORDER BY BINARY target.display_name, target.id LIMIT :limit';
        $rows = $this->connection->fetchAllAssociative($sql, $parameters, $types);
        $hasMore = count($rows) > $query->page->limit;
        if ($hasMore) {
            array_pop($rows);
        }
        foreach ($rows as $row) {
            $this->assertTargetRow($row);
        }

        $targetIds = array_map(fn (array $row): string => $this->binary($row, 'id'), $rows);
        $nodes = $this->allowedNodes($targetIds);
        $items = array_map(function (array $row) use ($nodes): ConfiguredBackupTarget {
            $id = $this->binary($row, 'id');
            $connectionName = $this->text($row, 'connection_name');
            $allowed = $nodes[bin2hex($id)] ?? [];
            $status = $this->text($row, 'status');
            $minimum = $this->nullableDecimal($row['minimum_free_bytes'] ?? null);
            $parallel = $this->nullablePositiveInteger($row['fixed_parallel_limit'] ?? null);
            $pbsConnection = $this->nullableUuid($row['pbs_connection_id'] ?? null);
            $pbsDatastore = $this->nullableUuid($row['pbs_datastore_id'] ?? null);
            $pbsNamespace = $this->nullableUuid($row['pbs_namespace_id'] ?? null);
            $blockers = [];
            if (null === $minimum || null === $parallel || [] === $allowed) {
                $blockers[] = ConfiguredBackupTargetBlockerCode::ConfigurationIncomplete;
            }
            if ('pbs' === $this->text($row, 'storage_type') && (null === $pbsConnection || null === $pbsDatastore)) {
                $blockers[] = ConfiguredBackupTargetBlockerCode::PbsBindingMissing;
            }
            if ('disabled' === $status) {
                $blockers[] = ConfiguredBackupTargetBlockerCode::ExecutorEvidenceMissing;
            }

            return new ConfiguredBackupTarget(
                $this->uuidBytes($id),
                $this->positiveInteger($row, 'revision'),
                'enabled' === $status,
                $this->text($row, 'display_name'),
                $this->uuid($row, 'connection_id'),
                $connectionName,
                $this->uuid($row, 'cluster_id'),
                $this->nullableText($row['cluster_name'] ?? null) ?? $connectionName,
                $this->uuid($row, 'storage_id'),
                $this->text($row, 'storage_name'),
                $this->text($row, 'storage_type'),
                $minimum,
                $parallel,
                $pbsConnection,
                $pbsDatastore,
                $pbsNamespace,
                $this->nullableDate($row['disabled_at'] ?? null),
                $allowed,
                $blockers,
            );
        }, $rows);

        $last = [] === $rows ? null : $rows[array_key_last($rows)];
        $next = $hasMore && null !== $last
            ? PageCursor::resource(
                $query->cursorContext(),
                $this->text($last, 'display_name'),
                $this->uuid($last, 'id'),
            )
            : null;

        return new ConfiguredBackupTargetPage($query->page, $items, $next);
    }

    /** @param array<string, mixed> $row */
    private function assertTargetRow(array $row): void
    {
        $this->uuid($row, 'id');
        $this->uuid($row, 'connection_id');
        $this->uuid($row, 'cluster_id');
        $this->uuid($row, 'storage_id');
        foreach (['display_name', 'connection_name', 'storage_name', 'storage_type'] as $key) {
            $this->text($row, $key);
        }
        $this->nullableText($row['cluster_name'] ?? null);
        $this->positiveInteger($row, 'revision');
        $this->nullableDecimal($row['minimum_free_bytes'] ?? null);
        $this->nullablePositiveInteger($row['fixed_parallel_limit'] ?? null);
        $this->nullableUuid($row['pbs_connection_id'] ?? null);
        $this->nullableUuid($row['pbs_datastore_id'] ?? null);
        $this->nullableUuid($row['pbs_namespace_id'] ?? null);
        $this->nullableDate($row['disabled_at'] ?? null);
        if (!in_array($this->text($row, 'status'), ['enabled', 'disabled'], true)) {
            throw new RuntimeException('MariaDB returned an unsupported configured backup-target status.');
        }
    }

    /** @param list<string> $targetIds
     *  @return array<string, list<ConfiguredBackupTargetAllowedNode>>
     */
    private function allowedNodes(array $targetIds): array
    {
        if ([] === $targetIds) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT allowed.target_id, node.id, node.node_name
                FROM backup_target_allowed_nodes AS allowed
                JOIN pve_nodes AS node
                  ON node.connection_id = allowed.connection_id
                 AND node.cluster_id = allowed.cluster_id
                 AND node.id = allowed.node_id
                WHERE allowed.target_id IN (:target_ids)
                ORDER BY allowed.target_id, BINARY node.node_name, node.id
                SQL,
            ['target_ids' => $targetIds],
            ['target_ids' => ArrayParameterType::BINARY],
        );
        $result = [];
        foreach ($rows as $row) {
            $result[bin2hex($this->binary($row, 'target_id'))][] = new ConfiguredBackupTargetAllowedNode(
                $this->uuid($row, 'id'),
                $this->text($row, 'node_name'),
            );
        }
        return $result;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** @param array<string, mixed> $row */
    private function binary(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || 16 !== strlen($value)) {
            throw new RuntimeException('MariaDB returned an invalid configured backup-target identifier.');
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private function uuid(array $row, string $key): string
    {
        return $this->uuidBytes($this->binary($row, $key));
    }

    private function uuidBytes(string $value): string
    {
        $hex = bin2hex($value);
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'
            .substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }

    /** @param array<string, mixed> $row */
    private function text(array $row, string $key): string
    {
        return $this->nullableText($row[$key] ?? null)
            ?? throw new RuntimeException('MariaDB returned invalid configured backup-target text.');
    }

    private function nullableText(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!is_string($value) || '' === $value || str_contains($value, "\0")) {
            throw new RuntimeException('MariaDB returned invalid configured backup-target text.');
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private function positiveInteger(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && '0' !== $value) {
            $maximum = (string) PHP_INT_MAX;
            $canonical = ltrim($value, '0');
            if ('' === $canonical
                || strlen($canonical) > strlen($maximum)
                || (strlen($canonical) === strlen($maximum) && $canonical > $maximum)
            ) {
                throw new RuntimeException('MariaDB returned an invalid configured backup-target integer.');
            }
            return (int) $value;
        }
        throw new RuntimeException('MariaDB returned an invalid configured backup-target integer.');
    }

    private function nullablePositiveInteger(mixed $value): ?int
    {
        if (null === $value) {
            return null;
        }
        return $this->positiveInteger(['value' => $value], 'value');
    }

    private function nullableUuid(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        return $this->uuidBytes($this->binary(['value' => $value], 'value'));
    }

    private function nullableDecimal(mixed $value): ?UInt64Decimal
    {
        if (null === $value) {
            return null;
        }
        if (is_int($value) && $value >= 0) {
            return new UInt64Decimal((string) $value);
        }
        if (is_string($value) && ctype_digit($value)) {
            return new UInt64Decimal($value);
        }
        throw new RuntimeException('MariaDB returned invalid configured backup-target bytes.');
    }

    private function nullableDate(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        }
        if (!is_string($value)) {
            throw new RuntimeException('MariaDB returned an invalid configured backup-target timestamp.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (false === $date || $date->format('Y-m-d H:i:s.u') !== $value) {
            throw new RuntimeException('MariaDB returned an invalid configured backup-target timestamp.');
        }
        return $date->format('Y-m-d\TH:i:s.u\Z');
    }
}
