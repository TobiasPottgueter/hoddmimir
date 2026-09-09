<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Inventory\ReadModel;

use App\Application\Inventory\ReadModel\CollectorRun;
use App\Application\Inventory\ReadModel\CollectorScope;
use App\Application\Inventory\ReadModel\CollectorScopeQuery;
use App\Application\Inventory\ReadModel\CollectorStatus;
use App\Application\Inventory\ReadModel\InventoryOverview;
use App\Application\Inventory\ReadModel\InventoryResource;
use App\Application\Inventory\ReadModel\InventoryResourceKind;
use App\Application\Inventory\ReadModel\InventoryResourceQuery;
use App\Application\Inventory\ReadModel\InventoryState;
use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageCursorKind;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use App\Application\Inventory\ReadModel\ReadPage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InventoryReadModelTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function invalidPages(): iterable
    {
        yield 'zero limit' => [0];
        yield 'large limit' => [101];
    }

    #[DataProvider('invalidPages')]
    public function testPaginationFailsClosed(int $limit): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PageRequest($limit);
    }

    public function testIdentifiersAndResourceQueryFiltersAreTyped(): void
    {
        $id = new ReadModelIdentifier('00112233-4455-6677-8899-aabbccddeeff');
        self::assertSame('00112233445566778899aabbccddeeff', bin2hex($id->binary()));
        $page = new PageRequest(2);
        $query = new InventoryResourceQuery(
            InventoryResourceKind::PveGuest,
            $page,
            $id,
            $id,
            InventoryState::Archived,
            'lxc',
        );
        self::assertSame('lxc', $query->guestType);
        self::assertSame(
            PageCursor::context(
                'inventory-resources-v1',
                'pve_guest',
                $id->value,
                $id->value,
                'archived',
                'lxc',
            ),
            $query->cursorContext(),
        );
        self::assertTrue(InventoryResourceKind::PveNode->permitsParentFilter());
        self::assertTrue(InventoryResourceKind::PveGuest->permitsParentFilter());
        self::assertTrue(InventoryResourceKind::PveStorage->permitsParentFilter());
        self::assertTrue(InventoryResourceKind::PbsDatastore->permitsParentFilter());
        self::assertTrue(InventoryResourceKind::PbsNamespace->permitsParentFilter());
        self::assertTrue(InventoryResourceKind::PbsBackupGroup->permitsParentFilter());
        self::assertTrue(InventoryResourceKind::PbsSnapshot->permitsParentFilter());
        self::assertFalse(InventoryResourceKind::PveCluster->permitsParentFilter());
        self::assertFalse(InventoryResourceKind::PbsServer->permitsParentFilter());
        self::assertSame('qemu', (new InventoryResourceQuery(
            InventoryResourceKind::PveGuest,
            new PageRequest(),
            guestType: 'qemu',
        ))->guestType);

        $unfiltered = new InventoryResourceQuery(InventoryResourceKind::PveNode, new PageRequest());
        self::assertSame(
            PageCursor::context('inventory-resources-v1', 'pve_node', '', '', '', ''),
            $unfiltered->cursorContext(),
        );
        $scopeQuery = new CollectorScopeQuery($id, new PageRequest());
        self::assertSame(PageCursor::context('collector-scopes-v1', $id->value), $scopeQuery->cursorContext());
    }

    public function testQueryCursorsAcceptOnlyTheirExactContext(): void
    {
        $id = new ReadModelIdentifier('00112233-4455-6677-8899-aabbccddeeff');
        $resourceContext = PageCursor::context('inventory-resources-v1', 'pve_node', '', '', '', '');
        $resourceCursor = PageCursor::resource(
            $resourceContext,
            'node-a',
            '00112233-4455-6677-8899-aabbccddeeff',
        );
        $query = new InventoryResourceQuery(
            InventoryResourceKind::PveNode,
            new PageRequest(cursor: $resourceCursor),
        );
        self::assertSame($resourceContext, $query->cursorContext());

        $scopeContext = PageCursor::context('collector-scopes-v1', $id->value);
        $scope = new CollectorScopeQuery(
            $id,
            new PageRequest(cursor: PageCursor::collectorScope($scopeContext, 'pbs_content', 'store')),
        );
        self::assertSame($scopeContext, $scope->cursorContext());
    }

    public function testOpaqueCursorsRoundTripAndAreBoundToTheirQueryContext(): void
    {
        $id = '00112233-4455-6677-8899-aabbccddeeff';
        $context = PageCursor::context('resource', 'pve_node');
        $resource = PageCursor::resource($context, 'node-a', $id);
        $decodedResource = PageCursor::decode($resource->opaque());
        self::assertSame(PageCursorKind::Resource, $decodedResource->kind);
        self::assertSame('node-a', $decodedResource->first);
        self::assertSame($id, $decodedResource->second);
        $decodedResource->assertContext(PageCursorKind::Resource, $context);

        $run = PageCursor::collectorRun('2026-07-12T10:00:00.000000Z', $id);
        self::assertSame($run->opaque(), PageCursor::decode($run->opaque())->opaque());

        $scope = PageCursor::collectorScope(PageCursor::context('scope', $id), 'pbs_content', 'store:@root');
        self::assertSame($scope->opaque(), PageCursor::decode($scope->opaque())->opaque());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCursors(): iterable
    {
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', 2049)];
        yield 'not base64url' => ['%%%'];
        yield 'base64url with invalid base64 length' => ['A'];
        yield 'two base64url characters' => ['AA'];
        yield 'three base64url characters' => ['AAA'];
        yield 'four base64url characters' => ['AAAA'];
        yield 'url-safe plus replacement' => ['-'];
        yield 'url-safe slash replacement' => ['_'];
        yield 'both url-safe replacements' => ['-_'];
        yield 'invalid json' => [rtrim(strtr(base64_encode('{'), '+/', '-_'), '=')];
        yield 'json scalar' => [self::opaque('value')];
        yield 'unknown version' => [self::opaque(['v' => 2, 'kind' => 'resource', 'context' => str_repeat('a', 64), 'first' => 'node', 'second' => '00112233-4455-6677-8899-aabbccddeeff'])];
        yield 'non-string kind' => [self::opaque(['v' => 1, 'kind' => 1, 'context' => str_repeat('a', 64), 'first' => 'node', 'second' => '00112233-4455-6677-8899-aabbccddeeff'])];
        yield 'non-string context' => [self::opaque(['v' => 1, 'kind' => 'resource', 'context' => 1, 'first' => 'node', 'second' => '00112233-4455-6677-8899-aabbccddeeff'])];
        yield 'non-string first' => [self::opaque(['v' => 1, 'kind' => 'resource', 'context' => str_repeat('a', 64), 'first' => 1, 'second' => '00112233-4455-6677-8899-aabbccddeeff'])];
        yield 'non-string second' => [self::opaque(['v' => 1, 'kind' => 'resource', 'context' => str_repeat('a', 64), 'first' => 'node', 'second' => 1])];
        yield 'unknown kind' => [self::opaque(['v' => 1, 'kind' => 'unknown', 'context' => str_repeat('a', 64), 'first' => 'node', 'second' => '00112233-4455-6677-8899-aabbccddeeff'])];
        yield 'extra field' => [self::opaque(['v' => 1, 'kind' => 'resource', 'context' => str_repeat('a', 64), 'first' => 'node', 'second' => '00112233-4455-6677-8899-aabbccddeeff', 'extra' => true])];
        yield 'bad context' => [self::opaque(['v' => 1, 'kind' => 'resource', 'context' => 'bad', 'first' => 'node', 'second' => '00112233-4455-6677-8899-aabbccddeeff'])];
        yield 'non-hex context' => [self::opaque(['v' => 1, 'kind' => 'resource', 'context' => str_repeat('z', 64), 'first' => 'node', 'second' => '00112233-4455-6677-8899-aabbccddeeff'])];
        yield 'empty resource name' => [self::opaque(['v' => 1, 'kind' => 'resource', 'context' => str_repeat('a', 64), 'first' => '', 'second' => '00112233-4455-6677-8899-aabbccddeeff'])];
        yield 'long resource name' => [self::opaque(['v' => 1, 'kind' => 'resource', 'context' => str_repeat('a', 64), 'first' => str_repeat('a', 256), 'second' => '00112233-4455-6677-8899-aabbccddeeff'])];
        yield 'nul resource name' => [self::opaque(['v' => 1, 'kind' => 'resource', 'context' => str_repeat('a', 64), 'first' => "node\0a", 'second' => '00112233-4455-6677-8899-aabbccddeeff'])];
        yield 'bad resource id' => [self::opaque(['v' => 1, 'kind' => 'resource', 'context' => str_repeat('a', 64), 'first' => 'node', 'second' => 'internal-class-name'])];
        yield 'malformed run date' => [self::opaque(['v' => 1, 'kind' => 'collector_run', 'context' => PageCursor::collectorRunsContext(), 'first' => 'not-a-timestamp', 'second' => '00112233-4455-6677-8899-aabbccddeeff'])];
        yield 'impossible run date' => [self::opaque(['v' => 1, 'kind' => 'collector_run', 'context' => PageCursor::collectorRunsContext(), 'first' => '2026-02-30T10:00:00.000000Z', 'second' => '00112233-4455-6677-8899-aabbccddeeff'])];
        yield 'run date outside MariaDB range' => [self::opaque(['v' => 1, 'kind' => 'collector_run', 'context' => PageCursor::collectorRunsContext(), 'first' => '0000-01-01T00:00:00.000000Z', 'second' => '00112233-4455-6677-8899-aabbccddeeff'])];
        yield 'empty scope type' => [self::opaque(['v' => 1, 'kind' => 'collector_scope', 'context' => str_repeat('a', 64), 'first' => '', 'second' => 'key'])];
        yield 'long scope type' => [self::opaque(['v' => 1, 'kind' => 'collector_scope', 'context' => str_repeat('a', 64), 'first' => str_repeat('a', 33), 'second' => 'key'])];
        yield 'nul scope type' => [self::opaque(['v' => 1, 'kind' => 'collector_scope', 'context' => str_repeat('a', 64), 'first' => "scope\0type", 'second' => 'key'])];
        yield 'empty scope key' => [self::opaque(['v' => 1, 'kind' => 'collector_scope', 'context' => str_repeat('a', 64), 'first' => 'scope', 'second' => ''])];
        yield 'long scope key' => [self::opaque(['v' => 1, 'kind' => 'collector_scope', 'context' => str_repeat('a', 64), 'first' => 'scope', 'second' => str_repeat('a', 513)])];
        yield 'nul scope key' => [self::opaque(['v' => 1, 'kind' => 'collector_scope', 'context' => str_repeat('a', 64), 'first' => 'scope', 'second' => "scope\0key"] )];
    }

    #[DataProvider('invalidCursors')]
    public function testOpaqueCursorValidationFailsClosed(string $opaque): void
    {
        $this->expectException(InvalidArgumentException::class);
        PageCursor::decode($opaque);
    }

    public function testCursorContextMismatchIsRejected(): void
    {
        $cursor = PageCursor::collectorRun(
            '2026-07-12T10:00:00.000000Z',
            '00112233-4455-6677-8899-aabbccddeeff',
        );
        $this->expectException(InvalidArgumentException::class);
        $cursor->assertContext(PageCursorKind::CollectorScope, PageCursor::context('another-query'));
    }

    public function testOpaqueEncodingRejectsInvalidUtf8(): void
    {
        $cursor = PageCursor::resource(
            PageCursor::context('invalid-utf8'),
            "bad\xB1",
            '00112233-4455-6677-8899-aabbccddeeff',
        );
        $this->expectException(InvalidArgumentException::class);
        $cursor->opaque();
    }

    public function testCursorHelpersCoverAllOpaquePaddingShapesAndEmptyContextInput(): void
    {
        self::assertSame(hash('sha256', ''), PageCursor::context());
        self::assertSame(PageCursor::context(), PageCursor::context(''));
        self::assertNotSame(PageCursor::context('', 'part'), PageCursor::context('part', ''));
        $context = PageCursor::context('padding');
        $id = '00112233-4455-6677-8899-aabbccddeeff';
        $remainders = [];
        for ($length = 1; $length <= 16; ++$length) {
            $opaque = PageCursor::collectorScope($context, 'scope', str_repeat('k', $length))->opaque();
            $remainders[strlen($opaque) % 4] = true;
        }
        self::assertSame([0, 2, 3], array_keys($remainders));
        self::assertNotSame('', PageCursor::resource($context, str_repeat('n', 255), $id)->opaque());
        self::assertNotSame('', PageCursor::collectorScope($context, str_repeat('s', 32), str_repeat('k', 512))->opaque());
        $unicode = PageCursor::collectorScope($context, 'scope', "\u{10ffff}");
        self::assertSame($unicode->opaque(), PageCursor::decode($unicode->opaque())->opaque());
    }

    public function testInvalidIdentifierIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReadModelIdentifier('NOT-A-UUID');
    }

    public function testUppercaseIdentifierIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReadModelIdentifier('00112233-4455-6677-8899-AABBCCDDEEFF');
    }

    public function testMisplacedUuidSeparatorsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReadModelIdentifier('001122334-455-6677-8899-aabbccddeeff');
    }

    public function testLowercaseNonHexIdentifierIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReadModelIdentifier('00112233-4455-6677-8899-aabbccddeefg');
    }

    public function testUnseparatedIdentifierIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReadModelIdentifier('00112233445566778899aabbccddeeff');
    }

    public function testParentFilterOnRootResourceIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new InventoryResourceQuery(
            InventoryResourceKind::PveCluster,
            new PageRequest(),
            parentId: new ReadModelIdentifier('00112233-4455-6677-8899-aabbccddeeff'),
        );
    }

    public function testGuestTypeOnAnotherResourceIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new InventoryResourceQuery(InventoryResourceKind::PveNode, new PageRequest(), guestType: 'qemu');
    }

    public function testUnknownGuestTypeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new InventoryResourceQuery(InventoryResourceKind::PveGuest, new PageRequest(), guestType: 'openvz');
    }

    public function testReadDtosProduceTheVersionedShape(): void
    {
        $resource = new InventoryResource(
            'resource',
            InventoryResourceKind::PveCluster,
            'connection',
            'PVE',
            null,
            'cluster-a',
            InventoryState::Active,
            '2026-07-12T10:00:00.000000Z',
            '2026-07-12T10:01:00.000000Z',
            null,
            null,
            ['topology' => 'clustered'],
        );
        $nextCursor = PageCursor::resource(PageCursor::context('page'), 'cluster-a', '00112233-4455-6677-8899-aabbccddeeff');
        $page = new ReadPage(new PageRequest(2), [$resource], $nextCursor);
        $serialized = $page->toArray();
        self::assertSame('pve_cluster', $serialized['items'][0]['kind']);
        self::assertTrue($serialized['page']['hasMore']);
        self::assertSame(1, $serialized['page']['count']);
        self::assertSame($nextCursor->opaque(), $serialized['page']['nextCursor']);

        $run = new CollectorRun(
            'run', 'connection', 'PVE', 'pve', 'succeeded', true,
            'start', 'finish', 'finish', 2, 3, 4, null,
        );
        self::assertSame('succeeded', $run->toArray()['status']);
        $scope = new CollectorScope('run', 'pve_guests', '@installation', 'complete', 'now', 'access_denied');
        self::assertSame('@installation', $scope->toArray()['scopeKey']);
        self::assertSame('access_denied', $scope->toArray()['errorCode']);
        $scopeQuery = new CollectorScopeQuery(
            new ReadModelIdentifier('00112233-4455-6677-8899-aabbccddeeff'),
            new PageRequest(),
        );
        self::assertSame(50, $scopeQuery->page->limit);

        $overview = new InventoryOverview('now', null, ['pveNodes' => 1]);
        self::assertSame(1, $overview->toArray()['counts']['pveNodes']);
        $status = new CollectorStatus('now', ['configured' => true], null);
        $statusArray = $status->toArray();
        self::assertIsArray($statusArray['schedule']);
        self::assertTrue($statusArray['schedule']['configured']);
    }

    public function testReadPageRejectsAdapterOverflow(): void
    {
        $item = new CollectorScope('run', 'scope', 'key', 'complete', 'now');
        $this->expectException(InvalidArgumentException::class);
        new ReadPage(new PageRequest(1), [$item, $item], null);
    }

    public function testReadPageRejectsContinuationCursorForEmptyPage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReadPage(
            new PageRequest(1),
            [],
            PageCursor::collectorRun(
                '2026-07-12T10:00:00.000000Z',
                '00112233-4455-6677-8899-aabbccddeeff',
            ),
        );
    }

    /** @param mixed $payload */
    private static function opaque(mixed $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }
}
