<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;

final class ExecutorEvidencePrivilegeTest extends DatabaseTestCase
{
    public function testViewsHaveExplicitCurrentDefinerAndDefinerSecurity(): void
    {
        $views = $this->connection()->fetchAllAssociative(<<<'SQL'
SELECT TABLE_NAME AS view_name, SECURITY_TYPE AS security_type, DEFINER AS definer
FROM information_schema.VIEWS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'executor_scan_credentials', 'executor_evidence_claim_catalog',
    'executor_evidence_endpoint_catalog', 'executor_evidence_subject_catalog',
    'current_executor_permission_evidence',
    'backup_request_client_configurations'
  )
ORDER BY BINARY TABLE_NAME
SQL);
        self::assertCount(6, $views);
        $currentUser = $this->connection()->fetchOne('SELECT CURRENT_USER()');
        self::assertIsString($currentUser);
        foreach ($views as $view) {
            self::assertSame('DEFINER', $view['security_type']);
            self::assertSame($currentUser, $view['definer']);
        }

        $routine = $this->connection()->fetchAssociative(<<<'SQL'
SELECT SECURITY_TYPE AS security_type, DEFINER AS definer
FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA = DATABASE()
  AND ROUTINE_NAME = 'delete_executor_evidence_publish_rows'
  AND ROUTINE_TYPE = 'PROCEDURE'
SQL);
        self::assertIsArray($routine);
        self::assertSame('DEFINER', $routine['security_type']);
        self::assertSame($currentUser, $routine['definer']);
    }

    public function testRuntimeRolesExposeOnlyCurrentEvidenceAndPurposeScopedWorkerConfiguration(): void
    {
        $worker = $this->runtime('backup_worker');
        $collector = $this->runtime('collector');
        $web = $this->runtime('web');
        try {
            foreach ([$worker, $collector, $web] as $reader) {
                self::assertSame([], $reader->fetchAllAssociative(
                    'SELECT * FROM current_executor_permission_evidence LIMIT 0',
                ));
                $this->assertDenied(static fn () => $reader->fetchAllAssociative(
                    'SELECT * FROM executor_permission_evidence LIMIT 0',
                ));
                $this->assertDenied(static fn () => $reader->fetchAllAssociative(
                    'SELECT * FROM proxmox_credentials LIMIT 0',
                ));
            }

            self::assertSame(0, $worker->executeStatement(
                "CALL delete_executor_evidence_publish_rows(UNHEX(REPEAT('ff', 16)))",
            ));
            $this->assertDenied(static fn () => $collector->executeStatement(
                "CALL delete_executor_evidence_publish_rows(UNHEX(REPEAT('ff', 16)))",
            ));
            $this->assertDenied(static fn () => $web->executeStatement(
                "CALL delete_executor_evidence_publish_rows(UNHEX(REPEAT('ff', 16)))",
            ));

            foreach ([
                'executor_scan_credentials', 'backup_credentials',
                'executor_evidence_claim_catalog', 'executor_evidence_endpoint_catalog',
                'executor_evidence_subject_catalog', 'backup_request_client_configurations',
            ] as $view) {
                self::assertSame([], $worker->fetchAllAssociative('SELECT * FROM '.$view.' LIMIT 0'));
                $this->assertDenied(static fn () => $collector->fetchAllAssociative('SELECT * FROM '.$view.' LIMIT 0'));
                $this->assertDenied(static fn () => $web->fetchAllAssociative('SELECT * FROM '.$view.' LIMIT 0'));
            }

            foreach (['executor_evidence_refresh_state', 'executor_evidence_refresh_subject_stage', 'executor_evidence_refresh_projection_stage'] as $table) {
                self::assertSame([], $worker->fetchAllAssociative('SELECT * FROM '.$table.' LIMIT 0'));
                $this->assertDenied(static fn () => $collector->fetchAllAssociative('SELECT * FROM '.$table.' LIMIT 0'));
                $this->assertDenied(static fn () => $web->fetchAllAssociative('SELECT * FROM '.$table.' LIMIT 0'));
            }
        } finally {
            $worker->close();
            $collector->close();
            $web->close();
        }
    }

    public function testWorkerRawEvidenceMutationIsColumnBoundAndDoesNotRequireRawRead(): void
    {
        $worker = $this->runtime('backup_worker');
        try {
            self::assertSame(0, $worker->executeStatement(<<<'SQL'
INSERT INTO executor_permission_evidence
    (id, connection_id, cluster_id, target_id, node_id, storage_id, guest_id,
     evidence_set_revision, endpoint_id, connection_revision,
     backup_credential_revision, scan_credential_revision,
     vm_backup_authorized, datastore_allocate_authorized, authorized, observed_at, revision)
SELECT UNHEX(REPEAT('01', 16)), UNHEX(REPEAT('02', 16)), UNHEX(REPEAT('03', 16)),
       UNHEX(REPEAT('04', 16)), UNHEX(REPEAT('05', 16)), UNHEX(REPEAT('06', 16)), NULL,
       1, UNHEX(REPEAT('07', 16)), 1, 1, 1, 1, 1, 1, UTC_TIMESTAMP(6), 1
WHERE 1 = 0
SQL));
            self::assertSame(0, $worker->executeStatement(
                "CALL delete_executor_evidence_publish_rows(UNHEX(REPEAT('ff', 16)))",
            ));
            $this->assertDenied(static fn () => $worker->executeStatement(
                'DELETE FROM executor_permission_evidence WHERE 1 = 0',
            ));
            $this->assertDenied(static fn () => $worker->executeStatement(
                'UPDATE executor_permission_evidence SET id = id WHERE 1 = 0',
            ));
        } finally {
            $worker->close();
        }
    }

    private function runtime(string $kind): Connection
    {
        $password = file_get_contents('/run/secrets/mariadb_'.$kind.'_password');
        self::assertIsString($password);

        return DriverManager::getConnection(array_replace($this->connection()->getParams(), [
            'user' => 'hoddmimir_'.$kind,
            'password' => trim($password),
        ]));
    }

    /** @param callable(): mixed $operation */
    private function assertDenied(callable $operation): void
    {
        try {
            $operation();
            self::fail('A runtime database user exceeded the executor-evidence contract.');
        } catch (Exception) {
            self::addToAssertionCount(1);
        }
    }
}
