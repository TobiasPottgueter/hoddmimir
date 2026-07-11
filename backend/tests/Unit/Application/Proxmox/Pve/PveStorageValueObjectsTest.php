<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Proxmox\Pve;

use App\Application\Proxmox\Pve\PveNodeStorageObservation;
use App\Application\Proxmox\Pve\PveNodeStorageStatus;
use App\Application\Proxmox\Pve\PveNodeStorageStatusSet;
use App\Application\Proxmox\Pve\PvePbsStorageMapping;
use App\Application\Proxmox\Pve\PveStorageCapacity;
use App\Application\Proxmox\Pve\PveStorageCapacityState;
use App\Application\Proxmox\Pve\PveStorageConfiguration;
use App\Application\Proxmox\Pve\PveStorageConfigurationSet;
use App\Application\Proxmox\Pve\PveStorageContentSet;
use App\Application\Proxmox\Pve\PveStorageInventorySnapshot;
use App\Application\Proxmox\Pve\PveStorageIdValidator;
use App\Application\Proxmox\Pve\PveStorageIssue;
use App\Application\Proxmox\Pve\PveStorageIssueCode;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PveStorageValueObjectsTest extends TestCase
{
    public function testContentTokensAreNormalizedButCapabilitiesRemainExactAndCaseSensitive(): void
    {
        $content = new PveStorageContentSet(['iso', 'backup', 'future', 'backup']);

        self::assertSame(['backup', 'future', 'iso'], $content->tokens);
        self::assertTrue($content->contains('backup'));
        self::assertFalse($content->contains('Backup'));
        self::assertFalse($content->contains('backup-archive'));
        self::assertTrue($content->equals(new PveStorageContentSet(['future', 'iso', 'backup'])));
        self::assertFalse($content->equals(new PveStorageContentSet(['backup'])));
    }

    /** @param list<string> $tokens */
    #[DataProvider('invalidContentProvider')]
    public function testContentSetRejectsEmptyOrStructurallyInvalidTokens(array $tokens): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PveStorageContentSet($tokens);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function invalidContentProvider(): iterable
    {
        yield 'empty' => [[]];
        yield 'empty token' => [['']];
    }

    public function testPbsMappingPreservesTheCompleteExplicitIdentityTuple(): void
    {
        $mapping = new PvePbsStorageMapping('pbs.test', 8007, 'vault', null);
        self::assertSame([
            'server' => 'pbs.test',
            'port' => 8007,
            'datastore' => 'vault',
            'namespace' => null,
        ], $mapping->signature());

        $namespaced = new PvePbsStorageMapping('pbs.test', 8443, 'vault', 'tenant-a');
        self::assertSame('tenant-a', $namespaced->namespace);
    }

    #[DataProvider('invalidPbsMappingProvider')]
    public function testPbsMappingRejectsInvalidComponents(
        string $server,
        int $port,
        string $datastore,
        ?string $namespace,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        new PvePbsStorageMapping($server, $port, $datastore, $namespace);
    }

    /** @return iterable<string, array{string, int, string, ?string}> */
    public static function invalidPbsMappingProvider(): iterable
    {
        yield 'empty server' => ['', 8007, 'vault', null];
        yield 'zero port' => ['pbs.test', 0, 'vault', null];
        yield 'oversized port' => ['pbs.test', 65536, 'vault', null];
        yield 'empty datastore' => ['pbs.test', 8007, '', null];
        yield 'empty namespace' => ['pbs.test', 8007, 'vault', ''];
    }

    public function testConfigurationDerivesBackupAndNodeExpectationWithoutTypeEnumeration(): void
    {
        $mapping = new PvePbsStorageMapping('pbs.test', 8007, 'vault', 'tenant');
        $definition = new PveStorageConfiguration(
            'future-store',
            'future-plugin',
            new PveStorageContentSet(['future', 'backup']),
            ['node-b.test', 'node-a.test', 'node-a.test'],
            false,
            true,
            $mapping,
        );

        self::assertSame(['node-a.test', 'node-b.test'], $definition->nodeAllowlist);
        self::assertTrue($definition->supportsBackup());
        self::assertTrue($definition->isExpectedOn('node-a.test'));
        self::assertFalse($definition->isExpectedOn('node-c.test'));
        self::assertSame('future-plugin', $definition->signature()['storage_type']);
        self::assertSame($mapping->signature(), $definition->signature()['pbs']);

        $allNodes = new PveStorageConfiguration(
            'all',
            'dir',
            new PveStorageContentSet(['backup']),
            null,
            false,
            false,
            null,
        );
        self::assertTrue($allNodes->isExpectedOn('any-node.test'));
        self::assertNull($allNodes->signature()['nodes']);
        self::assertNull($allNodes->signature()['pbs']);

        $disabled = new PveStorageConfiguration(
            'disabled',
            'dir',
            new PveStorageContentSet(['backup']),
            null,
            true,
            false,
            null,
        );
        self::assertFalse($disabled->isExpectedOn('node-a.test'));

        $substring = new PveStorageConfiguration(
            'substring',
            'future',
            new PveStorageContentSet(['backup-archive']),
            null,
            false,
            false,
            null,
        );
        self::assertFalse($substring->supportsBackup());
        self::assertFalse($substring->isExpectedOn('node-a.test'));
    }

    /** @param null|list<string> $nodes */
    #[DataProvider('invalidConfigurationProvider')]
    public function testConfigurationRejectsInvalidIdentifiersAndNodeLists(
        string $storageId,
        string $storageType,
        ?array $nodes,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        new PveStorageConfiguration(
            $storageId,
            $storageType,
            new PveStorageContentSet(['backup']),
            $nodes,
            false,
            false,
            null,
        );
    }

    /** @return iterable<string, array{string, string, null|list<string>}> */
    public static function invalidConfigurationProvider(): iterable
    {
        yield 'empty id' => ['', 'dir', null];
        yield 'empty type' => ['local', '', null];
        yield 'empty nodes' => ['local', 'dir', []];
        yield 'empty node' => ['local', 'dir', ['']];
    }

    public function testConfigurationSetSortsAndComparesNormalizedVisibleDefinitions(): void
    {
        $a = $this->definition('aa');
        $b = $this->definition('bb');
        $set = new PveStorageConfigurationSet('digest', [$b, $a], []);
        self::assertSame(['aa', 'bb'], array_map(
            static fn (PveStorageConfiguration $definition): string => $definition->storageId,
            $set->definitions,
        ));
        self::assertTrue($set->isContractValid());
        self::assertTrue($set->hasSameVisibleDefinitions(new PveStorageConfigurationSet('other-digest', [$a, $b], [])));
        self::assertFalse($set->hasSameVisibleDefinitions(new PveStorageConfigurationSet('digest', [$a], [])));

        $changed = new PveStorageConfiguration(
            'bb',
            'future',
            new PveStorageContentSet(['backup']),
            null,
            false,
            false,
            null,
        );
        self::assertFalse($set->hasSameVisibleDefinitions(new PveStorageConfigurationSet('digest', [$a, $changed], [])));

        $issue = new PveStorageIssue(PveStorageIssueCode::InvalidField, '/storage', '/data/0/type');
        self::assertFalse((new PveStorageConfigurationSet(null, [$a], []))->isContractValid());
        self::assertFalse((new PveStorageConfigurationSet('digest', [], []))->isContractValid());
        self::assertFalse((new PveStorageConfigurationSet('digest', [$a], [$issue]))->isContractValid());
    }

    #[DataProvider('invalidGlobalDigestProvider')]
    public function testConfigurationSetRejectsUnsafeGlobalDigests(string $digest): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PveStorageConfigurationSet($digest, [$this->definition('aa')], []);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidGlobalDigestProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['digest value'];
        yield 'nul' => ["digest\0value"];
        yield 'escape' => ["digest\x1Bvalue"];
        yield 'newline' => ["digest\nvalue"];
        yield 'delete' => ["digest\x7Fvalue"];
        yield 'unicode' => ['digést'];
    }

    public function testConfigurationSetRejectsDuplicateStorageIds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PveStorageConfigurationSet('digest', [$this->definition('aa'), $this->definition('aa')], []);
    }

    #[DataProvider('validStorageIdProvider')]
    public function testOfficialPveStorageIdGrammarAcceptsOnlyCanonicalIds(string $storageId): void
    {
        self::assertTrue(PveStorageIdValidator::isValid($storageId));
        self::assertSame($storageId, $this->definition($storageId)->storageId);
    }

    /** @return iterable<string, array{string}> */
    public static function validStorageIdProvider(): iterable
    {
        yield 'letters' => ['ab'];
        yield 'digit suffix' => ['a0'];
        yield 'dot' => ['backup.store'];
        yield 'preserved uppercase' => ['Backup.store'];
        yield 'uppercase middle' => ['backUp'];
        yield 'all separators' => ['a0.b_c-d9'];
    }

    #[DataProvider('invalidStorageIdProvider')]
    public function testOfficialPveStorageIdGrammarRejectsNonCanonicalIds(string $storageId): void
    {
        self::assertFalse(PveStorageIdValidator::isValid($storageId));

        $this->expectException(InvalidArgumentException::class);
        $this->definition($storageId);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidStorageIdProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'single letter' => ['a'];
        yield 'digit first' => ['1backup'];
        yield 'slash' => ['backup/store'];
        yield 'trailing dot' => ['backup.'];
        yield 'trailing hyphen' => ['backup-'];
        yield 'trailing underscore' => ['backup_'];
        yield 'control' => ["back\x1Bup"];
        yield 'unicode' => ['bäckup'];
    }

    public function testCapacityAndStatusInvariantsAreExplicit(): void
    {
        $capacity = new PveStorageCapacity(100, 30, 60);
        self::assertSame(100, $capacity->totalBytes);
        self::assertSame(30, $capacity->usedBytes);
        self::assertSame(60, $capacity->availableBytes);

        $status = new PveNodeStorageStatus(
            'node.test',
            'backup',
            'dir',
            new PveStorageContentSet(['backup']),
            true,
            true,
            false,
            PveStorageCapacityState::Fresh,
            $capacity,
        );
        $set = new PveNodeStorageStatusSet('node.test', [
            new PveNodeStorageStatus(
                'node.test',
                'zz',
                'dir',
                new PveStorageContentSet(['backup']),
                true,
                false,
                false,
                PveStorageCapacityState::Unavailable,
                null,
            ),
            $status,
        ], []);
        self::assertSame(['backup', 'zz'], array_map(
            static fn (PveNodeStorageStatus $item): string => $item->storageId,
            $set->statuses,
        ));
    }

    #[DataProvider('invalidCapacityProvider')]
    public function testCapacityRejectsNegativeOrOutOfBoundsValues(int $total, int $used, int $available): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PveStorageCapacity($total, $used, $available);
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function invalidCapacityProvider(): iterable
    {
        yield 'negative total' => [-1, 0, 0];
        yield 'negative used' => [1, -1, 0];
        yield 'negative available' => [1, 0, -1];
        yield 'used exceeds total' => [1, 2, 0];
        yield 'available exceeds total' => [1, 0, 2];
    }

    #[DataProvider('invalidStatusProvider')]
    public function testStatusRejectsInvalidCapacityStateCombinations(
        string $node,
        string $storage,
        string $type,
        PveStorageCapacityState $state,
        ?PveStorageCapacity $capacity,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        new PveNodeStorageStatus(
            $node,
            $storage,
            $type,
            new PveStorageContentSet(['backup']),
            true,
            true,
            false,
            $state,
            $capacity,
        );
    }

    /** @return iterable<string, array{string, string, string, PveStorageCapacityState, ?PveStorageCapacity}> */
    public static function invalidStatusProvider(): iterable
    {
        $capacity = new PveStorageCapacity(1, 0, 1);
        yield 'empty node' => ['', 'backup', 'dir', PveStorageCapacityState::Fresh, $capacity];
        yield 'empty storage' => ['node', '', 'dir', PveStorageCapacityState::Fresh, $capacity];
        yield 'empty type' => ['node', 'backup', '', PveStorageCapacityState::Fresh, $capacity];
        yield 'fresh missing values' => ['node', 'backup', 'dir', PveStorageCapacityState::Fresh, null];
        yield 'unavailable has values' => ['node', 'backup', 'dir', PveStorageCapacityState::Unavailable, $capacity];
        yield 'invalid has values' => ['node', 'backup', 'dir', PveStorageCapacityState::Invalid, $capacity];
    }

    public function testObservationUsesInjectedTimeAndNormalizesItToUtc(): void
    {
        $status = new PveNodeStorageStatus(
            'node.test',
            'backup',
            'dir',
            new PveStorageContentSet(['backup']),
            true,
            false,
            false,
            PveStorageCapacityState::Unavailable,
            null,
        );
        $observation = PveNodeStorageObservation::fromStatus(
            $status,
            new DateTimeImmutable('2026-07-10T14:00:00+02:00'),
        );

        self::assertSame('2026-07-10T12:00:00+00:00', $observation->observedAt->format('c'));
        self::assertSame('backup', $observation->storageId);
        self::assertSame(PveStorageCapacityState::Unavailable, $observation->capacityState);
    }

    public function testInventorySnapshotNeverInventsAuthority(): void
    {
        $valid = new PveStorageConfigurationSet('digest', [$this->definition('aa')], []);
        self::assertTrue((new PveStorageInventorySnapshot($valid, $valid, [], []))->isAuthoritative());

        $issue = new PveStorageIssue(PveStorageIssueCode::NodeReadFailed, '/nodes/a/storage', '/data');
        self::assertFalse((new PveStorageInventorySnapshot($valid, $valid, [], [$issue]))->isAuthoritative());
        self::assertFalse((new PveStorageInventorySnapshot(null, $valid, [], []))->isAuthoritative());
        self::assertFalse((new PveStorageInventorySnapshot($valid, null, [], []))->isAuthoritative());
        self::assertFalse((new PveStorageInventorySnapshot(
            new PveStorageConfigurationSet(null, [$this->definition('aa')], []),
            $valid,
            [],
            [],
        ))->isAuthoritative());
        self::assertFalse((new PveStorageInventorySnapshot(
            $valid,
            new PveStorageConfigurationSet(null, [$this->definition('aa')], []),
            [],
            [],
        ))->isAuthoritative());
        self::assertFalse((new PveStorageInventorySnapshot(
            $valid,
            new PveStorageConfigurationSet('changed', [$this->definition('aa')], []),
            [],
            [],
        ))->isAuthoritative());
        self::assertFalse((new PveStorageInventorySnapshot(
            $valid,
            new PveStorageConfigurationSet('digest', [$this->definition('bb')], []),
            [],
            [],
        ))->isAuthoritative());
    }

    private function definition(string $storageId): PveStorageConfiguration
    {
        return new PveStorageConfiguration(
            $storageId,
            'dir',
            new PveStorageContentSet(['backup']),
            null,
            false,
            false,
            null,
        );
    }
}
