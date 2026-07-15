<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Target\ReadModel;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Target\ReadModel\ConfiguredBackupTarget;
use App\Application\Target\ReadModel\ConfiguredBackupTargetAllowedNode;
use App\Domain\Target\TargetActivationBlocker;
use App\Application\Target\ReadModel\ConfiguredBackupTargetPage;
use App\Application\Target\ReadModel\ConfiguredBackupTargetQuery;
use App\Domain\Shared\UInt64Decimal;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConfiguredBackupTargetModelTest extends TestCase
{
    private const string ID = '00112233-4455-6677-8899-aabbccddeeff';

    public function testClosedProjectionSerializesDraftAndDisabledStateWithoutActivationLogic(): void
    {
        self::assertSame(
            [
                'minimum_free_unconfigured', 'allowed_nodes_empty', 'concurrency_unconfigured',
                'candidate_evidence_missing', 'candidate_rejected', 'candidate_evidence_stale',
                'candidate_evidence_future', 'inventory_evidence_missing', 'inventory_evidence_stale',
                'inventory_evidence_future', 'capacity_evidence_missing', 'capacity_evidence_stale',
                'capacity_evidence_future', 'executor_evidence_missing', 'executor_evidence_stale',
                'executor_evidence_future', 'executor_unauthorized', 'pbs_mapping_required',
                'pbs_mapping_unexpected',
            ],
            array_column(TargetActivationBlocker::cases(), 'value'),
        );
        $target = $this->target(false, '2026-07-12T10:00:00.000000Z');
        $payload = $target->toArray();

        self::assertSame([
            'id' => self::ID,
            'revision' => 3,
            'enabled' => false,
            'displayName' => 'Nightly',
            'connectionId' => self::ID,
            'connectionName' => 'PVE',
            'clusterId' => self::ID,
            'clusterName' => 'cluster-a',
            'storageId' => self::ID,
            'storageName' => 'backup',
            'storageType' => 'dir',
            'minimumFreeBytes' => '18446744073709551615',
            'fixedParallelLimit' => null,
            'pbsConnectionId' => null,
            'pbsDatastoreId' => null,
            'pbsNamespaceId' => null,
            'disabledAt' => '2026-07-12T10:00:00.000000Z',
            'allowedNodes' => [
                ['id' => '21112233-4455-6677-8899-aabbccddeeff', 'name' => 'node-a'],
                ['id' => '11112233-4455-6677-8899-aabbccddeeff', 'name' => 'node-b'],
            ],
            'canEnable' => false,
            'blockers' => ['concurrency_unconfigured'],
        ], $payload);

        self::assertNull($this->target(false, null)->disabledAt, 'An enabled=false row without timestamp remains distinguishable as a draft.');
    }

    public function testQueryBindsCursorToSearchAndEnabledFilters(): void
    {
        $query = new ConfiguredBackupTargetQuery(new PageRequest(1), 'Nightly', false);
        $cursor = PageCursor::resource($query->cursorContext(), 'Target', self::ID);
        $continued = new ConfiguredBackupTargetQuery(new PageRequest(1, $cursor), 'Nightly', false);
        self::assertSame($cursor->opaque(), $continued->page->cursor?->opaque());
        self::assertNotSame(
            $query->cursorContext(),
            (new ConfiguredBackupTargetQuery(new PageRequest(1), 'Nightly', true))->cursorContext(),
        );
        self::assertNotSame(
            $query->cursorContext(),
            (new ConfiguredBackupTargetQuery(new PageRequest(1), 'nightly', false))->cursorContext(),
        );

        $this->expectException(InvalidArgumentException::class);
        new ConfiguredBackupTargetQuery(new PageRequest(1, $cursor), 'Nightly', true);
    }

    public function testModelRejectsInvalidIdentityStateListsAndPages(): void
    {
        $invalidTargets = [
            fn () => $this->target(true, '2026-07-12T10:00:00.000000Z'),
            fn () => $this->target(false, 'not-utc'),
            fn () => $this->target(false, null, revision: 0),
            fn () => $this->target(false, null, fixedLimit: 0),
            fn () => new ConfiguredBackupTarget(
                'bad', 1, false, 'Target', self::ID, 'PVE', self::ID, 'cluster', self::ID,
                'store', 'dir', null, null, null, null, null, null, [], [],
            ),
            fn () => new ConfiguredBackupTarget(
                self::ID, 1, false, '', self::ID, 'PVE', self::ID, 'cluster', self::ID,
                'store', 'dir', null, null, null, null, null, null, [], [],
            ),
        ];
        foreach ($invalidTargets as $invalid) {
            try {
                $invalid();
                self::fail('Invalid configured target was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        foreach ([
            static fn () => new ConfiguredBackupTargetAllowedNode('bad', 'node'),
            static fn () => new ConfiguredBackupTargetAllowedNode(self::ID, ''),
            static fn () => new ConfiguredBackupTargetAllowedNode(self::ID, str_repeat('n', 191)),
            static fn () => new ConfiguredBackupTargetAllowedNode(self::ID, "node\0name"),
            static fn () => new ConfiguredBackupTargetQuery(new PageRequest(), ''),
            static fn () => new ConfiguredBackupTargetQuery(new PageRequest(), str_repeat('x', 191)),
            static fn () => new ConfiguredBackupTargetQuery(new PageRequest(), "target\0name"),
            fn () => (new \ReflectionClass(ConfiguredBackupTarget::class))->newInstanceArgs([
                self::ID, 1, false, 'Target', self::ID, 'PVE', self::ID, 'cluster', self::ID,
                'store', 'dir', null, null, null, null, null, null, [], ['not-a-blocker'],
            ]),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Invalid configured target input was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $unfiltered = new ConfiguredBackupTargetQuery(new PageRequest(), null, null);
        self::assertNotSame($unfiltered->cursorContext(), (new ConfiguredBackupTargetQuery(new PageRequest(), null, true))->cursorContext());

        try {
            (new \ReflectionClass(ConfiguredBackupTarget::class))->newInstanceArgs([
                self::ID, 1, false, 'Target', self::ID, 'PVE', self::ID, 'cluster', self::ID,
                'store', 'dir', null, 1, self::ID, self::ID, self::ID, null, ['not-a-node'], [],
            ]);
            self::fail('Invalid runtime node was accepted.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $duplicate = new ConfiguredBackupTargetAllowedNode(self::ID, 'node');
        try {
            new ConfiguredBackupTarget(
                self::ID, 1, false, 'Target', self::ID, 'PVE', self::ID, 'cluster', self::ID,
                'store', 'dir', null, null, null, null, null, null, [$duplicate, $duplicate], [],
            );
            self::fail('Duplicate allowed nodes were accepted.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $target = $this->target(false, null);
        $pagePayload = (new ConfiguredBackupTargetPage(new PageRequest(1), [$target], null))->toArray()['page'];
        self::assertIsArray($pagePayload);
        self::assertSame(1, $pagePayload['count']);
        foreach ([
            static fn () => new ConfiguredBackupTargetPage(new PageRequest(1), [$target, $target], null),
            static fn () => new ConfiguredBackupTargetPage(
                new PageRequest(1), [], PageCursor::resource(PageCursor::context('target'), 'Target', self::ID),
            ),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Invalid configured target page was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function target(
        bool $enabled,
        ?string $disabledAt,
        int $revision = 3,
        ?int $fixedLimit = null,
    ): ConfiguredBackupTarget {
        return new ConfiguredBackupTarget(
            self::ID,
            $revision,
            $enabled,
            'Nightly',
            self::ID,
            'PVE',
            self::ID,
            'cluster-a',
            self::ID,
            'backup',
            'dir',
            new UInt64Decimal(UInt64Decimal::MAXIMUM),
            $fixedLimit,
            null,
            null,
            null,
            $disabledAt,
            [
                new ConfiguredBackupTargetAllowedNode('11112233-4455-6677-8899-aabbccddeeff', 'node-b'),
                new ConfiguredBackupTargetAllowedNode('21112233-4455-6677-8899-aabbccddeeff', 'node-a'),
            ],
            [
                TargetActivationBlocker::ConcurrencyUnconfigured,
                TargetActivationBlocker::ConcurrencyUnconfigured,
            ],
        );
    }
}
