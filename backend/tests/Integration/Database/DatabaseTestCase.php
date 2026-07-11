<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
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
}
