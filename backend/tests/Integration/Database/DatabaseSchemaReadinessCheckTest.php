<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Infrastructure\Readiness\DatabaseSchemaReadinessCheck;

final class DatabaseSchemaReadinessCheckTest extends DatabaseTestCase
{
    public function testMigratedMariaDbSchemaIsReady(): void
    {
        self::assertSame(
            ['status' => 'ready'],
            (new DatabaseSchemaReadinessCheck($this->connection()))->check()->toArray(),
        );
    }

    public function testOldMariaDbSchemaVersionIsUnavailable(): void
    {
        $this->connection()->update(
            'doctrine_migration_versions',
            ['version' => 'DoctrineMigrations\\Version20260709000000'],
            ['version' => DatabaseSchemaReadinessCheck::EXPECTED_MIGRATIONS[0]],
        );

        self::assertSame(
            ['status' => 'unavailable', 'reason' => 'migration_version_mismatch'],
            (new DatabaseSchemaReadinessCheck($this->connection()))->check()->toArray(),
        );
    }

    public function testAdditionalNewerMigrationRemainsReadyForForwardOnlyRecovery(): void
    {
        $this->connection()->insert('doctrine_migration_versions', [
            'version' => 'DoctrineMigrations\\Version20260711000000',
            'executed_at' => '2026-07-11 00:00:00',
            'execution_time' => 1,
        ]);

        self::assertSame(
            ['status' => 'ready'],
            (new DatabaseSchemaReadinessCheck($this->connection()))->check()->toArray(),
        );
    }
}
