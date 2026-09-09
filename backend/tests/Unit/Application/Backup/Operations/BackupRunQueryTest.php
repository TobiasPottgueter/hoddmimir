<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Backup\Operations;

use App\Application\Backup\Operations\BackupRunQuery;
use App\Application\Backup\Operations\BackupRunState;
use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BackupRunQueryTest extends TestCase
{
    private const string ID = '11111111-1111-4111-8111-111111111111';

    public function testNormalizesUtcPrecisionAndBindsEveryAppliedFilterButNotThePageSize(): void
    {
        $query = new BackupRunQuery(new PageRequest(), startedFrom: '2026-09-09T00:00:00Z', startedBefore: '2026-09-10T00:00:00.1Z');
        self::assertSame('2026-09-09 00:00:00.000000', $query->startedFrom?->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-09-10 00:00:00.100000', $query->startedBefore?->format('Y-m-d H:i:s.u'));
        $cursor = PageCursor::resource($query->cursorContext(), '2026-09-09T12:00:00.123456Z', self::ID);
        $same = new BackupRunQuery(new PageRequest(1, PageCursor::decode($cursor->opaque())), startedFrom: '2026-09-09T00:00:00.000000Z', startedBefore: '2026-09-10T00:00:00.100000Z');
        self::assertSame($query->cursorContext(), $same->cursorContext());
        self::assertSame('UTC', $same->startedFrom?->getTimezone()->getName());
        $base = new BackupRunQuery(new PageRequest());
        self::assertNull($base->startedFrom);
        self::assertNull($base->startedBefore);
        $contexts = [$base->cursorContext()];
        foreach ([
            new BackupRunQuery(new PageRequest(), BackupRunState::Failed),
            new BackupRunQuery(new PageRequest(), guestId: new ReadModelIdentifier(self::ID)),
            new BackupRunQuery(new PageRequest(), nodeId: new ReadModelIdentifier(self::ID)),
            new BackupRunQuery(new PageRequest(), targetId: new ReadModelIdentifier(self::ID)),
            new BackupRunQuery(new PageRequest(), vmid: 1),
            new BackupRunQuery(new PageRequest(), vmid: 2147483647),
            new BackupRunQuery(new PageRequest(), search: 'München_%!'),
            new BackupRunQuery(new PageRequest(), search: str_repeat('a', 190)),
            new BackupRunQuery(new PageRequest(), startedFrom: '2026-01-01T00:00:00Z'),
            new BackupRunQuery(new PageRequest(), startedBefore: '2026-01-01T00:00:00Z'),
        ] as $filtered) $contexts[] = $filtered->cursorContext();
        self::assertSame($contexts, array_values(array_unique($contexts)));
    }

    /** @return iterable<string, array{array{vmid?: int, search?: string, startedFrom?: string, startedBefore?: string}}> */
    public static function invalidFilters(): iterable
    {
        foreach ([0, -1, 2147483648] as $vmid) yield 'vmid '.$vmid => [['vmid' => $vmid]];
        foreach (['', '  ', str_repeat('a', 191), "a\0b", "a\nb", "a\x7fb", "\xff"] as $i => $search) yield 'search '.$i => [['search' => $search]];
        foreach (['', 'yesterday', '2026-02-30T00:00:00Z', '2026-01-01T24:00:00Z', '2026-01-01T00:00:60Z', '2026-01-01T00:00:00+00:00', '2026-01-01', '2026-01-01T00:00:00.1234567Z', '0999-01-01T00:00:00Z'] as $date) {
            yield 'from '.$date => [['startedFrom' => $date]];
            yield 'before '.$date => [['startedBefore' => $date]];
        }
        yield 'equal boundaries' => [['startedFrom' => '2026-01-01T00:00:00Z', 'startedBefore' => '2026-01-01T00:00:00.0Z']];
        yield 'reversed boundaries' => [['startedFrom' => '2026-01-02T00:00:00Z', 'startedBefore' => '2026-01-01T00:00:00Z']];
    }

    /** @param array{vmid?: int, search?: string, startedFrom?: string, startedBefore?: string} $filters */
    #[DataProvider('invalidFilters')]
    public function testRejectsInvalidFilters(array $filters): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BackupRunQuery(new PageRequest(), ...$filters);
    }

    public function testRejectsAValidCursorFromAnotherFilterContext(): void
    {
        $query = new BackupRunQuery(new PageRequest(), search: 'old');
        $cursor = PageCursor::resource($query->cursorContext(), '2026-01-01T00:00:00.000000Z', self::ID);
        $this->expectException(InvalidArgumentException::class);
        new BackupRunQuery(new PageRequest(25, $cursor), search: 'new');
    }

    public function testRejectsAnInvalidDateInsideAnOtherwiseMatchingCursor(): void
    {
        $query = new BackupRunQuery(new PageRequest());
        $cursor = PageCursor::resource($query->cursorContext(), 'not-a-date', self::ID);
        $this->expectException(InvalidArgumentException::class);
        new BackupRunQuery(new PageRequest(25, $cursor));
    }
}
