<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\MariaDb;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Policy\ReadModel\PolicyListQuery;
use App\Application\Policy\ReadModel\PolicySelectionQuery;
use App\Infrastructure\Persistence\MariaDb\DbalPolicyReadModel;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DbalPolicyReadModelTest extends TestCase
{
    private const string UUID = '00112233-4455-6677-8899-aabbccddeeff';

    public function testPolicyProjectionBindsLiteralFiltersAndBuildsOpaqueCursor(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('fetchAllAssociative')
            ->with(
                self::callback(static fn (string $sql): bool => str_contains($sql, 'policy.status = :status')
                    && str_contains($sql, 'policy.display_name LIKE :search')),
                self::callback(static fn (array $parameters): bool => '%50\\%\\_\\\\%' === $parameters['search']
                    && 'draft' === $parameters['status'] && 2 === $parameters['limit']),
                self::anything(),
            )->willReturn([$this->policyRow('Alpha'), $this->policyRow('Zulu', str_repeat("\x09", 16))]);

        $page = (new DbalPolicyReadModel($database))->policies(
            new PolicyListQuery(new PageRequest(1), '50%_\\', 'draft'),
        );

        self::assertCount(1, $page->items);
        self::assertNotNull($page->nextCursor);
        self::assertSame('18446744073709551615', $page->items[0]->bytesWrittenThreshold);
        self::assertSame(['alerts@example.test'], $page->items[0]->failureNotificationRecipients);
        self::assertSame(['configuration_incomplete', 'executor_evidence_missing'], $page->items[0]->toArray()['blockers']);
    }

    public function testPolicyCursorAndCompleteConfigurationRemainFailClosedForCommands(): void
    {
        $base = new PolicyListQuery(new PageRequest(1));
        $cursor = PageCursor::resource($base->cursorContext(), 'Alpha', self::UUID);
        $row = $this->policyRow('Beta');
        $row['target_id'] = str_repeat("\x04", 16);
        $row['target_name'] = 'Target';
        $row['policy_priority'] = '500';
        $row['backup_mode'] = 'snapshot';
        $row['compression'] = 'zstd';
        $row['maximum_age_seconds'] = '3600';
        $row['schedule'] = 'collector_cycle';
        $row['keep_last'] = '3';
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('fetchAllAssociative')
            ->with(self::stringContains('policy.id > :cursor_id'), self::anything(), self::anything())
            ->willReturn([$row]);

        $page = (new DbalPolicyReadModel($database))->policies(
            new PolicyListQuery(new PageRequest(1, $cursor)),
        );
        self::assertSame(['executor_evidence_missing'], $page->items[0]->toArray()['blockers']);
        self::assertFalse($page->items[0]->toArray()['canEnable']);
    }

    public function testSelectionProjectionMapsAssignmentsAndGuestOverridesWithCursor(): void
    {
        $assignment = $this->selectionRow('assignment', str_repeat("\x05", 16));
        $override = $this->selectionRow('guest_override', str_repeat("\x06", 16));
        $override['selection_value'] = null;
        $override['backup_mode'] = 'stop';
        $override['keep_last'] = '2';
        $override['sort_key'] = 'guest_override:guest';
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('fetchAllAssociative')
            ->with(self::stringContains('UNION ALL'), self::anything(), self::anything())
            ->willReturn([$assignment, $override]);

        $page = (new DbalPolicyReadModel($database))->selection(
            new PolicySelectionQuery(self::UUID, new PageRequest(1)),
        );
        self::assertCount(1, $page->items);
        self::assertNotNull($page->nextCursor);
        self::assertSame('include', $page->items[0]->selectionValue);
    }

    public function testInvalidDatabaseIdentityFailsClosed(): void
    {
        $row = $this->policyRow('Alpha');
        $row['id'] = 'bad';
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('fetchAllAssociative')->willReturn([$row]);

        $this->expectException(RuntimeException::class);
        (new DbalPolicyReadModel($database))->policies(new PolicyListQuery(new PageRequest(1)));
    }

    public function testDatabaseIntegerAtPlatformMaximumIsAcceptedWithoutSaturation(): void
    {
        $row = $this->policyRow('Alpha');
        $row['maximum_age_seconds'] = '000'.PHP_INT_MAX;
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('fetchAllAssociative')->willReturn([$row]);

        $item = (new DbalPolicyReadModel($database))
            ->policies(new PolicyListQuery(new PageRequest(1)))->items[0];
        self::assertSame(PHP_INT_MAX, $item->maximumAgeSeconds);
    }

    public function testDatabaseIntegerAbovePlatformMaximumFailsClosed(): void
    {
        $row = $this->policyRow('Alpha');
        $row['maximum_age_seconds'] = '9223372036854775808';
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('fetchAllAssociative')->willReturn([$row]);

        $this->expectException(RuntimeException::class);
        (new DbalPolicyReadModel($database))->policies(new PolicyListQuery(new PageRequest(1)));
    }

    /** @return array<string, mixed> */
    private function policyRow(string $name, string $id = "\x00\x11\x22\x33\x44\x55\x66\x77\x88\x99\xaa\xbb\xcc\xdd\xee\xff"): array
    {
        return [
            'id' => $id, 'revision' => '1', 'status' => 'draft', 'display_name' => $name,
            'connection_id' => str_repeat("\x01", 16), 'connection_name' => 'PVE',
            'cluster_id' => str_repeat("\x02", 16), 'cluster_name' => 'cluster-a',
            'target_id' => null, 'target_name' => null, 'policy_priority' => null,
            'backup_mode' => null, 'compression' => null, 'maximum_age_seconds' => null,
            'bytes_written_threshold' => '18446744073709551615', 'cooldown_seconds' => '0',
            'schedule' => null, 'legacy_maxfiles' => null, 'keep_all' => null,
            'keep_last' => null, 'keep_hourly' => null, 'keep_daily' => null,
            'keep_weekly' => null, 'keep_monthly' => null, 'keep_yearly' => null,
            'retention_execution_enabled' => '0',
            'failure_notification_recipients_json' => '["alerts@example.test"]',
            'disabled_at' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function selectionRow(string $kind, string $id): array
    {
        return [
            'id' => $id, 'revision' => '1', 'status' => 'active', 'kind' => $kind,
            'scope' => 'guest', 'connection_id' => str_repeat("\x01", 16),
            'cluster_id' => str_repeat("\x02", 16), 'node_id' => null,
            'guest_id' => str_repeat("\x03", 16), 'subject_name' => 'vm-100',
            'selection_value' => 'include', 'backup_mode' => null, 'compression' => null,
            'legacy_maxfiles' => null, 'keep_all' => null, 'keep_last' => null,
            'keep_hourly' => null, 'keep_daily' => null, 'keep_weekly' => null,
            'keep_monthly' => null, 'keep_yearly' => null, 'disabled_at' => null,
            'sort_key' => 'assignment:guest',
        ];
    }
}
