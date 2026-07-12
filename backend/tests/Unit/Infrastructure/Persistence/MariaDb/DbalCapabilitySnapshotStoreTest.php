<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\MariaDb;

use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorLeaseOwnershipLost;
use App\Application\Collector\CollectorWorkerId;
use App\Application\Inventory\Capability\CapabilityProfile;
use App\Application\Inventory\Capability\CapabilitySnapshotConflict;
use App\Application\Inventory\Capability\CapabilitySnapshotObservation;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\InventoryIdentifierGenerator;
use App\Infrastructure\Persistence\MariaDb\DbalCapabilitySnapshotStore;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
final class DbalCapabilitySnapshotStoreTest extends TestCase
{
    private const string NOW = '2026-07-12 10:00:00.000000';

    public function testPersistsANewSnapshotAndLinksItAtomically(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('insert')->with(
            'proxmox_capability_snapshots',
            self::callback(static fn (array $values): bool => 'pve' === $values['product']
                && is_string($values['snapshot_hash'])
                && 32 === strlen($values['snapshot_hash'])
                && '{"legacyMaxFilesSupported":true}' === $values['capabilities_json']),
        );
        $id = (new DbalCapabilitySnapshotStore($database, new FixedCapabilityIdGenerator()))->persist(
            $this->lease(),
            $this->observation(),
        );

        self::assertSame(self::id('snapshot')->binary(), $id->binary());
    }

