<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Inventory\PbsContent;

use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Inventory\PbsContent\PbsContentRunStatus;
use App\Application\Inventory\PbsContent\PbsContentScopeResult;
use App\Application\Inventory\PbsContent\PbsContentScopeStatus;
use App\Application\Inventory\PbsContent\ReadPbsContent;
use App\Application\Proxmox\Pbs\PbsBackupType;
use App\Application\Proxmox\Pbs\PbsContentClient;
use App\Application\Proxmox\Pbs\PbsContentLimits;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsEffectivePermission;
use App\Application\Proxmox\Pbs\PbsNamespace;
use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use App\Application\Proxmox\Pbs\PbsSnapshotObservation;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ReadPbsContentTest extends TestCase
{
    public function testCompletePropagatedAuditScanReadsEveryNamespace(): void
    {
        $store = new PbsDatastoreId('store_a');
        $client = new FixtureContentClient(
            [$store->value => [PbsNamespace::root(), new PbsNamespace('tenant')]],
            [
                $store->value."\0" => [$this->snapshot($store, PbsNamespace::root(), '100', 1)],
                $store->value."\0tenant" => [$this->snapshot($store, new PbsNamespace('tenant'), '101', 2)],
            ],
        );
        $checkpoint = new CountingCheckpoint();
        $result = (new ReadPbsContent(new PbsContentLimits()))->read($client, [$store], $checkpoint);

        self::assertSame(PbsContentRunStatus::Succeeded, $result->status());
        self::assertCount(2, $result->namespaces);
        self::assertCount(2, $result->snapshots);
        self::assertCount(3, $result->scopes);
        self::assertSame(6, $checkpoint->calls);
        self::assertSame(6, $client->permissionCalls);
        self::assertSame([
            ['pbs_namespaces', 'store_a', null, 'complete', 2, null],
            ['pbs_snapshots', 'store_a', '', 'complete', 1, null],
            ['pbs_snapshots', 'store_a', 'tenant', 'complete', 1, null],
        ], array_map($this->scopeProjection(...), $result->scopes));
    }

    public function testAclChangeMakesScopesPartialWithoutDroppingPositiveRows(): void
    {
        $store = new PbsDatastoreId('store_a');
        $client = new FixtureContentClient(
            [$store->value => [PbsNamespace::root()]],
            [$store->value."\0" => [$this->snapshot($store, PbsNamespace::root(), '100', 1)]],
            [
                new PbsEffectivePermission('/datastore/store_a', ['Datastore.Audit' => true]),
                new PbsEffectivePermission('/datastore/store_a', ['Datastore.Audit' => false]),
                new PbsEffectivePermission('/datastore/store_a', ['Datastore.Backup' => true]),
                new PbsEffectivePermission('/datastore/store_a', ['Datastore.Backup' => true]),
            ],
        );
        $result = (new ReadPbsContent(new PbsContentLimits()))->read($client, [$store], new CountingCheckpoint());

        self::assertSame(PbsContentRunStatus::Partial, $result->status());
        self::assertCount(1, $result->snapshots);
        self::assertSame(
            [PbsContentScopeStatus::Partial, PbsContentScopeStatus::Partial],
            array_column($result->scopes, 'status'),
        );

        $afterChange = new FixtureContentClient(
            [$store->value => [PbsNamespace::root()]],
            [$store->value."\0" => [$this->snapshot($store, PbsNamespace::root(), '100', 1)]],
            [
                new PbsEffectivePermission('/datastore/store_a', ['Datastore.Audit' => true]),
                new PbsEffectivePermission('/datastore/store_a', ['Datastore.Audit' => true]),
                new PbsEffectivePermission('/datastore/store_a', ['Datastore.Audit' => true]),
                new PbsEffectivePermission('/datastore/store_a', ['Datastore.Backup' => true]),
            ],
        );
        $changedResult = (new ReadPbsContent(new PbsContentLimits()))->read(
            $afterChange, [$store], new CountingCheckpoint(),
        );
        self::assertSame('acl_incomplete', $changedResult->scopes[1]->errorCode);

        foreach ([
            [
                new PbsEffectivePermission('/datastore/store_a', ['Datastore.Backup' => true]),
                new PbsEffectivePermission('/datastore/store_a', ['Datastore.Audit' => true]),
            ],
            [
                new PbsEffectivePermission('/datastore/store_a', ['Datastore.Audit' => false]),
                new PbsEffectivePermission('/datastore/store_a', ['Datastore.Audit' => true]),
            ],
            [
                new PbsEffectivePermission('/datastore/store_a', ['Datastore.Audit' => true]),
                new PbsEffectivePermission('/datastore/store_a', ['Datastore.Backup' => true]),
            ],
        ] as $permissions) {
            $aclVariant = new FixtureContentClient([$store->value => []], [], $permissions);
            $aclVariantResult = (new ReadPbsContent(new PbsContentLimits()))->read(
                $aclVariant, [$store], new CountingCheckpoint(),
            );
            self::assertSame('acl_incomplete', $aclVariantResult->scopes[0]->errorCode);
        }
    }

    public function testNamespaceAndSnapshotLimitsAreVisibleAndPositiveOnly(): void
    {
        $store = new PbsDatastoreId('store_a');
        $client = new FixtureContentClient(
            [$store->value => [PbsNamespace::root(), new PbsNamespace('a'), new PbsNamespace('b')]],
            [
                $store->value."\0" => [
                    $this->snapshot($store, PbsNamespace::root(), '100', 1),
                    $this->snapshot($store, PbsNamespace::root(), '101', 2),
                ],
                $store->value."\0a" => [$this->snapshot($store, new PbsNamespace('a'), '102', 3)],
            ],
        );
        $limits = new PbsContentLimits(1, 2, 1, 1, 65_536, 1_048_576);
        $result = (new ReadPbsContent($limits))->read($client, [$store], new CountingCheckpoint());

        self::assertSame(PbsContentRunStatus::Partial, $result->status());
        self::assertCount(2, $result->namespaces);
        self::assertCount(1, $result->snapshots);
        self::assertSame('namespace_limit_exceeded', $result->scopes[0]->errorCode);
        self::assertContains('snapshot_limit_exceeded', array_column($result->scopes, 'errorCode'));
        self::assertContains('total_snapshot_limit_exceeded', array_column($result->scopes, 'errorCode'));
        self::assertSame([
            ['pbs_namespaces', 'store_a', null, 'partial', 2, 'namespace_limit_exceeded'],
            ['pbs_snapshots', 'store_a', '', 'partial', 1, 'snapshot_limit_exceeded'],
            ['pbs_snapshots', 'store_a', 'a', 'partial', 0, 'total_snapshot_limit_exceeded'],
        ], array_map($this->scopeProjection(...), $result->scopes));
    }

    public function testTransportFailureIsScopedAndLaterDatastoreContinues(): void
    {
        $a = new PbsDatastoreId('store_a');
        $b = new PbsDatastoreId('store_b');
        $client = new FixtureContentClient(
            [$b->value => [PbsNamespace::root()]],
            [$b->value."\0" => []],
        );
        $client->namespaceFailures[$a->value] = true;
        $result = (new ReadPbsContent(new PbsContentLimits()))->read($client, [$a, $b], new CountingCheckpoint());

        self::assertSame(PbsContentRunStatus::Partial, $result->status());
        self::assertSame(PbsContentScopeStatus::Failed, $result->scopes[0]->status);
        self::assertCount(1, $result->namespaces);
    }

    public function testDatastoreLimitAndSnapshotFailuresRemainVisibleAndPositiveOnly(): void
    {
        $a = new PbsDatastoreId('store_a');
        $b = new PbsDatastoreId('store_b');
        $client = new FixtureContentClient(
            [$a->value => [PbsNamespace::root()]],
            [],
        );
        $client->snapshotFailures[$a->value."\0"] = PbsReadFailure::for(PbsReadFailureCode::Transport);
        $result = (new ReadPbsContent(new PbsContentLimits(1)))->read($client, [$a, $b], new CountingCheckpoint());

        self::assertSame(PbsContentRunStatus::Partial, $result->status());
        self::assertSame(
            ['datastore_limit_exceeded', 'snapshot_read_failed'],
            array_values(array_filter(array_column($result->scopes, 'errorCode'))),
        );

        $invalid = new FixtureContentClient([$a->value => [PbsNamespace::root()]], []);
        $invalid->snapshotFailures[$a->value."\0"] = new \InvalidArgumentException('invalid namespace path');
        $invalidResult = (new ReadPbsContent(new PbsContentLimits()))->read($invalid, [$a], new CountingCheckpoint());
        self::assertSame('snapshot_read_failed', $invalidResult->scopes[1]->errorCode);

        $remainingLimit = new FixtureContentClient(
            [$a->value => [PbsNamespace::root(), new PbsNamespace('tenant')]],
            [
                $a->value."\0" => [$this->snapshot($a, PbsNamespace::root(), '100', 1)],
                $a->value."\0tenant" => [
                    $this->snapshot($a, new PbsNamespace('tenant'), '101', 2),
                    $this->snapshot($a, new PbsNamespace('tenant'), '102', 3),
                ],
            ],
        );
        $remainingResult = (new ReadPbsContent(new PbsContentLimits(1, 2, 2, 2, 65_536, 1_048_576)))
            ->read($remainingLimit, [$a], new CountingCheckpoint());
        self::assertContains('snapshot_limit_exceeded', array_column($remainingResult->scopes, 'errorCode'));
        self::assertCount(2, $remainingResult->snapshots);
    }

    public function testEmptyDatastoreListProducesSuccessfulNoopSnapshot(): void
    {
        $result = (new ReadPbsContent(new PbsContentLimits()))->read(
            new FixtureContentClient([], []), [], new CountingCheckpoint(),
        );
        self::assertSame(PbsContentRunStatus::Succeeded, $result->status());
        self::assertSame([], $result->scopes);
        self::assertSame([], $result->namespaces);
        self::assertSame([], $result->snapshots);
    }

    public function testLongValidNamespaceKeepsSnapshotPositivesWhenAclProbePathIsUnavailable(): void
    {
        $store = new PbsDatastoreId('store_a');
        $namespace = new PbsNamespace(str_repeat('a', 254).'/b');
        self::assertSame(256, strlen($namespace->value));
        $client = new FixtureContentClient(
            [$store->value => [$namespace]],
            [$store->value."\0".$namespace->value => [$this->snapshot($store, $namespace, '100', 1)]],
        );
        $client->enforceOfficialAclPathLimit = true;

        $result = (new ReadPbsContent(new PbsContentLimits()))->read(
            $client, [$store], new CountingCheckpoint(),
        );

        self::assertCount(1, $result->namespaces);
        self::assertCount(1, $result->snapshots);
        self::assertSame(PbsContentScopeStatus::Partial, $result->scopes[1]->status);
        self::assertSame('acl_incomplete', $result->scopes[1]->errorCode);
        self::assertSame(1, $client->snapshotCalls);
    }

    public function testPermissionProbeFailuresKeepSuccessfulSnapshotRowsPositiveOnly(): void
    {
        $store = new PbsDatastoreId('store_a');
        $root = PbsNamespace::root();
        $audit = new PbsEffectivePermission('/datastore/store_a', ['Datastore.Audit' => true]);
        $failures = [
            [$audit, $audit, PbsReadFailure::for(PbsReadFailureCode::Transport)],
            [$audit, $audit, $audit, PbsReadFailure::for(PbsReadFailureCode::Transport)],
            [$audit, $audit, $audit, new \InvalidArgumentException('ACL path unavailable')],
        ];

        foreach ($failures as $permissions) {
            $client = new FixtureContentClient(
                [$store->value => [$root]],
                [$store->value."\0" => [$this->snapshot($store, $root, '100', 1)]],
                $permissions,
            );

            $result = (new ReadPbsContent(new PbsContentLimits()))->read(
                $client,
                [$store],
                new CountingCheckpoint(),
            );

            self::assertCount(1, $result->snapshots);
            self::assertSame(PbsContentScopeStatus::Partial, $result->scopes[1]->status);
            self::assertSame('acl_incomplete', $result->scopes[1]->errorCode);
        }
    }

    private function snapshot(PbsDatastoreId $store, PbsNamespace $namespace, string $id, int $time): PbsSnapshotObservation
    {
        return new PbsSnapshotObservation(
            $store, $namespace, PbsBackupType::Vm, $id, new DateTimeImmutable('@'.$time),
            ['archive.blob'], false, null, null, 'backup@pbs', null, null,
        );
    }

    /** @return array{string, string, ?string, string, int, ?string} */
    private function scopeProjection(PbsContentScopeResult $scope): array
    {
        return [
            $scope->type->value,
            $scope->datastore->value,
            $scope->namespace?->value,
            $scope->status->value,
            $scope->rowsRead,
            $scope->errorCode,
        ];
    }
}

