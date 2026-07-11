<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\MariaDb;

use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorWorkerId;
use App\Application\Inventory\Connection\ConnectionReadFailureCode;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\InventoryIdentifierGenerator;
use App\Application\Inventory\Pve\EndpointAttemptOutcome;
use App\Application\Inventory\Pve\InventoryScopeStatus;
use App\Application\Inventory\Pve\PveCoreBindingKind;
use App\Application\Inventory\Pve\PveCoreInstallationBinding;
use App\Application\Inventory\Pve\PveCoreInventoryCommit;
use App\Application\Inventory\Pve\PveCoreScope;
use App\Application\Inventory\Pve\PveCoreScopeResult;
use App\Application\Inventory\Pve\PveEndpointAttempt;
use App\Application\Inventory\Pve\PveGuestObservation;
use App\Application\Inventory\Pve\PveNodeObservation;
use App\Application\Inventory\Pve\PveSyncRunStart;
use App\Application\Inventory\Pve\PveSyncRunFailure;
use App\Application\Proxmox\Pve\PveGuestType;
use App\Infrastructure\Persistence\MariaDb\DbalPveCoreInventoryStore;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class DbalPveCoreInventoryStoreTest extends TestCase
{
    private const string NOW = '2026-07-11 12:00:00.000000';

    public function testBeginAttemptAndFirstAuthoritativeApplyUseConcreteFencedTransactions(): void
    {
        $lease = $this->lease();
        $run = $this->id('r');
        $connectionId = $this->id('c');
        $endpoint = $this->id('e');

        $begin = $this->store($this->database('begin'));
        $begin->beginRun($lease, new PveSyncRunStart($run, $connectionId, 1, $this->now()));

        $attemptStore = $this->store($this->database('attempt'));
        $attemptStore->recordEndpointAttempt($lease, new PveEndpointAttempt(
            $this->id('a'), $run, $connectionId, $endpoint, 1,
            EndpointAttemptOutcome::Selected, null, $this->now(), $this->now(),
        ));

        $apply = $this->store($this->database('first'));
        $result = $apply->apply($lease, $this->commit($run, $connectionId, $endpoint, true));
        self::assertSame('succeeded', $result->status);
        self::assertSame(3, $result->created);
        self::assertSame(0, $result->updated);
        self::assertSame(0, $result->archived);
        self::assertFalse($result->diagnosticOnly);
    }

    public function testExistingInventoryUpdatesMovesArchivesAndKeepsStrictAuthority(): void
    {
        $result = $this->store($this->database('existing'))->apply(
            $this->lease(),
            $this->commit($this->id('r'), $this->id('c'), $this->id('e'), true),
        );
        self::assertSame(0, $result->created);
        self::assertSame(3, $result->updated);
        self::assertSame(2, $result->archived);
    }

    public function testFirstPartialSnapshotIsDiagnosticOnlyAndNeverCreatesBindingOrCore(): void
    {
        $result = $this->store($this->database('diagnostic'))->apply(
            $this->lease(),
            $this->commit($this->id('r'), $this->id('c'), $this->id('e'), false),
        );
        self::assertSame('partial', $result->status);
        self::assertTrue($result->diagnosticOnly);
        self::assertSame(0, $result->created);
        self::assertSame(0, $result->archived);
    }

    public function testFinishWithoutSnapshotUsesTheSameFenceAndPersistsOnlyTheTypedCode(): void
    {
        $this->store($this->database('terminal'))->finishWithoutSnapshot(
            $this->lease(),
            new PveSyncRunFailure(
                $this->id('r'),
                $this->id('c'),
                1,
                ConnectionReadFailureCode::EndpointsExhausted,
                $this->now(),
            ),
        );

        self::addToAssertionCount(1);
    }

    private function store(Connection $database): DbalPveCoreInventoryStore
    {
        return new DbalPveCoreInventoryStore($database, new SequenceInventoryIdentifierGenerator([
            $this->id('x'), $this->id('y'), $this->id('z'),
        ]));
    }

    private function database(string $scenario): Connection&MockObject
    {
        $database = $this->createMock(Connection::class);
        $database->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation($database),
        );
        $database->method('fetchOne')->willReturnCallback(static function (string $sql): string|int {
            if ('SELECT UTC_TIMESTAMP(6)' === $sql) {
                return self::NOW;
            }
            if (str_contains($sql, 'inventory_sync_endpoint_attempts')) {
                return 1;
            }
            return 0;
        });
        $database->method('fetchAssociative')->willReturnCallback(
            function (string $sql) use ($scenario): array|false {
                if (str_contains($sql, 'collector_schedule')) {
                    return $this->scheduleRow();
                }
                if (str_contains($sql, 'collector_cycles')) {
                    return $this->cycleRow();
                }
                if (str_contains($sql, 'inventory_sync_runs')) {
                    return $this->runRow('attempt' === $scenario ? null : $this->id('e')->binary());
                }
                if (str_contains($sql, 'proxmox_connections')) {
                    return ['product' => 'pve', 'enabled' => 1, 'revision' => 1];
                }
                if (str_contains($sql, 'proxmox_connection_endpoints')) {
                    return ['id' => $this->id('e')->binary()];
                }
                if (str_contains($sql, 'proxmox_installation_bindings')) {
                    return in_array($scenario, ['existing'], true) ? [
                        'product' => 'pve',
                        'identity_kind' => 'pve_cluster',
                        'identity_value' => 'cluster-a',
                    ] : false;
                }
                if (str_contains($sql, 'pve_clusters')) {
                    return 'existing' === $scenario ? ['id' => $this->id('k')->binary()] : false;
                }
                if (str_contains($sql, 'pve_nodes')) {
                    return 'existing' === $scenario ? ['id' => $this->id('n')->binary()] : false;
                }
                if (str_contains($sql, 'FROM guests')) {
                    return 'existing' === $scenario ? ['id' => $this->id('g')->binary()] : false;
                }
                return false;
            },
        );
        $database->method('fetchFirstColumn')->willReturn('existing' === $scenario ? ['node-a'] : []);
        $database->method('fetchAllAssociative')->willReturnCallback(
            function (string $sql) use ($scenario): array {
                if ('existing' !== $scenario) {
                    return [];
                }
                if (str_contains($sql, 'FROM guests')) {
                    return [['id' => $this->id('u')->binary()]];
                }
                if (str_contains($sql, 'FROM pve_nodes')) {
                    return [['id' => $this->id('v')->binary()]];
                }
                return [];
            },
        );
        $database->method('update')->willReturn(1);
        $database->method('delete')->willReturn(1);
        $database->method('executeStatement')->willReturn(1);
        return $database;
    }

    /** @return array<string, mixed> */
    private function scheduleRow(): array
    {
        return [
            'lease_owner' => $this->id('w')->binary(),
            'lease_token' => $this->id('t')->binary(),
            'lease_fencing_token' => 1,
            'lease_expires_at' => '2026-07-11 12:10:00.000000',
        ];
    }

    /** @return array<string, mixed> */
    private function cycleRow(): array
    {
        return [
            'status' => 'running',
            'worker_instance_id' => $this->id('w')->binary(),
            'worker_kind' => 'collector',
            'fencing_token' => 1,
        ];
    }

    /** @return array<string, mixed> */
    private function runRow(?string $endpointId): array
    {
        return [
            'status' => 'running',
            'cycle_token' => $this->id('t')->binary(),
            'collector_fencing_token' => 1,
            'expected_connection_revision' => 1,
            'endpoint_id' => $endpointId,
            'applied_at' => null,
        ];
    }

    private function commit(
        InventoryIdentifier $run,
        InventoryIdentifier $connection,
        InventoryIdentifier $endpoint,
        bool $complete,
    ): PveCoreInventoryCommit {
        return new PveCoreInventoryCommit(
            $run,
            $connection,
            $endpoint,
            1,
            new PveCoreInstallationBinding(PveCoreBindingKind::Cluster, 'cluster-a'),
            new PveCoreScopeResult(PveCoreScope::Topology, InventoryScopeStatus::Complete),
            new PveCoreScopeResult(
                PveCoreScope::Guests,
                $complete ? InventoryScopeStatus::Complete : InventoryScopeStatus::Partial,
            ),
            [new PveNodeObservation('node-a', 'online')],
            [new PveGuestObservation(PveGuestType::Qemu, 100, 'node-a', 'guest', false)],
            $this->now(),
        );
    }

    private function lease(): CollectorLease
    {
        return new CollectorLease(
            new CollectorWorkerId($this->id('w')->binary()),
            new CollectorCycleToken($this->id('t')->binary()),
            1,
            new DateTimeImmutable('2026-07-11T12:10:00+00:00'),
        );
    }

    private function id(string $byte): InventoryIdentifier
    {
        return new InventoryIdentifier(str_repeat($byte, 16));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-11T12:00:00+00:00');
    }
}

final class SequenceInventoryIdentifierGenerator implements InventoryIdentifierGenerator
{
    /** @param list<InventoryIdentifier> $identifiers */
    public function __construct(private array $identifiers)
    {
    }

    public function generate(): InventoryIdentifier
    {
        $identifier = array_shift($this->identifiers);
        TestCase::assertInstanceOf(InventoryIdentifier::class, $identifier);
        return $identifier;
    }
}
