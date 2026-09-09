<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;

final class OnboardingPrivilegeTest extends DatabaseTestCase
{
    public function testWebAndCollectorHaveOnlyTheirOnboardingTransactionSurfaces(): void
    {
        $web = $this->roleConnection('hoddmimir_web', '/run/secrets/mariadb_web_password');
        $collector = $this->roleConnection('hoddmimir_collector', '/run/secrets/mariadb_collector_password');
        try {
            self::assertSame([], $web->fetchAllAssociative('SELECT * FROM proxmox_connection_onboarding_state LIMIT 0'));
            self::assertSame([], $web->fetchAllAssociative('SELECT * FROM proxmox_endpoint_onboarding_evidence LIMIT 0'));
            self::assertSame([], $web->fetchAllAssociative('SELECT * FROM proxmox_onboarding_commands LIMIT 0'));
            self::assertSame([], $web->fetchAllAssociative('SELECT secret_verification_hash FROM proxmox_credentials LIMIT 0'));
            self::assertSame(0, $web->executeStatement('INSERT INTO proxmox_connection_onboarding_state SELECT * FROM proxmox_connection_onboarding_state WHERE 1 = 0'));
            self::assertSame(0, $web->executeStatement('INSERT INTO proxmox_endpoint_onboarding_evidence SELECT * FROM proxmox_endpoint_onboarding_evidence WHERE 1 = 0'));
            self::assertSame(0, $web->executeStatement('INSERT INTO proxmox_onboarding_commands SELECT * FROM proxmox_onboarding_commands WHERE 1 = 0'));
            self::assertSame(0, $web->executeStatement("UPDATE proxmox_connection_onboarding_state SET state = 'first_automatic_scan_pending' WHERE 1 = 0"));
            self::assertSame(0, $web->executeStatement('UPDATE proxmox_endpoint_onboarding_evidence SET verified_at = verified_at WHERE 1 = 0'));
            self::assertSame(0, $web->executeStatement('UPDATE proxmox_credentials SET secret_verification_hash = secret_verification_hash WHERE 1 = 0'));
            self::assertSame(0, $web->executeStatement('UPDATE proxmox_connection_endpoints SET last_attempted_at = NULL, last_success_at = NULL, last_error_code = NULL WHERE 1 = 0'));

            self::assertSame([], $collector->fetchAllAssociative('SELECT * FROM proxmox_connection_onboarding_state LIMIT 0'));
            self::assertSame(0, $collector->executeStatement("UPDATE proxmox_connection_onboarding_state SET state = 'inventory_failed', inventory_status_changed_at = UTC_TIMESTAMP(6), last_inventory_run_id = NULL WHERE 1 = 0"));

            foreach ([
                fn () => $web->executeStatement('DELETE FROM proxmox_connection_onboarding_state WHERE 1 = 0'),
                fn () => $web->executeStatement('DELETE FROM proxmox_endpoint_onboarding_evidence WHERE 1 = 0'),
                fn () => $web->executeStatement('DELETE FROM proxmox_onboarding_commands WHERE 1 = 0'),
                fn (): array => $web->fetchAllAssociative('SELECT secret_envelope FROM proxmox_credentials LIMIT 0'),
                fn (): array => $web->fetchAllAssociative('SELECT key_id FROM proxmox_credentials LIMIT 0'),
                fn (): array => $web->fetchAllAssociative('SELECT envelope_version FROM proxmox_credentials LIMIT 0'),
                fn () => $web->executeStatement('UPDATE proxmox_connection_endpoints SET created_at = created_at WHERE 1 = 0'),
                fn (): array => $collector->fetchAllAssociative('SELECT * FROM proxmox_onboarding_commands LIMIT 0'),
                fn (): array => $collector->fetchAllAssociative('SELECT * FROM proxmox_endpoint_onboarding_evidence LIMIT 0'),
                fn () => $collector->executeStatement("UPDATE proxmox_connection_onboarding_state SET detected_version = 'forbidden' WHERE 1 = 0"),
                fn () => $collector->executeStatement("INSERT INTO proxmox_connection_onboarding_state (connection_id) VALUES (UNHEX('00000000000000000000000000000000'))"),
            ] as $operation) {
                $this->assertDenied($operation);
            }
        } finally {
            $web->close();
            $collector->close();
        }
    }

    private function roleConnection(string $user, string $secretFile): Connection
    {
        $password = file_get_contents($secretFile);
        self::assertIsString($password);

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
            self::fail('A database role exceeded its onboarding grants.');
        } catch (Exception) {
            self::addToAssertionCount(1);
        }
    }
}
