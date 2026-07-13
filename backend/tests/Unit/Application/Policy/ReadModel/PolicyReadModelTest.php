<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Policy\ReadModel;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Policy\ReadModel\ConfiguredPolicy;
use App\Application\Policy\ReadModel\PolicyBlockerCode;
use App\Application\Policy\ReadModel\PolicyListQuery;
use App\Application\Policy\ReadModel\PolicyPage;
use App\Application\Policy\ReadModel\PolicyRetention;
use App\Application\Policy\ReadModel\PolicySelectionEntry;
use App\Application\Policy\ReadModel\PolicySelectionPage;
use App\Application\Policy\ReadModel\PolicySelectionQuery;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PolicyReadModelTest extends TestCase
{
    private const string ID = '00112233-4455-6677-8899-aabbccddeeff';
    private const string OTHER = '11112233-4455-6677-8899-aabbccddeeff';

    public function testConfiguredPolicyProducesClosedFailClosedProjection(): void
    {
        $retention = new PolicyRetention(null, false, 3, null, 7, null, null, null);
        $policy = new ConfiguredPolicy(
            self::ID, 2, 'draft', 'Nightly', self::ID, 'PVE', self::OTHER, 'cluster-a',
            self::OTHER, 'Target', 500, 'snapshot', 'zstd', 3600, '1000', 300,
            'collector_cycle', $retention, false, null,
            [PolicyBlockerCode::ExecutorEvidenceMissing],
        );

        self::assertSame([
            'legacyMaxFiles' => null, 'keepAll' => false, 'keepLast' => 3,
            'keepHourly' => null, 'keepDaily' => 7, 'keepWeekly' => null,
            'keepMonthly' => null, 'keepYearly' => null,
        ], $retention->toArray());
        self::assertSame([
            'id' => self::ID,
            'revision' => 2,
            'status' => 'draft',
            'displayName' => 'Nightly',
            'connectionId' => self::ID,
            'connectionName' => 'PVE',
            'clusterId' => self::OTHER,
            'clusterName' => 'cluster-a',
            'targetId' => self::OTHER,
            'targetName' => 'Target',
            'priority' => 500,
            'mode' => 'snapshot',
            'compression' => 'zstd',
            'maximumAgeSeconds' => 3600,
            'bytesWrittenThreshold' => '1000',
            'cooldownSeconds' => 300,
            'schedule' => 'collector_cycle',
            'desiredRetention' => $retention->toArray(),
            'retentionExecutionEnabled' => false,
            'failureNotificationRecipients' => [],
            'disabledAt' => null,
            'canEnable' => false,
            'blockers' => ['executor_evidence_missing'],
        ], $policy->toArray());
        $page = (new PolicyPage(new PageRequest(1), [$policy], null))->toArray();
        self::assertIsArray($page['items']);
        self::assertIsArray($page['items'][0]);
        self::assertSame('Nightly', $page['items'][0]['displayName']);
    }

    #[DataProvider('invalidPolicyProvider')]
    public function testConfiguredPolicyRejectsInvalidClosedIdentity(
        string $id,
        int $revision,
        string $status,
        string $name,
        ?string $targetId,
        string $connectionName,
        string $clusterName,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        new ConfiguredPolicy(
            $id, $revision, $status, $name, self::ID, $connectionName, self::OTHER, $clusterName,
            $targetId, null, null, null, null, null, null, null, null, null, false, null, [],
        );
    }

    /** @return iterable<string, array{string, int, string, string, ?string, string, string}> */
    public static function invalidPolicyProvider(): iterable
    {
        yield 'id' => ['invalid', 1, 'draft', 'Name', null, 'PVE', 'cluster'];
        yield 'target id' => [self::ID, 1, 'draft', 'Name', 'invalid', 'PVE', 'cluster'];
        yield 'revision' => [self::ID, 0, 'draft', 'Name', null, 'PVE', 'cluster'];
        yield 'status' => [self::ID, 1, 'unknown', 'Name', null, 'PVE', 'cluster'];
        yield 'name' => [self::ID, 1, 'draft', '', null, 'PVE', 'cluster'];
        yield 'connection name' => [self::ID, 1, 'draft', 'Name', null, '', 'cluster'];
        yield 'cluster name' => [self::ID, 1, 'draft', 'Name', null, 'PVE', ''];
    }

    public function testQueriesBindCursorToEveryFilterAndPolicy(): void
    {
        self::assertNotSame('', (new PolicyListQuery(new PageRequest()))->cursorContext());
        foreach (['draft', 'enabled', 'disabled'] as $status) {
            self::assertNotSame('', (new PolicyListQuery(new PageRequest(), null, $status))->cursorContext());
        }
        $query = new PolicyListQuery(new PageRequest(2), 'Night', 'draft');
        $cursor = PageCursor::resource($query->cursorContext(), 'Nightly', self::ID);
        self::assertSame(
            $query->cursorContext(),
            (new PolicyListQuery(new PageRequest(2, $cursor), 'Night', 'draft'))->cursorContext(),
        );
        $selection = new PolicySelectionQuery(self::ID, new PageRequest(2));
        $selectionCursor = PageCursor::resource($selection->cursorContext(), 'assignment:global', self::ID);
        self::assertSame(
            $selection->cursorContext(),
            (new PolicySelectionQuery(self::ID, new PageRequest(2, $selectionCursor)))->cursorContext(),
        );
    }

    #[DataProvider('invalidQueryProvider')]
    public function testPolicyListQueryRejectsInvalidFilters(?string $search, ?string $status): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PolicyListQuery(new PageRequest(), $search, $status);
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function invalidQueryProvider(): iterable
    {
        yield 'empty search' => ['', null];
        yield 'long search' => [str_repeat('a', 191), null];
        yield 'nul search' => ["a\0b", null];
        yield 'leading nul search' => ["\0a", null];
        yield 'status' => [null, 'active'];
    }

    public function testSelectionEntryAndPagesSerializeWithoutHiddenFields(): void
    {
        $entry = new PolicySelectionEntry(
            self::ID, 1, 'active', 'assignment', 'guest', self::ID, self::OTHER,
            null, self::OTHER, 'vm-100', 'include', null, null, null, null,
        );
        $policyPage = new PolicyPage(new PageRequest(1), [], null);
        $selectionPage = new PolicySelectionPage(new PageRequest(1), [$entry], null);

        self::assertSame([], $policyPage->toArray()['items']);
        self::assertSame([
            'id' => self::ID,
            'revision' => 1,
            'status' => 'active',
            'kind' => 'assignment',
            'scope' => 'guest',
            'connectionId' => self::ID,
            'clusterId' => self::OTHER,
            'nodeId' => null,
            'guestId' => self::OTHER,
            'subjectName' => 'vm-100',
            'selectionValue' => 'include',
            'mode' => null,
            'compression' => null,
            'desiredRetention' => null,
            'disabledAt' => null,
        ], $entry->toArray());
        $serialized = $selectionPage->toArray();
        $items = $serialized['items'];
        self::assertIsArray($items);
        self::assertIsArray($items[0]);
        self::assertSame('include', $items[0]['selectionValue']);
        $page = $serialized['page'];
        self::assertIsArray($page);
        self::assertSame(1, $page['count']);
    }

    #[DataProvider('validSelectionEntryProvider')]
    public function testSelectionEntryAcceptsEveryClosedStatusKindAndScope(
        string $status,
        string $kind,
        string $scope,
    ): void {
        $entry = new PolicySelectionEntry(
            self::ID, 1, $status, $kind, $scope, null, null, null, null,
            null, 'assignment' === $kind ? 'include' : null, null, null, null, null,
        );
        self::assertSame([$status, $kind, $scope], [$entry->status, $entry->kind, $entry->scope]);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function validSelectionEntryProvider(): iterable
    {
        yield 'active global assignment' => ['active', 'assignment', 'global'];
        yield 'disabled connection override' => ['disabled', 'guest_override', 'connection'];
        yield 'cluster' => ['active', 'assignment', 'cluster'];
        yield 'node' => ['active', 'assignment', 'node'];
        yield 'guest' => ['active', 'assignment', 'guest'];
    }

    #[DataProvider('invalidSelectionEntryProvider')]
    public function testSelectionEntryRejectsUnknownClosedValues(
        int $revision,
        string $status,
        string $kind,
        string $scope,
        ?string $guestId,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        new PolicySelectionEntry(
            self::ID, $revision, $status, $kind, $scope, null, null, null, $guestId,
            null, null, null, null, null, null,
        );
    }

    /** @return iterable<string, array{int, string, string, string, ?string}> */
    public static function invalidSelectionEntryProvider(): iterable
    {
        yield 'optional identifier' => [1, 'active', 'assignment', 'guest', 'invalid'];
        yield 'revision' => [0, 'active', 'assignment', 'global', null];
        yield 'status' => [1, 'unknown', 'assignment', 'global', null];
        yield 'kind' => [1, 'active', 'unknown', 'global', null];
        yield 'scope' => [1, 'active', 'assignment', 'unknown', null];
    }

    public function testPagesRejectEmptyContinuationAndOversizedItems(): void
    {
        $cursor = PageCursor::resource(PageCursor::context('x'), 'x', self::ID);
        try {
            new PolicyPage(new PageRequest(1), [], $cursor);
            self::fail('Empty policy continuation must fail.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            new PolicySelectionPage(new PageRequest(1), [], $cursor);
            self::fail('Empty selection continuation must fail.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        new PolicySelectionPage(new PageRequest(1), [
            new PolicySelectionEntry(self::ID, 1, 'active', 'assignment', 'global', null, null, null, null, null, 'include', null, null, null, null),
            new PolicySelectionEntry(self::OTHER, 1, 'active', 'assignment', 'global', null, null, null, null, null, 'exclude', null, null, null, null),
        ], null);
    }
}
