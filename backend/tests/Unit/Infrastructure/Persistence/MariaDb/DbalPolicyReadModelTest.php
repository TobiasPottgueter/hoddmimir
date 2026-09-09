<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\MariaDb;

use App\Application\Configuration\Policy\PolicyActivationAssessor;
use App\Application\Configuration\Policy\PolicyActivationEvidence;
use App\Application\Configuration\Policy\PolicyActivationEvidenceProvider;
use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Policy\ReadModel\PolicyListQuery;
use App\Application\Policy\ReadModel\PolicySelectionQuery;
use App\Infrastructure\Persistence\MariaDb\DbalPolicyReadModel;
use App\Domain\Policy\PolicyId;
use App\Domain\Scheduler\EvidenceFreshnessPolicy;
use App\Domain\Shared\Clock;
use App\Domain\Target\ActivationEvidenceObservation;
use DateTimeImmutable;
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

        $page = $this->model($database)->policies(
            new PolicyListQuery(new PageRequest(1), '50%_\\', 'draft'),
        );

        self::assertCount(1, $page->items);
        self::assertNotNull($page->nextCursor);
        self::assertSame('18446744073709551615', $page->items[0]->bytesWrittenThreshold);
        self::assertSame(['alerts@example.test'], $page->items[0]->failureNotificationRecipients);
        self::assertSame([
            'target_unconfigured', 'mode_unconfigured', 'compression_unconfigured',
            'retention_unconfigured', 'priority_unconfigured', 'schedule_unconfigured',
        ], $page->items[0]->toArray()['blockers']);
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

        $page = $this->model($database)->policies(
            new PolicyListQuery(new PageRequest(1, $cursor)),
        );
        self::assertSame([], $page->items[0]->toArray()['blockers']);
        self::assertTrue($page->items[0]->toArray()['canEnable']);
    }

    public function testTargetDefaultsResolveActivationAndExposeEffectiveValues(): void
    {
        $row = $this->policyRow('Inherited');
        $row['target_id'] = str_repeat("\x04", 16);
        $row['target_name'] = 'Target';
        $row['policy_priority'] = '500';
        $row['schedule'] = 'collector_cycle';
        $row['default_backup_mode'] = 'stop';
        $row['default_compression'] = 'gzip';
        $row['default_keep_last'] = '7';
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('fetchAllAssociative')->willReturn([$row]);
        $data = $this->model($database)->policies(new PolicyListQuery(new PageRequest(1)))->items[0]->toArray();
        self::assertSame([], $data['blockers']);
        self::assertTrue($data['canEnable']);
        self::assertNull($data['mode']);
        self::assertSame('stop', $data['effectiveMode']);
        self::assertSame('gzip', $data['effectiveCompression']);
        self::assertIsArray($data['effectiveRetention']);
        self::assertSame(7, $data['effectiveRetention']['keepLast']);
    }

    public function testEmptyFailureRecipientsAreProjectedAsAnActivationBlocker(): void
    {
        $row = $this->policyRow('No mail recipient');
        $row['failure_notification_recipients_json'] = '[]';
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('fetchAllAssociative')->willReturn([$row]);

        $item = $this->model($database)
            ->policies(new PolicyListQuery(new PageRequest(1)))->items[0]->toArray();

        self::assertFalse($item['canEnable']);
        self::assertIsArray($item['blockers']);
        self::assertContains('failure_notification_recipients_unconfigured', $item['blockers']);
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

        $page = $this->model($database)->selection(
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
        $this->model($database)->policies(new PolicyListQuery(new PageRequest(1)));
    }

    public function testDatabaseIntegerAtPlatformMaximumIsAcceptedWithoutSaturation(): void
    {
        $row = $this->policyRow('Alpha');
        $row['maximum_age_seconds'] = '000'.PHP_INT_MAX;
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('fetchAllAssociative')->willReturn([$row]);

        $item = $this->model($database)
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
        $this->model($database)->policies(new PolicyListQuery(new PageRequest(1)));
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

    private function model(Connection $connection): DbalPolicyReadModel
    {
        return new DbalPolicyReadModel($connection, new PolicyProjectionEvidence(),
            new PolicyActivationAssessor(), new PolicyProjectionClock(), new EvidenceFreshnessPolicy());
    }
}

final class PolicyProjectionEvidence implements PolicyActivationEvidenceProvider
{
    public function policyEvidence(PolicyId $id): PolicyActivationEvidence
    {
        $fresh = new ActivationEvidenceObservation(true, new DateTimeImmutable('2026-07-12T10:00:00Z'));
        return new PolicyActivationEvidence(9, $fresh->observedAt, $fresh, $fresh);
    }
    public function policyEvidenceBatch(array $ids): array
    {
        $result = [];
        foreach ($ids as $id) $result[bin2hex($id->binary())] = $this->policyEvidence($id);
        return $result;
    }
}

final readonly class PolicyProjectionClock implements Clock
{
    public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-07-12T10:00:00Z'); }
}
