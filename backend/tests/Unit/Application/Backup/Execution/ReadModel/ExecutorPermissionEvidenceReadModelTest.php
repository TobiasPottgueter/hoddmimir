<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Backup\Execution\ReadModel;

use App\Application\Backup\Execution\ReadModel\ExecutorPermissionEvidenceItem;
use App\Application\Backup\Execution\ReadModel\ExecutorPermissionEvidencePage;
use App\Application\Backup\Execution\ReadModel\ExecutorPermissionEvidenceQuery;
use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use App\Application\Target\ReadModel\EvidenceFreshness;
use App\Domain\Shared\UInt64Decimal;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExecutorPermissionEvidenceReadModelTest extends TestCase
{
    private const string ID = '00112233-4455-6677-8899-aabbccddeeff';
    private const string OTHER = '11112233-4455-6677-8899-aabbccddeeff';

    public function testItemIsClosedAndDerivesMissingPermissionsInCanonicalOrder(): void
    {
        $item = $this->item(vm: false, datastore: false, freshness: EvidenceFreshness::Stale);

        self::assertSame([
            'id', 'connectionId', 'clusterId', 'targetId', 'nodeId', 'storageId', 'guestId',
            'evidenceSetRevision', 'endpointId', 'connectionRevision', 'backupCredentialRevision',
            'scanCredentialRevision', 'observedAt', 'freshness', 'vmBackupAuthorized',
            'datastoreAllocateAuthorized', 'authorized', 'missingPermissions',
        ], array_keys($item->toArray()));
        self::assertSame(['VM.Backup', 'Datastore.AllocateSpace'], $item->missingPermissions());
        self::assertSame('stale', $item->toArray()['freshness']);

        self::assertSame([], $this->item()->missingPermissions());
        self::assertSame(['VM.Backup'], $this->item(vm: false)->missingPermissions());
        self::assertSame(['Datastore.AllocateSpace'], $this->item(datastore: false)->missingPermissions());
        self::assertSame(
            UInt64Decimal::MAXIMUM,
            $this->item(setRevision: UInt64Decimal::MAXIMUM)->toArray()['evidenceSetRevision'],
        );
    }

    /** @return iterable<string, array{callable(): mixed}> */
    public static function invalidItems(): iterable
    {
        yield 'identifier' => [static fn () => new ExecutorPermissionEvidenceItem(
            'invalid', self::ID, self::ID, self::ID, self::ID, self::ID, self::ID,
            new UInt64Decimal('1'), self::ID, 1, 1, 1, '2026-07-18T10:00:00.000000Z', EvidenceFreshness::Fresh,
            true, true, true,
        )];
        yield 'set revision' => [static fn () => new ExecutorPermissionEvidenceItem(
            self::ID, self::ID, self::ID, self::ID, self::ID, self::ID, self::ID,
            new UInt64Decimal('0'), self::ID, 1, 1, 1, '2026-07-18T10:00:00.000000Z', EvidenceFreshness::Fresh,
            true, true, true,
        )];
        yield 'binding revision' => [static fn () => new ExecutorPermissionEvidenceItem(
            self::ID, self::ID, self::ID, self::ID, self::ID, self::ID, self::ID,
            new UInt64Decimal('1'), self::ID, 1, 0, 1, '2026-07-18T10:00:00.000000Z', EvidenceFreshness::Fresh,
            true, true, true,
        )];
        yield 'timestamp' => [static fn () => new ExecutorPermissionEvidenceItem(
            self::ID, self::ID, self::ID, self::ID, self::ID, self::ID, self::ID,
            new UInt64Decimal('1'), self::ID, 1, 1, 1, '2026-07-18T10:00:00Z', EvidenceFreshness::Fresh,
            true, true, true,
        )];
        yield 'authorization' => [static fn () => new ExecutorPermissionEvidenceItem(
            self::ID, self::ID, self::ID, self::ID, self::ID, self::ID, self::ID,
            new UInt64Decimal('1'), self::ID, 1, 1, 1, '2026-07-18T10:00:00.000000Z', EvidenceFreshness::Fresh,
            true, false, true,
        )];
        yield 'missing freshness' => [static fn () => new ExecutorPermissionEvidenceItem(
            self::ID, self::ID, self::ID, self::ID, self::ID, self::ID, self::ID,
            new UInt64Decimal('1'), self::ID, 1, 1, 1, '2026-07-18T10:00:00.000000Z', EvidenceFreshness::Missing,
            true, true, true,
        )];
        yield 'set revision leading zero' => [static fn () => new UInt64Decimal('01')];
        yield 'set revision overflow' => [static fn () => new UInt64Decimal('18446744073709551616')];
    }

    #[DataProvider('invalidItems')]
    public function testInvalidItemsFailClosed(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);
        $factory();
    }

    public function testQueryBindsEveryFilterAndCursorContext(): void
    {
        $identifier = new ReadModelIdentifier(self::ID);
        $query = new ExecutorPermissionEvidenceQuery(
            new PageRequest(1), $identifier, $identifier, $identifier, $identifier, $identifier,
        );
        self::assertSame(
            PageCursor::context('executor-permission-evidence-v1', self::ID, self::ID, self::ID, self::ID, self::ID),
            $query->cursorContext(),
        );
        $cursor = PageCursor::resource($query->cursorContext(), self::OTHER, self::OTHER);
        $continued = new ExecutorPermissionEvidenceQuery(
            new PageRequest(1, PageCursor::decode($cursor->opaque())),
            $identifier, $identifier, $identifier, $identifier, $identifier,
        );
        self::assertSame(self::OTHER, $continued->page->cursor?->second);
    }

    public function testForeignOrNonCanonicalCursorFailsClosed(): void
    {
        $identifier = new ReadModelIdentifier(self::ID);
        foreach ([
            PageCursor::resource(PageCursor::context('foreign'), self::OTHER, self::OTHER),
            PageCursor::resource(
                PageCursor::context('executor-permission-evidence-v1', self::ID, '', '', '', ''),
                self::ID,
                self::OTHER,
            ),
        ] as $cursor) {
            try {
                new ExecutorPermissionEvidenceQuery(new PageRequest(1, $cursor), $identifier);
                self::fail('The cursor was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testPageIsClosedAndRejectsImpossibleShapes(): void
    {
        $page = new PageRequest(1);
        $item = $this->item();
        $payload = (new ExecutorPermissionEvidencePage($page, [$item], null))->toArray();
        $pagination = $payload['page'] ?? null;
        self::assertIsArray($pagination);
        self::assertSame(1, $pagination['count'] ?? null);
        foreach ([
            static fn () => new ExecutorPermissionEvidencePage($page, [$item, $item], null),
            static fn () => new ExecutorPermissionEvidencePage(
                $page,
                [],
                PageCursor::resource(PageCursor::context('page'), self::ID, self::ID),
            ),
        ] as $factory) {
            try {
                $factory();
                self::fail('The page shape was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function item(
        bool $vm = true,
        bool $datastore = true,
        EvidenceFreshness $freshness = EvidenceFreshness::Fresh,
        string $setRevision = '1',
    ): ExecutorPermissionEvidenceItem {
        return new ExecutorPermissionEvidenceItem(
            self::ID, self::ID, self::ID, self::ID, self::ID, self::ID, self::ID,
            new UInt64Decimal($setRevision), self::OTHER, 1, 1, 1, '2026-07-18T10:00:00.000000Z', $freshness,
            $vm, $datastore, $vm && $datastore,
        );
    }
}
