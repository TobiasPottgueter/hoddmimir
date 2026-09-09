<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Readiness;

use App\Infrastructure\Readiness\DatabaseSchemaReadinessCheck;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatabaseSchemaReadinessCheckTest extends TestCase
{
    /** @return iterable<string, array{int|string, list<string>, array<string, string>}> */
    public static function schemaStates(): iterable
    {
        yield 'expected version missing' => [
            1,
            [],
            ['status' => 'unavailable', 'reason' => 'migration_version_mismatch'],
        ];
        yield 'version mismatch' => [
            1,
            ['DoctrineMigrations\\Version20260709000000'],
            ['status' => 'unavailable', 'reason' => 'migration_version_mismatch'],
        ];
        yield 'unexpected additional version' => [
            1,
            [
                ...DatabaseSchemaReadinessCheck::EXPECTED_MIGRATIONS,
                'DoctrineMigrations\\Version20260711000000',
            ],
            ['status' => 'ready'],
        ];
        yield 'current schema' => [
            '1',
            DatabaseSchemaReadinessCheck::EXPECTED_MIGRATIONS,
            ['status' => 'ready'],
        ];
    }

    /**
     * @param list<string>          $executedVersions
     * @param array<string, string> $expected
     */
    #[DataProvider('schemaStates')]
    public function testItMapsTheFullExecutedMigrationSetToSafeReadinessResults(
        int|string $metadataTableCount,
        array $executedVersions,
        array $expected,
    ): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('fetchOne')
            ->willReturn($metadataTableCount);
        $connection
            ->expects(self::once())
            ->method('fetchFirstColumn')
            ->willReturn($executedVersions);

        $check = new DatabaseSchemaReadinessCheck($connection);

        self::assertSame('database_schema', $check->name());
        self::assertSame($expected, $check->check()->toArray());
    }

    public function testMissingMetadataFailsReadinessBeforeReadingVersions(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')->willReturn(0);
        $connection->expects(self::never())->method('fetchFirstColumn');

        self::assertSame(
            ['status' => 'unavailable', 'reason' => 'migration_metadata_missing'],
            (new DatabaseSchemaReadinessCheck($connection))->check()->toArray(),
        );
    }

    public function testDatabaseErrorsFailClosedWithoutLeakingDetails(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('fetchOne')
            ->willThrowException(new RuntimeException('password and host detail'));

        self::assertSame(
            ['status' => 'unavailable', 'reason' => 'database_unavailable'],
            (new DatabaseSchemaReadinessCheck($connection))->check()->toArray(),
        );
    }

    public function testUnexpectedMigrationMetadataTypeFailsClosed(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')->willReturn(1);
        $connection->expects(self::once())->method('fetchFirstColumn')->willReturn([123]);

        self::assertSame(
            ['status' => 'unavailable', 'reason' => 'database_unavailable'],
            (new DatabaseSchemaReadinessCheck($connection))->check()->toArray(),
        );
    }
}
