<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use App\Application\Configuration\Policy\PolicyActivationAssessor;
use App\Application\Configuration\Policy\PolicyActivationEvidence;
use App\Application\Configuration\Policy\PolicyActivationEvidenceProvider;
use App\Application\Policy\ReadModel\ConfiguredPolicy;
use App\Application\Policy\ReadModel\PolicyListQuery;
use App\Application\Policy\ReadModel\PolicyPage;
use App\Application\Policy\ReadModel\PolicyReadModel;
use App\Application\Policy\ReadModel\PolicyRetention;
use App\Application\Policy\ReadModel\PolicySelectionEntry;
use App\Application\Policy\ReadModel\PolicySelectionPage;
use App\Application\Policy\ReadModel\PolicySelectionQuery;
use App\Domain\Policy\FailureNotificationRecipients;
use App\Domain\Policy\BackupMode;
use App\Domain\Policy\BackupPolicy;
use App\Domain\Policy\Compression;
use App\Domain\Policy\PolicyId;
use App\Domain\Policy\PolicyPriority;
use App\Domain\Policy\PolicyRevision;
use App\Domain\Policy\PolicyStatus;
use App\Domain\Policy\PolicyThresholds;
use App\Domain\Policy\RetentionPolicy;
use App\Domain\Policy\Schedule;
use App\Domain\Scheduler\EvidenceFreshnessPolicy;
use App\Domain\Shared\Clock;
use App\Domain\Target\BackupTargetId;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use RuntimeException;
use Throwable;

