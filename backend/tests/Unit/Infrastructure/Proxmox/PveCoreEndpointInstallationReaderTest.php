<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Inventory\Connection\ConnectionReadFailure;
use App\Application\Inventory\Connection\ConnectionReadFailureCode;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\EndpointReadFailure;
use App\Application\Inventory\Connection\EndpointReadFailureCode;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Inventory\Connection\PveEndpointReadFailureMapper;
use App\Application\Proxmox\Pve\PveReadConnector;
use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveReadFailureCode;
use App\Domain\Shared\Clock;
use DateTimeImmutable;
use App\Application\Security\EncryptedSecret;
use App\Application\Security\PlaintextSecret;
use App\Application\Security\SecretCipher;
use App\Application\Security\SecretContext;
use App\Application\Security\SecretPurpose;
use App\Infrastructure\Proxmox\PveApiTokenIdentity;
use App\Infrastructure\Proxmox\PveCoreEndpointInstallationReader;
use App\Infrastructure\Proxmox\PveCoreReadConnectorFactory;
use App\Infrastructure\Proxmox\PveCoreReadConnectorFactoryFailure;
use App\Infrastructure\Proxmox\PveEndpointReadConfiguration;
use App\Infrastructure\Proxmox\PveEndpointReadConfigurationSource;
use App\Infrastructure\Proxmox\PveHttpClientFactory;
use App\Infrastructure\Proxmox\PveNativeCoreReadConnectorFactory;
use App\Infrastructure\Proxmox\PveRetryDelay;
use App\Infrastructure\Proxmox\PveRetryPolicy;
use App\Infrastructure\Proxmox\PveTlsConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PveCoreEndpointInstallationReaderTest extends TestCase
{
    #[DataProvider('supportedMajorProvider')]
    public function testProductionReaderUsesOnlyTheCompositeGetRoutes(int $major): void
    {
        $configuration = self::configuration();
        $source = new FixedPveEndpointConfigurationSource($configuration);
        $http = new FixturePveHttpClientFactory($major);
        $cipher = new RuntimeReaderFixedSecretCipher('TOKEN-SENTINEL');
        $checkpoint = new RuntimeReaderCheckpoint();
        $reader = new PveCoreEndpointInstallationReader(
            $source,
            new PveNativeCoreReadConnectorFactory(
                $http,
                $cipher,
                new PveRetryPolicy(3),
                new NoPausePveRetryDelay(),
            ),
            new PveEndpointReadFailureMapper(),
            new RuntimeReaderClock(),
            128,
        );

        $snapshot = $reader->read(
            new ConnectionId(str_repeat('c', 16)),
            new EndpointId(str_repeat('e', 16)),
            11,
            ProxmoxProduct::Pve,
            $checkpoint,
        );

        self::assertSame($major, $snapshot->core->version->major);
        self::assertTrue(
            $snapshot->isComplete(),
            implode(',', array_map(
                static fn ($issue): string => $issue->code->value.':'.($issue->node ?? '-').':'.($issue->storageId ?? '-'),
                $snapshot->storage->issues,
            )),
        );
        self::assertSame([
            '/api2/json/version',
            '/api2/json/access/permissions',
            '/api2/json/cluster/status',
            '/api2/json/cluster/resources',
            '/api2/json/storage',
            sprintf('/api2/json/nodes/pve%d-a.test/storage', $major),
            sprintf('/api2/json/nodes/pve%d-b.test/storage', $major),
            '/api2/json/storage',
        ], $http->paths);
        self::assertSame(8, $cipher->decryptions);
        self::assertSame(16, $checkpoint->calls, 'Each physical request has one pre and one post checkpoint.');
        self::assertSame(1, $source->loads);
        self::assertSame(11, $source->expectedRevisions[0]);
        self::assertStringNotContainsString('TOKEN-SENTINEL', var_export($snapshot, true));
    }

    /** @return iterable<string, array{int}> */
    public static function supportedMajorProvider(): iterable
    {
        yield 'PVE 7' => [7];
        yield 'PVE 8' => [8];
        yield 'PVE 9' => [9];
    }

    public function testRevisionDriftStopsBeforeFactoryOrRemoteRead(): void
    {
        $source = new ChangedPveEndpointConfigurationSource();
        $factory = new RejectingPveCoreReadConnectorFactory();
        $reader = new PveCoreEndpointInstallationReader(
            $source,
            $factory,
            new PveEndpointReadFailureMapper(),
            new RuntimeReaderClock(),
            128,
        );

        try {
            $reader->read(
                new ConnectionId(str_repeat('c', 16)),
                new EndpointId(str_repeat('e', 16)),
                2,
                ProxmoxProduct::Pve,
                new RuntimeReaderCheckpoint(),
            );
            self::fail('Revision drift was accepted.');
        } catch (ConnectionReadFailure $failure) {
            self::assertSame(ConnectionReadFailureCode::ConnectionChanged, $failure->failureCode);
        }

        self::assertSame(0, $factory->calls);
    }

    public function testUnsupportedProductAndTypedTlsFactoryFailureAreStable(): void
    {
        $source = new FixedPveEndpointConfigurationSource(self::configuration());
        $factory = new RejectingPveCoreReadConnectorFactory(new PveCoreReadConnectorFactoryFailure());
        $reader = new PveCoreEndpointInstallationReader(
            $source,
            $factory,
            new PveEndpointReadFailureMapper(),
            new RuntimeReaderClock(),
            128,
        );

        try {
            $reader->read(
                new ConnectionId(str_repeat('c', 16)),
                new EndpointId(str_repeat('e', 16)),
                1,
                ProxmoxProduct::Pbs,
                new RuntimeReaderCheckpoint(),
            );
            self::fail('PBS was accepted by the PVE-only reader.');
        } catch (EndpointReadFailure $failure) {
            self::assertSame(EndpointReadFailureCode::UnsupportedProductOrVersion, $failure->failureCode);
        }
        self::assertSame(0, $source->loads);

        try {
            $reader->read(
                new ConnectionId(str_repeat('c', 16)),
                new EndpointId(str_repeat('e', 16)),
                1,
                ProxmoxProduct::Pve,
                new RuntimeReaderCheckpoint(),
            );
            self::fail('TLS factory failure was accepted.');
        } catch (EndpointReadFailure $failure) {
            self::assertSame(EndpointReadFailureCode::Tls, $failure->failureCode);
        }
    }

    public function testTypedPveReadFailuresAreMappedByCodeWithoutMessageInspection(): void
    {
        $reader = new PveCoreEndpointInstallationReader(
            new FixedPveEndpointConfigurationSource(self::configuration()),
            new FailingPveReadConnectorFactory(PveReadFailure::for(PveReadFailureCode::PermissionDenied)),
            new PveEndpointReadFailureMapper(),
            new RuntimeReaderClock(),
            128,
        );

        try {
            $reader->read(
                new ConnectionId(str_repeat('c', 16)),
                new EndpointId(str_repeat('e', 16)),
                1,
                ProxmoxProduct::Pve,
                new RuntimeReaderCheckpoint(),
            );
            self::fail('PVE permission failure was accepted.');
        } catch (EndpointReadFailure $failure) {
            self::assertSame(EndpointReadFailureCode::PermissionDenied, $failure->failureCode);
        }
    }

    public function testNativeFactoryMapsBothTypedInitializationFailureFamiliesToTls(): void
    {
        foreach ([new InvalidArgumentException('invalid TLS'), new RuntimeException('materialization failed')] as $failure) {
            $reader = new PveCoreEndpointInstallationReader(
                new FixedPveEndpointConfigurationSource(self::configuration()),
                new PveNativeCoreReadConnectorFactory(
                    new ThrowingPveHttpClientFactory($failure),
                    new RuntimeReaderFixedSecretCipher('TOKEN-SENTINEL'),
                    new PveRetryPolicy(),
                    new NoPausePveRetryDelay(),
                ),
                new PveEndpointReadFailureMapper(),
                new RuntimeReaderClock(),
                128,
            );

            try {
                $reader->read(
                    new ConnectionId(str_repeat('c', 16)),
                    new EndpointId(str_repeat('e', 16)),
                    1,
                    ProxmoxProduct::Pve,
                    new RuntimeReaderCheckpoint(),
                );
                self::fail('TLS initialization failure was accepted.');
            } catch (EndpointReadFailure $mapped) {
                self::assertSame(EndpointReadFailureCode::Tls, $mapped->failureCode);
            }
        }
    }

    private static function configuration(): PveEndpointReadConfiguration
    {
        return new PveEndpointReadConfiguration(
            'pve.example.test',
            8006,
            PveTlsConfiguration::systemCa(),
            PveApiTokenIdentity::fromUserAndTokenId('collector@pve', 'inventory'),
            EncryptedSecret::fromEncoded('opaque-encrypted-envelope'),
            SecretContext::forBinaryCredentialId(str_repeat('d', 16), SecretPurpose::PveCollectorToken),
        );
    }
}

