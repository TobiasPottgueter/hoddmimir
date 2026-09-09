<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\EndpointReadFailure;
use App\Application\Inventory\Connection\EndpointReadFailureCode;
use App\Application\Inventory\PbsContent\PbsContentRunStatus;
use App\Application\Proxmox\Pbs\PbsContentClient;
use App\Application\Proxmox\Pbs\PbsContentLimits;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsEffectivePermission;
use App\Application\Proxmox\Pbs\PbsNamespace;
use App\Application\Security\EncryptedSecret;
use App\Application\Security\SecretContext;
use App\Application\Security\SecretPurpose;
use App\Infrastructure\Proxmox\NativeSelectedEndpointPbsContentReader;
use App\Infrastructure\Proxmox\Pbs\PbsApiTokenIdentity;
use App\Infrastructure\Proxmox\Pbs\PbsContentClientFactory;
use App\Infrastructure\Proxmox\Pbs\PbsEndpointReadConfiguration;
use App\Infrastructure\Proxmox\Pbs\PbsEndpointReadConfigurationSource;
use App\Infrastructure\Proxmox\Pbs\PbsReadConnectorFactoryFailure;
use App\Infrastructure\Proxmox\Pbs\PbsTlsConfiguration;
use PHPUnit\Framework\TestCase;

final class NativeSelectedEndpointPbsContentReaderTest extends TestCase
{
    public function testLoadsAndUsesOnlyTheExactSelectedEndpointConfiguration(): void
    {
        $configuration = self::configuration();
        $source = new RecordingPbsContentConfigurationSource($configuration);
        $client = new EmptyAuthoritativePbsContentClient();
        $factory = new RecordingPbsContentClientFactory($client);
        $checkpoint = new NativePbsContentCheckpoint();
        $reader = new NativeSelectedEndpointPbsContentReader($source, $factory, new PbsContentLimits());
        $connection = new ConnectionId(self::bytes('connection'));
        $endpoint = new EndpointId(self::bytes('selected-endpoint'));

        $snapshot = $reader->read($connection, $endpoint, 17, [new PbsDatastoreId('store_a')], $checkpoint);

        self::assertSame($connection, $source->connectionId);
        self::assertSame($endpoint, $source->endpointId);
        self::assertSame(17, $source->revision);
        self::assertSame($configuration, $factory->configuration);
        self::assertSame(1, $factory->creates);
        self::assertSame(['/datastore/store_a', '/datastore/store_a', '/datastore/store_a', '/datastore/store_a'], $client->permissionPaths);
        self::assertCount(1, $snapshot->namespaces);
        self::assertSame('', $snapshot->namespaces[0]->namespace->value);
        self::assertSame(PbsContentRunStatus::Succeeded, $snapshot->status());
        self::assertSame(4, $checkpoint->calls);
    }

    public function testClientConstructionFailureMapsToTlsWithoutFailover(): void
    {
        $source = new RecordingPbsContentConfigurationSource(self::configuration());
        $factory = new RecordingPbsContentClientFactory(new PbsReadConnectorFactoryFailure());
        $reader = new NativeSelectedEndpointPbsContentReader($source, $factory, new PbsContentLimits());

        try {
            $reader->read(
                new ConnectionId(self::bytes('connection')),
                new EndpointId(self::bytes('selected-endpoint')),
                3,
                [new PbsDatastoreId('store_a')],
                new NativePbsContentCheckpoint(),
            );
            self::fail('A connector construction failure was accepted.');
        } catch (EndpointReadFailure $failure) {
            self::assertSame(EndpointReadFailureCode::Tls, $failure->failureCode);
            self::assertSame(1, $source->loads);
            self::assertSame(1, $factory->creates);
        }
    }

    private static function configuration(): PbsEndpointReadConfiguration
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

    private static function bytes(string $seed): string
    {
        return substr(hash('sha256', $seed, true), 0, 16);
    }
}

final class RecordingPbsContentConfigurationSource implements PbsEndpointReadConfigurationSource
{
    public int $loads = 0;
    public ?ConnectionId $connectionId = null;
    public ?EndpointId $endpointId = null;
    public int $revision = 0;

    public function __construct(private readonly PbsEndpointReadConfiguration $configuration) {}

    public function load(ConnectionId $connectionId, EndpointId $endpointId, int $expectedRevision): PbsEndpointReadConfiguration
    {
        ++$this->loads;
        $this->connectionId = $connectionId;
        $this->endpointId = $endpointId;
        $this->revision = $expectedRevision;
        return $this->configuration;
    }
}

final class RecordingPbsContentClientFactory implements PbsContentClientFactory
{
    public int $creates = 0;
    public ?PbsEndpointReadConfiguration $configuration = null;

    public function __construct(private readonly PbsContentClient|PbsReadConnectorFactoryFailure $outcome) {}

    public function createContentClient(PbsEndpointReadConfiguration $configuration, ConnectionReadCheckpoint $checkpoint): PbsContentClient
    {
        ++$this->creates;
        $this->configuration = $configuration;
        if ($this->outcome instanceof PbsReadConnectorFactoryFailure) { throw $this->outcome; }
        return $this->outcome;
    }
}

final class EmptyAuthoritativePbsContentClient implements PbsContentClient
{
    /** @var list<string> */ public array $permissionPaths = [];

    public function permission(string $path): PbsEffectivePermission
    {
        $this->permissionPaths[] = $path;
        return new PbsEffectivePermission($path, ['Datastore.Audit' => true]);
    }

    public function namespaces(PbsDatastoreId $datastore, int $maximumBodyBytes): array
    {
        return [PbsNamespace::root()];
    }

    public function snapshots(PbsDatastoreId $datastore, PbsNamespace $namespace, int $maximumBodyBytes): array
    {
        return [];
    }
}

final class NativePbsContentCheckpoint implements ConnectionReadCheckpoint
{
    public int $calls = 0;
    public function checkpoint(): void { ++$this->calls; }
}
