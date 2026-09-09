<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\MariaDb;

use App\Application\Configuration\Target\TargetCandidateEvidence;
use App\Application\Configuration\Target\TargetCandidateEvidenceProvider;
use App\Application\Configuration\Target\TargetExecutorEvidenceProvider;
use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Target\ReadModel\ConfiguredBackupTargetQuery;
use App\Infrastructure\Persistence\MariaDb\DbalConfiguredBackupTargetReadModel;
use App\Domain\Scheduler\EvidenceFreshnessPolicy;
use App\Domain\Shared\Clock;
use App\Domain\Target\ActivationEvidenceObservation;
use App\Domain\Target\BackupTargetId;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DbalConfiguredBackupTargetReadModelTest extends TestCase
{
    public function testLargePageUsesExactlyOneTargetAndOneAllowedNodeQuery(): void
    {
        $rows = [];
        $nodes = [];
        for ($index = 0; $index < 101; ++$index) {
            $id = pack('N4', 0, 0, 0, $index + 1);
            $rows[] = $this->row($id, sprintf('Target %03d', $index));
            if ($index < 100) {
                $nodes[] = [
                    'target_id' => $id,
                    'id' => pack('N4', 1, 0, 0, $index + 1),
                    'node_name' => sprintf('node-%03d', $index),
                ];
            }
        }
        $queries = 0;
        $database = $this->createMock(Connection::class);
        $database->expects(self::exactly(2))->method('fetchAllAssociative')
            ->willReturnCallback(function (string $sql, array $parameters) use (&$queries, $rows, $nodes): array {
                ++$queries;
                if (str_contains($sql, 'FROM backup_targets')) {
                    self::assertSame(101, $parameters['limit']);
                    return $rows;
                }
                $targetIds = $parameters['target_ids'] ?? null;
                self::assertIsArray($targetIds);
                self::assertCount(100, $targetIds);
                return $nodes;
            });

        $page = $this->model($database)->targets(
            new ConfiguredBackupTargetQuery(new PageRequest(100), 'Target', false),
        );

        self::assertSame(2, $queries);
        self::assertCount(100, $page->items);
        self::assertNotNull($page->nextCursor);
        self::assertSame('node-099', $page->items[99]->allowedNodes[0]->name);
        self::assertTrue($page->items[99]->toArray()['canEnable']);
    }

    public function testFiltersAndCursorAreBoundAndEscapedWithoutExtraQueriesForEmptyPage(): void
    {
        $context = (new ConfiguredBackupTargetQuery(new PageRequest(), '50%_\\', false))->cursorContext();
        $cursor = PageCursor::resource($context, 'Target', '00112233-4455-6677-8899-aabbccddeeff');
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('fetchAllAssociative')
            ->with(
                self::callback(static fn (string $sql): bool => str_contains($sql, 'target.status = :status')
                    && str_contains($sql, 'target.display_name LIKE :search')
                    && str_contains($sql, 'target.id > :cursor_id')),
                self::callback(static fn (array $parameters): bool => 'disabled' === $parameters['status']
                    && '%50\\%\\_\\\\%' === $parameters['search']),
                self::anything(),
            )->willReturn([]);

        $page = $this->model($database)->targets(
            new ConfiguredBackupTargetQuery(new PageRequest(5, $cursor), '50%_\\', false),
        );
        self::assertSame([], $page->items);
        self::assertNull($page->nextCursor);
    }

    public function testUnknownStatusFailsClosed(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('fetchAllAssociative')
            ->willReturn([$this->row(str_repeat('i', 16), 'Target', 'unknown')]);

        $this->expectException(RuntimeException::class);
        $this->model($database)->targets(
            new ConfiguredBackupTargetQuery(new PageRequest(1)),
        );
    }

    public function testRevisionOutsidePhpIntegerRangeFailsClosedBeforeNodeQuery(): void
    {
        $row = $this->row(str_repeat('i', 16), 'Target');
        $row['revision'] = '18446744073709551615';
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('fetchAllAssociative')->willReturn([$row]);

        $this->expectException(RuntimeException::class);
        $this->model($database)->targets(
            new ConfiguredBackupTargetQuery(new PageRequest(1)),
        );
    }

    /** @return array<string, mixed> */
    private function row(string $id, string $name, string $status = 'disabled'): array
    {
        return [
            'id' => $id,
            'connection_id' => str_repeat('c', 16),
            'cluster_id' => str_repeat('k', 16),
            'storage_id' => str_repeat('s', 16),
            'display_name' => $name,
            'status' => $status,
            'revision' => '1',
            'minimum_free_bytes' => '18446744073709551615',
            'fixed_parallel_limit' => '2',
            'pbs_connection_id' => null,
            'pbs_datastore_id' => null,
            'pbs_namespace_id' => null,
            'disabled_at' => '2026-07-12 10:00:00.000000',
            'connection_name' => 'PVE',
            'cluster_name' => 'cluster-a',
            'storage_name' => 'backup',
            'storage_type' => 'dir',
        ];
    }

    private function model(Connection $connection): DbalConfiguredBackupTargetReadModel
    {
        $evidence = new ConfiguredTargetProjectionEvidence();
        return new DbalConfiguredBackupTargetReadModel($connection, $evidence, $evidence,
            new ConfiguredTargetProjectionClock(), new EvidenceFreshnessPolicy());
    }
}

final class ConfiguredTargetProjectionEvidence implements TargetCandidateEvidenceProvider, TargetExecutorEvidenceProvider
{
    private function observation(): ActivationEvidenceObservation
    {
        return new ActivationEvidenceObservation(true, new DateTimeImmutable('2026-07-12T10:00:00Z'));
    }
    public function candidateEvidence(BackupTargetId $id): TargetCandidateEvidence
    {
        $observation = $this->observation();
        return new TargetCandidateEvidence($observation, $observation, $observation);
    }
    public function candidateEvidenceBatch(array $ids): array
    {
        $result = [];
        foreach ($ids as $id) $result[$id->toHex()] = $this->candidateEvidence($id);
        return $result;
    }
    public function executorEvidence(BackupTargetId $id): ActivationEvidenceObservation
    {
        return $this->observation();
    }
    public function executorEvidenceBatch(array $ids): array
    {
        $result = [];
        foreach ($ids as $id) $result[$id->toHex()] = $this->executorEvidence($id);
        return $result;
    }
}

final readonly class ConfiguredTargetProjectionClock implements Clock
{
    public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-07-12T10:00:00Z'); }
}
