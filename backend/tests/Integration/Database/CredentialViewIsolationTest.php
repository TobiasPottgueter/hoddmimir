<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

final class CredentialViewIsolationTest extends DatabaseTestCase
{
    private const string NOW = '2026-07-13 10:00:00.000000';

    public function testRuntimeCredentialViewsExcludeEveryCredentialOwnedByDisabledConnections(): void
    {
        $active = random_bytes(16);
        $disabled = random_bytes(16);
        foreach ([[$active, 1], [$disabled, 0]] as [$connectionId, $enabled]) {
            $this->connection()->insert('proxmox_connections', [
                'id' => $connectionId,
                'display_name' => 'Credential view '.bin2hex($connectionId),
                'product' => 'pve',
                'enabled' => $enabled,
                'revision' => 1,
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]);
            foreach (['collector', 'backup'] as $purpose) {
                $this->connection()->insert('proxmox_credentials', [
                    'id' => random_bytes(16),
                    'connection_id' => $connectionId,
                    'purpose' => $purpose,
                    'auth_scheme' => 'api_token',
                    'principal' => 'hoddmimir@pve',
                    'token_name' => $purpose,
                    'secret_envelope' => 'opaque-'.$purpose,
                    'envelope_version' => 1,
                    'key_id' => 'key_1',
                    'revision' => 1,
                    'created_at' => self::NOW,
                    'updated_at' => self::NOW,
                ]);
            }
        }
        $this->connection()->commit();

        $collector = $this->runtime('collector');
        $worker = $this->runtime('backup_worker');
        try {
            self::assertSame([bin2hex($active)], $this->hexValues(
                $collector->fetchFirstColumn('SELECT connection_id FROM collector_credentials'),
            ));
            self::assertSame([bin2hex($active)], $this->hexValues(
                $worker->fetchFirstColumn('SELECT connection_id FROM backup_credentials'),
            ));

            $this->connection()->update('proxmox_connections', ['enabled' => 0], ['id' => $active]);
            self::assertSame([], $collector->fetchFirstColumn('SELECT connection_id FROM collector_credentials'));
            self::assertSame([], $worker->fetchFirstColumn('SELECT connection_id FROM backup_credentials'));
            self::assertSame(4, $this->connection()->fetchOne('SELECT COUNT(*) FROM proxmox_credentials WHERE connection_id IN (?, ?)', [$active, $disabled]));
        } finally {
            $collector->close();
            $worker->close();
            $this->connection()->delete('proxmox_connections', ['id' => $active]);
            $this->connection()->delete('proxmox_connections', ['id' => $disabled]);
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

    /** @param list<mixed> $values
     *
     * @return list<string>
     */
    private function hexValues(array $values): array
    {
        return array_map(static function (mixed $value): string {
            self::assertIsString($value);

            return bin2hex($value);
        }, $values);
    }
}
