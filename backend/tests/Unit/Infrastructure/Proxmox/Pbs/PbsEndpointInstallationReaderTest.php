<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox\Pbs;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\EndpointReadFailure;
use App\Application\Inventory\Connection\EndpointReadFailureCode;
use App\Application\Inventory\Connection\PbsEndpointReadFailureMapper;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Proxmox\Pbs\PbsReadConnector;
use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use App\Application\Security\EncryptedSecret;
use App\Application\Security\SecretContext;
use App\Application\Security\SecretPurpose;
use App\Infrastructure\Proxmox\Pbs\PbsApiEnvelope;
use App\Infrastructure\Proxmox\Pbs\PbsApiTokenIdentity;
use App\Infrastructure\Proxmox\Pbs\PbsApiTransport;
use App\Infrastructure\Proxmox\Pbs\PbsDatastoreConfigurationReader;
use App\Infrastructure\Proxmox\Pbs\PbsDatastoreListReader;
use App\Infrastructure\Proxmox\Pbs\PbsDatastoreStatusReader;
use App\Infrastructure\Proxmox\Pbs\PbsEndpointInstallationReader;
use App\Infrastructure\Proxmox\Pbs\PbsEndpointReadConfiguration;
use App\Infrastructure\Proxmox\Pbs\PbsEndpointReadConfigurationSource;
use App\Infrastructure\Proxmox\Pbs\PbsEndpointReadConnector;
use App\Infrastructure\Proxmox\Pbs\PbsInstanceIdentityReader;
use App\Infrastructure\Proxmox\Pbs\PbsNodeStatusReader;
use App\Infrastructure\Proxmox\Pbs\PbsPermissionReader;
use App\Infrastructure\Proxmox\Pbs\PbsPingReader;
use App\Infrastructure\Proxmox\Pbs\PbsReadConnectorFactory;
use App\Infrastructure\Proxmox\Pbs\PbsReadConnectorFactoryFailure;
use App\Infrastructure\Proxmox\Pbs\PbsRequest;
use App\Infrastructure\Proxmox\Pbs\PbsTlsConfiguration;
use App\Infrastructure\Proxmox\Pbs\PbsVersionReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PbsEndpointInstallationReaderTest extends TestCase
{
    public function testEndpointReaderRejectsNonPbsProductBeforeLoadingSecrets(): void
    {
        $source = new FixedPbsEndpointReadSource($this->configuration());
        $reader = new PbsEndpointInstallationReader(
            $source,
            new FixedPbsConnectorFactory($this->connector(new RouteRecordingPbsTransport('3.4.4'))),
            new PbsEndpointReadFailureMapper(),
            128,
        );

        try {
            $reader->read(
                new ConnectionId(str_repeat('c', 16)),
                new EndpointId(str_repeat('e', 16)),
                7,
                ProxmoxProduct::Pve,
                new EndpointReaderNoopCheckpoint(),
            );
            self::fail('A PVE target was sent through the PBS endpoint reader.');
        } catch (EndpointReadFailure $failure) {
            self::assertSame(EndpointReadFailureCode::UnsupportedProductOrVersion, $failure->failureCode);
        }
        self::assertSame(0, $source->loads);
    }

    public function testConnectorConstructionFailureIsMappedToTlsFailure(): void
    {
        $source = new FixedPbsEndpointReadSource($this->configuration());
        $reader = new PbsEndpointInstallationReader(
            $source,
            new FailingPbsConnectorFactory(new PbsReadConnectorFactoryFailure()),
            new PbsEndpointReadFailureMapper(),
            128,
        );

        try {
            $reader->read(
                new ConnectionId(str_repeat('c', 16)),
                new EndpointId(str_repeat('e', 16)),
                7,
                ProxmoxProduct::Pbs,
                new EndpointReaderNoopCheckpoint(),
            );
            self::fail('A connector construction failure was not mapped.');
        } catch (EndpointReadFailure $failure) {
            self::assertSame(EndpointReadFailureCode::Tls, $failure->failureCode);
        }
        self::assertSame(1, $source->loads);
    }

    public function testPbsReadFailureIsMappedAtTheEndpointBoundary(): void
    {
        $reader = new PbsEndpointInstallationReader(
            new FixedPbsEndpointReadSource($this->configuration()),
            new FailingPbsConnectorFactory(PbsReadFailure::for(PbsReadFailureCode::Authentication)),
            new PbsEndpointReadFailureMapper(),
            128,
        );

        try {
            $reader->read(
                new ConnectionId(str_repeat('c', 16)),
                new EndpointId(str_repeat('e', 16)),
                7,
                ProxmoxProduct::Pbs,
                new EndpointReaderNoopCheckpoint(),
            );
            self::fail('A PBS read failure was not mapped.');
        } catch (EndpointReadFailure $failure) {
            self::assertSame(EndpointReadFailureCode::Authentication, $failure->failureCode);
        }
    }

    #[DataProvider('supportedVersionsProvider')]
    public function testEndpointReaderUsesExactPhysicalGetOrderForSupportedVersions(
        string $version,
        bool $expectsIdentity,
    ): void {
        $transport = new RouteRecordingPbsTransport($version);
        $source = new FixedPbsEndpointReadSource($this->configuration());
        $reader = new PbsEndpointInstallationReader(
            $source,
            new FixedPbsConnectorFactory($this->connector($transport)),
            new PbsEndpointReadFailureMapper(),
            128,
        );

        $snapshot = $reader->read(
            new ConnectionId(str_repeat('c', 16)),
            new EndpointId(str_repeat('e', 16)),
            7,
            ProxmoxProduct::Pbs,
            new EndpointReaderNoopCheckpoint(),
        );

        self::assertTrue($snapshot->isComplete());
        self::assertSame(1, $source->loads);
        $expected = [
            '/version',
            '/ping',
            '/access/permissions?path=/system/status',
            '/nodes/localhost/status',
        ];
        if ($expectsIdentity) {
            $expected[] = '/nodes/localhost/identity';
        }
        array_push(
            $expected,
            '/access/permissions?path=/datastore',
            '/config/datastore',
            '/admin/datastore',
            '/admin/datastore/store_a/status?verbose=0',
            '/config/datastore',
        );
        self::assertSame($expected, $transport->routes);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function supportedVersionsProvider(): iterable
    {
        yield 'PBS 3' => ['3.4.4', false];
        yield 'PBS 4.0' => ['4.0.0', false];
        yield 'PBS 4.1' => ['4.1.1', false];
        yield 'PBS 4.2' => ['4.2.2', true];
    }

    private function connector(PbsApiTransport $transport): PbsReadConnector
    {
        return new PbsEndpointReadConnector(
            $transport,
            new PbsVersionReader(),
            new PbsPingReader(),
            new PbsPermissionReader(),
            new PbsNodeStatusReader(),
            new PbsInstanceIdentityReader(),
            new PbsDatastoreConfigurationReader(),
            new PbsDatastoreListReader(),
            new PbsDatastoreStatusReader(),
        );
    }

    private function configuration(): PbsEndpointReadConfiguration
    {
        return new PbsEndpointReadConfiguration(
            'pbs.example.test',
            8007,
            PbsTlsConfiguration::systemCa(),
            PbsApiTokenIdentity::fromParts('collector', 'pbs', 'inventory'),
            EncryptedSecret::fromEncoded('opaque'),
            SecretContext::forCredential('pbs-credential', SecretPurpose::PbsCollectorToken),
        );
    }
}

/** @internal */
final class FixedPbsEndpointReadSource implements PbsEndpointReadConfigurationSource
{
    public int $loads = 0;

    public function __construct(private readonly PbsEndpointReadConfiguration $configuration) {}

    public function load(
        ConnectionId $connectionId,
        EndpointId $endpointId,
        int $expectedRevision,
    ): PbsEndpointReadConfiguration {
        ++$this->loads;
        TestCase::assertSame(7, $expectedRevision);

        return $this->configuration;
    }
}

/** @internal */
final readonly class FixedPbsConnectorFactory implements PbsReadConnectorFactory
{
    public function __construct(private PbsReadConnector $connector) {}

    public function create(
        PbsEndpointReadConfiguration $configuration,
        ConnectionReadCheckpoint $checkpoint,
    ): PbsReadConnector {
        return $this->connector;
    }
}

/** @internal */
final readonly class FailingPbsConnectorFactory implements PbsReadConnectorFactory
{
    public function __construct(private \Throwable $failure) {}

    public function create(
        PbsEndpointReadConfiguration $configuration,
        ConnectionReadCheckpoint $checkpoint,
    ): PbsReadConnector {
        if ($this->failure instanceof PbsReadConnectorFactoryFailure) {
            throw $this->failure;
        }

        return new FailingPbsReadConnector($this->failure);
    }
}

/** @internal */
final readonly class FailingPbsReadConnector implements PbsReadConnector
{
    public function __construct(private \Throwable $failure) {}

    public function connect(): \App\Application\Proxmox\Pbs\PbsReadClient
    {
        throw $this->failure;
    }
}

/** @internal */
final class RouteRecordingPbsTransport implements PbsApiTransport
{
    /** @var list<string> */
    public array $routes = [];

    public function __construct(private readonly string $version) {}

    public function get(PbsRequest $request): PbsApiEnvelope
    {
        if (['nodes'] === $request->pathSegments) {
            throw PbsReadFailure::for(PbsReadFailureCode::PermissionDenied);
        }
        $path = '/'.implode('/', $request->pathSegments);
        if ([] !== $request->query) {
            $path .= '?'.http_build_query($request->query, '', '&', PHP_QUERY_RFC3986);
        }
        $this->routes[] = rawurldecode($path);

        return match ($request->pathSegments) {
            ['version'] => new PbsApiEnvelope(
                (object) ['version' => $this->version, 'release' => '1', 'repoid' => 'abcdef12'],
                null,
            ),
            ['ping'] => new PbsApiEnvelope((object) ['pong' => true], null),
            ['access', 'permissions'] => $this->permission($request),
            ['nodes', 'localhost', 'status'] => new PbsApiEnvelope((object) [
                'uptime' => 1,
                'memory' => (object) ['total' => 2, 'used' => 1],
                'root' => (object) ['total' => 10, 'used' => 2, 'avail' => 8],
            ], null),
            ['nodes', 'localhost', 'identity'] => new PbsApiEnvelope(
                (object) ['pbs-instance-id' => str_repeat('a', 32)],
                null,
            ),
            ['config', 'datastore'] => new PbsApiEnvelope(
                [(object) ['name' => 'store_a']],
                str_repeat('b', 64),
            ),
            ['admin', 'datastore'] => new PbsApiEnvelope([(object) array_filter([
                'store' => 'store_a',
                'mount-status' => 'mounted',
                'backend-type' => str_starts_with($this->version, '4.') ? 'filesystem' : null,
            ], static fn (mixed $value): bool => null !== $value)], null),
            ['admin', 'datastore', 'store_a', 'status'] => new PbsApiEnvelope((object) array_filter([
                'total' => 100,
                'used' => 20,
                'avail' => 80,
                'backend-type' => str_starts_with($this->version, '4.') ? 'filesystem' : null,
            ], static fn (mixed $value): bool => null !== $value), null),
            default => throw new \RuntimeException('Unexpected PBS route '.$path),
        };
    }

    private function permission(PbsRequest $request): PbsApiEnvelope
    {
        $path = $request->query['path'] ?? null;
        TestCase::assertIsString($path);
        $privileges = '/system/status' === $path
            ? ['Sys.Audit' => false]
            : ['Datastore.Audit' => true];

        return new PbsApiEnvelope((object) [$path => (object) $privileges], null);
    }
}

/** @internal */
final class EndpointReaderNoopCheckpoint implements ConnectionReadCheckpoint
{
    public function checkpoint(): void {}
}
