<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox;

use App\Application\Proxmox\Pve\PveClusterMode;
use App\Application\Proxmox\Pve\PveGuestType;
use App\Application\Proxmox\Pve\PveInventoryIssueCode;
use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveReadFailureCode;
use App\Infrastructure\Proxmox\PveClusterResourcesReader;
use App\Infrastructure\Proxmox\PveClusterStatusReader;
use App\Infrastructure\Proxmox\PveJsonEnvelopeDecoder;
use App\Infrastructure\Proxmox\PvePermissionReader;
use App\Infrastructure\Proxmox\PveVersionReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PveReadersTest extends TestCase
{
    #[DataProvider('supportedVersionProvider')]
    public function testVersionReaderAcceptsSupportedMajorsAndPreservesRawData(
        string $release,
        string $rawVersion,
        string $repoId,
        int $major,
        int $minor,
        ?int $patch,
    ): void {
        $version = (new PveVersionReader())->read([
            'release' => $release,
            'version' => $rawVersion,
            'repoid' => $repoId,
            'future-field' => 'ignored',
        ]);

        self::assertSame($major, $version->major);
        self::assertSame($minor, $version->minor);
        self::assertSame($patch, $version->patch);
        self::assertSame($release, $version->release);
        self::assertSame($rawVersion, $version->version);
        self::assertSame($repoId, $version->repoId);
    }

    /** @return iterable<string, array{string, string, string, int, int, ?int}> */
    public static function supportedVersionProvider(): iterable
    {
        yield 'PVE 7 package release' => ['7.4', '7.4-19', 'nonhex-pve7', 7, 4, null];
        yield 'PVE 8 unknown minor and patch' => ['8.99', '8.99.123+future', 'abcdef12', 8, 99, 123];
        yield 'PVE 9 suffix' => ['9.2', '9.2.3~test', 'ABCDEF1234567890', 9, 2, 3];
    }

    #[DataProvider('invalidVersionProvider')]
    public function testVersionReaderRejectsMalformedAndUnsupportedResponses(mixed $data, PveReadFailureCode $code): void
    {
        $this->assertFailure(static fn () => (new PveVersionReader())->read($data), $code);
    }

    /** @return iterable<string, array{mixed, PveReadFailureCode}> */
    public static function invalidVersionProvider(): iterable
    {
        yield 'not object' => [[], PveReadFailureCode::InvalidResponse];
        yield 'missing field' => [['release' => '9.2', 'version' => '9.2.3'], PveReadFailureCode::InvalidResponse];
        yield 'wrong field type' => [['release' => 9.2, 'version' => '9.2.3', 'repoid' => 'abcdef12'], PveReadFailureCode::InvalidResponse];
        yield 'empty repo' => [['release' => '9.2', 'version' => '9.2.3', 'repoid' => ''], PveReadFailureCode::InvalidResponse];
        yield 'bad release' => [['release' => '9', 'version' => '9.2.3', 'repoid' => 'abcdef12'], PveReadFailureCode::InvalidResponse];
        yield 'version mismatch' => [['release' => '9.2', 'version' => '9.3.0', 'repoid' => 'abcdef12'], PveReadFailureCode::InvalidResponse];
        yield 'bad version grammar' => [['release' => '9.2', 'version' => '9.2/', 'repoid' => 'abcdef12'], PveReadFailureCode::InvalidResponse];
        yield 'unsupported six' => [['release' => '6.4', 'version' => '6.4-1', 'repoid' => 'x'], PveReadFailureCode::UnsupportedVersion];
        yield 'unsupported ten' => [['release' => '10.0', 'version' => '10.0.1', 'repoid' => 'abcdef12'], PveReadFailureCode::UnsupportedVersion];
        yield 'invalid modern repoid' => [['release' => '8.4', 'version' => '8.4.0', 'repoid' => 'not-hex'], PveReadFailureCode::InvalidResponse];
    }

    public function testPermissionReaderUsesTheEffectiveRightsAtEachRequiredPath(): void
    {
        $reader = new PvePermissionReader();
        $empty = $reader->read((new PveJsonEnvelopeDecoder())->decode('{"data":{}}'));
        self::assertCount(3, $empty->missing);
        $this->assertFailure(
            static fn () => $reader->read((new PveJsonEnvelopeDecoder())->decode('{"data":[]}')),
            PveReadFailureCode::InvalidResponse,
        );

        $complete = $reader->read([
            '/' => ['Sys.Audit' => 1, 'VM.Audit' => 1, 'Datastore.Audit' => 1],
            '/vms' => ['VM.Audit' => 1],
            '/storage' => ['Datastore.Audit' => 1],
            '/ignored/' => ['VM.Audit' => 1],
            'invalid' => ['Sys.Audit' => 1],
            5 => 'invalid',
        ]);
        self::assertTrue($complete->isComplete());

        $rootOnly = $reader->read([
            '/' => ['Sys.Audit' => 1, 'VM.Audit' => 1, 'Datastore.Audit' => 1],
        ]);
        self::assertSame(['VM.Audit', 'Datastore.Audit'], array_map(
            static fn ($missing): string => $missing->privilege(),
            $rootOnly->missing,
        ));

        $partial = $reader->read([
            '/' => ['Sys.Audit' => 0, 'VM.Audit' => false],
            '/vms/' => ['VM.Audit' => 1],
            '/storage' => ['Datastore.Audit' => '1'],
        ]);
        self::assertFalse($partial->isComplete());
        self::assertSame(['Sys.Audit', 'Datastore.Audit'], array_map(
            static fn ($missing): string => $missing->privilege(),
            $partial->missing,
        ));

        $booleanIsNotThePropagationFlag = $reader->read([
            '/' => ['Sys.Audit' => 1],
            '/vms' => ['VM.Audit' => 1],
            '/storage' => ['Datastore.Audit' => true],
        ]);
        self::assertSame(['Datastore.Audit'], array_map(
            static fn ($missing): string => $missing->privilege(),
            $booleanIsNotThePropagationFlag->missing,
        ));

        $this->assertFailure(static fn () => $reader->read([]), PveReadFailureCode::InvalidResponse);
        $this->assertFailure(static fn () => $reader->read('invalid'), PveReadFailureCode::InvalidResponse);
    }

    public function testPermissionReaderIgnoresAStandaloneInvalidPath(): void
    {
        $assessment = (new PvePermissionReader())->read(['invalid' => ['Sys.Audit' => 1]]);
        self::assertCount(3, $assessment->missing);
    }

    public function testClusterReaderPreservesCompleteClusteredAndStandaloneTopology(): void
    {
        $reader = new PveClusterStatusReader();
        $clustered = $reader->read([
            ['id' => 'cluster', 'type' => 'cluster', 'name' => 'forest', 'nodes' => 1, 'version' => 7, 'quorate' => 1],
            ['id' => 'node/a', 'type' => 'node', 'name' => 'a.test', 'online' => 1, 'nodeid' => 1, 'local' => false],
            ['id' => 'future/x', 'type' => 'future', 'name' => 'ignored'],
        ]);
        self::assertSame(PveClusterMode::Clustered, $clustered->mode);
        self::assertSame('forest', $clustered->clusterName);
        self::assertSame(1, $clustered->declaredNodeCount);
        self::assertSame(7, $clustered->configurationVersion);
        self::assertTrue($clustered->quorate);
        self::assertTrue($clustered->isComplete());
        self::assertTrue($clustered->nodes[0]->online);
        self::assertSame(1, $clustered->nodes[0]->localNodeId);
        self::assertFalse($clustered->nodes[0]->local);

        $standalone = $reader->read([
            ['id' => 'node/solo', 'type' => 'node', 'name' => 'solo.test', 'online' => false, 'nodeid' => 0, 'local' => true],
        ]);
        self::assertSame(PveClusterMode::Standalone, $standalone->mode);
        self::assertNull($standalone->clusterName);
        self::assertNull($standalone->declaredNodeCount);
        self::assertNull($standalone->configurationVersion);
        self::assertNull($standalone->quorate);
        self::assertTrue($standalone->isComplete());
        self::assertFalse($standalone->nodes[0]->online);
        self::assertSame(0, $standalone->nodes[0]->localNodeId);

        $this->assertFailure(static fn () => $reader->read(['not' => 'a-list']), PveReadFailureCode::InvalidResponse);
    }

    public function testClusterReaderReportsDuplicateAndMalformedRows(): void
    {
        $reader = new PveClusterStatusReader();

        $partial = $reader->read([
            'not-a-row',
            ['id' => 'x', 'name' => 'missing-type'],
            ['type' => 1],
            ['type' => 'node', 'name' => 'missing-id'],
            ['type' => 'node', 'id' => 'node/no-name'],
            ['type' => 'cluster', 'id' => 'cluster', 'name' => 'first', 'nodes' => 1, 'version' => 7, 'quorate' => true],
            ['type' => 'cluster', 'id' => 'cluster', 'name' => 'duplicate', 'nodes' => 1, 'version' => 8, 'quorate' => true],
            ['type' => 'node', 'id' => 'node/a', 'name' => 'a.test', 'online' => 'yes', 'nodeid' => '1', 'local' => 0],
            ['type' => 'node', 'id' => 'node/a', 'name' => 'a.test'],
        ]);
        self::assertFalse($partial->isComplete());
        self::assertContains(PveInventoryIssueCode::DuplicateClusterRecord, $this->issueCodes($partial->issues));
        self::assertContains(PveInventoryIssueCode::DuplicateResource, $this->issueCodes($partial->issues));
        self::assertNull($partial->nodes[0]->online);
        self::assertNull($partial->nodes[0]->localNodeId);
        self::assertFalse($partial->nodes[0]->local);

        self::assertContains('/data/0/row', $this->issueFields($partial->issues));
        self::assertContains('/data/1/type', $this->issueFields($partial->issues));
        self::assertContains('/data/2/type', $this->issueFields($partial->issues));
    }

    public function testClusterReaderRejectsMissingInvalidMismatchedAndNonQuorateClusterState(): void
    {
        $reader = new PveClusterStatusReader();
        $node = ['type' => 'node', 'id' => 'node/a', 'name' => 'a.test', 'nodeid' => 1, 'local' => true];
        $validCluster = [
            'type' => 'cluster',
            'id' => 'cluster',
            'name' => 'forest',
            'nodes' => 1,
            'version' => 7,
            'quorate' => true,
        ];

        $cases = [
            'missing id' => [[array_diff_key($validCluster, ['id' => true]), $node], '/data/0/id'],
            'wrong id' => [[array_replace($validCluster, ['id' => 'cluster/wrong']), $node], '/data/0/id'],
            'missing name' => [[array_diff_key($validCluster, ['name' => true]), $node], '/data/0/name'],
            'empty name' => [[array_replace($validCluster, ['name' => '']), $node], '/data/0/name'],
            'missing nodes' => [[array_diff_key($validCluster, ['nodes' => true]), $node], '/data/0/nodes'],
            'zero nodes' => [[array_replace($validCluster, ['nodes' => 0]), $node], '/data/0/nodes'],
            'wrong nodes type' => [[array_replace($validCluster, ['nodes' => '1']), $node], '/data/0/nodes'],
            'missing version' => [[array_diff_key($validCluster, ['version' => true]), $node], '/data/0/version'],
            'zero version' => [[array_replace($validCluster, ['version' => 0]), $node], '/data/0/version'],
            'missing quorate' => [[array_diff_key($validCluster, ['quorate' => true]), $node], '/data/0/quorate'],
            'wrong quorate type' => [[array_replace($validCluster, ['quorate' => '1']), $node], '/data/0/quorate'],
            'not quorate' => [[array_replace($validCluster, ['quorate' => 0]), $node], '/data/0/quorate'],
            'declared count mismatch' => [[array_replace($validCluster, ['nodes' => 2]), $node], '/data/0/nodes'],
            'no observed nodes' => [[$validCluster], '/data'],
        ];

        foreach ($cases as $case => [$data, $expectedField]) {
            $topology = $reader->read($data);
            self::assertFalse($topology->isComplete(), $case);
            self::assertContains($expectedField, $this->issueFields($topology->issues), $case);
        }

        $nonQuorate = $reader->read([array_replace($validCluster, ['quorate' => false]), $node]);
        self::assertFalse($nonQuorate->quorate);
    }

    public function testClusterReaderRequiresExactlyTheLocalNodeZeroForStandalone(): void
    {
        $reader = new PveClusterStatusReader();
        $validNode = ['type' => 'node', 'id' => 'node/a', 'name' => 'a.test', 'nodeid' => 0, 'local' => true];
        $cases = [
            'no nodes' => [[], '/data'],
            'multiple nodes' => [[
                $validNode,
                ['type' => 'node', 'id' => 'node/b', 'name' => 'b.test', 'nodeid' => 1, 'local' => false],
            ], '/data'],
            'not local' => [[array_replace($validNode, ['local' => false])], '/data/0/local'],
            'missing local' => [[array_diff_key($validNode, ['local' => true])], '/data/0/local'],
            'wrong local type' => [[array_replace($validNode, ['local' => '1'])], '/data/0/local'],
            'missing nodeid' => [[array_diff_key($validNode, ['nodeid' => true])], '/data/0/nodeid'],
            'negative nodeid' => [[array_replace($validNode, ['nodeid' => -1])], '/data/0/nodeid'],
            'wrong nodeid type' => [[array_replace($validNode, ['nodeid' => '0'])], '/data/0/nodeid'],
        ];

        foreach ($cases as $case => [$data, $expectedField]) {
            $topology = $reader->read($data);
            self::assertFalse($topology->isComplete(), $case);
            self::assertContains(PveInventoryIssueCode::InvalidTopology, $this->issueCodes($topology->issues), $case);
            self::assertContains($expectedField, $this->issueFields($topology->issues), $case);
        }
    }

    public function testResourceReaderSupportsTypedRowsNullableFieldsAndPartialIssues(): void
    {
        $reader = new PveClusterResourcesReader();
        $inventory = $reader->read([
            ['id' => 'node/a', 'type' => 'node', 'node' => 'a.test', 'name' => 'different', 'status' => 'online'],
            ['id' => 'qemu/42', 'type' => 'qemu', 'vmid' => 42, 'node' => 'a.test', 'name' => 'vm', 'template' => 1, 'status' => 'running', 'diskwrite' => 123, 'future' => 'ignored'],
            ['id' => 'lxc/42', 'type' => 'lxc', 'vmid' => 42, 'node' => 'a.test', 'name' => null, 'template' => false],
            ['id' => 'storage/a/backup', 'type' => 'storage', 'storage' => 'backup', 'node' => 'a.test', 'content' => 'backup', 'disk' => 20, 'maxdisk' => 100, 'avail' => 80],
            ['id' => 'network/x', 'type' => 'network', 'future' => true],
        ]);

        self::assertTrue($inventory->isComplete());
        self::assertSame('a.test', $inventory->nodes[0]->name);
        self::assertSame(PveGuestType::Qemu, $inventory->guests[0]->type);
        self::assertTrue($inventory->guests[0]->template);
        self::assertSame(123, $inventory->guests[0]->diskWriteBytes);
        self::assertSame(PveGuestType::Lxc, $inventory->guests[1]->type);
        self::assertNull($inventory->guests[1]->name);
        self::assertFalse($inventory->guests[1]->template);
        self::assertNull($inventory->guests[1]->diskWriteBytes);
        self::assertSame(100, $inventory->storages[0]->totalBytes);
        self::assertSame(20, $inventory->storages[0]->usedBytes);
        self::assertNull($inventory->storages[0]->availableBytes);

        $nullable = $reader->read([
            ['id' => 'node/a', 'type' => 'node', 'node' => 'a', 'status' => 1],
            ['id' => 'qemu/1', 'type' => 'qemu', 'vmid' => 1, 'node' => 'a', 'name' => 1, 'template' => '1', 'status' => 1, 'diskwrite' => -1],
            ['id' => 'storage/a/s', 'type' => 'storage', 'storage' => 's', 'node' => 'a', 'content' => 'backup', 'disk' => -1, 'maxdisk' => '1', 'avail' => -1],
        ]);
        self::assertNull($nullable->nodes[0]->status);
        self::assertNull($nullable->guests[0]->name);
        self::assertNull($nullable->guests[0]->template);
        self::assertNull($nullable->guests[0]->status);
        self::assertNull($nullable->guests[0]->diskWriteBytes);
        self::assertNull($nullable->storages[0]->totalBytes);
        self::assertNull($nullable->storages[0]->usedBytes);
        self::assertNull($nullable->storages[0]->availableBytes);

        $partial = $reader->read([
            'bad-row',
            ['id' => 'x'],
            ['type' => 'node', 'node' => 'a'],
            ['id' => 'node/x', 'type' => 'node', 'name' => 'fallback-forbidden'],
            ['id' => 'qemu/0', 'type' => 'qemu', 'vmid' => 0, 'node' => 'a'],
            ['id' => 'qemu/2', 'type' => 'qemu', 'vmid' => 2],
            ['id' => 'storage/a/x', 'type' => 'storage', 'node' => 'a', 'content' => 'backup'],
            ['id' => 'storage/a/x', 'type' => 'storage', 'storage' => 'x', 'content' => 'backup'],
            ['id' => 'storage/a/x', 'type' => 'storage', 'storage' => 'x', 'node' => 'a'],
            ['id' => 'node/a', 'type' => 'node', 'node' => 'a'],
            ['id' => 'node/a2', 'type' => 'node', 'node' => 'a'],
            ['id' => 'qemu/3', 'type' => 'qemu', 'vmid' => 3, 'node' => 'a'],
            ['id' => 'qemu/3-copy', 'type' => 'qemu', 'vmid' => 3, 'node' => 'b'],
            ['id' => 'storage/a/s', 'type' => 'storage', 'storage' => 's', 'node' => 'a', 'content' => 'backup'],
            ['id' => 'storage/a/s-copy', 'type' => 'storage', 'storage' => 's', 'node' => 'a', 'content' => 'backup'],
        ]);
        self::assertFalse($partial->isComplete());
        self::assertContains(PveInventoryIssueCode::MissingRequiredField, $this->issueCodes($partial->issues));
        self::assertContains(PveInventoryIssueCode::DuplicateResource, $this->issueCodes($partial->issues));
        self::assertSame('/data/0/row', $partial->issues[0]->field);

        $this->assertFailure(static fn () => $reader->read(['not' => 'a-list']), PveReadFailureCode::InvalidResponse);
    }

    #[DataProvider('invalidEnvelopeProvider')]
    public function testEnvelopeDecoderRejectsMalformedOversizedOrMissingData(string $body): void
    {
        $this->assertFailure(static fn () => (new PveJsonEnvelopeDecoder())->decode($body), PveReadFailureCode::InvalidEnvelope);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidEnvelopeProvider(): iterable
    {
        yield 'invalid json' => ['TOKEN-SENTINEL {'];
        yield 'scalar' => ['42'];
        yield 'list' => ['[]'];
        yield 'missing data' => ['{"other":true}'];
        yield 'depth' => [str_repeat('[', 65).str_repeat(']', 65)];
        yield 'oversized' => [str_repeat('x', 8_388_609)];
    }

    public function testEnvelopeDecoderReturnsAnyDataShapeAndIgnoresAdditiveTopLevelFields(): void
    {
        $data = (new PveJsonEnvelopeDecoder())->decode('{"data":{"value":1},"future":true}');
        self::assertInstanceOf(\stdClass::class, $data);
        self::assertSame(1, $data->value);
    }

    public function testInvalidEnvelopeEscapesAsTypedReadFailure(): void
    {
        $this->expectException(PveReadFailure::class);
        (new PveJsonEnvelopeDecoder())->decode('invalid');
    }

    public function testInvalidPermissionShapeEscapesAsTypedReadFailure(): void
    {
        $this->expectException(PveReadFailure::class);
        (new PvePermissionReader())->read([]);
    }

    public function testInvalidVersionShapeEscapesAsTypedReadFailure(): void
    {
        $this->expectException(PveReadFailure::class);
        (new PveVersionReader())->read([]);
    }

    /** @param callable(): mixed $operation */
    private function assertFailure(callable $operation, PveReadFailureCode $code): void
    {
        try {
            $operation();
            self::fail('Expected a typed PVE read failure.');
        } catch (PveReadFailure $failure) {
            self::assertSame($code, $failure->failureCode);
            self::assertStringNotContainsString('TOKEN-SENTINEL', $failure->getMessage());
        }
    }

    /**
     * @param list<\App\Application\Proxmox\Pve\PveInventoryIssue> $issues
     * @return list<PveInventoryIssueCode>
     */
    private function issueCodes(array $issues): array
    {
        return array_map(static fn ($issue): PveInventoryIssueCode => $issue->code, $issues);
    }

    /**
     * @param list<\App\Application\Proxmox\Pve\PveInventoryIssue> $issues
     * @return list<string>
     */
    private function issueFields(array $issues): array
    {
        return array_map(static fn ($issue): string => $issue->field, $issues);
    }
}
