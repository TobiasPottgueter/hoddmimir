<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\MariaDb;

use App\Infrastructure\Persistence\MariaDb\DbalReferencedCredentialKeyIds;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DbalReferencedCredentialKeyIdsTest extends TestCase
{
    public function testItReturnsDistinctKeyIdsInRepositoryOrder(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('fetchFirstColumn')
            ->with('SELECT key_id FROM credential_key_usage ORDER BY key_id')
            ->willReturn(['key_current', 'key_old']);

        self::assertSame(
            ['key_current', 'key_old'],
            (new DbalReferencedCredentialKeyIds($connection))->referencedKeyIds(),
        );
    }

    public function testItFailsClosedForAnUnexpectedDatabaseValue(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('fetchFirstColumn')
            ->willReturn(['key_current', 123]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Credential key usage is unavailable.');
        (new DbalReferencedCredentialKeyIds($connection))->referencedKeyIds();
    }
}
