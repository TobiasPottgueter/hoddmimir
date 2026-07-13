<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class DatabaseTestCase extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();

        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        $this->connection = $connection;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            $this->connection->close();
        }

        parent::tearDown();
    }

    final protected function connection(): Connection
    {
        return $this->connection;
    }

    final protected function databaseName(): string
    {
        $databaseName = $this->connection->fetchOne('SELECT DATABASE()');
        self::assertIsString($databaseName);
        self::assertNotSame('', $databaseName);

        return $databaseName;
    }

    final protected function seedWebSession(
        string $userId,
        string $sessionId = 'session-audit-id',
    ): string {
        self::assertSame(16, strlen($userId));
        self::assertSame(16, strlen($sessionId));

        $this->connection->insert('web_sessions', [
            'id' => $sessionId,
            'user_id' => $userId,
            'token_hash' => hash('sha256', "session-token\0".$sessionId, true),
            'csrf_secret_hash' => hash('sha256', "csrf-token\0".$sessionId, true),
            'issued_at' => '2026-07-12 10:00:00.000000',
            'last_seen_at' => '2026-07-12 10:01:00.000000',
            'idle_expires_at' => '2026-07-12 10:31:00.000000',
            'absolute_expires_at' => '2026-07-12 22:00:00.000000',
            'revoked_at' => null,
        ], [
            'id' => ParameterType::BINARY,
            'user_id' => ParameterType::BINARY,
            'token_hash' => ParameterType::BINARY,
            'csrf_secret_hash' => ParameterType::BINARY,
        ]);

        return $sessionId;
    }
}
