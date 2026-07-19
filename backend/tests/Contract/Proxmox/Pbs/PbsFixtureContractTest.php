<?php

declare(strict_types=1);

namespace App\Tests\Contract\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsDatastoreScanScope;
use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use App\Application\Proxmox\Pbs\PbsNodeRoute;
use App\Application\Proxmox\Pbs\ReadPbsInstallation;
use App\Infrastructure\Proxmox\Pbs\PbsApiEnvelope;
use App\Infrastructure\Proxmox\Pbs\PbsApiTransport;
use App\Infrastructure\Proxmox\Pbs\PbsDatastoreConfigurationReader;
use App\Infrastructure\Proxmox\Pbs\PbsDatastoreListReader;
use App\Infrastructure\Proxmox\Pbs\PbsDatastoreStatusReader;
use App\Infrastructure\Proxmox\Pbs\PbsEndpointReadConnector;
use App\Infrastructure\Proxmox\Pbs\PbsInstanceIdentityReader;
use App\Infrastructure\Proxmox\Pbs\PbsJsonEnvelopeDecoder;
use App\Infrastructure\Proxmox\Pbs\PbsNodeStatusReader;
use App\Infrastructure\Proxmox\Pbs\PbsPermissionReader;
use App\Infrastructure\Proxmox\Pbs\PbsPingReader;
use App\Infrastructure\Proxmox\Pbs\PbsRequest;
use App\Infrastructure\Proxmox\Pbs\PbsVersionReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PbsFixtureContractTest extends TestCase
{
    #[DataProvider('majorProvider')]
    public function testConstructedMajorFixtureFormsACompleteBoundedSnapshot(int $major, int $expectedCalls): void
    {
        $transport = new FixturePbsTransport($major);
        $connector = new PbsEndpointReadConnector(
            $transport,
            new PbsVersionReader(), new PbsPingReader(), new PbsPermissionReader(),
            new PbsNodeStatusReader(), new PbsInstanceIdentityReader(), new PbsDatastoreConfigurationReader(),
            new PbsDatastoreListReader(), new PbsDatastoreStatusReader(),
        );
        $snapshot = (new ReadPbsInstallation($connector, PbsDatastoreScanScope::installationWide()))->read();
        self::assertTrue($snapshot->isComplete());
        self::assertCount(2, $snapshot->datastores);
        self::assertCount(2, $snapshot->capacities);
        self::assertCount($expectedCalls, $transport->requests);
        self::assertSame(['version'], $transport->requests[0]->pathSegments);
        self::assertSame(['ping'], $transport->requests[1]->pathSegments);
        self::assertSame(['config', 'datastore'], $transport->requests[$expectedCalls - 1]->pathSegments);
        $paths = array_map(static fn (PbsRequest $request): array => $request->pathSegments, $transport->requests);
        self::assertNotContains(['nodes'], $paths, 'The PBS /nodes list requires broader ACLs and is forbidden.');
        self::assertContains(['nodes', PbsNodeRoute::Local->value, 'status'], $paths);
        self::assertSame(4 === $major, in_array(['nodes', PbsNodeRoute::Local->value, 'identity'], $paths, true));
    }

    /** @return iterable<string,array{int,int}> */
    public static function majorProvider(): iterable
    {
        yield 'PBS 3' => [3, 10];
        yield 'PBS 4.2' => [4, 11];
    }
}

/** @internal */
final class FixturePbsTransport implements PbsApiTransport
{
    /** @var list<PbsRequest> */ public array $requests = [];
    private PbsJsonEnvelopeDecoder $decoder;

    public function __construct(private readonly int $major) { $this->decoder = new PbsJsonEnvelopeDecoder(); }

    public function get(PbsRequest $request): PbsApiEnvelope
    {
        if (['nodes'] === $request->pathSegments) {
            throw PbsReadFailure::for(PbsReadFailureCode::PermissionDenied);
        }
        $this->requests[] = $request;
        $name = match ($request->pathSegments) {
            ['version'] => 'version', ['ping'] => 'ping',
            ['access', 'permissions'] => '/system/status' === $request->query['path'] ? 'permission-system-status' : 'permission-datastore',
            ['nodes', $request->pathSegments[1], 'status'] => 'node-status',
            ['nodes', $request->pathSegments[1], 'identity'] => 'identity',
            ['config', 'datastore'] => 'config-datastore',
            ['admin', 'datastore'] => 'admin-datastore',
            ['admin', 'datastore', $request->pathSegments[2], 'status'] => 'admin-datastore-'.$request->pathSegments[2].'-status',
            default => throw new RuntimeException('Unexpected PBS fixture request.'),
        };
        $path = sprintf('%s/Fixtures/Proxmox/Pbs/%d/%s.json', dirname(__DIR__, 3), $this->major, $name);
        $json = file_get_contents($path);
        if (!is_string($json)) { throw new RuntimeException('Missing PBS fixture.'); }
        return $this->decoder->decode($json, $request->maximumBodyBytes);
    }
}
