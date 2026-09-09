<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox\Pbs;

use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsNamespace;
use App\Application\Proxmox\Pbs\PbsTasksAndJobsLimits;
use App\Application\Security\EncryptedSecret;
use App\Application\Security\PlaintextSecret;
use App\Application\Security\SecretCipher;
use App\Application\Security\SecretContext;
use App\Application\Security\SecretPurpose;
use App\Infrastructure\Proxmox\Pbs\PbsApiTokenIdentity;
use App\Infrastructure\Proxmox\Pbs\PbsEndpointReadConfiguration;
use App\Infrastructure\Proxmox\Pbs\PbsHttpClientFactory;
use App\Infrastructure\Proxmox\Pbs\PbsNativeReadConnectorFactory;
use App\Infrastructure\Proxmox\Pbs\PbsReadConnectorFactoryFailure;
use App\Infrastructure\Proxmox\Pbs\PbsRetryDelay;
use App\Infrastructure\Proxmox\Pbs\PbsRetryPolicy;
use App\Infrastructure\Proxmox\Pbs\PbsTlsConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PbsNativeReadConnectorFactoryTest extends TestCase
{
    public function testFactoryBuildsAUsableConnectorWithoutExposingTheTokenSecret(): void
    {
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options];
            $body = str_ends_with($url, '/version')
                ? '{"data":{"version":"4.2.1","release":"1","repoid":"abcdef12"}}'
                : '{"data":{"pong":true}}';

            return new MockResponse($body, ['http_code' => 200]);
        });
        $checkpoint = new NativeFactoryRecordingCheckpoint();
        $connector = (new PbsNativeReadConnectorFactory(
            new NativeFactoryHttpClientFactory($http),
            new NativeFactorySecretCipher(),
            new PbsRetryPolicy(1),
            new NativeFactoryRetryDelay(),
        ))->create($this->configuration(), $checkpoint);

        $client = $connector->connect();

        self::assertSame(4, $client->version()->major);
        self::assertCount(2, $requests);
        self::assertSame(4, $checkpoint->calls);
        self::assertStringContainsString('/api2/json/version', $requests[0][1]);
        self::assertStringContainsString('/api2/json/ping', $requests[1][1]);
        $headers = $requests[0][2]['normalized_headers'] ?? [];
        self::assertIsArray($headers);
        self::assertTrue(str_contains(strtolower(serialize($headers)), 'authorization: pbsapitoken '));
    }

    public function testFactoryBuildsTheMonitoringClientWithTheSameSafeTransport(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::assertSame('GET', $method);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $path = $query['path'] ?? null;
            self::assertIsString($path);
            $privileges = match ($path) {
                '/system/tasks' => ['Sys.Audit' => false],
                '/datastore' => ['Datastore.Audit' => true],
                '/remote' => ['Remote.Audit' => true],
                default => self::fail('Unexpected PBS permission path.'),
            };
            return new MockResponse(json_encode(
                ['data' => [$path => $privileges]],
                JSON_THROW_ON_ERROR,
            ), ['http_code' => 200]);
        });
        $checkpoint = new NativeFactoryRecordingCheckpoint();
        $client = (new PbsNativeReadConnectorFactory(
            new NativeFactoryHttpClientFactory($http),
            new NativeFactorySecretCipher(),
            new PbsRetryPolicy(1),
            new NativeFactoryRetryDelay(),
        ))->createMonitoringClient($this->configuration(), $checkpoint, new PbsTasksAndJobsLimits());

        self::assertTrue($client->aclEvidence()->hasBroadReadEvidence());
        self::assertGreaterThan(0, $checkpoint->calls);
    }

    public function testFactoryBuildsTheTypedContentClientWithTheSameSafeTransport(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::assertSame('GET', $method);
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (str_ends_with($path, '/access/permissions')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $aclPath = $query['path'] ?? null;
                self::assertIsString($aclPath);
                return new MockResponse(json_encode(
                    ['data' => [$aclPath => ['Datastore.Audit' => true]]],
                    JSON_THROW_ON_ERROR,
                ), ['http_code' => 200]);
            }
            if (str_ends_with($path, '/namespace')) {
                return new MockResponse('{"data":[{"ns":""}]}', ['http_code' => 200]);
            }
            if (str_ends_with($path, '/snapshots')) {
                return new MockResponse('{"data":[]}', ['http_code' => 200]);
            }
            return self::fail('Unexpected PBS content route.');
        });
        $checkpoint = new NativeFactoryRecordingCheckpoint();
        $client = (new PbsNativeReadConnectorFactory(
            new NativeFactoryHttpClientFactory($http),
            new NativeFactorySecretCipher(),
            new PbsRetryPolicy(1),
            new NativeFactoryRetryDelay(),
        ))->createContentClient($this->configuration(), $checkpoint);
        $store = new PbsDatastoreId('store_a');

        self::assertTrue($client->permission('/datastore/store_a')->grants('Datastore.Audit'));
        self::assertSame([''], array_column($client->namespaces($store, 65_536), 'value'));
        self::assertSame([], $client->snapshots($store, PbsNamespace::root(), 1_048_576));
        self::assertGreaterThan(0, $checkpoint->calls);
    }

    #[DataProvider('httpClientConstructionFailureProvider')]
    public function testFactoryMapsUnsafeHttpClientConstructionFailures(\Throwable $failure): void
    {
        $factory = new PbsNativeReadConnectorFactory(
            new NativeFactoryHttpClientFactory($failure),
            new NativeFactorySecretCipher(),
            new PbsRetryPolicy(),
            new NativeFactoryRetryDelay(),
        );

        $this->expectException(PbsReadConnectorFactoryFailure::class);
        $factory->create($this->configuration(), new NativeFactoryRecordingCheckpoint());
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function httpClientConstructionFailureProvider(): iterable
    {
        yield 'invalid TLS configuration' => [new \InvalidArgumentException('invalid TLS')];
        yield 'runtime setup failure' => [new \RuntimeException('runtime TLS failure')];
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
final readonly class NativeFactoryHttpClientFactory implements PbsHttpClientFactory
{
    public function __construct(private HttpClientInterface|\Throwable $outcome) {}

    public function create(PbsTlsConfiguration $tls): HttpClientInterface
    {
        if ($this->outcome instanceof \Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}

/** @internal */
final readonly class NativeFactorySecretCipher implements SecretCipher
{
    public function encrypt(PlaintextSecret $plaintext, SecretContext $context): EncryptedSecret
    {
        return EncryptedSecret::fromEncoded('unused');
    }

    public function decrypt(EncryptedSecret $encrypted, SecretContext $context): PlaintextSecret
    {
        return PlaintextSecret::fromString('01234567-89ab-cdef-0123-456789abcdef');
    }

    public function primaryKeyId(): string
    {
        return 'test';
    }
}

/** @internal */
final class NativeFactoryRecordingCheckpoint implements ConnectionReadCheckpoint
{
    public int $calls = 0;

    public function checkpoint(): void
    {
        ++$this->calls;
    }
}

/** @internal */
final readonly class NativeFactoryRetryDelay implements PbsRetryDelay
{
    public function pause(int $attempt): void
    {
        TestCase::fail('No retry was expected.');
    }
}
