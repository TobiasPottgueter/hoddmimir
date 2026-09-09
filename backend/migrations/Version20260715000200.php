<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260715000200 extends AbstractMigration
{
    /** @var list<string> */
    private const ACTIVE_STATES = [
        'pending',
        'retry_wait',
        'leased',
        'starting',
        'running',
        'reconcile_required',
    ];

    /** @var list<string> */
    private const TERMINAL_STATES = ['succeeded', 'failed', 'cancelled', 'unknown'];

    public function getDescription(): string
    {
        return 'Enforce at most one active backup request per guest across every request origin.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $duplicate = $this->connection->fetchAssociative(<<<'SQL'
SELECT
    HEX(connection_id) AS connection_id,
    HEX(cluster_id) AS cluster_id,
    HEX(guest_id) AS guest_id,
    COUNT(*) AS duplicate_count
FROM backup_requests
WHERE state IN ('pending', 'retry_wait', 'leased', 'starting', 'running', 'reconcile_required')
GROUP BY connection_id, cluster_id, guest_id
HAVING COUNT(*) > 1
ORDER BY connection_id, cluster_id, guest_id
LIMIT 1
SQL);
        $this->abortIf(
            false !== $duplicate,
            false === $duplicate
                ? 'Duplicate active backup requests must be resolved before adding the per-guest uniqueness fence.'
                : sprintf(
                    'Duplicate active backup requests must be resolved before adding the per-guest uniqueness fence (connection=%s, cluster=%s, guest=%s, count=%s).',
                    (string) ($duplicate['connection_id'] ?? 'unknown'),
                    (string) ($duplicate['cluster_id'] ?? 'unknown'),
                    (string) ($duplicate['guest_id'] ?? 'unknown'),
                    (string) ($duplicate['duplicate_count'] ?? 'unknown'),
                ),
        );

        $column = $this->activeGuestColumn();
        if (false === $column) {
            $this->addSql(<<<'SQL'
ALTER TABLE backup_requests
ADD active_guest_id BINARY(16) AS (
    CASE
        WHEN state IN ('pending', 'retry_wait', 'leased', 'starting', 'running', 'reconcile_required') THEN guest_id
        ELSE NULL
    END
) PERSISTENT
SQL);
        } else {
            $this->abortIf(
                !$this->isExpectedActiveGuestColumn($column),
                'backup_requests.active_guest_id exists with an incompatible generated-column definition.',
            );
        }

        $index = $this->activeGuestIndex();
        if ([] === $index) {
            $this->addSql(<<<'SQL'
ALTER TABLE backup_requests
ADD CONSTRAINT uq_backup_requests_active_guest UNIQUE (connection_id, cluster_id, active_guest_id)
SQL);
        } else {
            $actual = array_map(
                static fn (array $row): array => [
                    strtolower((string) ($row['column_name'] ?? '')),
                    (string) ($row['non_unique'] ?? ''),
                ],
                $index,
            );
            $this->abortIf(
                [
                    ['connection_id', '0'],
                    ['cluster_id', '0'],
                    ['active_guest_id', '0'],
                ] !== $actual,
                'uq_backup_requests_active_guest exists with an incompatible index definition.',
            );
        }
    }

    public function down(Schema $schema): void
    {
        // This is an expand-only compatibility migration. Removing the
        // generated key during a rolling rollback would let an old image
        // create a second active request for a guest. A later contract
        // migration may remove it only after that rollback window has closed.
    }

    /** @return array<string, mixed>|false */
    private function activeGuestColumn(): array|false
    {
        return $this->connection->fetchAssociative(<<<'SQL'
SELECT
    DATA_TYPE AS data_type,
    CHARACTER_MAXIMUM_LENGTH AS maximum_length,
    EXTRA AS extra,
    GENERATION_EXPRESSION AS generation_expression
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'backup_requests'
  AND COLUMN_NAME = 'active_guest_id'
SQL);
    }

    /** @param array<string, mixed> $column */
    private function isExpectedActiveGuestColumn(array $column): bool
    {
        $expression = strtolower((string) ($column['generation_expression'] ?? ''));
        if ('binary' !== strtolower((string) ($column['data_type'] ?? ''))
            || '16' !== (string) ($column['maximum_length'] ?? '')
            || !str_contains(strtoupper((string) ($column['extra'] ?? '')), 'STORED GENERATED')
            || !str_contains($expression, 'guest_id')
            || !str_contains($expression, 'else null')) {
            return false;
        }

        foreach (self::ACTIVE_STATES as $state) {
            if (!str_contains($expression, "'".$state."'")) {
                return false;
            }
        }
        foreach (self::TERMINAL_STATES as $state) {
            if (str_contains($expression, "'".$state."'")) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array<string, mixed>> */
    private function activeGuestIndex(): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
SELECT COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'backup_requests'
  AND INDEX_NAME = 'uq_backup_requests_active_guest'
ORDER BY SEQ_IN_INDEX
SQL);
    }
}
