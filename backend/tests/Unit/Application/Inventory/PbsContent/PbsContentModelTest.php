<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Inventory\PbsContent;

use App\Application\Inventory\PbsContent\PbsContentApplyResult;
use App\Application\Inventory\PbsContent\PbsContentCommit;
use App\Application\Inventory\PbsContent\PbsContentRunFailure;
use App\Application\Inventory\PbsContent\PbsContentRunStart;
use App\Application\Inventory\PbsContent\PbsContentRunStatus;
use App\Application\Inventory\PbsContent\PbsContentScopeResult;
use App\Application\Inventory\PbsContent\PbsContentScopeStatus;
use App\Application\Inventory\PbsContent\PbsContentScopeType;
use App\Application\Inventory\PbsContent\PbsContentSnapshot;
use App\Application\Inventory\PbsContent\PbsNamespaceObservation;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\InstallationBinding;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Proxmox\Pbs\PbsBackupType;
use App\Application\Proxmox\Pbs\PbsContentLimits;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsNamespace;
use App\Application\Proxmox\Pbs\PbsSnapshotObservation;
use App\Application\Proxmox\Pbs\PbsSnapshotVerification;
use App\Application\Proxmox\Pbs\PbsUpid;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PbsContentModelTest extends TestCase
{
    public function testRootNamespaceAndContentIdentitiesAreCanonical(): void
    {
        $root = PbsNamespace::root();
        self::assertTrue($root->isRoot());
        self::assertSame(0, $root->depth());
        self::assertNull($root->parent());
        $nested = new PbsNamespace('tenant/pve');
        self::assertFalse($nested->isRoot());
        self::assertSame(2, $nested->depth());
        self::assertSame('tenant', $nested->parent()?->value);
        self::assertSame('', (new PbsNamespace('tenant'))->parent()?->value);
        self::assertSame('a/b', (new PbsNamespace('a/b/c'))->parent()?->value);
        $store = new PbsDatastoreId('store_a');
        $observation = new PbsNamespaceObservation($store, $root);
        self::assertSame("store_a\0", $observation->key());
        $snapshot = $this->snapshot($root, '100', 'backup@pbs');
        self::assertSame('store_a'."\0"."\0".'vm'."\0".'100', $snapshot->groupKey());
        self::assertStringEndsWith("\0".'1', $snapshot->key());
    }

    #[DataProvider('invalidNamespaceProvider')]
    public function testInvalidNamespacesAreRejected(string $namespace): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PbsNamespace($namespace);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNamespaceProvider(): iterable
    {
        yield 'too long' => [str_repeat('a', 257)];
        yield 'too deep' => ['a/b/c/d/e/f/g/h/i'];
        yield 'empty segment' => ['a//b'];
        yield 'unsafe first' => ['-bad'];
        yield 'unsafe character' => ['bad:value'];
    }

    /** @param array{int, int, int, int, int, int} $arguments */
    #[DataProvider('invalidLimitsProvider')]
    public function testInvalidLimitsAreRejected(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PbsContentLimits(...$arguments);
    }

    /** @return iterable<string, array{array{int, int, int, int, int, int}}> */
    public static function invalidLimitsProvider(): iterable
    {
        yield 'datastore low' => [[0, 1, 1, 1, 65536, 1048576]];
        yield 'namespace high' => [[1, 65537, 1, 1, 65536, 1048576]];
        yield 'snapshot high' => [[1, 1, 1048577, 1048577, 65536, 1048576]];
        yield 'total below per scope' => [[1, 1, 2, 1, 65536, 1048576]];
        yield 'namespace body low' => [[1, 1, 1, 1, 65535, 1048576]];
        yield 'snapshot body high' => [[1, 1, 1, 1, 65536, 268435457]];
    }

    public function testSnapshotValidationAndCanonicalFiles(): void
    {
        $verification = new PbsSnapshotVerification('ok', $this->upid());
        $snapshot = new PbsSnapshotObservation(
            new PbsDatastoreId('store_a'), PbsNamespace::root(), PbsBackupType::Vm, '100',
            new DateTimeImmutable('@1'), ['z.blob', 'a.blob'], true, 'comment', 'AA', 'backup@pbs', 0,
            $verification,
        );
        self::assertSame(['a.blob', 'z.blob'], $snapshot->files);
        self::assertSame('ok', $snapshot->verification?->state);
        self::assertSame('', self::observation(comment: '')->comment);
        self::assertSame(PbsContentRunStatus::Succeeded, (new PbsContentApplyResult(
            PbsContentRunStatus::Succeeded, 1, 2, 3,
        ))->status);

        foreach (['future', ''] as $state) {
            try {
                new PbsSnapshotVerification($state, $this->upid());
                self::fail('Invalid verification state accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[DataProvider('invalidSnapshotProvider')]
    public function testInvalidSnapshotsAreRejected(\Closure $invalidSnapshot): void
    {
        $this->expectException(InvalidArgumentException::class);
        $invalidSnapshot();
    }

    /** @return iterable<string, array{\Closure(): PbsSnapshotObservation}> */
    public static function invalidSnapshotProvider(): iterable
    {
        yield 'empty backup id' => [static fn (): PbsSnapshotObservation => self::observation(backupId: '')];
        yield 'bad backup id' => [static fn (): PbsSnapshotObservation => self::observation(backupId: '-bad')];
        yield 'bad internal backup id character' => [static fn (): PbsSnapshotObservation => self::observation(backupId: 'bad:value')];
        yield 'long backup id' => [static fn (): PbsSnapshotObservation => self::observation(backupId: str_repeat('a', 256))];
        yield 'negative size' => [static fn (): PbsSnapshotObservation => self::observation(size: -1)];
        yield 'control comment' => [static fn (): PbsSnapshotObservation => self::observation(comment: "bad\ncomment")];
        yield 'delete control comment' => [static fn (): PbsSnapshotObservation => self::observation(comment: "bad\x7Fcomment")];
        yield 'long comment' => [static fn (): PbsSnapshotObservation => self::observation(comment: str_repeat('a', 129))];
        yield 'owner short' => [static fn (): PbsSnapshotObservation => self::observation(owner: 'x')];
        yield 'duplicate files' => [static fn (): PbsSnapshotObservation => self::observation(files: ['a.blob', 'a.blob'])];
        yield 'bad file' => [static fn (): PbsSnapshotObservation => self::observation(files: ['-bad'])];
        yield 'untyped file' => [static fn (): PbsSnapshotObservation => self::observationWithUntypedFile()];
        yield 'invalid time' => [static fn (): PbsSnapshotObservation => self::observation(backupTime: new DateTimeImmutable('@0'))];
    }

    public function testScopeAndSnapshotStatusValidation(): void
    {
        $store = new PbsDatastoreId('store_a');
        $complete = new PbsContentScopeResult(
            PbsContentScopeType::Namespaces, $store, null, PbsContentScopeStatus::Complete, 0,
        );
        self::assertFalse($complete->permitsAbsenceDecisions());
        $completeSnapshots = new PbsContentScopeResult(
            PbsContentScopeType::Snapshots, $store, PbsNamespace::root(), PbsContentScopeStatus::Complete, 0,
        );
        self::assertTrue($completeSnapshots->permitsAbsenceDecisions());
        $partial = new PbsContentScopeResult(
            PbsContentScopeType::Snapshots, $store, PbsNamespace::root(), PbsContentScopeStatus::Partial, 1, 'partial',
        );
        self::assertSame("store_a\0@namespaces", $complete->key());
        self::assertSame("store_a\0", $completeSnapshots->key());
        self::assertSame('x', (new PbsContentScopeResult(
            PbsContentScopeType::Namespaces, $store, null, PbsContentScopeStatus::Partial, 0, 'x',
        ))->errorCode);
        self::assertSame(str_repeat('x', 64), (new PbsContentScopeResult(
            PbsContentScopeType::Namespaces,
            $store,
            null,
            PbsContentScopeStatus::Partial,
            0,
            str_repeat('x', 64),
        ))->errorCode);
        $snapshot = new PbsContentSnapshot(
            [new PbsNamespaceObservation($store, PbsNamespace::root())], [], [$complete, $partial],
        );
        self::assertSame(PbsContentRunStatus::Partial, $snapshot->status());
        self::assertSame(PbsContentRunStatus::Succeeded, (new PbsContentSnapshot([], [], []))->status());
        $failed = new PbsContentSnapshot([], [], [new PbsContentScopeResult(
            PbsContentScopeType::Namespaces, $store, null, PbsContentScopeStatus::Failed, 0, 'failed',
        )]);
        self::assertSame(PbsContentRunStatus::Failed, $failed->status());
    }

    public function testSnapshotCanonicalizesEveryCollectionAndPreservesDistinctIdentities(): void
    {
        $storeA = new PbsDatastoreId('store_a');
        $storeB = new PbsDatastoreId('store_b');
        $root = PbsNamespace::root();
        $tenant = new PbsNamespace('tenant');
        $namespaceA = new PbsNamespaceObservation($storeA, $tenant);
        $namespaceB = new PbsNamespaceObservation($storeB, $root);
        $snapshotA = new PbsSnapshotObservation(
            $storeA, $tenant, PbsBackupType::Vm, '200', new DateTimeImmutable('@2'),
            ['archive.blob'], false, null, null, null, null, null,
        );
        $snapshotB = new PbsSnapshotObservation(
            $storeB, $root, PbsBackupType::Vm, '100', new DateTimeImmutable('@1'),
            ['archive.blob'], false, null, null, null, null, null,
        );
        $scopeA = new PbsContentScopeResult(
            PbsContentScopeType::Snapshots, $storeA, $tenant, PbsContentScopeStatus::Complete, 1,
        );
        $scopeB = new PbsContentScopeResult(
            PbsContentScopeType::Namespaces, $storeB, null, PbsContentScopeStatus::Complete, 1,
        );

        $snapshot = new PbsContentSnapshot(
            [$namespaceB, $namespaceA],
            [$snapshotB, $snapshotA],
            [$scopeA, $scopeB],
        );

        self::assertSame([$namespaceA, $namespaceB], $snapshot->namespaces);
        self::assertSame([$snapshotA, $snapshotB], $snapshot->snapshots);
        self::assertSame([$scopeB, $scopeA], $snapshot->scopes);
        self::assertSame([0, 1], array_keys($snapshot->namespaces));
        self::assertSame([0, 1], array_keys($snapshot->snapshots));
        self::assertSame([0, 1], array_keys($snapshot->scopes));
    }

    #[DataProvider('invalidScopeProvider')]
    public function testInvalidScopesAreRejected(\Closure $invalidScope): void
    {
        $this->expectException(InvalidArgumentException::class);
        $invalidScope();
    }

    /** @return iterable<string, array{\Closure(): PbsContentScopeResult}> */
    public static function invalidScopeProvider(): iterable
    {
        yield 'negative rows' => [static fn (): PbsContentScopeResult => new PbsContentScopeResult(PbsContentScopeType::Namespaces, self::store(), null, PbsContentScopeStatus::Complete, -1)];
        yield 'namespace scope with namespace' => [static fn (): PbsContentScopeResult => new PbsContentScopeResult(PbsContentScopeType::Namespaces, self::store(), PbsNamespace::root(), PbsContentScopeStatus::Complete, 0)];
        yield 'snapshot scope without namespace' => [static fn (): PbsContentScopeResult => new PbsContentScopeResult(PbsContentScopeType::Snapshots, self::store(), null, PbsContentScopeStatus::Complete, 0)];
        yield 'complete with error' => [static fn (): PbsContentScopeResult => new PbsContentScopeResult(PbsContentScopeType::Namespaces, self::store(), null, PbsContentScopeStatus::Complete, 0, 'error')];
        yield 'partial without error' => [static fn (): PbsContentScopeResult => new PbsContentScopeResult(PbsContentScopeType::Namespaces, self::store(), null, PbsContentScopeStatus::Partial, 0)];
        yield 'invalid error code' => [static fn (): PbsContentScopeResult => new PbsContentScopeResult(PbsContentScopeType::Namespaces, self::store(), null, PbsContentScopeStatus::Partial, 0, 'Bad-Code')];
    }

    public function testSnapshotRejectsDuplicatesUnknownNamespacesAndOwnerConflicts(): void
    {
        $store = new PbsDatastoreId('store_a');
        $namespace = new PbsNamespaceObservation($store, PbsNamespace::root());
        $scope = new PbsContentScopeResult(
            PbsContentScopeType::Namespaces, $store, null, PbsContentScopeStatus::Complete, 1,
        );
        $cases = [
            [[ $namespace, $namespace ], [], [$scope]],
            [[$namespace], [$this->snapshot(new PbsNamespace('missing'), '100', null)], [$scope]],
            [[$namespace], [$this->snapshot(PbsNamespace::root(), '100', null), $this->snapshot(PbsNamespace::root(), '100', null)], [$scope]],
            [[$namespace], [$this->snapshot(PbsNamespace::root(), '100', 'a@pbs'), $this->snapshot(PbsNamespace::root(), '100', 'b@pbs', 2)], [$scope]],
            [[$namespace], [], [$scope, $scope]],
            [[$namespace], [], []],
        ];
        foreach ($cases as $arguments) {
            try {
                new PbsContentSnapshot(...$arguments);
                self::fail('Invalid PBS content snapshot accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        try {
            // @phpstan-ignore argument.type (verify the declared runtime boundary)
            new PbsContentSnapshot([], [], [new \stdClass()]);
            self::fail('An untyped PBS content scope was accepted.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $reflection = new \ReflectionClass(PbsNamespace::class);
        $corrupt = $reflection->newInstanceWithoutConstructor();
        self::assertInstanceOf(PbsNamespace::class, $corrupt);
        $reflection->getProperty('value')->setValue($corrupt, 'bad//child');
        try {
            $corrupt->parent();
            self::fail('A corrupted namespace produced an invalid parent object.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testRunHeadersFailuresAndCommitRejectInvalidContracts(): void
    {
        $endpoint = new EndpointId(str_repeat('e', 16));
        $binding = InstallationBinding::pbsLegacyEndpoint($endpoint);
        $id = new InventoryIdentifier(str_repeat('i', 16));
        $snapshot = new PbsContentSnapshot(
            [new PbsNamespaceObservation(new PbsDatastoreId('store_a'), PbsNamespace::root())],
            [],
            [new PbsContentScopeResult(PbsContentScopeType::Namespaces, new PbsDatastoreId('store_a'), null, PbsContentScopeStatus::Complete, 1)],
        );
        self::assertSame('UTC', (new PbsContentRunStart($id, $id, $id, $endpoint, $binding, 1, new DateTimeImmutable('2026-07-12T02:00:00+02:00')))->startedAt->getTimezone()->getName());
        self::assertSame('UTC', (new PbsContentRunFailure($id, $id, 'failed', new DateTimeImmutable('2026-07-12T02:00:00+02:00')))->finishedAt->getTimezone()->getName());
        self::assertSame('x', (new PbsContentRunFailure($id, $id, 'x', new DateTimeImmutable()))->errorCode);
        self::assertSame(
            str_repeat('x', 64),
            (new PbsContentRunFailure($id, $id, str_repeat('x', 64), new DateTimeImmutable()))->errorCode,
        );
        self::assertSame('UTC', (new PbsContentCommit($id, $id, $id, $endpoint, $binding, 1, $snapshot, new DateTimeImmutable('2026-07-12T02:00:00+02:00')))->observedAt->getTimezone()->getName());

        foreach ([
            [$id, $id, $id, $endpoint, $binding, 0, new DateTimeImmutable()],
            [$id, $id, $id, $endpoint, InstallationBinding::pveStandalone('pve-a'), 1, new DateTimeImmutable()],
            [$id, $id, $id, $endpoint, InstallationBinding::pbsLegacyEndpoint(new EndpointId(str_repeat('x', 16))), 1, new DateTimeImmutable()],
        ] as $arguments) {
            try {
                new PbsContentRunStart(...$arguments);
                self::fail('An invalid PBS content run header was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        foreach (['', 'Bad-Code', str_repeat('a', 65)] as $code) {
            try { new PbsContentRunFailure($id, $id, $code, new DateTimeImmutable()); self::fail('Invalid failure code accepted.'); }
            catch (InvalidArgumentException) { self::addToAssertionCount(1); }
        }
        self::assertSame('UTC', (new PbsContentCommit(
            $id, $id, $id, $endpoint, $binding, 1, new PbsContentSnapshot([], [], []), new DateTimeImmutable(),
        ))->observedAt->getTimezone()->getName());

        foreach ([
            [$id, $id, $id, $endpoint, $binding, 0, $snapshot, new DateTimeImmutable()],
            [$id, $id, $id, $endpoint, InstallationBinding::pveStandalone('pve-a'), 1, $snapshot, new DateTimeImmutable()],
            [$id, $id, $id, $endpoint, InstallationBinding::pbsLegacyEndpoint(new EndpointId(str_repeat('x', 16))), 1, $snapshot, new DateTimeImmutable()],
        ] as $arguments) {
            try {
                new PbsContentCommit(...$arguments);
                self::fail('An invalid PBS content commit header was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function snapshot(PbsNamespace $namespace, string $id, ?string $owner, int $time = 1): PbsSnapshotObservation
    {
        return new PbsSnapshotObservation(
            new PbsDatastoreId('store_a'), $namespace, PbsBackupType::Vm, $id,
            new DateTimeImmutable('@'.$time), ['archive.blob'], false, null, null, $owner, null, null,
        );
    }

    private function upid(): PbsUpid
    {
        return new PbsUpid('UPID:pbs:0000002D:000F4243:66800003:66800000:verify:store:backup@pbs:');
    }

    /** @param list<string> $files */
    private static function observation(
        string $backupId = '100',
        ?DateTimeImmutable $backupTime = null,
        array $files = ['archive.blob'],
        ?string $comment = null,
        ?string $owner = null,
        ?int $size = null,
    ): PbsSnapshotObservation {
        return new PbsSnapshotObservation(
            self::store(), PbsNamespace::root(), PbsBackupType::Vm, $backupId,
            $backupTime ?? new DateTimeImmutable('@1'), $files, false, $comment, null, $owner, $size, null,
        );
    }

    private static function store(): PbsDatastoreId
    {
        return new PbsDatastoreId('store_a');
    }

    private static function observationWithUntypedFile(): PbsSnapshotObservation
    {
        return new PbsSnapshotObservation(
            self::store(), PbsNamespace::root(), PbsBackupType::Vm, '100', new DateTimeImmutable('@1'),
            // @phpstan-ignore argument.type (verify the declared runtime boundary)
            [1], false, null, null, null, null, null,
        );
    }
}
