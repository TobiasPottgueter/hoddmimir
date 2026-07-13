<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;

final class ShadowEvaluationPrivilegeTest extends DatabaseTestCase
{
    private const array TABLES = [
        'scheduler_decision_gates',
        'scheduler_decisions',
        'scheduler_evaluation_runs',
    ];

    public function testShadowTablesHaveExactRuntimeGrants(): void
    {
        $collector = $this->runtimeConnection('collector');
        try {
            $grants = $collector->fetchFirstColumn('SHOW GRANTS FOR CURRENT_USER()');
            foreach (self::TABLES as $table) {
                $tableGrants = array_values(array_filter(
                    $grants,
                    static fn (mixed $grant): bool => is_string($grant)
                        && str_contains($grant, sprintf('.`%s`', $table)),
                ));
                self::assertCount(1, $tableGrants);
                self::assertStringContainsString('GRANT SELECT, INSERT ON', $tableGrants[0]);
                self::assertStringNotContainsString('UPDATE', $tableGrants[0]);
                self::assertStringNotContainsString('DELETE', $tableGrants[0]);
            }
        } finally {
            $collector->close();
        }
    }

    public function testCollectorIsAppendOnlyWebIsReadOnlyAndBackupWorkerIsDenied(): void
    {
        $collector = $this->runtimeConnection('collector');
        $web = $this->runtimeConnection('web');
        $backup = $this->runtimeConnection('backup_worker');
        try {
            foreach (self::TABLES as $table) {
                $identityColumn = 'scheduler_decision_gates' === $table ? 'decision_id' : 'id';
                self::assertSame([], $collector->fetchAllAssociative(sprintf('SELECT * FROM %s LIMIT 0', $table)));
                $this->assertDenied(static fn () => $collector->executeStatement(sprintf(
                    'UPDATE %s SET %s = %s WHERE 1 = 0',
                    $table,
                    $identityColumn,
                    $identityColumn,
                )));
                $this->assertDenied(static fn () => $collector->executeStatement(sprintf('DELETE FROM %s WHERE 1 = 0', $table)));
                self::assertSame([], $web->fetchAllAssociative(sprintf('SELECT * FROM %s LIMIT 0', $table)));
                foreach ([$web, $backup] as $deniedConnection) {
                    if ($deniedConnection === $backup) {
                        $this->assertDenied(static fn () => $deniedConnection->fetchAllAssociative(sprintf('SELECT * FROM %s LIMIT 0', $table)));
                    }
                    $this->assertDenied(static fn () => $deniedConnection->executeStatement(sprintf(
                        'INSERT INTO %s (%s) SELECT :id WHERE 1 = 0',
                        $table,
                        $identityColumn,
                    ), ['id' => random_bytes(16)]));
                    $this->assertDenied(static fn () => $deniedConnection->executeStatement(sprintf(
                        'UPDATE %s SET %s = %s WHERE 1 = 0',
                        $table,
                        $identityColumn,
                        $identityColumn,
                    )));
                    $this->assertDenied(static fn () => $deniedConnection->executeStatement(sprintf('DELETE FROM %s WHERE 1 = 0', $table)));
                }
            }
        } finally {
            $collector->close();
            $web->close();
            $backup->close();
        }
    }

    private function runtimeConnection(string $kind): Connection
    {
        $password = file_get_contents(sprintf('/run/secrets/mariadb_%s_password', $kind));
        self::assertIsString($password);
        $user = 'backup_worker' === $kind ? 'hoddmimir_backup_worker' : 'hoddmimir_'.$kind;

        return DriverManager::getConnection(array_replace($this->connection()->getParams(), [
            'user' => $user,
            'password' => trim($password),
        ]));
    }

    /** @param callable(): mixed $operation */
    private function assertDenied(callable $operation): void
    {
        try {
            $operation();
            self::fail('A runtime database user exceeded its shadow-evaluation grants.');
        } catch (Exception) {
            self::addToAssertionCount(1);
        }
    }
}
