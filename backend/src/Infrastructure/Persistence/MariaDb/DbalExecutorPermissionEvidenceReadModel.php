<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Backup\Execution\ReadModel\ExecutorPermissionEvidenceItem;
use App\Application\Backup\Execution\ReadModel\ExecutorPermissionEvidencePage;
use App\Application\Backup\Execution\ReadModel\ExecutorPermissionEvidenceQuery;
use App\Application\Backup\Execution\ReadModel\ExecutorPermissionEvidenceReadModel;
use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use App\Application\Target\ReadModel\EvidenceFreshnessEvaluator;
use App\Domain\Shared\Clock;
use App\Domain\Shared\UInt64Decimal;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use RuntimeException;

final readonly class DbalExecutorPermissionEvidenceReadModel implements ExecutorPermissionEvidenceReadModel
{
    private EvidenceFreshnessEvaluator $freshness;

    public function __construct(
        private Connection $connection,
        private Clock $clock,
        int $evidenceFreshnessSeconds,
    ) {
        $this->freshness = new EvidenceFreshnessEvaluator($evidenceFreshnessSeconds);
    }

    public function evidence(ExecutorPermissionEvidenceQuery $query): ExecutorPermissionEvidencePage
    {
        $where = ['evidence.guest_id IS NOT NULL'];
        $parameters = [];
        $types = [];
        foreach ([
            'connection_id' => $query->connectionId,
            'cluster_id' => $query->clusterId,
            'target_id' => $query->targetId,
            'node_id' => $query->nodeId,
            'guest_id' => $query->guestId,
        ] as $column => $identifier) {
            if (null === $identifier) {
                continue;
            }
            $where[] = 'evidence.'.$column.' = :'.$column;
            $parameters[$column] = $identifier->binary();
            $types[$column] = ParameterType::BINARY;
        }
        if (null !== $query->page->cursor) {
            $where[] = 'evidence.id > :cursor_id';
            $parameters['cursor_id'] = (new ReadModelIdentifier($query->page->cursor->second))->binary();
            $types['cursor_id'] = ParameterType::BINARY;
        }
        $parameters['limit'] = $query->page->limit + 1;
        $types['limit'] = ParameterType::INTEGER;
        $whereSql = implode(' AND ', $where);

        $rows = $this->connection->fetchAllAssociative(<<<SQL
SELECT evidence.id, evidence.connection_id, evidence.cluster_id, evidence.target_id,
       evidence.node_id, evidence.storage_id, evidence.guest_id,
       evidence.evidence_set_revision, evidence.endpoint_id,
       evidence.connection_revision, evidence.backup_credential_revision,
       evidence.scan_credential_revision, evidence.observed_at,
       evidence.vm_backup_authorized, evidence.datastore_allocate_authorized,
       evidence.authorized
FROM current_executor_permission_evidence evidence
WHERE {$whereSql}
ORDER BY evidence.id
LIMIT :limit
SQL, $parameters, $types);

        $hasMore = count($rows) > $query->page->limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $now = $this->clock->now();
        $items = array_map(fn (array $row): ExecutorPermissionEvidenceItem => $this->item($row, $now), $rows);
        $last = [] === $items ? null : $items[array_key_last($items)];
        $next = $hasMore && null !== $last
            ? PageCursor::resource($query->cursorContext(), $last->id, $last->id)
            : null;

        return new ExecutorPermissionEvidencePage($query->page, $items, $next);
    }

    /** @param array<string, mixed> $row */
    private function item(array $row, DateTimeImmutable $now): ExecutorPermissionEvidenceItem
    {
        $observed = $this->date($row['observed_at'] ?? null);

        return new ExecutorPermissionEvidenceItem(
            $this->uuid($row['id'] ?? null),
            $this->uuid($row['connection_id'] ?? null),
            $this->uuid($row['cluster_id'] ?? null),
            $this->uuid($row['target_id'] ?? null),
            $this->uuid($row['node_id'] ?? null),
            $this->uuid($row['storage_id'] ?? null),
            $this->uuid($row['guest_id'] ?? null),
            $this->positiveUInt64($row['evidence_set_revision'] ?? null),
            $this->uuid($row['endpoint_id'] ?? null),
            $this->positiveInteger($row['connection_revision'] ?? null),
            $this->positiveInteger($row['backup_credential_revision'] ?? null),
            $this->positiveInteger($row['scan_credential_revision'] ?? null),
            $observed->format('Y-m-d\TH:i:s.u\Z'),
            $this->freshness->assess($now, $observed),
            $this->boolean($row['vm_backup_authorized'] ?? null),
            $this->boolean($row['datastore_allocate_authorized'] ?? null),
            $this->boolean($row['authorized'] ?? null),
        );
    }

    private function uuid(mixed $value): string
    {
        if (!is_string($value) || 16 !== strlen($value)) {
            throw new RuntimeException('MariaDB returned an invalid executor evidence identifier.');
        }
        $hex = bin2hex($value);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }

    private function positiveInteger(mixed $value): int
    {
        if (is_int($value)) {
            if ($value < 1) {
                throw new RuntimeException('MariaDB returned an invalid executor evidence revision.');
            }

            return $value;
        }
        if (!is_string($value) || !ctype_digit($value) || '0' === $value) {
            throw new RuntimeException('MariaDB returned an invalid executor evidence revision.');
        }
        $integer = (int) $value;
        if ($integer < 1 || (string) $integer !== $value) {
            throw new RuntimeException('MariaDB returned an invalid executor evidence revision.');
        }

        return $integer;
    }

    private function positiveUInt64(mixed $value): UInt64Decimal
    {
        if (!is_int($value) && !is_string($value)) {
            throw new RuntimeException('MariaDB returned an invalid executor evidence set revision.');
        }
        try {
            $revision = new UInt64Decimal((string) $value);
        } catch (\InvalidArgumentException $exception) {
            throw new RuntimeException(
                'MariaDB returned an invalid executor evidence set revision.',
                previous: $exception,
            );
        }
        if ('0' === $revision->value) {
            throw new RuntimeException('MariaDB returned an invalid executor evidence set revision.');
        }

        return $revision;
    }

    private function boolean(mixed $value): bool
    {
        return match ($value) {
            0, '0' => false,
            1, '1' => true,
            default => throw new RuntimeException('MariaDB returned an invalid executor evidence boolean.'),
        };
    }

    private function date(mixed $value): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new RuntimeException('MariaDB returned an invalid executor evidence timestamp.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (false === $date || $date->format('Y-m-d H:i:s.u') !== $value) {
            throw new RuntimeException('MariaDB returned an invalid executor evidence timestamp.');
        }

        return $date;
    }
}
