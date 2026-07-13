<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsCapacitySemantics;
use App\Application\Proxmox\Pbs\PbsDatastoreBackendType;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsMaintenanceMode;
use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use App\Application\Proxmox\Pbs\PbsVersion;
use App\Infrastructure\Proxmox\Pbs\PbsApiEnvelope;
use App\Infrastructure\Proxmox\Pbs\PbsDatastoreConfigurationReader;
use App\Infrastructure\Proxmox\Pbs\PbsDatastoreListReader;
use App\Infrastructure\Proxmox\Pbs\PbsDatastoreStatusReader;
use App\Infrastructure\Proxmox\Pbs\PbsInstanceIdentityReader;
use App\Infrastructure\Proxmox\Pbs\PbsJsonEnvelopeDecoder;
use App\Infrastructure\Proxmox\Pbs\PbsNodesReader;
use App\Infrastructure\Proxmox\Pbs\PbsNodeStatusReader;
use App\Infrastructure\Proxmox\Pbs\PbsPermissionReader;
use App\Infrastructure\Proxmox\Pbs\PbsPingReader;
use App\Infrastructure\Proxmox\Pbs\PbsVersionReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PbsReadersTest extends TestCase
{
    public function testPbs3FixturesAreReadStrictlyAndAdditiveFieldsAreIgnored(): void
    {
        $version = (new PbsVersionReader())->read($this->fixture(3, 'version'));
        self::assertSame([3, 4, 4, '1'], [$version->major, $version->minor, $version->patch, $version->release]);
        (new PbsPingReader())->assertPbs($this->fixture(3, 'ping'));
        self::assertSame(['pbs-three'], (new PbsNodesReader())->read($this->fixture(3, 'nodes')));
        $permission = (new PbsPermissionReader())->read($this->fixture(3, 'permission-system-status'), '/system/status');
        self::assertTrue($permission->grants('Sys.Audit'));
        self::assertFalse($permission->propagates('Sys.Audit'));
        $status = (new PbsNodeStatusReader())->read($this->fixture(3, 'node-status'), 'pbs-three');
        self::assertSame(86400, $status->uptimeSeconds);
        $config = (new PbsDatastoreConfigurationReader())->read($this->fixture(3, 'config-datastore'));
        self::assertSame(str_repeat('a', 64), $config->digest);
        self::assertSame(['archive_a', 'store_b'], array_map(static fn (PbsDatastoreId $id): string => $id->value, $config->datastores));
        $definitions = (new PbsDatastoreListReader())->read($this->fixture(3, 'admin-datastore'), $version);
        self::assertCount(2, $definitions);
        self::assertSame(PbsDatastoreBackendType::Filesystem, $definitions[0]->backendType);
        $statusEnvelope = $this->fixture(3, 'admin-datastore-archive_a-status');
        self::assertInstanceOf(\stdClass::class, $statusEnvelope->data);
        self::assertFalse(property_exists($statusEnvelope->data, 'counts'));
        $capacity = (new PbsDatastoreStatusReader())->read(
            $statusEnvelope,
            $definitions[0]->id,
            $definitions[0]->backendType,
            $version,
        );
        self::assertSame(PbsCapacitySemantics::DatastoreFilesystem, $capacity->semantics);
    }

    public function testPbs42FixturesExposeIdentityAndS3OnlyAsLocalCache(): void
    {
        $version = (new PbsVersionReader())->read($this->fixture(4, 'version'));
        self::assertTrue($version->supportsInstanceIdentity());
        $identity = (new PbsInstanceIdentityReader())->read($this->fixture(4, 'identity'));
        self::assertSame('0123456789abcdef0123456789abcdef', $identity->value);
        $configEnvelope = $this->fixture(4, 'config-datastore');
        self::assertIsArray($configEnvelope->data);
        self::assertInstanceOf(\stdClass::class, $configEnvelope->data[1]);
        self::assertSame(
            'bucket=backup-bucket,client=s3-client,type=s3',
            $configEnvelope->data[1]->backend,
        );
        self::assertCount(2, (new PbsDatastoreConfigurationReader())->read($configEnvelope)->datastores);
        $definitions = (new PbsDatastoreListReader())->read($this->fixture(4, 'admin-datastore'), $version);
        self::assertSame(PbsDatastoreBackendType::S3, $definitions[1]->backendType);
        $statusEnvelope = $this->fixture(4, 'admin-datastore-object_s3-status');
        self::assertInstanceOf(\stdClass::class, $statusEnvelope->data);
        self::assertFalse(property_exists($statusEnvelope->data, 's3-statistics'));
        $capacity = (new PbsDatastoreStatusReader())->read(
            $statusEnvelope,
            $definitions[1]->id,
            $definitions[1]->backendType,
            $version,
        );
        self::assertSame(PbsCapacitySemantics::LocalCache, $capacity->semantics);
        self::assertFalse($capacity->maySatisfyTargetFreeSpaceGate());
    }

    public function testPermissionReaderHandlesAbsentAndSortedPrivileges(): void
    {
        $reader = new PbsPermissionReader();
        self::assertSame([], $reader->read(new PbsApiEnvelope((object) [], null), '/missing')->privileges);
        self::assertSame([], $reader->read(new PbsApiEnvelope((object) ['/missing' => null], null), '/missing')->privileges);
        $permission = $reader->read(new PbsApiEnvelope((object) [
            '/path' => (object) ['Sys.Modify' => false, 'Datastore.Audit' => true],
        ], null), '/path');
        self::assertSame(['Datastore.Audit', 'Sys.Modify'], array_keys($permission->privileges));
        $matrix = $reader->readAll(new PbsApiEnvelope((object) [
            '/z' => (object) ['Sys.Audit' => true],
            '/a' => (object) ['Datastore.Audit' => false],
        ], null));
        self::assertSame(['/a', '/z'], array_map(static fn ($entry): string => $entry->path, $matrix));
    }

    #[DataProvider('invalidVersionProvider')]
    public function testVersionReaderRejectsInvalidOrUnsupportedProduct(mixed $data, PbsReadFailureCode $code): void
    {
        $this->assertFailure($code, static fn () => (new PbsVersionReader())->read(new PbsApiEnvelope($data, null)));
    }

    public function testVersionReaderAcceptsVersionWithoutPatchComponent(): void
    {
        $version = (new PbsVersionReader())->read(new PbsApiEnvelope((object) [
            'version' => '4.1',
            'release' => '2',
            'repoid' => 'abcdef12',
        ], null));

        self::assertSame(4, $version->major);
        self::assertSame(1, $version->minor);
        self::assertNull($version->patch);
    }

    /** @return iterable<string, array{mixed, PbsReadFailureCode}> */
    public static function invalidVersionProvider(): iterable
    {
        yield 'not object' => [[], PbsReadFailureCode::InvalidResponse];
        yield 'missing' => [(object) [], PbsReadFailureCode::InvalidResponse];
        yield 'bad version' => [(object) ['version' => '3.x', 'release' => '1', 'repoid' => 'abcdef12'], PbsReadFailureCode::InvalidResponse];
        yield 'bad release' => [(object) ['version' => '3.4.4', 'release' => "1\n", 'repoid' => 'abcdef12'], PbsReadFailureCode::InvalidResponse];
        yield 'bad repo' => [(object) ['version' => '3.4.4', 'release' => '1', 'repoid' => 'not-hex!'], PbsReadFailureCode::InvalidResponse];
        yield 'old product' => [(object) ['version' => '2.4.2', 'release' => '1', 'repoid' => 'abcdef12'], PbsReadFailureCode::UnsupportedVersion];
        yield 'future product' => [(object) ['version' => '5.0.0', 'release' => '1', 'repoid' => 'abcdef12'], PbsReadFailureCode::UnsupportedVersion];
    }

    public function testEnvelopeAndSimpleReadersRejectMalformedShapes(): void
    {
        $decoder = new PbsJsonEnvelopeDecoder();
        self::assertSame(1, $decoder->decode('{"data":1,"future":true}', 100)->data);
        self::assertSame('abc', $decoder->decode('{"data":[],"digest":"abc"}', 100)->digest);
        self::assertSame(42, $decoder->decode('{"data":[],"total":42}', 100)->total);
        foreach (['', '[]', '{}', '{', '{"data":1,"digest":2}', '{"data":[],"total":"1"}', '{"data":[],"total":-1}'] as $json) {
            $this->assertFailure(PbsReadFailureCode::InvalidEnvelope, static fn () => $decoder->decode($json, 100));
        }
        $this->assertFailure(PbsReadFailureCode::InvalidEnvelope, static fn () => $decoder->decode('{"data":123}', 3));
        $this->assertFailure(PbsReadFailureCode::InvalidResponse, static fn () => (new PbsPingReader())->assertPbs(new PbsApiEnvelope((object) ['pong' => false], null)));
        $this->assertFailure(PbsReadFailureCode::InvalidResponse, static fn () => (new PbsNodesReader())->read(new PbsApiEnvelope([], null)));
        $this->assertFailure(PbsReadFailureCode::InvalidResponse, static fn () => (new PbsNodesReader())->read(new PbsApiEnvelope([(object) []], null)));
        $this->assertFailure(PbsReadFailureCode::InvalidResponse, static fn () => (new PbsNodesReader())->read(new PbsApiEnvelope([(object) ['node' => "bad\n"]], null)));
        $this->assertFailure(PbsReadFailureCode::InvalidResponse, static fn () => (new PbsPermissionReader())->read(new PbsApiEnvelope([], null), '/x'));
        $this->assertFailure(PbsReadFailureCode::InvalidResponse, static fn () => (new PbsPermissionReader())->read(new PbsApiEnvelope((object) ['/x' => []], null), '/x'));
        $this->assertFailure(PbsReadFailureCode::InvalidResponse, static fn () => (new PbsPermissionReader())->read(new PbsApiEnvelope((object) ['/x' => (object) ['bad key' => true]], null), '/x'));
        $this->assertFailure(PbsReadFailureCode::InvalidResponse, static fn () => (new PbsPermissionReader())->read(new PbsApiEnvelope((object) ['/x' => (object) ['Sys.Audit' => 1]], null), '/x'));
        $this->assertFailure(PbsReadFailureCode::InvalidResponse, static fn () => (new PbsPermissionReader())->readAll(new PbsApiEnvelope([], null)));
        $this->assertFailure(PbsReadFailureCode::InvalidResponse, static fn () => (new PbsPermissionReader())->readAll(new PbsApiEnvelope((object) ['relative' => (object) []], null)));
        $this->assertFailure(PbsReadFailureCode::InvalidResponse, static fn () => (new PbsInstanceIdentityReader())->read(new PbsApiEnvelope((object) [], null)));
        $this->assertFailure(PbsReadFailureCode::InvalidResponse, static fn () => (new PbsInstanceIdentityReader())->read(new PbsApiEnvelope([], null)));
        $this->assertFailure(PbsReadFailureCode::InvalidResponse, static fn () => (new PbsInstanceIdentityReader())->read(new PbsApiEnvelope((object) ['pbs-instance-id' => str_repeat('A', 32)], null)));
    }

    public function testPermissionMatrixBoundsPathsAndPrivileges(): void
    {
        $reader = new PbsPermissionReader();
        $paths = [];
        for ($index = 0; $index < 4097; ++$index) {
            $paths['/p'.$index] = (object) [];
        }
        $this->assertFailure(
            PbsReadFailureCode::InvalidResponse,
            static fn () => $reader->readAll(new PbsApiEnvelope((object) $paths, null)),
        );

        $privileges = [];
        for ($index = 0; $index < 257; ++$index) {
            $privileges['P.'.str_repeat('A', $index + 1)] = true;
        }
        $this->assertFailure(
            PbsReadFailureCode::InvalidResponse,
            static fn () => $reader->read(new PbsApiEnvelope((object) ['/x' => (object) $privileges], null), '/x'),
        );

        array_pop($privileges);
        $many = [];
        for ($index = 0; $index < 257; ++$index) {
            $many['/p'.$index] = (object) $privileges;
        }
        $this->assertFailure(
            PbsReadFailureCode::InvalidResponse,
            static fn () => $reader->readAll(new PbsApiEnvelope((object) $many, null)),
        );
    }

    public function testNodeStatusRejectsMissingNegativeAndContradictoryCounters(): void
    {
        $reader = new PbsNodeStatusReader();
        foreach ([
            new PbsApiEnvelope([], null),
            new PbsApiEnvelope((object) ['memory' => [], 'root' => []], null),
            new PbsApiEnvelope((object) ['uptime' => -1, 'memory' => (object) ['total' => 1, 'used' => 0], 'root' => (object) ['total' => 1, 'used' => 0, 'avail' => 1]], null),
            new PbsApiEnvelope((object) ['uptime' => 1, 'memory' => (object) ['total' => 1, 'used' => 2], 'root' => (object) ['total' => 1, 'used' => 0, 'avail' => 1]], null),
            new PbsApiEnvelope((object) ['uptime' => 1, 'memory' => (object) ['total' => 2, 'used' => 1], 'root' => (object) ['total' => 1, 'used' => 2, 'avail' => 0]], null),
            new PbsApiEnvelope((object) ['uptime' => 1, 'memory' => (object) ['total' => 2, 'used' => 1], 'root' => (object) ['total' => 1, 'used' => 0, 'avail' => 2]], null),
        ] as $envelope) {
            $this->assertFailure(PbsReadFailureCode::InvalidResponse, static fn () => $reader->read($envelope, 'pbs'));
        }
    }

    public function testConfigurationListAndStatusReadersRejectUnsafeContracts(): void
    {
        $configReader = new PbsDatastoreConfigurationReader();
        foreach ([
            new PbsApiEnvelope((object) [], str_repeat('a', 64)),
            new PbsApiEnvelope([], null),
            new PbsApiEnvelope([], str_repeat('A', 64)),
            new PbsApiEnvelope([(object) []], str_repeat('a', 64)),
            new PbsApiEnvelope([[]], str_repeat('a', 64)),
            new PbsApiEnvelope([(object) ['name' => 'bad/']], str_repeat('a', 64)),
            new PbsApiEnvelope([(object) ['name' => 'same_id'], (object) ['name' => 'same_id']], str_repeat('a', 64)),
        ] as $envelope) {
            $this->assertFailure(PbsReadFailureCode::InvalidResponse, static fn () => $configReader->read($envelope));
        }

        $listReader = new PbsDatastoreListReader();
        $v3 = new PbsVersion(3, 4, 4, '3.4.4', '1', 'abcdef12');
        $v4 = new PbsVersion(4, 2, 2, '4.2.2', '1', 'abcdef12');
        foreach ([
            [new PbsApiEnvelope((object) [], null), $v3],
            [new PbsApiEnvelope([[]], null), $v3],
            [new PbsApiEnvelope([(object) ['mount-status' => 'mounted']], null), $v3],
            [new PbsApiEnvelope([(object) ['store' => 'store_a']], null), $v3],
            [new PbsApiEnvelope([(object) ['store' => 'bad/', 'mount-status' => 'mounted']], null), $v3],
            [new PbsApiEnvelope([(object) ['store' => 'store_a', 'mount-status' => 'bad']], null), $v3],
            [new PbsApiEnvelope([(object) ['store' => 'store_a', 'mount-status' => 'mounted']], null), $v4],
            [new PbsApiEnvelope([(object) ['store' => 'store_a', 'mount-status' => 'mounted', 'backend-type' => 'bad']], null), $v4],
            [new PbsApiEnvelope([(object) ['store' => 'store_a', 'mount-status' => 'mounted', 'maintenance' => 'bad']], null), $v3],
            [new PbsApiEnvelope([(object) ['store' => 'store_a', 'mount-status' => 'mounted', 'maintenance' => 's3-refresh']], null), $v3],
            [new PbsApiEnvelope([(object) ['store' => 'same_id', 'mount-status' => 'mounted'], (object) ['store' => 'same_id', 'mount-status' => 'mounted']], null), $v3],
        ] as [$envelope, $version]) {
            $this->assertFailure(PbsReadFailureCode::InvalidResponse, static fn () => $listReader->read($envelope, $version));
        }
        $maintenance = $listReader->read(new PbsApiEnvelope([(object) [
            'store' => 'store_a', 'mount-status' => 'mounted', 'backend-type' => 'filesystem',
            'maintenance' => 'type=read-only,message=planned',
        ]], null), $v4);
        self::assertSame(PbsMaintenanceMode::ReadOnly, $maintenance[0]->maintenanceMode);

        $statusReader = new PbsDatastoreStatusReader();
        $id = new PbsDatastoreId('store_a');
        foreach ([
            [new PbsApiEnvelope([], null), PbsDatastoreBackendType::Filesystem, $v3],
            [new PbsApiEnvelope((object) ['total' => -1, 'used' => 0, 'avail' => 0], null), PbsDatastoreBackendType::Filesystem, $v3],
            [new PbsApiEnvelope((object) ['total' => 1, 'used' => 2, 'avail' => 0], null), PbsDatastoreBackendType::Filesystem, $v3],
            [new PbsApiEnvelope((object) ['total' => 1, 'used' => 0, 'avail' => 2], null), PbsDatastoreBackendType::Filesystem, $v3],
            [new PbsApiEnvelope((object) ['total' => 1, 'used' => 0, 'avail' => 1], null), PbsDatastoreBackendType::Filesystem, $v4],
            [new PbsApiEnvelope((object) ['total' => 1, 'used' => 0, 'avail' => 1, 'backend-type' => 'bad'], null), PbsDatastoreBackendType::Filesystem, $v4],
            [new PbsApiEnvelope((object) ['total' => 1, 'used' => 0, 'avail' => 1, 'backend-type' => 's3'], null), PbsDatastoreBackendType::Filesystem, $v4],
        ] as [$envelope, $backend, $version]) {
            $this->assertFailure(PbsReadFailureCode::InvalidResponse, static fn () => $statusReader->read($envelope, $id, $backend, $version));
        }
    }

    private function fixture(int $major, string $name): PbsApiEnvelope
    {
        $json = file_get_contents(sprintf('%s/Fixtures/Proxmox/Pbs/%d/%s.json', dirname(__DIR__, 4), $major, $name));
        self::assertIsString($json);
        return (new PbsJsonEnvelopeDecoder())->decode($json, 8_388_608);
    }

    private function assertFailure(PbsReadFailureCode $code, callable $operation): void
    {
        try { $operation(); self::fail('Expected PBS read failure.'); }
        catch (PbsReadFailure $failure) { self::assertSame($code, $failure->failureCode); }
    }
}
