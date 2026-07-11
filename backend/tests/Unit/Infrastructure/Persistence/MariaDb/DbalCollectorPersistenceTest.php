<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\MariaDb;

use App\Application\Collector\CollectorCycleStatus;
use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorScheduleConfigurationMismatch;
use App\Application\Collector\CollectorWorkerId;
use App\Application\Collector\CollectorWorkerStatus;
use App\Infrastructure\Persistence\MariaDb\DbalCollectorHeartbeatStore;
use App\Infrastructure\Persistence\MariaDb\DbalCollectorScheduleStore;
use App\Infrastructure\Time\SystemMonotonicClock;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
final class DbalCollectorPersistenceTest extends TestCase
{
    private const string NOW = '2026-07-11 10:00:00.000000';

    public function testBootstrapCreatesImmediateScheduleAndRestoresThePersistentAnchor(): void
    {
        $createdRow = $this->scheduleRow([
            'grid_started_at' => self::NOW,
            'next_scan_at' => self::NOW,
        ]);
        $missing = $this->connection($createdRow);
        $missing->expects(self::once())->method('executeStatement')->with(
            self::stringContains('ON DUPLICATE KEY UPDATE'),
            ['schedule_name' => 'inventory', 'interval_seconds' => 120],
        )->willReturn(1);
        $created = (new DbalCollectorScheduleStore($missing))->bootstrap(120);
        self::assertSame($created->grid->startedAt()->format('U.u'), $created->nextScanAt->format('U.u'));

        $existing = $this->connection($this->scheduleRow());
        $restored = (new DbalCollectorScheduleStore($existing))->bootstrap(120);
        self::assertSame('2026-07-11 10:02:00.000000', $restored->nextScanAt->format('Y-m-d H:i:s.u'));

        $this->expectException(CollectorScheduleConfigurationMismatch::class);
        (new DbalCollectorScheduleStore($this->connection($this->scheduleRow())))->bootstrap(30);
    }

    public function testClaimWaitsForActiveLeaseOrFutureTick(): void
    {
        $active = $this->scheduleRow([
            'lease_expires_at' => '2026-07-11 10:04:00.000000',
        ]);
        $decision = (new DbalCollectorScheduleStore($this->connection($active)))->claimDue(
            $this->worker('a'),
            $this->token('b'),
            10,
        );
        self::assertFalse($decision->isClaimed());
        self::assertSame('10:04:00', $decision->retryAt->format('H:i:s'));

        $future = $this->scheduleRow(['next_scan_at' => '2026-07-11 10:02:00.000000']);
        $decision = (new DbalCollectorScheduleStore($this->connection($future)))->claimDue(
            $this->worker('a'),
            $this->token('b'),
            10,
        );
        self::assertFalse($decision->isClaimed());
        self::assertSame('10:02:00', $decision->retryAt->format('H:i:s'));
    }

    public function testDueClaimCreatesHistoryButExpiredLeaseSkipsToStrictlyFutureGrid(): void
    {
        $due = $this->scheduleRow(['next_scan_at' => self::NOW]);
        $connection = $this->connection($due);
        $connection->expects(self::once())->method('insert')->with(
            'collector_cycles',
            self::callback(static fn (array $values): bool => 1 === $values['fencing_token'] && 'running' === $values['status']),
        );
        $connection->expects(self::once())->method('update')->with(
            'collector_schedule',
            self::callback(static fn (array $values): bool => 1 === $values['lease_fencing_token']),
            ['schedule_name' => 'inventory'],
        )->willReturn(1);
        $claimed = (new DbalCollectorScheduleStore($connection))->claimDue($this->worker('a'), $this->token('b'), 10);
        self::assertTrue($claimed->isClaimed());
        self::assertSame(1, $claimed->lease?->fencingToken);

        $expired = $this->scheduleRow([
            'grid_started_at' => '2026-07-11 09:00:00.000000',
            'next_scan_at' => '2026-07-11 09:00:00.000000',
            'lease_token' => str_repeat('x', 16),
            'lease_expires_at' => '2026-07-11 09:59:59.000000',
            'lease_fencing_token' => 8,
        ]);
        $takeover = $this->connection($expired);
        $takeover->expects(self::exactly(2))->method('executeStatement');
        $takeover->expects(self::never())->method('insert');
        $takeover->expects(self::once())->method('update')->with(
            'collector_schedule',
            self::callback(static fn (array $values): bool => '2026-07-11 10:02:00.000000' === $values['next_scan_at']
                && null === $values['lease_owner']
                && null === $values['lease_token']),
            ['schedule_name' => 'inventory'],
        )->willReturn(1);
        $decision = (new DbalCollectorScheduleStore($takeover))->claimDue($this->worker('a'), $this->token('b'), 10);
        self::assertFalse($decision->isClaimed());
        self::assertSame('10:02:00', $decision->retryAt->format('H:i:s'));
    }

    public function testRenewAndFinalizeCancellationCheckEveryLeasePart(): void
    {
        $lease = $this->lease();
        $connection = $this->connection($this->activeScheduleRow([
            'lease_expires_at' => '2026-07-11 10:00:10.000000',
        ]));
        $connection->method('update')->willReturn(1);
        $renewed = (new DbalCollectorScheduleStore($connection))->renew($lease, 20);
        self::assertSame('10:00:20', $renewed->expiresAt->format('H:i:s'));

        $finalConnection = $this->connection($this->activeScheduleRow());
        $finalConnection->method('update')->willReturn(1);
        $finalConnection->expects(self::once())->method('executeStatement')->with(
            self::stringContains('UPDATE inventory_sync_runs'),
            self::callback(static fn (array $values): bool => 'cancelled' === $values['status']),
        )->willReturn(1);
        $next = (new DbalCollectorScheduleStore($finalConnection))->finalize(
            $lease,
            CollectorCycleStatus::Cancelled,
            500,
        );
        self::assertSame('10:02:00', $next->format('H:i:s'));

    }