/** @internal */
final class FixedPveEndpointConfigurationSource implements PveEndpointReadConfigurationSource
{
    public int $loads = 0;
    /** @var list<int> */
    public array $expectedRevisions = [];

    public function __construct(private readonly PveEndpointReadConfiguration $configuration) {}

    public function load(ConnectionId $connectionId, EndpointId $endpointId, int $expectedRevision): PveEndpointReadConfiguration
    {
        ++$this->loads;
        $this->expectedRevisions[] = $expectedRevision;

        return $this->configuration;
    }
}

/** @internal */
final class ChangedPveEndpointConfigurationSource implements PveEndpointReadConfigurationSource
{
    public function load(ConnectionId $connectionId, EndpointId $endpointId, int $expectedRevision): PveEndpointReadConfiguration
    {
        throw ConnectionReadFailure::connectionChanged();
    }
}

/** @internal */
final class RejectingPveCoreReadConnectorFactory implements PveCoreReadConnectorFactory
{
    public int $calls = 0;

    public function __construct(private readonly ?RuntimeException $failure = null) {}

    public function create(PveEndpointReadConfiguration $configuration, ConnectionReadCheckpoint $checkpoint): PveReadConnector
    {
        ++$this->calls;
        throw $this->failure ?? new RuntimeException('Remote connector must not be created.');
    }
}