final class CountingCheckpoint implements ConnectionReadCheckpoint
{
    public int $calls = 0;
    public function checkpoint(): void { ++$this->calls; }
}

final class FixtureContentClient implements PbsContentClient
{
    /** @var array<string, true> */ public array $namespaceFailures = [];
    /** @var array<string, PbsReadFailure|\InvalidArgumentException> */ public array $snapshotFailures = [];
    public int $permissionCalls = 0;
    public int $snapshotCalls = 0;
    public bool $enforceOfficialAclPathLimit = false;

    /**
     * @param array<string, list<PbsNamespace>> $namespaceRows
     * @param array<string, list<PbsSnapshotObservation>> $snapshotRows
     * @param list<PbsEffectivePermission|PbsReadFailure|\InvalidArgumentException> $permissions
     */
    public function __construct(
        private readonly array $namespaceRows,
        private readonly array $snapshotRows,
        private array $permissions = [],
    ) {}

    public function permission(string $path): PbsEffectivePermission
    {
        ++$this->permissionCalls;
        if ($this->enforceOfficialAclPathLimit && strlen($path) > 128) {
            throw new \InvalidArgumentException('Official PBS ACL path limit exceeded.');
        }
        if ([] === $this->permissions) {
            return new PbsEffectivePermission($path, ['Datastore.Audit' => true]);
        }
        $result = array_shift($this->permissions);
        if ($result instanceof PbsReadFailure || $result instanceof \InvalidArgumentException) {
            throw $result;
        }
        return $result;
    }

    public function namespaces(PbsDatastoreId $datastore, int $maximumBodyBytes): array
    {
        if (isset($this->namespaceFailures[$datastore->value])) {
            throw PbsReadFailure::for(PbsReadFailureCode::Transport);
        }
        return $this->namespaceRows[$datastore->value] ?? [];
    }

    public function snapshots(PbsDatastoreId $datastore, PbsNamespace $namespace, int $maximumBodyBytes): array
    {
        ++$this->snapshotCalls;
        $key = $datastore->value."\0".$namespace->value;
        if (isset($this->snapshotFailures[$key])) {
            throw $this->snapshotFailures[$key];
        }
        return $this->snapshotRows[$key] ?? [];
    }
}
