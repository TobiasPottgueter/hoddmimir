<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Inventory\Pve;

use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\Pve\InventoryScopeStatus;
use App\Application\Inventory\Pve\PveCoreBindingKind;
use App\Application\Inventory\Pve\PveCoreInstallationBinding;
use App\Application\Inventory\Pve\PveCoreInventoryCommit;
use App\Application\Inventory\Pve\PveCoreScope;
use App\Application\Inventory\Pve\PveCoreScopeResult;
use App\Application\Inventory\Pve\PveInventoryCommit;
use App\Application\Inventory\Pve\PveNodeObservation;
use App\Application\Inventory\Pve\PveNodeStorageScopeResult;
use App\Application\Inventory\Pve\PveNodeStorageStateObservation;
use App\Application\Inventory\Pve\PveStorageCapacityStatus;
use App\Application\Inventory\Pve\PveStorageObservation;
use App\Application\Proxmox\Pve\PveNodeStorageObservation;
use App\Application\Proxmox\Pve\PvePbsStorageMapping;
use App\Application\Proxmox\Pve\PveStorageCapacity;
use App\Application\Proxmox\Pve\PveStorageCapacityState;
use App\Application\Proxmox\Pve\PveStorageConfiguration;
use App\Application\Proxmox\Pve\PveStorageContentSet;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PveStorageInventoryModelTest extends TestCase
{
    private const string NOW = '2026-07-11T12:00:00+00:00';

    public function testStorageObservationNormalizesAndPreservesDisabledBackupConfiguration(): void
    {
        $mapping = new PvePbsStorageMapping('pbs.example.test', 8443, 'vault', 'tenant-a');
        $observation = new PveStorageObservation(
            'Backup.store',
            'pbs',
            ['iso', 'backup', 'backup'],
            ['node-b.test', 'node-a.test', 'node-a.test'],
            true,
            true,
            $mapping,
            new DateTimeImmutable('2026-07-11T14:00:00+02:00'),
        );

        self::assertSame(['backup', 'iso'], $observation->content);
        self::assertSame(['node-a.test', 'node-b.test'], $observation->nodeAllowlist);
        self::assertTrue($observation->disabled);
        self::assertTrue($observation->shared);
        self::assertTrue($observation->supportsBackup());
        self::assertSame('2026-07-11T12:00:00+00:00', $observation->observedAt->format('c'));
        self::assertSame([
            'server' => 'pbs.example.test',
            'port' => 8443,
            'datastore' => 'vault',
            'namespace' => 'tenant-a',
        ], $observation->pbsMapping?->signature());

        $fromConfiguration = PveStorageObservation::fromConfiguration(new PveStorageConfiguration(
            'local',
            'dir',
            new PveStorageContentSet(['backup']),
            null,
            false,
            false,
            null,
        ), new DateTimeImmutable(self::NOW));
        self::assertSame('local', $fromConfiguration->storageId);
        self::assertNull($fromConfiguration->nodeAllowlist);
        self::assertTrue((new PveStorageObservation(
            'multi-content', 'dir', ['aaa', 'backup'], null, false, false, null, new DateTimeImmutable(self::NOW),
        ))->supportsBackup());
        self::assertFalse((new PveStorageObservation(
            'images', 'dir', ['images'], null, false, false, null, new DateTimeImmutable(self::NOW),
        ))->supportsBackup());
    }

    #[DataProvider('invalidPbsTextProvider')]
    public function testStorageObservationRejectsUnsafePbsMappingText(PvePbsStorageMapping $mapping): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The PVE PBS storage mapping contains invalid text.');
        new PveStorageObservation(
            'pbs-target', 'pbs', ['backup'], null, false, true, $mapping, new DateTimeImmutable(self::NOW),
        );
    }

    /** @return iterable<string, array{PvePbsStorageMapping}> */
    public static function invalidPbsTextProvider(): iterable
    {
        yield 'server' => [new PvePbsStorageMapping("pbs\ninvalid", 8007, 'vault', null)];
        yield 'server space' => [new PvePbsStorageMapping('pbs invalid', 8007, 'vault', null)];
        yield 'datastore' => [new PvePbsStorageMapping('pbs.test', 8007, "vault\ninvalid", null)];
        yield 'datastore slash' => [new PvePbsStorageMapping('pbs.test', 8007, 'tenant/vault', null)];
        yield 'datastore prefix' => [new PvePbsStorageMapping('pbs.test', 8007, '.vault', null)];
        yield 'namespace' => [new PvePbsStorageMapping('pbs.test', 8007, 'vault', "tenant\ninvalid")];
        yield 'namespace space' => [new PvePbsStorageMapping('pbs.test', 8007, 'vault', 'tenant invalid')];
    }

    public function testStorageObservationRejectsInconsistentPbsTypeAndMapping(): void
    {
        $mapping = new PvePbsStorageMapping('pbs.example.test', 8007, 'vault', null);
        foreach ([
            ['pbs', null],
            ['dir', $mapping],
        ] as [$type, $candidateMapping]) {
            try {
                new PveStorageObservation(
                    'backup', $type, ['backup'], null, false, false, $candidateMapping, new DateTimeImmutable(self::NOW),
                );
                self::fail('An inconsistent PVE PBS storage observation was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame(
                    'The PVE PBS storage type and mapping must be consistent.',
                    $exception->getMessage(),
                );
            }
        }
    }

    /**
     * @param list<string>      $content
     * @param null|list<string> $nodes
     */
    #[DataProvider('invalidStorageObservationProvider')]
    public function testStorageObservationRejectsInvalidInputs(
        string $storageId,
        string $storageType,
        array $content,
        ?array $nodes,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        new PveStorageObservation(
            $storageId,
            $storageType,
            $content,
            $nodes,
            false,
            false,
            null,
            new DateTimeImmutable(self::NOW),
        );
    }

    /** @return iterable<string, array{string, string, list<string>, null|list<string>}> */
    public static function invalidStorageObservationProvider(): iterable
    {
        yield 'invalid storage ID' => ['bad/id', 'dir', ['backup'], null];
        yield 'empty storage type' => ['local', '', ['backup'], null];
        yield 'oversized storage type' => ['local', str_repeat('x', 65), ['backup'], null];
        yield 'control in storage type' => ['local', "dir\n", ['backup'], null];
        yield 'no content' => ['local', 'dir', [], null];
        yield 'empty content token' => ['local', 'dir', [''], null];
        yield 'oversized content token' => ['local', 'dir', [str_repeat('x', 65)], null];
        yield 'control in content token' => ['local', 'dir', ["backup\0"], null];
        yield 'empty allowlist' => ['local', 'dir', ['backup'], []];
        yield 'invalid allowlist node' => ['local', 'dir', ['backup'], ['bad/node']];
    }

    public function testNodeStorageStateMapsAllCapacityStatesAndNormalizesUtc(): void
    {
        $measured = PveNodeStorageStateObservation::fromReadObservation(new PveNodeStorageObservation(
            'node-a.test',
            'backup',
            'dir',
            new PveStorageContentSet(['backup']),
            true,
            true,
            false,
            PveStorageCapacityState::Fresh,
            new PveStorageCapacity(100, 30, 60),
            new DateTimeImmutable('2026-07-11T14:00:00+02:00'),
        ));
        self::assertSame(PveStorageCapacityStatus::Measured, $measured->capacityStatus);
        self::assertSame([100, 30, 60], [$measured->totalBytes, $measured->usedBytes, $measured->availableBytes]);
        self::assertSame("node-a.test\0backup", $measured->key());
        self::assertSame('+00:00', $measured->observedAt->format('P'));

        foreach ([
            [PveStorageCapacityState::Unavailable, PveStorageCapacityStatus::Unavailable],
            [PveStorageCapacityState::Invalid, PveStorageCapacityStatus::Invalid],
        ] as [$readState, $expectedStatus]) {
            $state = PveNodeStorageStateObservation::fromReadObservation(new PveNodeStorageObservation(
                'node-a.test',
                'backup',
                'dir',
                new PveStorageContentSet(['backup']),
                false,
                false,
                true,
                $readState,
                null,
                new DateTimeImmutable(self::NOW),
            ));
            self::assertSame($expectedStatus, $state->capacityStatus);
            self::assertNull($state->totalBytes);
            self::assertNull($state->usedBytes);
            self::assertNull($state->availableBytes);
        }
    }

    /** @return iterable<string, array{callable(): PveNodeStorageStateObservation}> */
    public static function invalidNodeStorageStateProvider(): iterable
    {
        $at = new DateTimeImmutable(self::NOW);
        yield 'invalid node' => [static fn () => new PveNodeStorageStateObservation(
            'bad/node', 'backup', true, true, false, PveStorageCapacityStatus::Unavailable, null, null, null, $at,
        )];
        yield 'invalid storage' => [static fn () => new PveNodeStorageStateObservation(
            'node-a', 'bad/id', true, true, false, PveStorageCapacityStatus::Unavailable, null, null, null, $at,
        )];
        yield 'measured missing values' => [static fn () => new PveNodeStorageStateObservation(
            'node-a', 'backup', true, true, false, PveStorageCapacityStatus::Measured, 100, null, 50, $at,
        )];
        yield 'negative total' => [static fn () => new PveNodeStorageStateObservation(
            'node-a', 'backup', true, true, false, PveStorageCapacityStatus::Measured, -1, 0, 0, $at,
        )];
        yield 'negative used' => [static fn () => new PveNodeStorageStateObservation(
            'node-a', 'backup', true, true, false, PveStorageCapacityStatus::Measured, 1, -1, 0, $at,
        )];
        yield 'negative available' => [static fn () => new PveNodeStorageStateObservation(
            'node-a', 'backup', true, true, false, PveStorageCapacityStatus::Measured, 1, 0, -1, $at,
        )];
        yield 'used exceeds total' => [static fn () => new PveNodeStorageStateObservation(
            'node-a', 'backup', true, true, false, PveStorageCapacityStatus::Measured, 1, 2, 0, $at,
        )];
        yield 'available exceeds total' => [static fn () => new PveNodeStorageStateObservation(
            'node-a', 'backup', true, true, false, PveStorageCapacityStatus::Measured, 1, 0, 2, $at,
        )];
        yield 'unavailable exposes values' => [static fn () => new PveNodeStorageStateObservation(
            'node-a', 'backup', true, true, false, PveStorageCapacityStatus::Unavailable, 1, null, null, $at,
        )];
        yield 'invalid exposes values' => [static fn () => new PveNodeStorageStateObservation(
            'node-a', 'backup', true, true, false, PveStorageCapacityStatus::Invalid, null, 1, null, $at,
        )];
    }

    #[DataProvider('invalidNodeStorageStateProvider')]
    public function testNodeStorageStateRejectsInvalidInputs(callable $operation): void
    {
        $this->expectException(InvalidArgumentException::class);
        $operation();
    }

    public function testInventoryCommitSortsAndDerivesCompletePartialAndFailedAuthority(): void
    {
        $storageA = $this->storage('aa');
        $storageB = $this->storage('bb');
        $stateA = $this->state('node-a', 'aa');
        $stateB = $this->state('node-b', 'bb');
        $complete = new PveInventoryCommit(
            $this->core(InventoryScopeStatus::Complete, InventoryScopeStatus::Complete),
            $this->storageScope(InventoryScopeStatus::Complete),
            [
                new PveNodeStorageScopeResult('node-b', InventoryScopeStatus::Complete),
                new PveNodeStorageScopeResult('node-a', InventoryScopeStatus::Complete),
            ],
            [$storageB, $storageA],
            [$stateB, $stateA],
        );

        self::assertTrue($complete->isFullyAuthoritative());
        self::assertSame('succeeded', $complete->overallStatus());
        self::assertSame(['node-a', 'node-b'], array_column($complete->nodeStorageScopes, 'node'));
        self::assertSame(['aa', 'bb'], array_column($complete->storages, 'storageId'));
        self::assertSame(["node-a\0aa", "node-b\0bb"], array_map(
            static fn (PveNodeStorageStateObservation $state): string => $state->key(),
            $complete->nodeStorageStates,
        ));

        $partialCore = new PveInventoryCommit(
            $this->core(InventoryScopeStatus::Partial, InventoryScopeStatus::Complete),
            $this->storageScope(InventoryScopeStatus::Complete),
            [
                new PveNodeStorageScopeResult('node-a', InventoryScopeStatus::Complete),
                new PveNodeStorageScopeResult('node-b', InventoryScopeStatus::Complete),
            ],
            [],
            [],
        );
        self::assertFalse($partialCore->isFullyAuthoritative());
        self::assertSame('partial', $partialCore->overallStatus());

        $partialStorage = new PveInventoryCommit(
            $this->core(InventoryScopeStatus::Complete, InventoryScopeStatus::Complete),
            $this->storageScope(InventoryScopeStatus::Partial),
            [
                new PveNodeStorageScopeResult('node-a', InventoryScopeStatus::Partial),
                new PveNodeStorageScopeResult('node-b', InventoryScopeStatus::Complete),
            ],
            [],
            [],
        );
        self::assertFalse($partialStorage->isFullyAuthoritative());
        self::assertSame('partial', $partialStorage->overallStatus());
        self::assertFalse($partialStorage->nodeStorageScopes[0]->isComplete());

        $partialNodeScope = new PveInventoryCommit(
            $this->core(InventoryScopeStatus::Complete, InventoryScopeStatus::Complete),
            $this->storageScope(InventoryScopeStatus::Complete),
            [
                new PveNodeStorageScopeResult('node-a', InventoryScopeStatus::Partial),
                new PveNodeStorageScopeResult('node-b', InventoryScopeStatus::Complete),
            ],
            [],
            [],
        );
        self::assertFalse($partialNodeScope->isFullyAuthoritative());
        self::assertSame('partial', $partialNodeScope->overallStatus());

        $failed = new PveInventoryCommit(
            $this->core(InventoryScopeStatus::Failed, InventoryScopeStatus::Failed, []),
            $this->storageScope(InventoryScopeStatus::Failed),
            [],
            [],
            [],
        );
        self::assertSame('failed', $failed->overallStatus());

        $failedWithPositive = new PveInventoryCommit(
            $this->core(InventoryScopeStatus::Failed, InventoryScopeStatus::Failed),
            $this->storageScope(InventoryScopeStatus::Failed),
            [],
            [$storageA],
            [],
        );
        self::assertSame('partial', $failedWithPositive->overallStatus());
    }

    /** @return iterable<string, array{callable(): PveInventoryCommit}> */
    public static function invalidCommitProvider(): iterable
    {
        $test = new self('testInventoryCommitSortsAndDerivesCompletePartialAndFailedAuthority');
        $core = $test->core(InventoryScopeStatus::Complete, InventoryScopeStatus::Complete);
        $storage = $test->storage('aa');
        $state = $test->state('node-a', 'aa');
        $completeScope = $test->storageScope(InventoryScopeStatus::Complete);

        yield 'wrong global scope' => [static fn () => new PveInventoryCommit(
            $core,
            new PveCoreScopeResult(PveCoreScope::Topology, InventoryScopeStatus::Complete),
            [], [], [],
        )];
        yield 'non-scope item' => [static fn () => new PveInventoryCommit(
            $core, $completeScope, ['invalid'], [], [], // @phpstan-ignore argument.type
        )];
        yield 'unknown scope node' => [static fn () => new PveInventoryCommit(
            $core, $completeScope, [new PveNodeStorageScopeResult('node-c', InventoryScopeStatus::Complete)], [], [],
        )];
        yield 'duplicate scope' => [static fn () => new PveInventoryCommit(
            $core, $completeScope, [
                new PveNodeStorageScopeResult('node-a', InventoryScopeStatus::Complete),
                new PveNodeStorageScopeResult('node-a', InventoryScopeStatus::Partial),
            ], [], [],
        )];
        yield 'non-storage item' => [static fn () => new PveInventoryCommit(
            $core, $completeScope, [], ['invalid'], [], // @phpstan-ignore argument.type
        )];
        yield 'storage without backup content' => [static fn () => new PveInventoryCommit(
            $core, $completeScope, [], [new PveStorageObservation(
                'aa', 'dir', ['images'], null, false, false, null, new DateTimeImmutable(self::NOW),
            )], [],
        )];
        yield 'duplicate storage' => [static fn () => new PveInventoryCommit(
            $core, $completeScope, [], [$storage, $storage], [],
        )];
        yield 'non-state item' => [static fn () => new PveInventoryCommit(
            $core, $completeScope, [], [$storage], ['invalid'], // @phpstan-ignore argument.type
        )];
        yield 'state for unknown node' => [static fn () => new PveInventoryCommit(
            $core, $completeScope, [new PveNodeStorageScopeResult('node-a', InventoryScopeStatus::Complete)],
            [$storage], [$test->state('node-c', 'aa')],
        )];
        yield 'state for unknown storage' => [static fn () => new PveInventoryCommit(
            $core, $completeScope, [new PveNodeStorageScopeResult('node-a', InventoryScopeStatus::Complete)],
            [$storage], [$test->state('node-a', 'bb')],
        )];
        yield 'state without node scope' => [static fn () => new PveInventoryCommit(
            $core, $completeScope, [], [$storage], [$state],
        )];
        yield 'duplicate state' => [static fn () => new PveInventoryCommit(
            $core, $completeScope, [new PveNodeStorageScopeResult('node-a', InventoryScopeStatus::Complete)],
            [$storage], [$state, $state],
        )];
        yield 'state for disabled storage' => [static fn () => new PveInventoryCommit(
            $core, $completeScope, [new PveNodeStorageScopeResult('node-a', InventoryScopeStatus::Complete)],
            [new PveStorageObservation(
                'aa', 'dir', ['backup'], null, true, false, null, new DateTimeImmutable(self::NOW),
            )], [$state],
        )];
        yield 'authoritative missing node scope' => [static fn () => new PveInventoryCommit(
            $core, $completeScope, [new PveNodeStorageScopeResult('node-a', InventoryScopeStatus::Complete)], [], [],
        )];
    }

    #[DataProvider('invalidCommitProvider')]
    public function testInventoryCommitRejectsInvalidInputs(callable $operation): void
    {
        $this->expectException(InvalidArgumentException::class);
        $operation();
    }

    public function testNodeScopeAndCapacityEnumsExposeStableValues(): void
    {
        $scope = new PveNodeStorageScopeResult('node-a.test', InventoryScopeStatus::Complete);
        self::assertTrue($scope->isComplete());
        self::assertSame('measured', PveStorageCapacityStatus::Measured->value);
        self::assertSame('unavailable', PveStorageCapacityStatus::Unavailable->value);
        self::assertSame('invalid', PveStorageCapacityStatus::Invalid->value);

        $this->expectException(InvalidArgumentException::class);
        new PveNodeStorageScopeResult('bad/node', InventoryScopeStatus::Complete);
    }

    /** @param list<PveNodeObservation>|null $nodes */
    private function core(
        InventoryScopeStatus $topologyStatus,
        InventoryScopeStatus $guestStatus,
        ?array $nodes = null,
    ): PveCoreInventoryCommit {
        return new PveCoreInventoryCommit(
            $this->id('r'),
            $this->id('c'),
            $this->id('e'),
            1,
            new PveCoreInstallationBinding(PveCoreBindingKind::Cluster, 'cluster-a'),
            new PveCoreScopeResult(PveCoreScope::Topology, $topologyStatus),
            new PveCoreScopeResult(PveCoreScope::Guests, $guestStatus),
            $nodes ?? [new PveNodeObservation('node-a', 'online'), new PveNodeObservation('node-b', 'online')],
            [],
            new DateTimeImmutable(self::NOW),
        );
    }

    private function storageScope(InventoryScopeStatus $status): PveCoreScopeResult
    {
        return new PveCoreScopeResult(PveCoreScope::Storages, $status);
    }

    private function storage(string $id): PveStorageObservation
    {
        return new PveStorageObservation(
            $id, 'dir', ['backup'], null, false, false, null, new DateTimeImmutable(self::NOW),
        );
    }

    private function state(string $node, string $storage): PveNodeStorageStateObservation
    {
        return new PveNodeStorageStateObservation(
            $node,
            $storage,
            true,
            true,
            false,
            PveStorageCapacityStatus::Measured,
            100,
            30,
            60,
            new DateTimeImmutable(self::NOW),
        );
    }

    private function id(string $byte): InventoryIdentifier
    {
        return new InventoryIdentifier(str_repeat($byte, 16));
    }
}