/** @internal */
final class FailingPveReadConnectorFactory implements PveCoreReadConnectorFactory
{
    public function __construct(private readonly PveReadFailure $failure) {}

    public function create(PveEndpointReadConfiguration $configuration, ConnectionReadCheckpoint $checkpoint): PveReadConnector
    {
        return new class($this->failure) implements PveReadConnector {
            public function __construct(private readonly PveReadFailure $failure) {}
            public function connect(): \App\Application\Proxmox\Pve\PveReadClient { throw $this->failure; }
        };
    }
}

/** @internal */
final class ThrowingPveHttpClientFactory implements PveHttpClientFactory
{
    public function __construct(private readonly InvalidArgumentException|RuntimeException $failure) {}

    public function create(PveTlsConfiguration $tls): HttpClientInterface
    {
        throw $this->failure;
    }
}

/** @internal */
final class FixturePveHttpClientFactory implements PveHttpClientFactory
{
    /** @var list<string> */
    public array $paths = [];

    public function __construct(private readonly int $major) {}

    public function create(PveTlsConfiguration $tls): HttpClientInterface
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            TestCase::assertSame('GET', $method);
            TestCase::assertTrue($options['verify_peer']);
            TestCase::assertTrue($options['verify_host']);
            TestCase::assertSame(0, $options['max_redirects']);
            $headers = $options['normalized_headers'] ?? [];
            TestCase::assertIsArray($headers);
            $serializedHeaders = strtolower(var_export($headers, true));
            TestCase::assertStringContainsString(
                'authorization: pveapitoken=collector@pve!inventory=token-sentinel',
                $serializedHeaders,
            );

            $path = parse_url($url, PHP_URL_PATH);
            TestCase::assertIsString($path);
            $query = parse_url($url, PHP_URL_QUERY);
            if (str_contains($path, '/nodes/')) {
                TestCase::assertSame('content=backup', $query);
            } else {
                TestCase::assertNull($query);
            }
            $this->paths[] = $path;
            $fixture = match (true) {
                1 === preg_match('#/nodes/pve[789]-a\.test/storage\z#D', $path) => 'storage-node-a.json',
                1 === preg_match('#/nodes/pve[789]-b\.test/storage\z#D', $path) => 'storage-node-b.json',
                '/api2/json/version' === $path => 'version.json',
                '/api2/json/access/permissions' === $path => 'access-permissions.json',
                '/api2/json/cluster/status' === $path => 'cluster-status-clustered.json',
                '/api2/json/cluster/resources' === $path => 'cluster-resources.json',
                '/api2/json/storage' === $path => 'storage-config.json',
                default => throw new RuntimeException('Unexpected PVE route: '.$path),
            };
            $body = file_get_contents(sprintf(
                '%s/Fixtures/Proxmox/Pve/%d/%s',
                dirname(__DIR__, 3),
                $this->major,
                $fixture,
            ));
            if (!is_string($body)) {
                throw new RuntimeException('PVE fixture could not be read.');
            }

            return new MockResponse($body, ['http_code' => 200]);
        }, 'https://pve.example.test:8006');
    }
}

/** @internal */
final class RuntimeReaderClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-11T20:00:00Z');
    }
}

/** @internal */
final class RuntimeReaderFixedSecretCipher implements SecretCipher
{
    public int $decryptions = 0;

    public function __construct(private readonly string $secret) {}

    public function encrypt(PlaintextSecret $plaintext, SecretContext $context): EncryptedSecret
    {
        return EncryptedSecret::fromEncoded('unused');
    }

    public function decrypt(EncryptedSecret $encrypted, SecretContext $context): PlaintextSecret
    {
        ++$this->decryptions;

        return PlaintextSecret::fromString($this->secret);
    }

    public function primaryKeyId(): string
    {
        return 'test';
    }
}

/** @internal */
final class NoPausePveRetryDelay implements PveRetryDelay
{
    public function pause(int $retryNumber): void
    {
        throw new RuntimeException('Unexpected retry.');
    }
}

/** @internal */
final class RuntimeReaderCheckpoint implements ConnectionReadCheckpoint
{
    public int $calls = 0;

    public function checkpoint(): void
    {
        ++$this->calls;
    }
}