final readonly class DbalPolicyReadModel implements PolicyReadModel
{
    public function __construct(
        private Connection $connection,
        private PolicyActivationEvidenceProvider $evidence,
        private PolicyActivationAssessor $assessor,
        private Clock $clock,
        private EvidenceFreshnessPolicy $freshness,
    ) {
    }

    public function policies(PolicyListQuery $query): PolicyPage
    {
        $started = !$this->connection->isTransactionActive();
        if ($started) {
            $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->connection->beginTransaction();
        }
        try {
            $page = $this->policiesInSnapshot($query);
            if ($started) {
                $this->connection->commit();
            }
            return $page;
        } catch (Throwable $failure) {
            if ($started && $this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw $failure;
        }
    }

    private function policiesInSnapshot(PolicyListQuery $query): PolicyPage
    {
        $where = [];
        $parameters = [];
        $types = ['limit' => ParameterType::INTEGER];
        if (null !== $query->search) {
            $where[] = "policy.display_name LIKE :search ESCAPE '\\\\'";
            $parameters['search'] = '%'.$this->escapeLike($query->search).'%';
        }
        if (null !== $query->status) {
            $where[] = 'policy.status = :status';
            $parameters['status'] = $query->status;
        }
        if (null !== $query->page->cursor) {
            $where[] = '(BINARY policy.display_name > BINARY :cursor_name OR '
                .'(BINARY policy.display_name = BINARY :cursor_name AND policy.id > :cursor_id))';
            $parameters['cursor_name'] = $query->page->cursor->first;
            $parameters['cursor_id'] = (new ReadModelIdentifier($query->page->cursor->second))->binary();
        }
        $parameters['limit'] = $query->page->limit + 1;
        $sql = <<<'SQL'
            SELECT policy.*, connection.display_name AS connection_name,
                   COALESCE(cluster.external_name, connection.display_name) AS cluster_name,
                   target.display_name AS target_name,
                   target.default_backup_mode, target.default_compression, target.default_legacy_maxfiles, target.default_keep_all, target.default_keep_last, target.default_keep_hourly, target.default_keep_daily, target.default_keep_weekly, target.default_keep_monthly, target.default_keep_yearly,
                   storage.storage_type AS target_storage_type
            FROM backup_policies AS policy
            JOIN proxmox_connections AS connection ON connection.id = policy.connection_id
            JOIN pve_clusters AS cluster
              ON cluster.connection_id = policy.connection_id AND cluster.id = policy.cluster_id
            LEFT JOIN backup_targets AS target
              ON target.connection_id = policy.connection_id
             AND target.cluster_id = policy.cluster_id AND target.id = policy.target_id
            LEFT JOIN pve_storages AS storage
              ON storage.connection_id = target.connection_id
             AND storage.cluster_id = target.cluster_id AND storage.id = target.storage_id
            SQL;
        if ([] !== $where) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }
        $sql .= ' ORDER BY BINARY policy.display_name, policy.id LIMIT :limit';
        $rows = $this->connection->fetchAllAssociative($sql, $parameters, $types);
        $hasMore = count($rows) > $query->page->limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $ids = array_map(fn (array $row): PolicyId => new PolicyId($this->binary($row, 'id')), $rows);
        $evidence = $this->evidence->policyEvidenceBatch($ids);
        $now = $this->clock->now();
        $items = array_map(fn (array $row): ConfiguredPolicy => $this->policy(
            $row,
            $evidence[bin2hex($this->binary($row, 'id'))],
            $now,
        ), $rows);
        $last = [] === $rows ? null : $rows[array_key_last($rows)];
        $next = $hasMore && null !== $last ? PageCursor::resource(
            $query->cursorContext(),
            $this->text($last, 'display_name'),
            $this->uuid($last, 'id'),
        ) : null;

        return new PolicyPage($query->page, $items, $next);
    }

    public function selection(PolicySelectionQuery $query): PolicySelectionPage
    {
        $parameters = [
            'policy_id' => (new ReadModelIdentifier($query->policyId))->binary(),
            'limit' => $query->page->limit + 1,
        ];
        $types = ['policy_id' => ParameterType::BINARY, 'limit' => ParameterType::INTEGER];
        $cursor = '';
        if (null !== $query->page->cursor) {
            $cursor = ' WHERE (projection.sort_key > :cursor_key OR '
                .'(projection.sort_key = :cursor_key AND projection.id > :cursor_id))';
            $parameters['cursor_key'] = $query->page->cursor->first;
            $parameters['cursor_id'] = (new ReadModelIdentifier($query->page->cursor->second))->binary();
        }
        $sql = <<<'SQL'
            SELECT * FROM (
                SELECT assignment.id, assignment.revision, assignment.status,
                       'assignment' AS kind, assignment.scope,
                       assignment.subject_connection_id AS connection_id,
                       assignment.subject_cluster_id AS cluster_id,
                       assignment.node_id, assignment.guest_id,
                       COALESCE(guest.name, node.node_name, cluster.external_name,
                           connection.display_name, 'All policy subjects') AS subject_name,
                       assignment.selection_value, NULL AS backup_mode, NULL AS compression,
                       NULL AS legacy_maxfiles, NULL AS keep_all, NULL AS keep_last,
                       NULL AS keep_hourly, NULL AS keep_daily, NULL AS keep_weekly,
                       NULL AS keep_monthly, NULL AS keep_yearly, assignment.disabled_at,
                       CONCAT('assignment:', assignment.scope) AS sort_key
                FROM backup_policy_assignments AS assignment
                LEFT JOIN proxmox_connections AS connection ON connection.id = assignment.subject_connection_id
                LEFT JOIN pve_clusters AS cluster
                  ON cluster.connection_id = assignment.subject_connection_id
                 AND cluster.id = assignment.subject_cluster_id
                LEFT JOIN pve_nodes AS node
                  ON node.connection_id = assignment.subject_connection_id
                 AND node.cluster_id = assignment.subject_cluster_id AND node.id = assignment.node_id
                LEFT JOIN guests AS guest
                  ON guest.connection_id = assignment.subject_connection_id
                 AND guest.cluster_id = assignment.subject_cluster_id AND guest.id = assignment.guest_id
                WHERE assignment.policy_id = :policy_id
                UNION ALL
                SELECT override_row.id, override_row.revision, override_row.status,
                       'guest_override' AS kind, 'guest' AS scope,
                       override_row.connection_id, override_row.cluster_id,
                       NULL AS node_id, override_row.guest_id, guest.name AS subject_name,
                       NULL AS selection_value, override_row.backup_mode, override_row.compression,
                       override_row.legacy_maxfiles, override_row.keep_all, override_row.keep_last,
                       override_row.keep_hourly, override_row.keep_daily, override_row.keep_weekly,
                       override_row.keep_monthly, override_row.keep_yearly, override_row.disabled_at,
                       'guest_override:guest' AS sort_key
                FROM backup_policy_guest_overrides AS override_row
                JOIN guests AS guest
                  ON guest.connection_id = override_row.connection_id
                 AND guest.cluster_id = override_row.cluster_id AND guest.id = override_row.guest_id
                WHERE override_row.policy_id = :policy_id
            ) AS projection
            SQL;
        $sql .= $cursor.' ORDER BY projection.sort_key, projection.id LIMIT :limit';
        $rows = $this->connection->fetchAllAssociative($sql, $parameters, $types);
        $hasMore = count($rows) > $query->page->limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $items = array_map(fn (array $row): PolicySelectionEntry => new PolicySelectionEntry(
            $this->uuid($row, 'id'),
            $this->positiveInteger($row, 'revision'),
            $this->text($row, 'status'),
            $this->text($row, 'kind'),
            $this->text($row, 'scope'),
            $this->nullableUuid($row['connection_id'] ?? null),
            $this->nullableUuid($row['cluster_id'] ?? null),
            $this->nullableUuid($row['node_id'] ?? null),
            $this->nullableUuid($row['guest_id'] ?? null),
            $this->nullableText($row['subject_name'] ?? null),
            $this->nullableText($row['selection_value'] ?? null),
            $this->nullableText($row['backup_mode'] ?? null),
            $this->nullableText($row['compression'] ?? null),
            $this->retention($row),
            $this->nullableDate($row['disabled_at'] ?? null),
        ), $rows);
        $last = [] === $rows ? null : $rows[array_key_last($rows)];
        $next = $hasMore && null !== $last ? PageCursor::resource(
            $query->cursorContext(),
            $this->text($last, 'sort_key'),
            $this->uuid($last, 'id'),
        ) : null;

        return new PolicySelectionPage($query->page, $items, $next);
    }

    /** @param array<string, mixed> $row */
    private function policy(array $row, PolicyActivationEvidence $evidence, DateTimeImmutable $now): ConfiguredPolicy
    {
        $retentionExecutionEnabled = $this->boolean($row, 'retention_execution_enabled');
        $pbsTarget = 'pbs' === ($row['target_storage_type'] ?? null);
        $retention = $this->retention($row);
        $domainPolicy = BackupPolicy::rehydrate(
            new PolicyId($this->binary($row, 'id')),
            new PolicyRevision($this->positiveInteger($row, 'revision')),
            PolicyStatus::from($this->text($row, 'status')),
            null === ($row['target_id'] ?? null) ? null : new BackupTargetId($this->binary($row, 'target_id')),
            null === ($row['backup_mode'] ?? null) ? null : BackupMode::from($this->text($row, 'backup_mode')),
            null === ($row['compression'] ?? null) ? null : Compression::from($this->text($row, 'compression')),
            $this->domainRetention($retention),
            null === ($row['policy_priority'] ?? null) ? null : new PolicyPriority($this->nullableInteger($row['policy_priority'])
                ?? throw new RuntimeException('MariaDB returned invalid policy integer.')),
            $this->domainThresholds($row),
            null === ($row['schedule'] ?? null) ? null : Schedule::from($this->text($row, 'schedule')),
            new FailureNotificationRecipients($this->failureRecipients($row['failure_notification_recipients_json'] ?? null)),
            (new BackupDefaultsMapper())->fromRow($row),
        );
        $assessment = $this->assessor->assess($domainPolicy, $evidence, $now, $this->freshness->maximumAgeSeconds);

        $defaultRetentionRow = [];
        foreach (BackupDefaultsMapper::FIELDS as $column) {
            $defaultRetentionRow[substr($column, 8)] = $row[$column] ?? null;
        }
        return new ConfiguredPolicy(
            $this->uuid($row, 'id'),
            $domainPolicy->revision->value,
            $this->text($row, 'status'),
            $this->text($row, 'display_name'),
            $this->uuid($row, 'connection_id'),
            $this->text($row, 'connection_name'),
            $this->uuid($row, 'cluster_id'),
            $this->text($row, 'cluster_name'),
            $this->nullableUuid($row['target_id'] ?? null),
            $this->nullableText($row['target_name'] ?? null),
            $this->nullableInteger($row['policy_priority'] ?? null),
            $this->nullableText($row['backup_mode'] ?? null),
            $this->nullableText($row['compression'] ?? null),
            $this->nullableInteger($row['maximum_age_seconds'] ?? null),
            $this->nullableDecimal($row['bytes_written_threshold'] ?? null),
            $this->nullableInteger($row['cooldown_seconds'] ?? null),
            $this->nullableText($row['schedule'] ?? null),
            $retention,
            $retentionExecutionEnabled && !$pbsTarget,
            $this->nullableDate($row['disabled_at'] ?? null),
            $assessment->blockers,
            $domainPolicy->failureNotificationRecipients->addresses,
            $domainPolicy->effectiveMode()?->value,
            $domainPolicy->effectiveCompression()?->value,
            $retention ?? $this->retention($defaultRetentionRow),
        );
    }

    /** @param array<string, mixed> $row */
    private function domainThresholds(array $row): ?PolicyThresholds
    {
        $maximumAge = $this->nullableInteger($row['maximum_age_seconds'] ?? null);
        $bytes = $this->nullableDecimal($row['bytes_written_threshold'] ?? null);
        $cooldown = $this->nullableInteger($row['cooldown_seconds'] ?? null);
        return null === $maximumAge && null === $bytes ? null : new PolicyThresholds($maximumAge, $bytes, $cooldown);
    }

    private function domainRetention(?PolicyRetention $retention): ?RetentionPolicy
    {
        if (null === $retention) {
            return null;
        }
        return null !== $retention->legacyMaxFiles
            ? RetentionPolicy::legacyMaxFiles($retention->legacyMaxFiles)
            : RetentionPolicy::prune(
                $retention->keepAll,
                $retention->keepLast,
                $retention->keepHourly,
                $retention->keepDaily,
                $retention->keepWeekly,
                $retention->keepMonthly,
                $retention->keepYearly,
            );
    }

    /** @return list<string> */
    private function failureRecipients(mixed $value): array
    {
        if (!is_string($value)) {
            throw new RuntimeException('Policy failure recipients are invalid.');
        }
        try {
            $decoded = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('Policy failure recipients are invalid.', 0, $error);
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new RuntimeException('Policy failure recipients are invalid.');
        }
        foreach ($decoded as $address) {
            if (!is_string($address)) {
                throw new RuntimeException('Policy failure recipients are invalid.');
            }
        }
        /** @var list<string> $decoded */
        return (new FailureNotificationRecipients($decoded))->addresses;
    }

    /** @param array<string, mixed> $row */
    private function retention(array $row): ?PolicyRetention
    {
        $values = array_map(fn (string $key): mixed => $row[$key] ?? null, [
            'legacy_maxfiles', 'keep_all', 'keep_last', 'keep_hourly', 'keep_daily',
            'keep_weekly', 'keep_monthly', 'keep_yearly',
        ]);
        if ([] === array_filter($values, static fn (mixed $value): bool => null !== $value)) {
            return null;
        }
        return new PolicyRetention(
            $this->nullableInteger($values[0]),
            null === $values[1] ? null : $this->booleanValue($values[1]),
            $this->nullableInteger($values[2]),
            $this->nullableInteger($values[3]),
            $this->nullableInteger($values[4]),
            $this->nullableInteger($values[5]),
            $this->nullableInteger($values[6]),
            $this->nullableInteger($values[7]),
        );
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** @param array<string, mixed> $row */
    private function text(array $row, string $key): string
    {
        return $this->nullableText($row[$key] ?? null)
            ?? throw new RuntimeException('MariaDB returned invalid policy text.');
    }

    private function nullableText(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!is_string($value) || '' === $value || str_contains($value, "\0")) {
            throw new RuntimeException('MariaDB returned invalid policy text.');
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private function uuid(array $row, string $key): string
    {
        return $this->nullableUuid($row[$key] ?? null)
            ?? throw new RuntimeException('MariaDB returned invalid policy identifier.');
    }

    /** @param array<string, mixed> $row */
    private function binary(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || 16 !== strlen($value)) {
            throw new RuntimeException('MariaDB returned invalid policy identifier.');
        }
        return $value;
    }

    private function nullableUuid(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!is_string($value) || 16 !== strlen($value)) {
            throw new RuntimeException('MariaDB returned invalid policy identifier.');
        }
        $hex = bin2hex($value);
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'
            .substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }

    /** @param array<string, mixed> $row */
    private function positiveInteger(array $row, string $key): int
    {
        $value = $this->nullableInteger($row[$key] ?? null);
        if (null === $value || $value < 1) {
            throw new RuntimeException('MariaDB returned invalid policy integer.');
        }
        return $value;
    }

    private function nullableInteger(mixed $value): ?int
    {
        if (null === $value) {
            return null;
        }
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            $canonical = ltrim($value, '0');
            $canonical = '' === $canonical ? '0' : $canonical;
            $maximum = (string) PHP_INT_MAX;
            if (strlen($canonical) < strlen($maximum)
                || (strlen($canonical) === strlen($maximum) && strcmp($canonical, $maximum) <= 0)) {
                return (int) $canonical;
            }
        }
        throw new RuntimeException('MariaDB returned invalid policy integer.');
    }

    private function nullableDecimal(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if ((is_int($value) && $value >= 0) || (is_string($value) && ctype_digit($value))) {
            return (string) $value;
        }
        throw new RuntimeException('MariaDB returned invalid policy decimal.');
    }

    /** @param array<string, mixed> $row */
    private function boolean(array $row, string $key): bool
    {
        return $this->booleanValue($row[$key] ?? null);
    }

    private function booleanValue(mixed $value): bool
    {
        return match ($value) {
            0, '0' => false,
            1, '1' => true,
            default => throw new RuntimeException('MariaDB returned invalid policy boolean.'),
        };
    }

    private function nullableDate(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $date = $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))
            : (is_string($value)
                ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'))
                : false);
        if (false === $date) {
            throw new RuntimeException('MariaDB returned invalid policy timestamp.');
        }
        return $date->format('Y-m-d\TH:i:s.u\Z');
    }
}
