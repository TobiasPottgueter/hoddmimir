<?php

declare(strict_types=1);

namespace App\Tests\Contract\Proxmox\Pbs;

use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Inventory\PbsContent\PbsContentRunStatus;
use App\Application\Inventory\PbsContent\ReadPbsContent;
use App\Application\Proxmox\Pbs\PbsContentLimits;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Infrastructure\Proxmox\Pbs\PbsApiEnvelope;
use App\Infrastructure\Proxmox\Pbs\PbsApiTransport;
use App\Infrastructure\Proxmox\Pbs\PbsHttpContentClient;
use App\Infrastructure\Proxmox\Pbs\PbsJsonEnvelopeDecoder;
use App\Infrastructure\Proxmox\Pbs\PbsNamespaceListReader;
use App\Infrastructure\Proxmox\Pbs\PbsPermissionReader;
use App\Infrastructure\Proxmox\Pbs\PbsRequest;
use App\Infrastructure\Proxmox\Pbs\PbsSnapshotListReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PbsContentFixtureContractTest extends TestCase
{
    #[DataProvider('majorProvider')]
    public function testMajorFixturesProduceCompleteRootAndNestedContent(int $major): void
    {
        $transport = new ContentFixtureTransport($major);
        $client = new PbsHttpContentClient(
            $transport, new PbsPermissionReader(), new PbsNamespaceListReader(), new PbsSnapshotListReader(),
        );
        $snapshot = (new ReadPbsContent(new PbsContentLimits()))->read(
            $client, [new PbsDatastoreId('store_a')], new NoopContentCheckpoint(),
        );

        self::assertSame(PbsContentRunStatus::Succeeded, $snapshot->status());
        self::assertCount(3, $snapshot->namespaces);
        self::assertCount(3, $snapshot->snapshots);
        self::assertCount(12, $transport->requests);
        self::assertSame(['max-depth' => 7], $transport->requests[1]->query);
        self::assertSame([], $transport->requests[4]->query);
        self::assertSame(['ns' => 'tenant'], $transport->requests[7]->query);
        self::assertSame(['ns' => 'tenant/pve'], $transport->requests[10]->query);
    }

    /** @return iterable<string, array{int}> */
    public static function majorProvider(): iterable
    {
        yield 'PBS 3' => [3];
        yield 'PBS 4' => [4];
    }
}

final class ContentFixtureTransport implements PbsApiTransport
{
    /** @var list<PbsRequest> */ public array $requests = [];
    private PbsJsonEnvelopeDecoder $decoder;

    public function __construct(private readonly int $major) { $this->decoder = new PbsJsonEnvelopeDecoder(); }

    public function get(PbsRequest $request): PbsApiEnvelope
    {
        $this->requests[] = $request;
        if (['access', 'permissions'] === $request->pathSegments) {
            $path = $request->query['path'];
            if (!is_string($path)) { throw new RuntimeException('Invalid fixture permission path.'); }
            return new PbsApiEnvelope((object) [
                $path => (object) ['Datastore.Audit' => true],
            ], null);
        }
        $name = match ($request->pathSegments) {
            ['admin', 'datastore', 'store_a', 'namespace'] => 'admin-datastore-store_a-namespace',
            ['admin', 'datastore', 'store_a', 'snapshots'] => 'admin-datastore-store_a-snapshots-'.match ($request->query['ns'] ?? '') {
                '' => 'root',
                'tenant' => 'tenant',
                'tenant/pve' => 'tenant-pve',
                default => throw new RuntimeException('Unexpected namespace fixture request.'),
            },
            default => throw new RuntimeException('Unexpected PBS content fixture request.'),
        };
        $path = sprintf('%s/Fixtures/Proxmox/Pbs/%d/%s.json', dirname(__DIR__, 3), $this->major, $name);
        $json = file_get_contents($path);
        if (!is_string($json)) { throw new RuntimeException('Missing PBS content fixture.'); }
        return $this->decoder->decode($json, $request->maximumBodyBytes);
    }
}

final class NoopContentCheckpoint implements ConnectionReadCheckpoint
{
    public function checkpoint(): void {}
}
