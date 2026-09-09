<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Target\ReadModel;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use App\Application\Target\ReadModel\BackupTargetBlockerCode;
use App\Application\Target\ReadModel\BackupTargetCandidate;
use App\Application\Target\ReadModel\BackupTargetCandidatePage;
use App\Application\Target\ReadModel\BackupTargetCandidateQuery;
use App\Application\Target\ReadModel\BackupTargetCapacityStatus;
use App\Application\Target\ReadModel\BackupTargetExecutorEvidence;
use App\Application\Target\ReadModel\BackupTargetExecutorStatus;
use App\Application\Target\ReadModel\EvidenceFreshness;
use App\Application\Target\ReadModel\BackupTargetNodeEvidence;
use App\Application\Target\ReadModel\PbsBackupTargetEvidence;
use App\Application\Target\ReadModel\PbsEndpointMatchStatus;
use App\Domain\Shared\UInt64Decimal;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BackupTargetCandidateModelTest extends TestCase
{
    private const string UUID = '00112233-4455-6677-8899-aabbccddeeff';

    public function testBlockerAndEvidenceEnumsAreClosed(): void
    {
        self::assertSame([
            'storage_inventory_evidence_missing', 'storage_inventory_evidence_stale',
            'storage_inventory_evidence_future', 'executor_evidence_missing',
            'executor_evidence_partial', 'executor_evidence_stale', 'executor_evidence_future',
            'executor_unauthorized',
            'connection_disabled', 'connection_not_pve', 'cluster_archived',
            'storage_archived', 'storage_disabled', 'backup_content_unsupported',
            'no_active_node', 'no_usable_node', 'storage_not_configured_on_node',
            'node_state_missing', 'node_state_evidence_missing', 'node_state_evidence_stale',
            'node_state_evidence_future', 'node_offline', 'node_storage_disabled', 'node_storage_inactive',
            'capacity_unavailable', 'capacity_invalid', 'capacity_evidence_missing',
            'capacity_evidence_stale', 'capacity_evidence_future', 'pbs_mapping_missing',
            'pbs_mapping_evidence_missing', 'pbs_mapping_evidence_stale', 'pbs_mapping_evidence_future',
            'pbs_endpoint_unresolved', 'pbs_endpoint_ambiguous', 'pbs_connection_disabled',
            'pbs_server_missing', 'pbs_datastore_missing', 'pbs_datastore_archived',
            'pbs_datastore_read_only', 'pbs_namespace_missing', 'pbs_namespace_archived',
            'pbs_capacity_missing', 'pbs_capacity_evidence_missing', 'pbs_capacity_evidence_stale',
            'pbs_capacity_evidence_future', 'pbs_remote_capacity_unproven',
        ], array_column(BackupTargetBlockerCode::cases(), 'value'));
        self::assertSame(['missing', 'measured', 'unavailable', 'invalid'], array_column(BackupTargetCapacityStatus::cases(), 'value'));
        self::assertSame(['requires_target_configuration', 'missing', 'partial', 'authorized', 'unauthorized'], array_column(BackupTargetExecutorStatus::cases(), 'value'));
        self::assertSame(['matched', 'unresolved', 'ambiguous'], array_column(PbsEndpointMatchStatus::cases(), 'value'));
    }

    public function testUInt64DecimalIsCanonicalBoundedAndOrdered(): void
    {
        $zero = new UInt64Decimal('0');
        $ten = new UInt64Decimal('10');
        $maximum = new UInt64Decimal(UInt64Decimal::MAXIMUM);
        self::assertTrue($zero->lessThanOrEqual($ten));
        self::assertTrue($ten->lessThanOrEqual($ten));
        self::assertTrue($ten->lessThanOrEqual($maximum));
        self::assertFalse($maximum->lessThanOrEqual($ten));
        foreach (['', '-1', '00', '01', '18446744073709551616'] as $invalid) {
            try {
                new UInt64Decimal($invalid);
                self::fail('An invalid uint64 decimal was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testQueryBindsOpaqueCursorToItsExactFilters(): void
    {
        $unfiltered = new BackupTargetCandidateQuery(new PageRequest(1));
        self::assertSame(PageCursor::context('backup-target-candidates-v1', '', ''), $unfiltered->cursorContext());
        $connectionId = new ReadModelIdentifier(self::UUID);
        $clusterId = new ReadModelIdentifier('11112233-4455-6677-8899-aabbccddeeff');
        $query = new BackupTargetCandidateQuery(new PageRequest(1), $connectionId, $clusterId);
        self::assertSame(
            PageCursor::context('backup-target-candidates-v1', $connectionId->value, $clusterId->value),
            $query->cursorContext(),
        );
        $cursor = PageCursor::resource($query->cursorContext(), 'backup', self::UUID);
        $continued = new BackupTargetCandidateQuery(
            new PageRequest(1, PageCursor::decode($cursor->opaque())),
            $connectionId,
            $clusterId,
        );
        self::assertSame($cursor->opaque(), $continued->page->cursor?->opaque());

        $this->expectException(InvalidArgumentException::class);
        new BackupTargetCandidateQuery(new PageRequest(1, $cursor), $connectionId);
    }

    public function testClosedEvidenceSerializesDecimalBytesAndFailClosedCandidate(): void
    {
        $node = new BackupTargetNodeEvidence(
            self::UUID,
            'pve-a',
            true,
            true,
            true,
            BackupTargetCapacityStatus::Measured,
            new UInt64Decimal(UInt64Decimal::MAXIMUM),
            new UInt64Decimal('1'),
            new UInt64Decimal('18446744073709551614'),
            '2026-07-12T10:00:00.000000Z',
            [],
        );
        self::assertTrue($node->usable());
        self::assertSame('18446744073709551615', $node->toArray()['totalBytes']);

        $pbs = new PbsBackupTargetEvidence(
            'pbs.example.test', 8007, 'primary', null, '2026-07-12T10:00:00.000000Z',
            PbsEndpointMatchStatus::Matched, self::UUID, self::UUID, self::UUID, self::UUID,
            'datastore_filesystem', new UInt64Decimal('100'), new UInt64Decimal('10'), new UInt64Decimal('90'), '2026-07-12T10:00:00.000000Z', [],
        );
        $candidate = new BackupTargetCandidate(
            self::UUID, self::UUID, 'PVE', self::UUID, 'cluster-a', 'backup', 'pbs', true,
            'active', '2026-07-12T10:00:00.000000Z', [$node], $pbs,
            [BackupTargetBlockerCode::ExecutorEvidenceMissing, BackupTargetBlockerCode::ExecutorEvidenceMissing],
        );
        self::assertFalse($candidate->canEnable());
        self::assertSame(['executor_evidence_missing'], $candidate->toArray()['blockers']);
        $serializedPbs = $candidate->toArray()['pbs'];
        self::assertSame([
            'server' => 'pbs.example.test',
            'port' => 8007,
            'datastore' => 'primary',
            'namespace' => null,
            'mappingObservedAt' => '2026-07-12T10:00:00.000000Z',
            'endpointMatch' => 'matched',
            'pbsConnectionId' => self::UUID,
            'pbsServerId' => self::UUID,
            'pbsDatastoreId' => self::UUID,
            'pbsNamespaceId' => self::UUID,
            'capacitySemantics' => 'datastore_filesystem',
            'totalBytes' => '100',
            'usedBytes' => '10',
            'availableBytes' => '90',
            'capacityObservedAt' => '2026-07-12T10:00:00.000000Z',
            'blockers' => [],
        ], $serializedPbs);
        $enabledPbs = new BackupTargetCandidate(
            self::UUID, self::UUID, 'PVE', self::UUID, 'cluster-a', 'backup', 'pbs', true,
            'active', '2026-07-12T10:00:00.000000Z', [$node], $pbs, [], self::authorizedExecutor(),
        );
        self::assertTrue($enabledPbs->canEnable());
        $enabled = new BackupTargetCandidate(
            self::UUID, self::UUID, 'PVE', self::UUID, 'cluster-a', 'backup', 'dir', true,
            'active', '2026-07-12T10:00:00.000000Z', [$node], null, [], self::authorizedExecutor(),
        );
        self::assertTrue($enabled->canEnable());
        $pbsBlocked = new PbsBackupTargetEvidence(
            'pbs', 8007, 'store', null, '2026-07-12T10:00:00.000000Z',
            PbsEndpointMatchStatus::Unresolved, null, null, null, null,
            null, null, null, null, null,
            [BackupTargetBlockerCode::PbsEndpointUnresolved, BackupTargetBlockerCode::PbsEndpointUnresolved],
        );
        $blockedOnlyByPbs = new BackupTargetCandidate(
            self::UUID, self::UUID, 'PVE', self::UUID, 'cluster', 'store', 'pbs', true,
            'active', '2026-07-12T10:00:00.000000Z', [$node], $pbsBlocked, [],
        );
        self::assertFalse($blockedOnlyByPbs->canEnable());
        self::assertSame(['pbs_endpoint_unresolved'], $pbsBlocked->toArray()['blockers']);
        $blockedNode = new BackupTargetNodeEvidence(
            self::UUID, 'node', true, true, true, BackupTargetCapacityStatus::Measured,
            new UInt64Decimal('1'), new UInt64Decimal('0'), new UInt64Decimal('1'), '2026-07-12T10:00:00.000000Z',
            [BackupTargetBlockerCode::CapacityInvalid, BackupTargetBlockerCode::CapacityInvalid],
        );
        self::assertFalse($blockedNode->usable());
        self::assertSame(['capacity_invalid'], $blockedNode->toArray()['blockers']);
    }

    private static function authorizedExecutor(): BackupTargetExecutorEvidence
    {
        return new BackupTargetExecutorEvidence(
            BackupTargetExecutorStatus::Authorized,
            1,
            1,
            1,
            true,
            true,
            true,
            EvidenceFreshness::Fresh,
            '2026-07-12T10:00:00.000000Z',
            [],
        );
    }

    public function testExecutorEvidenceIsClosedAndFailClosed(): void
    {
        $requiresTarget = new BackupTargetExecutorEvidence(
            BackupTargetExecutorStatus::RequiresTargetConfiguration,
            0, 0, 0, null, null, null, EvidenceFreshness::Missing, null, [],
        );
        self::assertFalse($requiresTarget->usable());
        self::assertSame('requires_target_configuration', $requiresTarget->toArray()['status']);
        self::assertTrue(self::authorizedExecutor()->usable());

        foreach ([
            static fn () => new BackupTargetExecutorEvidence(BackupTargetExecutorStatus::Missing, 1, 1, 2, null, null, null, EvidenceFreshness::Missing, null, []),
            static fn () => new BackupTargetExecutorEvidence(BackupTargetExecutorStatus::RequiresTargetConfiguration, 1, 0, 0, null, null, null, EvidenceFreshness::Missing, null, []),
            static fn () => new BackupTargetExecutorEvidence(BackupTargetExecutorStatus::Authorized, 1, 1, 1, true, true, false, EvidenceFreshness::Fresh, '2026-07-12T10:00:00.000000Z', []),
            static fn () => new BackupTargetExecutorEvidence(BackupTargetExecutorStatus::Unauthorized, 1, 1, 1, true, true, true, EvidenceFreshness::Fresh, '2026-07-12T10:00:00.000000Z', []),
            static fn () => new BackupTargetExecutorEvidence(BackupTargetExecutorStatus::Missing, 1, 1, 0, null, null, null, EvidenceFreshness::Fresh, null, []),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Invalid executor evidence was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testNodeUsabilityRequiresPositiveFactsEvenWhenBlockersAreEmpty(): void
    {
        $contradictoryNode = new BackupTargetNodeEvidence(
            self::UUID, 'node', false, true, true, BackupTargetCapacityStatus::Measured,
            new UInt64Decimal('100'), new UInt64Decimal('10'), new UInt64Decimal('90'),
            '2026-07-12T10:00:00.000000Z', [],
        );
        self::assertFalse($contradictoryNode->usable());
        $cases = [
            [false, true, BackupTargetCapacityStatus::Measured],
            [null, true, BackupTargetCapacityStatus::Measured],
            [true, false, BackupTargetCapacityStatus::Measured],
            [true, null, BackupTargetCapacityStatus::Measured],
            [true, true, BackupTargetCapacityStatus::Missing],
            [true, true, BackupTargetCapacityStatus::Unavailable],
            [true, true, BackupTargetCapacityStatus::Invalid],
        ];
        foreach ($cases as [$enabled, $active, $status]) {
            $measured = BackupTargetCapacityStatus::Measured === $status;
            $node = new BackupTargetNodeEvidence(
                self::UUID,
                'node',
                true,
                $enabled,
                $active,
                $status,
                $measured ? new UInt64Decimal('100') : null,
                $measured ? new UInt64Decimal('10') : null,
                $measured ? new UInt64Decimal('90') : null,
                '2026-07-12T10:00:00.000000Z',
                [],
            );
            self::assertFalse($node->usable());
        }
        $missingObservation = new BackupTargetNodeEvidence(
            self::UUID, 'node', true, true, true, BackupTargetCapacityStatus::Measured,
            new UInt64Decimal('100'), new UInt64Decimal('10'), new UInt64Decimal('90'), null, [],
        );
        self::assertFalse($missingObservation->usable());
        $candidate = new BackupTargetCandidate(
            self::UUID, self::UUID, 'PVE', self::UUID, 'cluster', 'store', 'dir', true,
            'active', '2026-07-12T10:00:00.000000Z', [$contradictoryNode], null, [],
        );
        self::assertFalse($candidate->canEnable());
    }

    public function testAllReachableCandidateAndCapacityBranchesFailClosed(): void
    {
        $node = new BackupTargetNodeEvidence(
            self::UUID, 'node', true, true, true, BackupTargetCapacityStatus::Measured,
            new UInt64Decimal('100'), new UInt64Decimal('10'), new UInt64Decimal('90'),
            '2026-07-12T10:00:00.000000Z', [],
        );
        $archived = new BackupTargetCandidate(
            self::UUID, self::UUID, 'PVE', self::UUID, 'cluster', 'store', 'dir', true,
            'archived', '2026-07-12T10:00:00.000000Z', [$node], null, [],
        );
        self::assertFalse($archived->canEnable());

        $ambiguous = new PbsBackupTargetEvidence(
            'pbs', 8007, 'store', null, '2026-07-12T10:00:00.000000Z',
            PbsEndpointMatchStatus::Ambiguous, null, null, null, null,
            null, null, null, null, null,
            [BackupTargetBlockerCode::PbsEndpointUnresolved, BackupTargetBlockerCode::PbsEndpointAmbiguous],
        );
        self::assertSame('ambiguous', $ambiguous->endpointMatch->value);

        foreach ([
            static fn () => new BackupTargetNodeEvidence(
                self::UUID, 'node', true, true, true, BackupTargetCapacityStatus::Measured,
                new UInt64Decimal('100'), new UInt64Decimal('101'), new UInt64Decimal('0'),
                '2026-07-12T10:00:00.000000Z', [],
            ),
            static fn () => new BackupTargetNodeEvidence(
                self::UUID, 'node', true, true, true, BackupTargetCapacityStatus::Measured,
                new UInt64Decimal('100'), new UInt64Decimal('0'), new UInt64Decimal('101'),
                '2026-07-12T10:00:00.000000Z', [],
            ),
            static fn () => new PbsBackupTargetEvidence(
                'pbs', 8007, 'store', null, '2026-07-12T10:00:00.000000Z',
                PbsEndpointMatchStatus::Matched, self::UUID, self::UUID, self::UUID, self::UUID,
                'datastore_filesystem', new UInt64Decimal('100'), new UInt64Decimal('101'),
                new UInt64Decimal('0'), '2026-07-12T10:00:00.000000Z', [],
            ),
            static fn () => new PbsBackupTargetEvidence(
                'pbs', 8007, 'store', null, '2026-07-12T10:00:00.000000Z',
                PbsEndpointMatchStatus::Matched, self::UUID, self::UUID, self::UUID, self::UUID,
                'datastore_filesystem', new UInt64Decimal('100'), new UInt64Decimal('0'),
                new UInt64Decimal('101'), '2026-07-12T10:00:00.000000Z', [],
            ),
            static fn () => new PbsBackupTargetEvidence(
                'pbs', 8007, 'store', null, '2026-07-12T10:00:00.000000Z',
                PbsEndpointMatchStatus::Matched, self::UUID, self::UUID, self::UUID, null,
                null, null, null, null, null, [],
            ),
            static fn () => new PbsBackupTargetEvidence(
                'pbs', 8007, 'store', null, '2026-07-12T10:00:00.000000Z',
                PbsEndpointMatchStatus::Unresolved, null, null, null, null,
                null, null, null, null, null, [],
            ),
            static fn () => new PbsBackupTargetEvidence(
                'pbs', 8007, 'store', null, '2026-07-12T10:00:00.000000Z',
                PbsEndpointMatchStatus::Ambiguous, null, null, null, null,
                null, null, null, null, null, [BackupTargetBlockerCode::PbsEndpointUnresolved],
            ),
            static fn () => new PbsBackupTargetEvidence(
                'pbs', 8007, 'store', null, '2026-07-12T10:00:00.000000Z',
                PbsEndpointMatchStatus::Unresolved, self::UUID, null, null, null,
                null, null, null, null, null, [BackupTargetBlockerCode::PbsEndpointUnresolved],
            ),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Invalid target evidence was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testPageAndNodeInvariantsFailClosed(): void
    {
        try {
            new BackupTargetNodeEvidence(
                self::UUID, 'node', true, true, true, BackupTargetCapacityStatus::Measured,
                null, new UInt64Decimal('1'), new UInt64Decimal('1'), null, [],
            );
            self::fail('Incomplete measured capacity was accepted.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        try {
            new BackupTargetNodeEvidence(
                self::UUID, 'node', true, true, true, BackupTargetCapacityStatus::Unavailable,
                new UInt64Decimal('-1'), null, null, null, [],
            );
            self::fail('Non-decimal capacity was accepted.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        foreach ([
            static fn () => new BackupTargetNodeEvidence(
                'bad', 'node', true, null, null, BackupTargetCapacityStatus::Missing,
                null, null, null, null, [],
            ),
            static fn () => new BackupTargetNodeEvidence(
                self::UUID, '', true, null, null, BackupTargetCapacityStatus::Missing,
                null, null, null, null, [],
            ),
            static fn () => new BackupTargetNodeEvidence(
                self::UUID, 'node', true, null, null, BackupTargetCapacityStatus::Missing,
                null, null, null, 'not-utc', [],
            ),
            static fn () => new PbsBackupTargetEvidence(
                '', 0, '', null, 'bad', PbsEndpointMatchStatus::Unresolved,
                null, null, null, null, null, null, null, null, null, [],
            ),
            static fn () => new PbsBackupTargetEvidence(
                'pbs', 8007, 'store', null, '2026-07-12T10:00:00.000000Z',
                PbsEndpointMatchStatus::Matched, 'bad', null, null, null,
                'datastore_filesystem', new UInt64Decimal('1'), null, new UInt64Decimal('1'), null, [],
            ),
            static fn () => new PbsBackupTargetEvidence(
                'pbs', 8007, 'store', null, '2026-07-12T10:00:00.000000Z',
                PbsEndpointMatchStatus::Matched, null, null, null, null,
                'datastore_filesystem', new UInt64Decimal('bad'), new UInt64Decimal('1'), new UInt64Decimal('1'), '2026-07-12T10:00:00.000000Z', [],
            ),
            static fn () => new PbsBackupTargetEvidence(
                'pbs', 8007, 'store', null, 'bad', PbsEndpointMatchStatus::Unresolved,
                null, null, null, null, null, null, null, null, null, [],
            ),
            static fn () => new PbsBackupTargetEvidence(
                'pbs', 8007, 'store', null, '2026-07-12T10:00:00.000000Z',
                PbsEndpointMatchStatus::Matched, null, null, null, null,
                'datastore_filesystem', null, null, null, null, [],
            ),
            static fn () => new PbsBackupTargetEvidence(
                'pbs', 8007, 'store', null, '2026-07-12T10:00:00.000000Z',
                PbsEndpointMatchStatus::Matched, null, null, null, null,
                'datastore_filesystem', new UInt64Decimal('1'), new UInt64Decimal('1'), new UInt64Decimal('1'), 'bad', [],
            ),
            static fn () => new BackupTargetCandidate(
                self::UUID, self::UUID, '', self::UUID, 'cluster', 'store', 'dir', false,
                'active', 'bad', [], null, [],
            ),
            static fn () => new BackupTargetCandidate(
                self::UUID, self::UUID, 'PVE', self::UUID, 'cluster', 'store', 'dir', false,
                'other', '2026-07-12T10:00:00.000000Z', [], null, [],
            ),
            static fn () => new BackupTargetCandidate(
                self::UUID, self::UUID, 'PVE', self::UUID, 'cluster', 'store', 'dir', false,
                'active', 'bad', [], null, [],
            ),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Invalid target-candidate evidence was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        /** @var list<BackupTargetNodeEvidence> $invalidNodes */
        $invalidNodes = ['not-node']; // @phpstan-ignore varTag.nativeType
        try {
            new BackupTargetCandidate(
                self::UUID, self::UUID, 'PVE', self::UUID, 'cluster', 'store', 'dir', false,
                'active', '2026-07-12T10:00:00.000000Z', $invalidNodes, null, [],
            );
            self::fail('An invalid node-evidence list was accepted.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $page = new BackupTargetCandidatePage(
            new PageRequest(1),
            [new BackupTargetCandidate(
                self::UUID, self::UUID, 'PVE', self::UUID, 'cluster', 'store', 'dir', false,
                'active', '2026-07-12T10:00:00.000000Z', [], null, [],
            )],
            null,
        );
        $serializedPage = $page->toArray()['page'];
        self::assertIsArray($serializedPage);
        self::assertSame(1, $serializedPage['count']);
        try {
            new BackupTargetCandidatePage(new PageRequest(1), [$page->items[0], $page->items[0]], null);
            self::fail('An oversized target page was accepted.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        new BackupTargetCandidatePage(
            new PageRequest(1),
            [],
            PageCursor::resource(PageCursor::context('candidate'), 'backup', self::UUID),
        );
    }
}
