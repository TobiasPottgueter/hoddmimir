<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Target\ReadModel\BackupTargetBlockerCode;
use App\Application\Target\ReadModel\BackupTargetCandidate;
use App\Application\Target\ReadModel\BackupTargetCandidatePage;
use App\Application\Target\ReadModel\BackupTargetCandidateQuery;
use App\Application\Target\ReadModel\BackupTargetCandidateReadModel;
use App\Application\Target\ReadModel\BackupTargetCapacityStatus;
use App\Application\Target\ReadModel\BackupTargetExecutorEvidence;
use App\Application\Target\ReadModel\BackupTargetExecutorStatus;
use App\Application\Target\ReadModel\BackupTargetNodeEvidence;
use App\Application\Target\ReadModel\EvidenceFreshness;
use App\Application\Target\ReadModel\EvidenceFreshnessEvaluator;
use App\Application\Target\ReadModel\PbsBackupTargetEvidence;
use App\Application\Target\ReadModel\PbsEndpointMatchStatus;
use App\Domain\Shared\UInt64Decimal;
use App\Domain\Shared\Clock;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use JsonException;
use RuntimeException;

final readonly class DbalBackupTargetCandidateReadModel implements BackupTargetCandidateReadModel
{
    private EvidenceFreshnessEvaluator $freshness;

    public function __construct(
        private Connection $connection,
        private Clock $clock,
        int $evidenceFreshnessSeconds,
    ) {
        $this->freshness = new EvidenceFreshnessEvaluator($evidenceFreshnessSeconds);
    }

    public function candidates(BackupTargetCandidateQuery $query): BackupTargetCandidatePage
    {
        $now = $this->clock->now();
        $context = $query->cursorContext();
        $params = [];
        $where = [];
        if (null !== $query->connectionId) {
            $where[] = 'storage.connection_id = :connection_id';
            $params['connection_id'] = $query->connectionId->binary();
        }
        if (null !== $query->clusterId) {
            $where[] = 'storage.cluster_id = :cluster_id';
            $params['cluster_id'] = $query->clusterId->binary();
        }
        if (null !== $query->page->cursor) {
            $where[] = '(BINARY storage.storage_name > BINARY :cursor_name'
                .' OR (BINARY storage.storage_name = BINARY :cursor_name AND storage.id > :cursor_id))';
            $params['cursor_name'] = $query->page->cursor->first;
            $params['cursor_id'] = (new \App\Application\Inventory\ReadModel\ReadModelIdentifier(
                $query->page->cursor->second,
            ))->binary();
        }
        $params['limit'] = $query->page->limit + 1;
        $sql = <<<'SQL'
            SELECT storage.id, storage.connection_id, storage.cluster_id, storage.storage_name,
                   storage.storage_type, storage.supports_backup, storage.disabled, storage.shared,
                   storage.node_allowlist_json, storage.content_json, storage.inventory_state, storage.last_seen_at,
                   connection.display_name AS connection_name, connection.product AS connection_product,
                   connection.enabled AS connection_enabled,
                   cluster.external_name AS cluster_name, cluster.inventory_state AS cluster_state,
                   mapping.server AS pbs_server, mapping.port AS pbs_port,
                   mapping.datastore AS pbs_datastore, mapping.namespace AS pbs_namespace,
                   mapping.observed_at AS pbs_mapping_observed_at
            FROM pve_storages AS storage
            JOIN proxmox_connections AS connection ON connection.id = storage.connection_id
            JOIN pve_clusters AS cluster ON cluster.id = storage.cluster_id
            LEFT JOIN pve_storage_pbs_mappings AS mapping ON mapping.storage_id = storage.id
            SQL;
        if ([] !== $where) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }
        $sql .= ' ORDER BY BINARY storage.storage_name, storage.id LIMIT :limit';
        $rows = $this->connection->fetchAllAssociative($sql, $params, ['limit' => ParameterType::INTEGER]);
        $hasMore = count($rows) > $query->page->limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $storageIds = array_map(fn (array $row): string => $this->binary($row, 'id'), $rows);
        $allowlists = [];
        foreach ($rows as $row) {
            $allowlists[bin2hex($this->binary($row, 'id'))] = $this->allowlist($row['node_allowlist_json'] ?? null);
        }
        $nodes = $this->nodes($storageIds, $allowlists, $now);
        $executorEvidence = $this->executorEvidence($storageIds, $now);
        $pbsEvidence = $this->pbsEvidence($rows, $storageIds, $now);
        $items = [];
        foreach ($rows as $row) {
            $key = bin2hex($this->binary($row, 'id'));
            $items[] = $this->candidate(
                $row,
                $nodes[$key] ?? [],
                $executorEvidence[$key] ?? $this->requiresTargetConfiguration(),
                $pbsEvidence[$key] ?? null,
                $now,
            );
        }
        $last = [] === $rows ? null : $rows[array_key_last($rows)];
        $next = $hasMore && null !== $last
            ? PageCursor::resource($context, $this->string($last, 'storage_name'), $this->uuid($last, 'id'))
            : null;

        return new BackupTargetCandidatePage($query->page, $items, $next);
    }

    /** @param array<string, mixed> $row
     *  @param list<BackupTargetNodeEvidence> $nodes
     */
    private function candidate(
        array $row,
        array $nodes,
        BackupTargetExecutorEvidence $executor,
        ?PbsBackupTargetEvidence $pbs,
        DateTimeImmutable $now,
    ): BackupTargetCandidate
    {
        $observedAt = $this->nullableDate($row['last_seen_at'] ?? null);
        $blockers = $this->freshnessBlocker(
            $this->assessFreshness($now, $observedAt),
            BackupTargetBlockerCode::StorageInventoryEvidenceMissing,
            BackupTargetBlockerCode::StorageInventoryEvidenceStale,
            BackupTargetBlockerCode::StorageInventoryEvidenceFuture,
        );
        array_push($blockers, ...$executor->blockers);
        if (!$this->boolean($row, 'connection_enabled')) {
            $blockers[] = BackupTargetBlockerCode::ConnectionDisabled;
        }
        if ('pve' !== $this->string($row, 'connection_product')) {
            $blockers[] = BackupTargetBlockerCode::ConnectionNotPve;
        }
        if ('active' !== $this->string($row, 'cluster_state')) {
            $blockers[] = BackupTargetBlockerCode::ClusterArchived;
        }
        if ('active' !== $this->string($row, 'inventory_state')) {
            $blockers[] = BackupTargetBlockerCode::StorageArchived;
        }
        if ($this->boolean($row, 'disabled')) {
            $blockers[] = BackupTargetBlockerCode::StorageDisabled;
        }
        if (!$this->boolean($row, 'supports_backup') || !in_array('backup', $this->content($row['content_json'] ?? null), true)) {
            $blockers[] = BackupTargetBlockerCode::BackupContentUnsupported;
        }
        if ([] === $nodes) {
            $blockers[] = BackupTargetBlockerCode::NoActiveNode;
        } elseif ([] === array_filter($nodes, static fn (BackupTargetNodeEvidence $node): bool => $node->usable())) {
            $blockers[] = BackupTargetBlockerCode::NoUsableNode;
        }
        $storageType = $this->string($row, 'storage_type');
        if ('pbs' === $storageType) {
            if (null === ($row['pbs_server'] ?? null)) {
                $blockers[] = BackupTargetBlockerCode::PbsMappingMissing;
                $blockers[] = BackupTargetBlockerCode::PbsMappingEvidenceMissing;
                $blockers[] = BackupTargetBlockerCode::PbsCapacityEvidenceMissing;
            }
        }

        return new BackupTargetCandidate(
            $this->uuid($row, 'id'),
            $this->uuid($row, 'connection_id'),
            $this->string($row, 'connection_name'),
            $this->uuid($row, 'cluster_id'),
            $this->nullableString($row['cluster_name'] ?? null) ?? $this->string($row, 'connection_name'),
            $this->string($row, 'storage_name'),
            $storageType,
            $this->boolean($row, 'shared'),
            $this->string($row, 'inventory_state'),
            $observedAt,
            $nodes,
            $pbs,
            $blockers,
            $executor,
        );
    }

    /** @param list<string> $storageIds
     *  @return array<string, BackupTargetExecutorEvidence>
     */
    private function executorEvidence(array $storageIds, DateTimeImmutable $now): array
    {
        if ([] === $storageIds) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT storage.id AS storage_id,
       COUNT(DISTINCT target.id) AS target_count,
       COUNT(allowed.node_id) AS expected_count,
       COUNT(evidence.id) AS observed_count,
       MIN(evidence.vm_backup_authorized) AS vm_backup_authorized,
       MIN(evidence.datastore_allocate_authorized) AS datastore_allocate_authorized,
       MIN(evidence.authorized) AS authorized,
       MIN(evidence.observed_at) AS observed_at
FROM pve_storages storage
LEFT JOIN backup_targets target
  ON target.connection_id=storage.connection_id AND target.cluster_id=storage.cluster_id
 AND target.storage_id=storage.id
LEFT JOIN backup_target_allowed_nodes allowed ON allowed.target_id=target.id
LEFT JOIN current_executor_permission_evidence evidence
  ON evidence.connection_id=allowed.connection_id AND evidence.cluster_id=allowed.cluster_id
 AND evidence.target_id=allowed.target_id AND evidence.node_id=allowed.node_id
 AND evidence.storage_id=storage.id AND evidence.guest_id IS NULL
WHERE storage.id IN (:storage_ids)
GROUP BY storage.id
SQL, ['storage_ids' => $storageIds], ['storage_ids' => ArrayParameterType::BINARY]);

        $result = [];
        foreach ($rows as $row) {
            $key = bin2hex($this->binary($row, 'storage_id'));
            $targets = $this->integer($row, 'target_count');
            if (0 === $targets) {
                $result[$key] = $this->requiresTargetConfiguration();
                continue;
            }
            $expected = $this->integer($row, 'expected_count');
            $observed = $this->integer($row, 'observed_count');
            $observedAt = $this->nullableDate($row['observed_at'] ?? null);
            $freshness = $this->assessFreshness($now, $observedAt);
            $blockers = [];
            $status = BackupTargetExecutorStatus::Missing;
            if (0 === $observed || 0 === $expected) {
                $blockers[] = BackupTargetBlockerCode::ExecutorEvidenceMissing;
            } elseif ($observed < $expected) {
                $status = BackupTargetExecutorStatus::Partial;
                $blockers[] = BackupTargetBlockerCode::ExecutorEvidencePartial;
            } else {
                $authorized = $this->nullableBoolean($row['authorized'] ?? null);
                $status = true === $authorized ? BackupTargetExecutorStatus::Authorized : BackupTargetExecutorStatus::Unauthorized;
                if (true !== $authorized) {
                    $blockers[] = BackupTargetBlockerCode::ExecutorUnauthorized;
                }
            }
            array_push($blockers, ...$this->freshnessBlocker(
                $freshness,
                BackupTargetBlockerCode::ExecutorEvidenceMissing,
                BackupTargetBlockerCode::ExecutorEvidenceStale,
                BackupTargetBlockerCode::ExecutorEvidenceFuture,
            ));
            $complete = $observed === $expected && $expected > 0;
            $result[$key] = new BackupTargetExecutorEvidence(
                $status,
                $targets,
                $expected,
                $observed,
                $complete ? $this->nullableBoolean($row['vm_backup_authorized'] ?? null) : null,
                $complete ? $this->nullableBoolean($row['datastore_allocate_authorized'] ?? null) : null,
                $complete ? $this->nullableBoolean($row['authorized'] ?? null) : null,
                $freshness,
                $observedAt,
                array_values(array_unique($blockers, SORT_REGULAR)),
            );
        }
        return $result;
    }

    private function requiresTargetConfiguration(): BackupTargetExecutorEvidence
    {
        return new BackupTargetExecutorEvidence(
            BackupTargetExecutorStatus::RequiresTargetConfiguration,
            0,
            0,
            0,
            null,
            null,
            null,
            EvidenceFreshness::Missing,
            null,
            [],
        );
    }

    /** @param list<string> $storageIds
     *  @param array<string, null|list<string>> $allowlists
     *  @return array<string, list<BackupTargetNodeEvidence>>
     */
    private function nodes(array $storageIds, array $allowlists, DateTimeImmutable $now): array
    {
        if ([] === $storageIds) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT storage.id AS storage_id, node.id, node.node_name, node.api_status,
                       state.enabled, state.active, state.capacity_status,
                       state.total_bytes, state.used_bytes, state.available_bytes, state.observed_at
                FROM pve_storages AS storage
                JOIN pve_nodes AS node ON node.cluster_id = storage.cluster_id AND node.inventory_state = 'active'
                LEFT JOIN pve_node_storage_state AS state
                    ON state.storage_id = storage.id AND state.node_id = node.id
                WHERE storage.id IN (:storage_ids)
                ORDER BY storage.id, BINARY node.node_name, node.id
                SQL,
            ['storage_ids' => $storageIds],
            ['storage_ids' => ArrayParameterType::BINARY],
        );
        $result = [];
        foreach ($rows as $row) {
            $key = bin2hex($this->binary($row, 'storage_id'));
            $allowlist = $allowlists[$key] ?? null;
            $name = $this->string($row, 'node_name');
            $configured = null === $allowlist || in_array($name, $allowlist, true);
            $status = null === ($row['capacity_status'] ?? null)
                ? BackupTargetCapacityStatus::Missing
                : BackupTargetCapacityStatus::from($this->string($row, 'capacity_status'));
            $blockers = [];
            $observedAt = $this->nullableDate($row['observed_at'] ?? null);
            if (!$configured) {
                $blockers[] = BackupTargetBlockerCode::StorageNotConfiguredOnNode;
            }
            if ('online' !== $this->string($row, 'api_status')) {
                $blockers[] = BackupTargetBlockerCode::NodeOffline;
            }
            if (null === ($row['enabled'] ?? null)) {
                $blockers[] = BackupTargetBlockerCode::NodeStateMissing;
            } else {
                if (!$this->boolean($row, 'enabled')) {
                    $blockers[] = BackupTargetBlockerCode::NodeStorageDisabled;
                }
                if (!$this->boolean($row, 'active')) {
                    $blockers[] = BackupTargetBlockerCode::NodeStorageInactive;
                }
                if (BackupTargetCapacityStatus::Unavailable === $status) {
                    $blockers[] = BackupTargetBlockerCode::CapacityUnavailable;
                } elseif (BackupTargetCapacityStatus::Invalid === $status) {
                    $blockers[] = BackupTargetBlockerCode::CapacityInvalid;
                }
            }
            $freshness = $this->assessFreshness($now, $observedAt);
            array_push($blockers, ...$this->freshnessBlocker(
                $freshness,
                BackupTargetBlockerCode::NodeStateEvidenceMissing,
                BackupTargetBlockerCode::NodeStateEvidenceStale,
                BackupTargetBlockerCode::NodeStateEvidenceFuture,
            ));
            array_push($blockers, ...$this->freshnessBlocker(
                $freshness,
                BackupTargetBlockerCode::CapacityEvidenceMissing,
                BackupTargetBlockerCode::CapacityEvidenceStale,
                BackupTargetBlockerCode::CapacityEvidenceFuture,
            ));
            $result[$key][] = new BackupTargetNodeEvidence(
                $this->uuid($row, 'id'),
                $name,
                $configured,
                $this->nullableBoolean($row['enabled'] ?? null),
                $this->nullableBoolean($row['active'] ?? null),
                $status,
                $this->nullableDecimal($row['total_bytes'] ?? null),
                $this->nullableDecimal($row['used_bytes'] ?? null),
                $this->nullableDecimal($row['available_bytes'] ?? null),
                $observedAt,
                $blockers,
            );
        }
        return $result;
    }

    /** @param list<array<string, mixed>> $storageRows
     *  @param list<string> $storageIds
     *  @return array<string, PbsBackupTargetEvidence>
     */
    private function pbsEvidence(array $storageRows, array $storageIds, DateTimeImmutable $now): array
    {
        $mappingRows = array_filter(
            $storageRows,
            static fn (array $row): bool => null !== ($row['pbs_server'] ?? null),
        );
        if ([] === $mappingRows) {
            return [];
        }
        $rawMatches = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT mapping.storage_id, endpoint.enabled AS endpoint_enabled,
                       pbs_connection.id AS pbs_connection_id,
                       pbs_connection.enabled AS pbs_connection_enabled,
                       server.id AS pbs_server_id, datastore.id AS pbs_datastore_id,
                       datastore.inventory_state AS pbs_datastore_state,
                       datastore.allows_backup_writes, namespace.id AS pbs_namespace_id,
                       namespace.inventory_state AS pbs_namespace_state,
                       capacity.semantics, capacity.total_bytes, capacity.used_bytes,
                       capacity.available_bytes, capacity.observed_at AS capacity_observed_at
                FROM pve_storage_pbs_mappings AS mapping
                LEFT JOIN proxmox_connection_endpoints AS endpoint
                    ON BINARY endpoint.host = BINARY mapping.server AND endpoint.port = mapping.port
                LEFT JOIN proxmox_connections AS pbs_connection
                    ON pbs_connection.id = endpoint.connection_id AND pbs_connection.product = 'pbs'
                LEFT JOIN pbs_servers AS server ON server.connection_id = pbs_connection.id
                LEFT JOIN pbs_datastores AS datastore
                    ON datastore.server_id = server.id
                   AND BINARY datastore.datastore_name = BINARY mapping.datastore
                LEFT JOIN pbs_namespaces AS namespace
                    ON namespace.datastore_id = datastore.id
                   AND BINARY namespace.namespace_path = BINARY COALESCE(mapping.namespace, '')
                LEFT JOIN pbs_datastore_capacity_state AS capacity ON capacity.datastore_id = datastore.id
                WHERE mapping.storage_id IN (:storage_ids)
                ORDER BY mapping.storage_id, pbs_connection.id, endpoint.id
                SQL,
            ['storage_ids' => $storageIds],
            ['storage_ids' => ArrayParameterType::BINARY],
        );
        $matchesByStorage = [];
        foreach ($rawMatches as $match) {
            $matchesByStorage[bin2hex($this->binary($match, 'storage_id'))][] = $match;
        }
        $result = [];
        foreach ($mappingRows as $mapping) {
            $key = bin2hex($this->binary($mapping, 'id'));
            $result[$key] = $this->pbsFromMatches($mapping, $matchesByStorage[$key] ?? [], $now);
        }
        return $result;
    }

    /** @param array<string, mixed> $mapping
     *  @param list<array<string, mixed>> $matches
     */
    private function pbsFromMatches(
        array $mapping,
        array $matches,
        DateTimeImmutable $now,
    ): PbsBackupTargetEvidence
    {
        $server = $this->string($mapping, 'pbs_server');
        $port = $this->integer($mapping, 'pbs_port');
        $datastore = $this->string($mapping, 'pbs_datastore');
        $namespace = $this->nullableString($mapping['pbs_namespace'] ?? null);
        $byConnection = [];
        foreach ($matches as $match) {
            if (true !== $this->nullableBoolean($match['endpoint_enabled'] ?? null)) {
                continue;
            }
            if (null === ($match['pbs_connection_id'] ?? null)) {
                continue;
            }
            $connectionId = $this->binary($match, 'pbs_connection_id');
            $byConnection[bin2hex($connectionId)] = $match;
        }
        $mappingObservedAt = $this->nullableDate($mapping['pbs_mapping_observed_at'] ?? null);
        $blockers = $this->freshnessBlocker(
            $this->assessFreshness($now, $mappingObservedAt),
            BackupTargetBlockerCode::PbsMappingEvidenceMissing,
            BackupTargetBlockerCode::PbsMappingEvidenceStale,
            BackupTargetBlockerCode::PbsMappingEvidenceFuture,
        );
        $status = PbsEndpointMatchStatus::Matched;
        $selected = null;
        if ([] === $byConnection) {
            $status = PbsEndpointMatchStatus::Unresolved;
            $blockers[] = BackupTargetBlockerCode::PbsEndpointUnresolved;
        } elseif (1 !== count($byConnection)) {
            $status = PbsEndpointMatchStatus::Ambiguous;
            $blockers[] = BackupTargetBlockerCode::PbsEndpointAmbiguous;
        } else {
            $selected = array_values($byConnection)[0];
        }
        if (null !== $selected) {
            if (!$this->boolean($selected, 'pbs_connection_enabled')) {
                $blockers[] = BackupTargetBlockerCode::PbsConnectionDisabled;
            }
            if (null === ($selected['pbs_server_id'] ?? null)) {
                $blockers[] = BackupTargetBlockerCode::PbsServerMissing;
            } elseif (null === ($selected['pbs_datastore_id'] ?? null)) {
                $blockers[] = BackupTargetBlockerCode::PbsDatastoreMissing;
            } else {
                if ('active' !== $this->string($selected, 'pbs_datastore_state')) {
                    $blockers[] = BackupTargetBlockerCode::PbsDatastoreArchived;
                }
                if (!$this->boolean($selected, 'allows_backup_writes')) {
                    $blockers[] = BackupTargetBlockerCode::PbsDatastoreReadOnly;
                }
                if (null === ($selected['pbs_namespace_id'] ?? null)) {
                    $blockers[] = BackupTargetBlockerCode::PbsNamespaceMissing;
                } elseif ('active' !== $this->string($selected, 'pbs_namespace_state')) {
                    $blockers[] = BackupTargetBlockerCode::PbsNamespaceArchived;
                }
                if (null === ($selected['semantics'] ?? null)) {
                    $blockers[] = BackupTargetBlockerCode::PbsCapacityMissing;
                } elseif ('local_cache' === $this->string($selected, 'semantics')) {
                    $blockers[] = BackupTargetBlockerCode::PbsRemoteCapacityUnproven;
                }
            }
        }
        if (null !== $selected && (null === ($selected['pbs_server_id'] ?? null)
            || null === ($selected['pbs_datastore_id'] ?? null)
            || null === ($selected['pbs_namespace_id'] ?? null))) {
            $status = PbsEndpointMatchStatus::Unresolved;
            $blockers[] = BackupTargetBlockerCode::PbsEndpointUnresolved;
            $selected = null;
        }
        $capacityObservedAt = null === $selected
            ? null
            : $this->nullableDate($selected['capacity_observed_at'] ?? null);
        array_push($blockers, ...$this->freshnessBlocker(
            $this->assessFreshness($now, $capacityObservedAt),
            BackupTargetBlockerCode::PbsCapacityEvidenceMissing,
            BackupTargetBlockerCode::PbsCapacityEvidenceStale,
            BackupTargetBlockerCode::PbsCapacityEvidenceFuture,
        ));

        return new PbsBackupTargetEvidence(
            $server,
            $port,
            $datastore,
            $namespace,
            $mappingObservedAt,
            $status,
            null === $selected ? null : $this->nullableUuid($selected['pbs_connection_id']),
            null === $selected ? null : $this->nullableUuid($selected['pbs_server_id']),
            null === $selected ? null : $this->nullableUuid($selected['pbs_datastore_id']),
            null === $selected ? null : $this->nullableUuid($selected['pbs_namespace_id']),
            null === $selected ? null : $this->nullableString($selected['semantics'] ?? null),
            null === $selected ? null : $this->nullableDecimal($selected['total_bytes'] ?? null),
            null === $selected ? null : $this->nullableDecimal($selected['used_bytes'] ?? null),
            null === $selected ? null : $this->nullableDecimal($selected['available_bytes'] ?? null),
            $capacityObservedAt,
            $blockers,
        );
    }

    /** @return null|list<string> */
    private function allowlist(mixed $value): ?array
    {
        if (null === $value) {
            return null;
        }
        if (!is_string($value)) {
            throw new RuntimeException('MariaDB returned an invalid PVE storage node allowlist.');
        }
        try {
            $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('MariaDB returned an invalid PVE storage node allowlist.');
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new RuntimeException('MariaDB returned an invalid PVE storage node allowlist.');
        }
        foreach ($decoded as $node) {
            if (!is_string($node) || '' === $node) {
                throw new RuntimeException('MariaDB returned an invalid PVE storage node allowlist.');
            }
        }
        return $decoded;
    }

    /** @return list<string> */
    private function content(mixed $value): array
    {
        $decoded = $this->allowlist($value);
        if (null === $decoded) {
            throw new RuntimeException('MariaDB returned missing PVE storage content.');
        }
        return $decoded;
    }

    /** @param array<string, mixed> $row */
    private function binary(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || 16 !== strlen($value)) {
            throw new RuntimeException('MariaDB returned an invalid target-candidate identifier.');
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private function uuid(array $row, string $key): string
    {
        return $this->uuidBytes($this->binary($row, $key));
    }

    private function nullableUuid(mixed $value): ?string
    {
        return null === $value ? null : $this->uuidBytes($this->binary(['id' => $value], 'id'));
    }

    private function uuidBytes(string $binary): string
    {
        $hex = bin2hex($binary);
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'
            .substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || '' === $value) {
            throw new RuntimeException('MariaDB returned invalid target-candidate text.');
        }
        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!is_string($value) || '' === $value) {
            throw new RuntimeException('MariaDB returned invalid target-candidate text.');
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        throw new RuntimeException('MariaDB returned an invalid target-candidate integer.');
    }

    /** @param array<string, mixed> $row */
    private function boolean(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;
        if (0 === $value || '0' === $value) {
            return false;
        }
        if (1 === $value || '1' === $value) {
            return true;
        }
        throw new RuntimeException('MariaDB returned an invalid target-candidate boolean.');
    }

    private function nullableBoolean(mixed $value): ?bool
    {
        return null === $value ? null : $this->boolean(['value' => $value], 'value');
    }

    private function nullableDecimal(mixed $value): ?UInt64Decimal
    {
        if (null === $value) {
            return null;
        }
        if (is_int($value) && $value >= 0) {
            return new UInt64Decimal((string) $value);
        }
        if (is_string($value) && '' !== $value && ctype_digit($value)) {
            return new UInt64Decimal($value);
        }
        throw new RuntimeException('MariaDB returned invalid target-candidate bytes.');
    }

    private function assessFreshness(DateTimeImmutable $now, ?string $observedAt): EvidenceFreshness
    {
        if (null === $observedAt) {
            return $this->freshness->assess($now, null);
        }
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.u\Z',
            $observedAt,
            new DateTimeZone('UTC'),
        );
        if (false === $date) {
            throw new RuntimeException('MariaDB returned an invalid target-candidate timestamp.');
        }

        return $this->freshness->assess($now, $date);
    }

    /** @return list<BackupTargetBlockerCode> */
    private function freshnessBlocker(
        EvidenceFreshness $freshness,
        BackupTargetBlockerCode $missing,
        BackupTargetBlockerCode $stale,
        BackupTargetBlockerCode $future,
    ): array {
        return match ($freshness) {
            EvidenceFreshness::Fresh => [],
            EvidenceFreshness::Missing => [$missing],
            EvidenceFreshness::Stale => [$stale],
            EvidenceFreshness::Future => [$future],
        };
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
            throw new RuntimeException('MariaDB returned an invalid target-candidate timestamp.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (false === $date || $date->format('Y-m-d H:i:s.u') !== $value) {
            throw new RuntimeException('MariaDB returned an invalid target-candidate timestamp.');
        }
        return $date->format('Y-m-d\TH:i:s.u\Z');
    }
}
