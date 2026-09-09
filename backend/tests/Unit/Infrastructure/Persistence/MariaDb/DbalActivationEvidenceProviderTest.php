<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\MariaDb;

use App\Domain\Policy\PolicyId;
use App\Domain\Target\BackupTargetId;
use App\Infrastructure\Persistence\MariaDb\DbalActivationEvidenceProvider;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DbalActivationEvidenceProviderTest extends TestCase
{
    public function testExecutorEvidenceIsBoundToTheTargetsConnectionAndCluster(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAllAssociative')
            ->with(self::callback(static fn (string $sql): bool =>
                str_contains($sql, 'target.connection_id=allowed.connection_id')
                && str_contains($sql, 'target.cluster_id=allowed.cluster_id')
                && str_contains($sql, 'evidence.storage_id=target.storage_id')))
            ->willReturn([]);

        $evidence = (new DbalActivationEvidenceProvider($connection))->executorEvidence(
            new BackupTargetId(str_repeat("\x01", 16)),
        );

        self::assertNull($evidence->accepted);
        self::assertNull($evidence->observedAt);
    }

    public function testCandidateEvidenceUsesOldestInventoryTimestampAndContextBoundJoins(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAllAssociative')
            ->with(self::callback(static fn (string $sql): bool =>
                str_contains($sql, 'cluster.last_seen_at AS cluster_observed_at')
                && str_contains($sql, 'MIN(node.last_seen_at) AS node_observed_at')
                && str_contains($sql, 'MAX(node.last_seen_at) AS node_newest_observed_at')
                && str_contains($sql, 'JSON_CONTAINS(storage.content_json')
                && str_contains($sql, 'JSON_CONTAINS(storage.node_allowlist_json')
                && str_contains($sql, 'cluster.connection_id=target.connection_id')
                && str_contains($sql, 'storage.connection_id=target.connection_id')
                && str_contains($sql, 'storage.cluster_id=target.cluster_id')
                && str_contains($sql, 'allowed.connection_id=target.connection_id')
                && str_contains($sql, 'allowed.cluster_id=target.cluster_id')))
            ->willReturn([]);

        $evidence = (new DbalActivationEvidenceProvider($connection))->candidateEvidence(
            new BackupTargetId(str_repeat("\x02", 16)),
        );

        self::assertNull($evidence->candidate->accepted);
        self::assertNull($evidence->inventory->accepted);
        self::assertNull($evidence->capacity->accepted);
    }

    public function testExecutorBatchUsesOneQueryAndMapsOutOfOrderRowsWithoutInventingMissingEvidence(): void
    {
        $first = new BackupTargetId(str_repeat("\x11", 16));
        $second = new BackupTargetId(str_repeat("\x22", 16));
        $missing = new BackupTargetId(str_repeat("\x33", 16));
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAllAssociative')->willReturn([
            [
                'target_id' => $second->binary(), 'expected_count' => '1', 'evidence_count' => '1',
                'accepted' => '1', 'observed_at' => '2026-07-12 10:00:00.000000',
                'newest_observed_at' => '2026-07-12 10:00:00.000000',
            ],
            [
                'target_id' => $first->binary(), 'expected_count' => '1', 'evidence_count' => '1',
                'accepted' => '0', 'observed_at' => '2026-07-12 09:59:00.000000',
                'newest_observed_at' => '2026-07-12 09:59:30.000000',
            ],
        ]);

        $result = (new DbalActivationEvidenceProvider($connection))->executorEvidenceBatch([
            $first, $second, $missing,
        ]);

        self::assertSame([$first->toHex(), $second->toHex(), $missing->toHex()], array_keys($result));
        self::assertFalse($result[$first->toHex()]->accepted);
        self::assertTrue($result[$second->toHex()]->accepted);
        self::assertNull($result[$missing->toHex()]->accepted);
        self::assertSame('2026-07-12T09:59:00+00:00', $result[$first->toHex()]->observedAt?->format(DATE_ATOM));
        self::assertSame('2026-07-12T09:59:30+00:00', $result[$first->toHex()]->newestObservedAt?->format(DATE_ATOM));
    }

    public function testBatchRejectsEvidenceForAnUnrequestedTarget(): void
    {
        $requested = new BackupTargetId(str_repeat("\x44", 16));
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAllAssociative')->willReturn([[
            'target_id' => str_repeat("\x55", 16), 'expected_count' => '1', 'evidence_count' => '1',
            'accepted' => '1', 'observed_at' => '2026-07-12 10:00:00.000000',
            'newest_observed_at' => '2026-07-12 10:00:00.000000',
        ]]);

        $this->expectException(RuntimeException::class);
        (new DbalActivationEvidenceProvider($connection))->executorEvidenceBatch([$requested]);
    }

    public function testCandidateBatchUsesOneQueryAndMapsOutOfOrderRowsAndMissingIds(): void
    {
        $first = new BackupTargetId(str_repeat("\x61", 16));
        $second = new BackupTargetId(str_repeat("\x62", 16));
        $missing = new BackupTargetId(str_repeat("\x63", 16));
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAllAssociative')->willReturn([
            $this->candidateRow($second->binary(), '2026-07-12 10:00:00.000000'),
            $this->candidateRow($first->binary(), '2026-07-12 09:59:00.000000'),
        ]);

        $result = (new DbalActivationEvidenceProvider($connection))->candidateEvidenceBatch([
            $first, $second, $missing,
        ]);

        self::assertSame([$first->toHex(), $second->toHex(), $missing->toHex()], array_keys($result));
        self::assertTrue($result[$first->toHex()]->candidate->accepted);
        self::assertTrue($result[$second->toHex()]->capacity->accepted);
        self::assertNull($result[$missing->toHex()]->inventory->accepted);
        self::assertSame(
            '2026-07-12T09:59:00+00:00',
            $result[$first->toHex()]->candidate->newestObservedAt?->format(DATE_ATOM),
        );
    }

    public function testPolicyEvidenceOnlyJoinsATargetAndStorageFromThePolicyContext(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAllAssociative')
            ->with(self::callback(static fn (string $sql): bool =>
                str_contains($sql, 'target.connection_id=policy.connection_id')
                && str_contains($sql, 'target.cluster_id=policy.cluster_id')
                && str_contains($sql, 'storage.connection_id=target.connection_id')
                && str_contains($sql, 'storage.cluster_id=target.cluster_id')))
            ->willReturn([]);

        $evidence = (new DbalActivationEvidenceProvider($connection))->policyEvidence(
            new PolicyId(str_repeat("\x03", 16)),
        );

        self::assertNull($evidence->pveMajor);
        self::assertNull($evidence->target->accepted);
        self::assertNull($evidence->executor->accepted);
    }

    /** @return array<string, mixed> */
    private function candidateRow(string $id, string $observedAt): array
    {
        return [
            'target_id' => $id,
            'connection_enabled' => '1',
            'connection_product' => 'pve',
            'cluster_state' => 'active',
            'cluster_observed_at' => $observedAt,
            'supports_backup' => '1',
            'backup_content_configured' => '1',
            'storage_disabled' => '0',
            'storage_state' => 'active',
            'storage_observed_at' => $observedAt,
            'minimum_free_bytes' => '100',
            'storage_type' => 'dir',
            'pbs_connection_id' => null,
            'pbs_datastore_id' => null,
            'pbs_namespace_id' => null,
            'pbs_mapping_namespace' => null,
            'allowed_count' => '1',
            'node_count' => '1',
            'state_count' => '1',
            'nodes_online' => '1',
            'nodes_active_inventory' => '1',
            'nodes_storage_configured' => '1',
            'node_observed_at' => $observedAt,
            'node_newest_observed_at' => $observedAt,
            'nodes_enabled' => '1',
            'nodes_active' => '1',
            'available_bytes' => '1000',
            'capacity_observed_at' => $observedAt,
            'capacity_newest_observed_at' => $observedAt,
        ];
    }
}