    public function testScheduleStoreRejectsInvalidTtlDurationMissingScheduleAndExhaustedFence(): void
    {
        $store = new DbalCollectorScheduleStore($this->connection($this->scheduleRow()));
        foreach (['claim', 'renew'] as $operation) {
            try {
                'claim' === $operation
                    ? $store->claimDue($this->worker('a'), $this->token('b'), 0)
                    : $store->renew($this->lease(), 0);
                self::fail('Invalid TTL should fail.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        try {
            $store->finalize($this->lease(), CollectorCycleStatus::Failed, -1);
            self::fail('Negative duration should fail.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            $store->finalize($this->lease(), CollectorCycleStatus::Abandoned, 1);
            self::fail('A worker must not explicitly abandon its own cycle.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            (new DbalCollectorScheduleStore($this->connection($this->activeScheduleRow())))->renew($this->lease(), 20);
            self::fail('A renewal must extend the persisted expiry.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            (new DbalCollectorScheduleStore($this->connection(false)))->claimDue($this->worker('a'), $this->token('b'), 1);
            self::fail('Missing schedule should fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('initialized', $exception->getMessage());
        }

        $exhausted = $this->scheduleRow([
            'next_scan_at' => self::NOW,
            'lease_fencing_token' => PHP_INT_MAX,
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exhausted');
        (new DbalCollectorScheduleStore($this->connection($exhausted)))->claimDue(
            $this->worker('a'),
            $this->token('b'),
            1,
        );
    }

    public function testHeartbeatStoreValidatesStateWritesAndFreshness(): void
    {
        $connection = $this->connection(false);
        $connection->expects(self::once())->method('executeStatement')->with(
            self::stringContains('INSERT INTO worker_heartbeats'),
            self::callback(static fn (array $values): bool => 'busy' === $values['status'] && null !== $values['cycle_token']),
        )->willReturn(1);
        $store = new DbalCollectorHeartbeatStore($connection);
        $store->record($this->worker('a'), CollectorWorkerStatus::Busy, 10, 'build-1', $this->token('b'));

        $freshConnection = $this->connection(false, '1');
        self::assertTrue((new DbalCollectorHeartbeatStore($freshConnection))->isFresh($this->worker('a')));
        $staleConnection = $this->connection(false, 0);
        self::assertFalse((new DbalCollectorHeartbeatStore($staleConnection))->isFresh($this->worker('a')));

        foreach (
            [
                [0, 'build-1', CollectorWorkerStatus::Ready, null],
                [1, "bad\n", CollectorWorkerStatus::Ready, null],
                [1, 'build-1', CollectorWorkerStatus::Busy, null],
                [1, 'build-1', CollectorWorkerStatus::Ready, $this->token('b')],
            ] as [$ttl, $build, $status, $token]
        ) {
            try {
                $store->record($this->worker('a'), $status, $ttl, $build, $token);
                self::fail('Invalid heartbeat should fail.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSystemMonotonicClockReturnsNonNegativeNanoseconds(): void
    {
        self::assertGreaterThanOrEqual(0, (new SystemMonotonicClock())->nowNanoseconds());
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function scheduleRow(array $overrides = []): array
    {
        return $overrides + [
            'schedule_name' => 'inventory',
            'grid_started_at' => self::NOW,
            'interval_seconds' => 120,
            'next_scan_at' => '2026-07-11 10:02:00.000000',
            'lease_owner' => null,
            'lease_token' => null,
            'lease_fencing_token' => 0,
            'lease_acquired_at' => null,
            'lease_expires_at' => null,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function activeScheduleRow(array $overrides = []): array
    {
        return $this->scheduleRow($overrides + [
            'next_scan_at' => self::NOW,
            'lease_owner' => $this->worker('a')->bytes,
            'lease_token' => $this->token('b')->binary(),
            'lease_fencing_token' => 1,
            'lease_acquired_at' => '2026-07-11 09:59:00.000000',
            'lease_expires_at' => '2026-07-11 10:04:00.000000',
        ]);
    }

    private function lease(): CollectorLease
    {
        return new CollectorLease(
            $this->worker('a'),
            $this->token('b'),
            1,
            new \DateTimeImmutable('2026-07-11T10:04:00+00:00'),
        );
    }

    private function worker(string $byte): CollectorWorkerId
    {
        return new CollectorWorkerId(str_repeat($byte, 16));
    }

    private function token(string $byte): CollectorCycleToken
    {
        return new CollectorCycleToken(str_repeat($byte, 16));
    }

    /** @param array<string, mixed>|false $row */
    private function connection(array|false $row, string|int $fetchOne = 0): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation($connection),
        );
        $connection->method('fetchAssociative')->willReturn($row);
        $connection->method('fetchOne')->willReturnCallback(
            static fn (string $sql): string|int => 'SELECT UTC_TIMESTAMP(6)' === $sql ? self::NOW : $fetchOne,
        );

        return $connection;
    }
}
