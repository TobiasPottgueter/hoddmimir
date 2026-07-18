<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Backup\Execution\ReadModel\ExecutorPermissionEvidenceQuery;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use App\Infrastructure\Persistence\MariaDb\DbalExecutorPermissionEvidenceReadModel;
use App\Tests\Fakes\FrozenClock;
use DateTimeImmutable;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\DataProvider;

final class DbalExecutorPermissionEvidenceReadModelTest extends DatabaseTestCase
{
    private const string NOW = '2026-07-18T10:05:00.000000Z';
    private const string OBSERVED = '2026-07-18 10:00:00.000000';
    private const string CONNECTION = 'a1000000-0000-4000-8000-000000000001';
    private const string CLUSTER = 'a3000000-0000-4000-8000-000000000001';
    private const string NODE_A = 'a4000000-0000-4000-8000-000000000001';
    private const string NODE_B = 'a4000000-0000-4000-8000-000000000002';
    private const string QEMU = 'a5000000-0000-4000-8000-000000000101';
    private const string LXC = 'a5000000-0000-4000-8000-000000000201';
    private const string STORAGE = 'a6000000-0000-4000-8000-000000000001';
    private const string TARGET = 'a7000000-0000-4000-8000-000000000001';

    public function testCurrentGuestRowsArePagedWithoutActivationRowsAndProjectFreshnessAndPermissions(): void
    {
        $this->seed();
        $model = $this->model();

        $first = $model->evidence(new ExecutorPermissionEvidenceQuery(new PageRequest(1)));
        self::assertCount(1, $first->items);
        self::assertNotNull($first->nextCursor);
        $second = $model->evidence(new ExecutorPermissionEvidenceQuery(new PageRequest(1, $first->nextCursor)));
        self::assertCount(1, $second->items);
        self::assertNull($second->nextCursor);

        $items = [...$first->items, ...$second->items];
        self::assertEqualsCanonicalizing([self::QEMU, self::LXC], array_column(array_map(
            static fn ($item): array => $item->toArray(),
            $items,
        ), 'guestId'));
        $byGuest = [];
        foreach ($items as $item) {
            $byGuest[$item->guestId] = $item->toArray();
        }
        self::assertSame('fresh', $byGuest[self::QEMU]['freshness']);
        self::assertSame([], $byGuest[self::QEMU]['missingPermissions']);
        self::assertTrue($byGuest[self::QEMU]['authorized']);
        self::assertSame('stale', $byGuest[self::LXC]['freshness']);
        self::assertSame(['Datastore.AllocateSpace'], $byGuest[self::LXC]['missingPermissions']);
        self::assertFalse($byGuest[self::LXC]['authorized']);
        self::assertSame('7', $byGuest[self::LXC]['evidenceSetRevision']);
        foreach (['id', 'endpointId', 'connectionId', 'clusterId', 'targetId', 'nodeId', 'storageId', 'guestId'] as $key) {
            $value = $byGuest[self::LXC][$key] ?? null;
            self::assertIsString($value);
            self::assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $value);
        }
    }

    public function testEveryFilterIsAppliedTogetherAndContinuationContextCannotBeReused(): void
    {
        $this->seed();
        $model = $this->model();
        $identifier = static fn (string $value): ReadModelIdentifier => new ReadModelIdentifier($value);
        $query = new ExecutorPermissionEvidenceQuery(
            new PageRequest(1),
            $identifier(self::CONNECTION),
            $identifier(self::CLUSTER),
            $identifier(self::TARGET),
            $identifier(self::NODE_B),
            $identifier(self::LXC),
        );
        $page = $model->evidence($query);
        self::assertCount(1, $page->items);
        self::assertSame(self::LXC, $page->items[0]->guestId);
        self::assertNull($page->nextCursor);

        $missing = $model->evidence(new ExecutorPermissionEvidenceQuery(
            new PageRequest(10), guestId: $identifier('ffffffff-ffff-4fff-8fff-ffffffffffff'),
        ));
        self::assertSame([], $missing->items);
    }

    /** @return iterable<string, array{string}> */
    public static function unsignedSetRevisions(): iterable
    {
        yield 'above signed bigint' => ['9223372036854775808'];
        yield 'maximum uint64' => ['18446744073709551615'];
    }

    #[DataProvider('unsignedSetRevisions')]
    public function testUnsignedSetRevisionRemainsAnExactDecimalString(string $revision): void
    {
        $this->seed($revision);

        $page = $this->model()->evidence(new ExecutorPermissionEvidenceQuery(new PageRequest(10)));
        self::assertCount(2, $page->items);
        foreach ($page->items as $item) {
            self::assertSame($revision, $item->evidenceSetRevision->value);
            $json = json_encode($item->toArray(), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('"evidenceSetRevision":"'.$revision.'"', $json);
        }
    }

    private function model(): DbalExecutorPermissionEvidenceReadModel
    {
        return new DbalExecutorPermissionEvidenceReadModel(
            $this->connection(),
            new FrozenClock(new DateTimeImmutable(self::NOW)),
            300,
        );
    }

    private function seed(string $setRevision = '7'): void
    {
        $connection = $this->binary(self::CONNECTION);
        $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $this->connection()->insert('proxmox_connections', [
                'id' => $connection,
                'display_name' => 'Executor evidence fixture',
                'product' => 'pve',
                'enabled' => 1,
                'revision' => 1,
                'created_at' => self::OBSERVED,
                'updated_at' => self::OBSERVED,
            ], ['id' => ParameterType::BINARY]);
            $this->seedExecutorEvidenceFixtureConfiguration($connection);
            foreach ([
                ['guest' => self::QEMU, 'node' => self::NODE_A, 'vm' => 1, 'datastore' => 1, 'observed' => self::OBSERVED],
                ['guest' => self::LXC, 'node' => self::NODE_B, 'vm' => 1, 'datastore' => 0, 'observed' => '2026-07-18 09:59:59.999999'],
                ['guest' => null, 'node' => self::NODE_A, 'vm' => 1, 'datastore' => 1, 'observed' => self::OBSERVED],
            ] as $index => $row) {
                $this->connection()->insert('executor_permission_evidence', [
                    'id' => substr(hash('sha256', 'executor-read-'.$index, true), 0, 16),
                    'connection_id' => $connection,
                    'cluster_id' => $this->binary(self::CLUSTER),
                    'target_id' => $this->binary(self::TARGET),
                    'node_id' => $this->binary($row['node']),
                    'storage_id' => $this->binary(self::STORAGE),
                    'guest_id' => null === $row['guest'] ? null : $this->binary($row['guest']),
                    'vm_backup_authorized' => $row['vm'],
                    'datastore_allocate_authorized' => $row['datastore'],
                    'authorized' => $row['datastore'],
                    'observed_at' => $row['observed'],
                    'revision' => 1,
                ], [
                    'id' => ParameterType::BINARY,
                    'connection_id' => ParameterType::BINARY,
                    'cluster_id' => ParameterType::BINARY,
                    'target_id' => ParameterType::BINARY,
                    'node_id' => ParameterType::BINARY,
                    'storage_id' => ParameterType::BINARY,
                    'guest_id' => ParameterType::BINARY,
                ]);
            }
            $this->publishExecutorEvidenceFixture($connection);
            $this->connection()->executeStatement(
                'UPDATE executor_permission_evidence SET evidence_set_revision = :revision WHERE connection_id = :connection',
                ['revision' => $setRevision, 'connection' => $connection],
                ['revision' => ParameterType::STRING, 'connection' => ParameterType::BINARY],
            );
            $this->connection()->executeStatement(
                'UPDATE executor_evidence_refresh_state SET published_set_revision = :revision WHERE connection_id = :connection',
                ['revision' => $setRevision, 'connection' => $connection],
                ['revision' => ParameterType::STRING, 'connection' => ParameterType::BINARY],
            );
        } finally {
            $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function binary(string $uuid): string
    {
        $binary = hex2bin(str_replace('-', '', $uuid));
        self::assertIsString($binary);

        return $binary;
    }
}