    public function testReusesAnExactSnapshotAndAcceptsTheExistingRunLink(): void
    {
        $snapshot = $this->snapshotRow();
        $database = $this->database([
            'snapshot' => $snapshot,
            'run' => ['capability_snapshot_id' => $snapshot['id']],
        ]);
        $database->expects(self::never())->method('insert');
        $updates = [];
        $database->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$updates): int {
                $updates[] = $sql;
                return 1;
            },
        );

        $id = (new DbalCapabilitySnapshotStore($database, new FixedCapabilityIdGenerator()))->persist(
            $this->lease(),
            $this->observation(),
        );

        self::assertSame($snapshot['id'], $id->binary());
        self::assertCount(1, $updates);
        self::assertStringContainsString('last_observed_at', $updates[0]);
    }

    public function testAcceptsNumericDatabaseIntegerRepresentations(): void
    {
        $database = $this->database([
            'schedule' => ['lease_fencing_token' => '7'],
            'cycle' => ['fencing_token' => '7'],
            'run' => ['collector_fencing_token' => '7', 'expected_connection_revision' => '1'],
            'configuration' => ['enabled' => '1', 'revision' => '1'],
        ]);

        $id = (new DbalCapabilitySnapshotStore($database, new FixedCapabilityIdGenerator()))->persist(
            $this->lease(),
            $this->observation(),
        );

        self::assertSame(self::id('snapshot')->binary(), $id->binary());
    }

    /** @return iterable<string, array{array<string, mixed>, class-string<\Throwable>}> */
    public static function rejectedRows(): iterable
    {
        yield 'missing schedule' => [['schedule' => false], CollectorLeaseOwnershipLost::class];
        yield 'missing cycle' => [['cycle' => false], CollectorLeaseOwnershipLost::class];
        yield 'expired lease' => [['schedule' => ['lease_expires_at' => '2020-01-01 00:00:00.000000']], CollectorLeaseOwnershipLost::class];
        yield 'wrong lease owner' => [['schedule' => ['lease_owner' => str_repeat('x', 16)]], CollectorLeaseOwnershipLost::class];
        yield 'wrong lease token' => [['schedule' => ['lease_token' => str_repeat('x', 16)]], CollectorLeaseOwnershipLost::class];
        yield 'invalid schedule fence' => [['schedule' => ['lease_fencing_token' => 'invalid']], RuntimeException::class];
        yield 'finished cycle' => [['cycle' => ['status' => 'succeeded']], CollectorLeaseOwnershipLost::class];
        yield 'wrong cycle worker' => [['cycle' => ['worker_instance_id' => str_repeat('x', 16)]], CollectorLeaseOwnershipLost::class];
        yield 'wrong cycle kind' => [['cycle' => ['worker_kind' => 'backup']], CollectorLeaseOwnershipLost::class];
        yield 'missing run' => [['run' => false], CollectorLeaseOwnershipLost::class];
        yield 'wrong run status' => [['run' => ['status' => 'failed']], CollectorLeaseOwnershipLost::class];
        yield 'wrong run token' => [['run' => ['cycle_token' => str_repeat('x', 16)]], CollectorLeaseOwnershipLost::class];
        yield 'invalid run fence' => [['run' => ['collector_fencing_token' => null]], RuntimeException::class];
        yield 'run revision drift' => [['run' => ['expected_connection_revision' => 2]], CapabilitySnapshotConflict::class];
        yield 'run endpoint drift' => [['run' => ['endpoint_id' => str_repeat('x', 16)]], CapabilitySnapshotConflict::class];
        yield 'missing connection' => [['configuration' => false], CapabilitySnapshotConflict::class];
        yield 'wrong product' => [['configuration' => ['product' => 'pbs']], CapabilitySnapshotConflict::class];
        yield 'disabled connection' => [['configuration' => ['enabled' => 0]], CapabilitySnapshotConflict::class];
        yield 'connection revision drift' => [['configuration' => ['revision' => 2]], CapabilitySnapshotConflict::class];
        yield 'invalid database clock type' => [['clock' => 123], RuntimeException::class];
        yield 'invalid database clock value' => [['clock' => 'invalid'], RuntimeException::class];
        yield 'invalid snapshot id' => [['snapshot' => ['id' => 'short']], RuntimeException::class];
        yield 'snapshot metadata conflict' => [['snapshot' => ['product' => 'pbs']], CapabilitySnapshotConflict::class];
        yield 'link lost race' => [['link_update' => 0], CapabilitySnapshotConflict::class];
        yield 'non-binary existing link' => [['run' => ['capability_snapshot_id' => 123]], CapabilitySnapshotConflict::class];
        yield 'different existing link' => [['run' => ['capability_snapshot_id' => str_repeat('x', 16)]], CapabilitySnapshotConflict::class];
    }

    /**
     * @param array<string, mixed> $options
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('rejectedRows')]
    public function testRejectsLostFencesDriftAndInvalidDatabaseRows(array $options, string $exception): void
    {
        if (isset($options['snapshot']) && is_array($options['snapshot'])) {
            $options['snapshot'] = array_replace($this->snapshotRow(), $options['snapshot']);
        }
        $this->expectException($exception);
        (new DbalCapabilitySnapshotStore($this->database($options), new FixedCapabilityIdGenerator()))->persist(
            $this->lease(),
            $this->observation(),
        );
    }

    /** @param array<string, mixed> $options */
    private function database(array $options = []): Connection&MockObject
    {
        $lease = $this->lease();
        $options += [
            'schedule' => [],
            'cycle' => [],
            'run' => [],
            'configuration' => [],
            'snapshot' => false,
            'clock' => self::NOW,
            'link_update' => 1,
        ];
        $schedule = false === $options['schedule'] ? false : array_replace([
            'lease_owner' => $lease->ownerId->bytes,
            'lease_token' => $lease->token->binary(),
            'lease_fencing_token' => 7,
            'lease_expires_at' => '2099-01-01 00:00:00.000000',
        ], is_array($options['schedule']) ? $options['schedule'] : []);
        $cycle = false === $options['cycle'] ? false : array_replace([
            'status' => 'running',
            'worker_instance_id' => $lease->ownerId->bytes,
            'worker_kind' => 'collector',
            'fencing_token' => 7,
        ], is_array($options['cycle']) ? $options['cycle'] : []);
        $run = false === $options['run'] ? false : array_replace([
            'status' => 'running',
            'cycle_token' => $lease->token->binary(),
            'collector_fencing_token' => 7,
            'expected_connection_revision' => 1,
            'endpoint_id' => self::id('endpoint')->binary(),
            'capability_snapshot_id' => null,
        ], is_array($options['run']) ? $options['run'] : []);
        $configuration = false === $options['configuration'] ? false : array_replace([
            'product' => 'pve', 'enabled' => 1, 'revision' => 1,
        ], is_array($options['configuration']) ? $options['configuration'] : []);
        $linkUpdate = $options['link_update'];
        if (!is_int($linkUpdate)) {
            throw new RuntimeException('The test link update result is invalid.');
        }

        $database = $this->createMock(Connection::class);
        $database->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation($database),
        );
        $database->method('fetchAssociative')->willReturnCallback(
            static function (string $sql) use ($schedule, $cycle, $run, $configuration, $options): array|false {
                return match (true) {
                    str_contains($sql, 'FROM collector_schedule') => $schedule,
                    str_contains($sql, 'FROM collector_cycles') => $cycle,
                    str_contains($sql, 'FROM inventory_sync_runs') => $run,
                    str_contains($sql, 'FROM proxmox_connections') => $configuration,
                    str_contains($sql, 'FROM proxmox_capability_snapshots') => is_array($options['snapshot'])
                        ? $options['snapshot'] : false,
                    default => false,
                };
            },
        );
        $database->method('fetchOne')->willReturn($options['clock']);
        $database->method('executeStatement')->willReturnCallback(
            static fn (string $sql): int => str_contains($sql, 'UPDATE inventory_sync_runs')
                ? $linkUpdate : 1,
        );

        return $database;
    }

    /** @return array<string, mixed> */
    private function snapshotRow(): array
    {
        return [
            'id' => self::id('snapshot')->binary(),
            'endpoint_id' => self::id('endpoint')->binary(),
            'product' => 'pve',
            'version_major' => 8,
            'version_minor' => 2,
            'version_patch' => 0,
            'release_name' => '1',
            'raw_version' => '8.2.0',
            'profile_version' => 1,
            'capabilities_json' => '{"legacyMaxFilesSupported":true}',
        ];
    }

    private function observation(): CapabilitySnapshotObservation
    {
        return new CapabilitySnapshotObservation(
            self::id('run'),
            self::id('connection'),
            1,
            new EndpointId(self::id('endpoint')->binary()),
            new CapabilityProfile(
                ProxmoxProduct::Pve,
                8,
                2,
                0,
                '1',
                '8.2.0',
                ['legacyMaxFilesSupported' => true],
            ),
            new DateTimeImmutable('2026-07-12T10:00:00Z'),
        );
    }

    private function lease(): CollectorLease
    {
        return new CollectorLease(
            new CollectorWorkerId(self::id('worker')->binary()),
            new CollectorCycleToken(self::id('cycle')->binary()),
            7,
            new DateTimeImmutable('2099-01-01T00:00:00Z'),
        );
    }

    private static function id(string $label): InventoryIdentifier
    {
        return new InventoryIdentifier(substr(hash('sha256', $label, true), 0, 16));
    }
}

final class FixedCapabilityIdGenerator implements InventoryIdentifierGenerator
{
    public function generate(): InventoryIdentifier
    {
        return new InventoryIdentifier(substr(hash('sha256', 'snapshot', true), 0, 16));
    }
}
