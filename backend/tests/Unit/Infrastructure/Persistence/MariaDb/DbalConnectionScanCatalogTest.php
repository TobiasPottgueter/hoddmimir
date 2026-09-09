<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\MariaDb;

use App\Infrastructure\Persistence\MariaDb\DbalConnectionScanCatalog;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
final class DbalConnectionScanCatalogTest extends TestCase
{
    public function testItReturnsOnlySecretFreeGroupedTargetsInStableOrder(): void
    {
        $connectionA = str_repeat('a', 16);
        $connectionB = str_repeat('b', 16);
        $database = $this->database([
            ['connection_id' => $connectionA, 'product' => 'pve', 'revision' => '2', 'endpoint_id' => str_repeat('2', 16), 'priority' => 20],
            ['connection_id' => $connectionA, 'product' => 'pve', 'revision' => 2, 'endpoint_id' => str_repeat('1', 16), 'priority' => '10'],
            ['connection_id' => $connectionB, 'product' => 'pbs', 'revision' => 1, 'endpoint_id' => str_repeat('3', 16), 'priority' => 5],
        ]);
        $targets = (new DbalConnectionScanCatalog($database))->enabledTargets();

        self::assertCount(2, $targets);
        self::assertSame($connectionA, $targets[0]->connectionId->bytes);
        self::assertSame(2, $targets[0]->expectedRevision);
        self::assertSame([10, 20], array_map(static fn ($endpoint): int => $endpoint->priority, $targets[0]->endpoints));
        $fields = array_keys(get_object_vars($targets[0]));
        sort($fields, SORT_STRING);
        self::assertSame(
            ['connectionId', 'endpoints', 'expectedRevision', 'product'],
            $fields,
            'The catalog DTO has no host, TLS, credential, ciphertext or plaintext field.',
        );
    }

    public function testEmptyCatalogIsSafe(): void
    {
        self::assertSame([], (new DbalConnectionScanCatalog($this->database([])))->enabledTargets());

        $target = (new DbalConnectionScanCatalog($this->database([[
            'connection_id' => str_repeat('a', 16),
            'product' => 'pve',
            'revision' => 1,
            'endpoint_id' => null,
            'priority' => null,
        ]])))->enabledTargets();
        self::assertCount(1, $target);
        self::assertSame([], $target[0]->endpoints, 'Enabled connections without endpoints must remain diagnosable.');
    }

    public function testMalformedRowsFailClosed(): void
    {
        foreach ([
            [['connection_id' => 'short', 'product' => 'pve', 'revision' => 1, 'endpoint_id' => str_repeat('e', 16), 'priority' => 1]],
            [['connection_id' => str_repeat('c', 16), 'product' => null, 'revision' => 1, 'endpoint_id' => str_repeat('e', 16), 'priority' => 1]],
            [['connection_id' => str_repeat('c', 16), 'product' => 'other', 'revision' => 1, 'endpoint_id' => str_repeat('e', 16), 'priority' => 1]],
            [['connection_id' => str_repeat('c', 16), 'product' => 'pve', 'revision' => 'bad', 'endpoint_id' => str_repeat('e', 16), 'priority' => 1]],
            [['connection_id' => str_repeat('c', 16), 'product' => 'pve', 'revision' => 1, 'endpoint_id' => 'short', 'priority' => 1]],
            [
                ['connection_id' => str_repeat('c', 16), 'product' => 'pve', 'revision' => 1, 'endpoint_id' => str_repeat('e', 16), 'priority' => 1],
                ['connection_id' => str_repeat('c', 16), 'product' => 'pbs', 'revision' => 1, 'endpoint_id' => str_repeat('f', 16), 'priority' => 2],
            ],
        ] as $rows) {
            try {
                (new DbalConnectionScanCatalog($this->database($rows)))->enabledTargets();
                self::fail('A malformed connection catalog row must fail closed.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function database(array $rows): Connection&MockObject
    {
        $database = $this->createMock(Connection::class);
        $database->method('fetchAllAssociative')->willReturn($rows);
        return $database;
    }
}
