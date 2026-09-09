<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\MariaDb;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Monitoring\MonitoringCursorKind;
use App\Infrastructure\Persistence\MariaDb\DbalMonitoringCursorCatalog;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DbalMonitoringCursorCatalogTest extends TestCase
{
    public function testRequiresValidNonEmptyScopeKeys(): void
    {
        $catalog = new DbalMonitoringCursorCatalog($this->createStub(Connection::class));
        foreach ([[], [''], [str_repeat('x', 256)]] as $keys) {
            try {
                $catalog->oldestCompletedUntil($this->connectionId(), MonitoringCursorKind::PveTasksArchive, $keys);
                self::fail('Invalid monitoring cursor keys were accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testReturnsOldestCursorOnlyWhenEveryUniqueScopeExists(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAssociative')->with(
            self::stringContains('MIN(completed_until)'),
            self::callback(static fn (array $values): bool => ['node-a', 'node-b'] === $values['scopes']),
            ['scopes' => ArrayParameterType::STRING],
        )->willReturn(['cursor_count' => '2', 'completed_until' => '2026-07-12 10:00:00.000000']);
        $value = (new DbalMonitoringCursorCatalog($connection))->oldestCompletedUntil(
            $this->connectionId(),
            MonitoringCursorKind::PveTasksArchive,
            ['node-b', 'node-a', 'node-a'],
        );
        self::assertSame('2026-07-12T10:00:00+00:00', $value?->format('c'));

        $missing = $this->connection(['cursor_count' => 1, 'completed_until' => '2026-07-12 10:00:00.000000']);
        self::assertNull((new DbalMonitoringCursorCatalog($missing))->oldestCompletedUntil(
            $this->connectionId(), MonitoringCursorKind::PveTasksArchive, ['node-a', 'node-b'],
        ));
    }

    public function testRejectsMissingOrMalformedDatabaseAggregate(): void
    {
        foreach ([false, ['cursor_count' => '1', 'completed_until' => null],
            ['cursor_count' => '1', 'completed_until' => 'not-a-date']] as $row) {
            try {
                (new DbalMonitoringCursorCatalog($this->connection($row)))->oldestCompletedUntil(
                    $this->connectionId(), MonitoringCursorKind::PbsTasksWindow, ['pbs-a'],
                );
                self::fail('Malformed monitoring cursor aggregate was accepted.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @param array<string, mixed>|false $row */
    private function connection(array|false $row): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn($row);
        return $connection;
    }

    private function connectionId(): ConnectionId
    {
        return new ConnectionId(substr(hash('sha256', 'connection', true), 0, 16));
    }
}
