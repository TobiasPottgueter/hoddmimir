<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\MariaDb;

use App\Domain\Policy\BackupMode;
use App\Domain\Policy\Compression;
use App\Infrastructure\Persistence\MariaDb\BackupDefaultsMapper;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BackupDefaultsMapperTest extends TestCase
{
    public function testNullableDefaultsAndDatabaseScalarTypes(): void
    {
        $mapper = new BackupDefaultsMapper();
        self::assertNull($mapper->fromRow([])->retention);
        $defaults = $mapper->fromRow(['default_backup_mode' => 'stop', 'default_compression' => '0', 'default_keep_last' => '5']);
        self::assertSame(BackupMode::Stop, $defaults->mode);
        self::assertSame(Compression::None, $defaults->compression);
        self::assertSame(5, $defaults->retention?->keepLast);
        self::assertSame(2, $mapper->fromRow(['default_legacy_maxfiles' => 2])->retention?->legacyMaxFiles);
        self::assertTrue($mapper->fromRow(['default_keep_all' => '1'])->retention?->keepAll);
        self::assertFalse($mapper->fromRow(['default_keep_all' => '0', 'default_keep_daily' => 1])->retention?->keepAll);
        self::assertSame('gzip', $mapper->payloadData(['defaultCompression' => 'gzip'])['default_compression']);
        self::assertNull($mapper->payloadData([])['default_compression']);
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('invalidDefaults')]
    public function testInvalidDefaultsAreRejected(array $row): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new BackupDefaultsMapper())->fromRow($row);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidDefaults(): iterable
    {
        yield 'mixed retention' => [['default_legacy_maxfiles' => 2, 'default_keep_last' => 1]];
        yield 'invalid boolean' => [['default_keep_all' => 'yes']];
        yield 'float count' => [['default_keep_last' => 1.5]];
        yield 'zero count' => [['default_keep_last' => 0]];
        yield 'negative count' => [['default_keep_last' => -1]];
        yield 'overflow' => [['default_keep_last' => 1000001]];
        yield 'string overflow' => [['default_keep_last' => '99999999']];
        yield 'invalid mode type' => [['default_backup_mode' => 1]];
        yield 'contradicting keep all' => [['default_keep_all' => true, 'default_keep_last' => 1]];
        yield 'empty false keep all' => [['default_keep_all' => false]];
    }

    public function testUnknownBackupOptionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new BackupDefaultsMapper())->fromRow(['default_backup_mode' => 'unknown']);
    }
}
