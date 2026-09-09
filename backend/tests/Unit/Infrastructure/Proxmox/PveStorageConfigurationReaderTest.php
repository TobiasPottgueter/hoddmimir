<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox;

use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveReadFailureCode;
use App\Application\Proxmox\Pve\PveStorageIssue;
use App\Application\Proxmox\Pve\PveStorageIssueCode;
use App\Infrastructure\Proxmox\PveStorageConfigurationReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PveStorageConfigurationReaderTest extends TestCase
{
    public function testItWhitelistsAndNormalizesValidConfigurationRows(): void
    {
        $set = (new PveStorageConfigurationReader())->read([
            (object) [
                'storage' => 'local',
                'type' => 'dir',
                'content' => ' iso, backup,backup ',
                'nodes' => 'node-b.test, node-a.test,node-a.test',
                'digest' => 'opaque-digest',
                'password' => 'must-not-leave-reader',
            ],
            [
                'storage' => 'pbs-default',
                'type' => 'pbs',
                'content' => 'backup',
                'digest' => 'opaque-digest',
                'shared' => 1,
                'server' => 'pbs.test',
                'datastore' => 'vault',
                'maxfiles' => 7,
            ],
            [
                'storage' => 'pbs-namespaced',
                'type' => 'pbs',
                'content' => 'backup,future-content',
                'digest' => 'opaque-digest',
                'disable' => false,
                'shared' => true,
                'server' => 'pbs-alt.test',
                'port' => 8443,
                'datastore' => 'archive',
                'namespace' => 'tenant-a',
            ],
            [
                'storage' => 'future',
                'type' => 'future-plugin',
                'content' => 'future-content',
                'digest' => 'opaque-digest',
                'disable' => 0,
                'shared' => 0,
            ],
        ]);

        self::assertTrue($set->isContractValid());
        self::assertSame('opaque-digest', $set->globalDigest);
        self::assertSame(['future', 'local', 'pbs-default', 'pbs-namespaced'], array_map(
            static fn ($definition): string => $definition->storageId,
            $set->definitions,
        ));

        $local = $set->definitions[1];
        self::assertSame(['backup', 'iso'], $local->content->tokens);
        self::assertSame(['node-a.test', 'node-b.test'], $local->nodeAllowlist);
        self::assertFalse($local->disabled);
        self::assertFalse($local->shared);
        self::assertNull($local->pbsMapping);

        $default = $set->definitions[2]->pbsMapping;
        self::assertNotNull($default);
        self::assertSame(8007, $default->port);
        self::assertNull($default->namespace);

        $namespaced = $set->definitions[3]->pbsMapping;
        self::assertNotNull($namespaced);
        self::assertSame(8443, $namespaced->port);
        self::assertSame('tenant-a', $namespaced->namespace);

        self::assertSame('future-plugin', $set->definitions[0]->storageType);
        self::assertFalse($set->definitions[0]->supportsBackup());
    }

    #[DataProvider('invalidTopLevelProvider')]
    public function testItRejectsInvalidTopLevelResponses(mixed $data): void
    {
        try {
            (new PveStorageConfigurationReader())->read($data);
            self::fail('Expected a typed response failure.');
        } catch (PveReadFailure $failure) {
            self::assertSame(PveReadFailureCode::InvalidResponse, $failure->failureCode);
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidTopLevelProvider(): iterable
    {
        yield 'object' => [(object) []];
        yield 'map' => [['data' => []]];
        yield 'string' => ['invalid'];
    }

    public function testEmptyMalformedMissingAndMixedDigestReadsAreNeverValid(): void
    {
        $reader = new PveStorageConfigurationReader();
        $empty = $reader->read([]);
        self::assertNull($empty->globalDigest);
        self::assertSame([PveStorageIssueCode::MissingConfigurationDigest], $this->codes($empty->issues));

        $malformed = $reader->read([
            'not-a-row',
            ['storage' => 'aa', 'type' => 'dir', 'content' => 'backup'],
            ['storage' => 'bb', 'type' => 'dir', 'content' => 'backup', 'digest' => 'one'],
            ['storage' => 'cc', 'type' => 'dir', 'content' => 'backup', 'digest' => 'two'],
        ]);
        self::assertNull($malformed->globalDigest);
        self::assertContains(PveStorageIssueCode::InvalidField, $this->codes($malformed->issues));
        self::assertContains(PveStorageIssueCode::MissingConfigurationDigest, $this->codes($malformed->issues));
        self::assertContains(PveStorageIssueCode::InconsistentConfigurationDigest, $this->codes($malformed->issues));
        self::assertCount(3, $malformed->definitions);

        $invalidDigest = $reader->read([
            ['storage' => 'aa', 'type' => 'dir', 'content' => 'backup', 'digest' => ' invalid'],
        ]);
        self::assertNull($invalidDigest->globalDigest);
        self::assertSame([PveStorageIssueCode::MissingConfigurationDigest], $this->codes($invalidDigest->issues));

        foreach (["digest\0value", "digest\x1Bvalue", "digest\nvalue", "digest\x7Fvalue", 'digést'] as $digest) {
            $unsafeDigest = $reader->read([
                ['storage' => 'aa', 'type' => 'dir', 'content' => 'backup', 'digest' => $digest],
            ]);
            self::assertNull($unsafeDigest->globalDigest);
            self::assertSame(
                [PveStorageIssueCode::MissingConfigurationDigest],
                $this->codes($unsafeDigest->issues),
            );
        }
    }

    public function testItReportsDuplicatesAndRequiredFieldFailuresWithoutInventingDefinitions(): void
    {
        $set = (new PveStorageConfigurationReader())->read([
            ['storage' => 'valid', 'type' => 'dir', 'content' => 'backup', 'digest' => 'digest'],
            ['storage' => 'valid', 'type' => 'dir', 'content' => 'backup', 'digest' => 'digest'],
            ['type' => 'dir', 'content' => 'backup', 'digest' => 'digest'],
            ['storage' => '', 'type' => 'dir', 'content' => 'backup', 'digest' => 'digest'],
            ['storage' => 'missing-type', 'content' => 'backup', 'digest' => 'digest'],
            ['storage' => 'bad-type', 'type' => 7, 'content' => 'backup', 'digest' => 'digest'],
            ['storage' => 'missing-content', 'type' => 'dir', 'digest' => 'digest'],
            ['storage' => 'bad-content', 'type' => 'dir', 'content' => 'backup,,iso', 'digest' => 'digest'],
            ['storage' => 'wrong-content', 'type' => 'dir', 'content' => 7, 'digest' => 'digest'],
            ['storage' => 'empty-content', 'type' => 'dir', 'content' => '', 'digest' => 'digest'],
            ['storage' => 'unicode-content', 'type' => 'dir', 'content' => 'bäckup', 'digest' => 'digest'],
        ]);

        self::assertSame(['valid'], array_map(static fn ($item): string => $item->storageId, $set->definitions));
        self::assertContains(PveStorageIssueCode::DuplicateStorage, $this->codes($set->issues));
        self::assertContains(PveStorageIssueCode::MissingRequiredField, $this->codes($set->issues));
        self::assertContains(PveStorageIssueCode::InvalidField, $this->codes($set->issues));
        self::assertContains('/data/7/content', $this->fields($set->issues));
        self::assertContains('/data/10/content', $this->fields($set->issues));
    }

    public function testInvalidNodesAndConfigurationBooleansInvalidateOnlyTheirRows(): void
    {
        $set = (new PveStorageConfigurationReader())->read([
            ['storage' => 'nodes-type', 'type' => 'dir', 'content' => 'backup', 'nodes' => ['a'], 'digest' => 'digest'],
            ['storage' => 'nodes-empty', 'type' => 'dir', 'content' => 'backup', 'nodes' => '', 'digest' => 'digest'],
            ['storage' => 'nodes-token', 'type' => 'dir', 'content' => 'backup', 'nodes' => 'node/a', 'digest' => 'digest'],
            ['storage' => 'nodes-control', 'type' => 'dir', 'content' => 'backup', 'nodes' => "node\x1B.test", 'digest' => 'digest'],
            ['storage' => 'nodes-unicode', 'type' => 'dir', 'content' => 'backup', 'nodes' => 'nöde.test', 'digest' => 'digest'],
            ['storage' => 'disable', 'type' => 'dir', 'content' => 'backup', 'disable' => '0', 'digest' => 'digest'],
            ['storage' => 'shared', 'type' => 'dir', 'content' => 'backup', 'shared' => 2, 'digest' => 'digest'],
            ['storage' => 'valid', 'type' => 'dir', 'content' => 'backup', 'nodes' => null, 'digest' => 'digest'],
        ]);

        self::assertSame(['valid'], array_map(static fn ($item): string => $item->storageId, $set->definitions));
        self::assertCount(7, $set->issues);
        self::assertSame(array_fill(0, 7, PveStorageIssueCode::InvalidField), $this->codes($set->issues));
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalidIdentifierProvider')]
    public function testConfigurationIdentifiersRejectControlsDeleteUnicodeAndInvalidGrammar(array $override): void
    {
        $set = (new PveStorageConfigurationReader())->read([array_replace([
            'storage' => 'backup',
            'type' => 'dir',
            'content' => 'backup',
            'digest' => 'digest',
        ], $override)]);

        self::assertSame([], $set->definitions);
        self::assertContains(PveStorageIssueCode::InvalidField, $this->codes($set->issues));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidIdentifierProvider(): iterable
    {
        yield 'storage digit first' => [['storage' => '1backup.store']];
        yield 'storage single letter' => [['storage' => 'a']];
        yield 'storage trailing dot' => [['storage' => 'backup.']];
        yield 'storage trailing hyphen' => [['storage' => 'backup-']];
        yield 'storage trailing underscore' => [['storage' => 'backup_']];
        yield 'storage nul' => [['storage' => "backup\0store"]];
        yield 'storage escape' => [['storage' => "backup\x1Bstore"]];
        yield 'storage newline' => [['storage' => "backup\nstore"]];
        yield 'storage delete' => [['storage' => "backup\x7Fstore"]];
        yield 'storage unicode' => [['storage' => 'bäckup']];
        yield 'type control' => [['type' => "d\x1Bir"]];
        yield 'type delete' => [['type' => "dir\x7F"]];
        yield 'type unicode' => [['type' => 'dír']];
    }

    public function testOfficialDottedStorageIdIsAccepted(): void
    {
        $set = (new PveStorageConfigurationReader())->read([[
            'storage' => 'Backup.store',
            'type' => 'dir',
            'content' => 'backup',
            'digest' => 'digest',
        ]]);

        self::assertTrue($set->isContractValid());
        self::assertSame('Backup.store', $set->definitions[0]->storageId);
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidPbsProvider')]
    public function testInvalidPbsMappingStaysVisibleButCannotCreateAMapping(array $overrides, string $field): void
    {
        $row = array_replace([
            'storage' => 'pbs',
            'type' => 'pbs',
            'content' => 'backup',
            'digest' => 'digest',
            'server' => 'pbs.test',
            'datastore' => 'vault',
        ], $overrides);
        foreach ($overrides as $key => $value) {
            if ('__unset__' === $value) {
                unset($row[$key]);
            }
        }

        $set = (new PveStorageConfigurationReader())->read([$row]);

        self::assertCount(1, $set->definitions);
        self::assertNull($set->definitions[0]->pbsMapping);
        self::assertContains('/data/0/'.$field, $this->fields($set->issues));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidPbsProvider(): iterable
    {
        yield 'missing server' => [['server' => '__unset__'], 'server'];
        yield 'invalid server' => [['server' => ' pbs.test'], 'server'];
        yield 'server nul' => [['server' => "pbs\0.test"], 'server'];
        yield 'server escape' => [['server' => "pbs\x1B.test"], 'server'];
        yield 'server delete' => [['server' => "pbs\x7F.test"], 'server'];
        yield 'server unicode' => [['server' => 'pbs-é.test'], 'server'];
        yield 'missing datastore' => [['datastore' => '__unset__'], 'datastore'];
        yield 'invalid datastore' => [['datastore' => ''], 'datastore'];
        yield 'datastore control' => [['datastore' => "vault\nname"], 'datastore'];
        yield 'datastore unicode' => [['datastore' => 'väult'], 'datastore'];
        yield 'zero port' => [['port' => 0], 'port'];
        yield 'oversized port' => [['port' => 65536], 'port'];
        yield 'string port' => [['port' => '8007'], 'port'];
        yield 'empty namespace' => [['namespace' => ''], 'namespace'];
        yield 'wrong namespace' => [['namespace' => 7], 'namespace'];
        yield 'namespace escape' => [['namespace' => "tenant\x1B"], 'namespace'];
        yield 'namespace delete' => [['namespace' => "tenant\x7F"], 'namespace'];
        yield 'namespace unicode' => [['namespace' => 'tënant'], 'namespace'];
    }

    /**
     * @param list<PveStorageIssue> $issues
     *
     * @return list<PveStorageIssueCode>
     */
    private function codes(array $issues): array
    {
        return array_map(static fn (PveStorageIssue $issue): PveStorageIssueCode => $issue->code, $issues);
    }

    /**
     * @param list<PveStorageIssue> $issues
     *
     * @return list<string>
     */
    private function fields(array $issues): array
    {
        return array_map(static fn (PveStorageIssue $issue): string => $issue->field, $issues);
    }
}
