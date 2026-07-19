<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsBackupType;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsNamespace;
use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Infrastructure\Proxmox\Pbs\PbsApiEnvelope;
use App\Infrastructure\Proxmox\Pbs\PbsApiTransport;
use App\Infrastructure\Proxmox\Pbs\PbsHttpContentClient;
use App\Infrastructure\Proxmox\Pbs\PbsNamespaceListReader;
use App\Infrastructure\Proxmox\Pbs\PbsPermissionReader;
use App\Infrastructure\Proxmox\Pbs\PbsRequest;
use App\Infrastructure\Proxmox\Pbs\PbsSnapshotListReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PbsContentReadersTest extends TestCase
{
    #[DataProvider('majorProvider')]
    public function testSanitizedNamespaceAndSnapshotFixturesAreMapped(int $major, int $rootTime): void
    {
        $decoder = new \App\Infrastructure\Proxmox\Pbs\PbsJsonEnvelopeDecoder();
        $namespaceEnvelope = $decoder->decode($this->fixture($major, 'admin-datastore-store_a-namespace'), 8_388_608);
        $namespaces = (new PbsNamespaceListReader())->read($namespaceEnvelope);
        self::assertSame(['', 'tenant', 'tenant/pve'], array_column($namespaces, 'value'));
        self::assertTrue($namespaces[0]->isRoot());
        self::assertNull($namespaces[0]->parent());
        self::assertSame('', $namespaces[1]->parent()?->value);
        self::assertSame('tenant', $namespaces[2]->parent()?->value);

        $snapshotEnvelope = $decoder->decode($this->fixture($major, 'admin-datastore-store_a-snapshots-root'), 67_108_864);
        $snapshots = (new PbsSnapshotListReader())->read(
            $snapshotEnvelope,
            new PbsDatastoreId('store_a'),
            PbsNamespace::root(),
        );
        self::assertCount(2, $snapshots);
        self::assertSame(PbsBackupType::Ct, $snapshots[0]->backupType);
        self::assertSame(PbsBackupType::Vm, $snapshots[1]->backupType);
        self::assertSame($rootTime, (int) $snapshots[1]->backupTime->format('U'));
        self::assertSame(['drive-scsi0.img.fidx', 'index.json.blob'], $snapshots[1]->files);
        self::assertSame('ok', $snapshots[1]->verification?->state);
        self::assertNull($snapshots[0]->size);
    }

    public function testRequestsUseExactGetPathsAndCanonicalRootQueries(): void
    {
        $datastore = new PbsDatastoreId('store_a');
        $namespaces = PbsRequest::namespaces($datastore, 65_536);
        self::assertSame(['admin', 'datastore', 'store_a', 'namespace'], $namespaces->pathSegments);
        self::assertSame(['max-depth' => 7], $namespaces->query);
        self::assertSame(65_536, $namespaces->maximumBodyBytes);

        $root = PbsRequest::snapshots($datastore, PbsNamespace::root(), 1_048_576);
        self::assertSame(['admin', 'datastore', 'store_a', 'snapshots'], $root->pathSegments);
        self::assertSame([], $root->query);
        $nested = PbsRequest::snapshots($datastore, new PbsNamespace('tenant/pve'), 1_048_576);
        self::assertSame(['ns' => 'tenant/pve'], $nested->query);
        self::assertSame(
            ['path' => '/datastore/store_a/tenant/pve'],
            PbsRequest::permission('/datastore/store_a/tenant/pve')->query,
        );
    }

    public function testHttpClientRoutesAllReadsThroughTheTypedGetContracts(): void
    {
        $transport = new ContentRecordingTransport();
        $client = new PbsHttpContentClient(
            $transport,
            new PbsPermissionReader(),
            new PbsNamespaceListReader(),
            new PbsSnapshotListReader(),
        );
        $store = new PbsDatastoreId('store_a');

        self::assertTrue($client->permission('/datastore/store_a')->propagates('Datastore.Audit'));
        self::assertSame([''], array_column($client->namespaces($store, 65_536), 'value'));
        self::assertSame([], $client->snapshots($store, PbsNamespace::root(), 1_048_576));
        self::assertSame(
            [
                'access/permissions?path=%2Fdatastore%2Fstore_a',
                'admin/datastore/store_a/namespace?max-depth=7',
                'admin/datastore/store_a/snapshots',
            ],
            $transport->routes,
        );
        self::assertSame([262_144, 65_536, 1_048_576], $transport->bodyLimits);
    }

    public function testSnapshotFileObjectsMapFullMinimalAndAdditiveFormsToFilenames(): void
    {
        $snapshots = (new PbsSnapshotListReader())->read(
            new PbsApiEnvelope([self::snapshotRow(files: [
                (object) [
                    'filename' => 'drive-scsi0.img.fidx',
                    'size' => 1024,
                    'crypt-mode' => 'encrypt',
                    'future-file-property' => (object) ['ignored' => true],
                ],
                (object) ['filename' => 'index.json.blob'],
            ])], null),
            new PbsDatastoreId('store_a'),
            PbsNamespace::root(),
        );

        self::assertSame(['drive-scsi0.img.fidx', 'index.json.blob'], $snapshots[0]->files);
    }

    #[DataProvider('invalidEnvelopeProvider')]
    public function testReadersRejectInvalidOrDuplicateRows(mixed $data, bool $namespace): void
    {
        $this->expectException(PbsReadFailure::class);
        if ($namespace) {
            (new PbsNamespaceListReader())->read(new PbsApiEnvelope($data, null));
            return;
        }
        (new PbsSnapshotListReader())->read(
            new PbsApiEnvelope($data, null),
            new PbsDatastoreId('store_a'),
            PbsNamespace::root(),
        );
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function invalidEnvelopeProvider(): iterable
    {
        yield 'namespace not list' => [new \stdClass(), true];
        yield 'namespace associative array' => [[1 => (object) ['ns' => '']], true];
        yield 'namespace row type' => [['bad'], true];
        yield 'namespace missing ns' => [[(object) []], true];
        yield 'namespace non-string ns' => [[(object) ['ns' => 1]], true];
        yield 'namespace duplicate' => [[(object) ['ns' => ''], (object) ['ns' => '']], true];
        yield 'namespace invalid value' => [[(object) ['ns' => 'bad//ns']], true];
        yield 'snapshots not list' => [new \stdClass(), false];
        yield 'snapshots associative array' => [[1 => self::snapshotRow()], false];
        yield 'snapshot row type' => [['bad'], false];
        yield 'snapshot missing required' => [[(object) []], false];
        yield 'snapshot non-integer time' => [[self::snapshotRow(time: 'bad')], false];
        yield 'snapshot unknown type' => [[self::snapshotRow(type: 'future')], false];
        yield 'snapshot invalid time' => [[self::snapshotRow(time: 0)], false];
        yield 'snapshot legacy string files' => [[self::snapshotRow(files: ['archive.blob'])], false];
        yield 'snapshot file row not object' => [[self::snapshotRow(files: [(object) ['filename' => 'ok'], 1])], false];
        yield 'snapshot file missing filename' => [[self::snapshotRow(files: [(object) ['size' => 1]])], false];
        yield 'snapshot file filename wrong type' => [[self::snapshotRow(files: [(object) ['filename' => 1]])], false];
        yield 'snapshot file filename empty' => [[self::snapshotRow(files: [(object) ['filename' => '']])], false];
        yield 'snapshot duplicate filename' => [[self::snapshotRow(files: [
            (object) ['filename' => 'archive.blob'],
            (object) ['filename' => 'archive.blob'],
        ])], false];
        yield 'snapshot files not array' => [[self::snapshotRow(files: 'bad')], false];
        yield 'snapshot files not list' => [[self::snapshotRow(files: ['archive' => 'bad'])], false];
        yield 'snapshot invalid protected' => [[self::snapshotRow(protected: 1)], false];
        yield 'snapshot invalid verification' => [[self::snapshotRow(verification: (object) ['state' => 'future', 'upid' => 'bad'])], false];
        yield 'snapshot verification not object' => [[self::snapshotRow(verification: 'bad')], false];
        yield 'snapshot duplicate' => [[self::snapshotRow(), self::snapshotRow()], false];
        yield 'snapshot invalid optional string' => [[self::snapshotRow(comment: 1)], false];
        yield 'snapshot invalid size' => [[self::snapshotRow(size: -1)], false];
        yield 'snapshot non-integer size' => [[self::snapshotRow(size: 'bad')], false];
    }

    /** @return \stdClass */
    private static function snapshotRow(
        string $type = 'vm',
        mixed $time = 1,
        mixed $files = null,
        mixed $protected = false,
        mixed $verification = null,
        mixed $comment = null,
        mixed $size = null,
    ): \stdClass {
        $files ??= [(object) ['filename' => 'archive.blob']];
        $row = (object) [
            'backup-type' => $type,
            'backup-id' => '100',
            'backup-time' => $time,
            'files' => $files,
            'protected' => $protected,
        ];
        if (null !== $verification) { $row->verification = $verification; }
        if (null !== $comment) { $row->comment = $comment; }
        if (null !== $size) { $row->size = $size; }
        return $row;
    }

    /** @return iterable<string, array{int, int}> */
    public static function majorProvider(): iterable
    {
        yield 'PBS 3' => [3, 1719664640];
        yield 'PBS 4' => [4, 1750000000];
    }

    private function fixture(int $major, string $name): string
    {
        $path = sprintf('%s/Fixtures/Proxmox/Pbs/%d/%s.json', dirname(__DIR__, 4), $major, $name);
        $json = file_get_contents($path);
        self::assertIsString($json);
        return $json;
    }
}

final class ContentRecordingTransport implements PbsApiTransport
{
    /** @var list<string> */ public array $routes = [];
    /** @var list<int> */ public array $bodyLimits = [];

    public function get(PbsRequest $request): PbsApiEnvelope
    {
        $query = http_build_query($request->query, '', '&', PHP_QUERY_RFC3986);
        $this->routes[] = implode('/', $request->pathSegments).('' === $query ? '' : '?'.$query);
        $this->bodyLimits[] = $request->maximumBodyBytes;
        if (['access', 'permissions'] === $request->pathSegments) {
            $path = $request->query['path'];
            \PHPUnit\Framework\Assert::assertIsString($path);
            return new PbsApiEnvelope((object) [$path => (object) ['Datastore.Audit' => true]], null);
        }
        if ('namespace' === $request->pathSegments[count($request->pathSegments) - 1]) {
            return new PbsApiEnvelope([(object) ['ns' => '']], null);
        }
        return new PbsApiEnvelope([], null);
    }
}
