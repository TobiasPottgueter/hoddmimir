<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox\ExecutorEvidence;

use App\Application\Backup\Execution\ExecutorEvidenceRefreshClaim;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshEndpoint;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshFailure;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshFailureCode;
use App\Application\Security\EncryptedSecret;
use App\Application\Security\PlaintextSecret;
use App\Application\Security\SecretCipher;
use App\Application\Security\SecretContext;
use App\Application\Security\SecretPurpose;
use App\Infrastructure\Proxmox\ExecutorEvidence\NativePveExecutorEvidenceRefreshSource;
use App\Infrastructure\Proxmox\ExecutorEvidence\PveNativeExecutorEvidenceHttpClientFactory;
use App\Infrastructure\Proxmox\ExecutorEvidence\PveExecutorEvidenceConfigurationSource;
use App\Infrastructure\Proxmox\ExecutorEvidence\PveExecutorEvidenceEndpointConfiguration;
use App\Infrastructure\Proxmox\ExecutorEvidence\PveExecutorEvidenceGetAuthenticator;
use App\Infrastructure\Proxmox\ExecutorEvidence\PveExecutorEvidenceGetTransport;
use App\Infrastructure\Proxmox\ExecutorEvidence\PveExecutorEvidenceHttpClientFactory;
use App\Infrastructure\Proxmox\ExecutorEvidence\PveExecutorEvidenceHttpGetTransport;
use App\Infrastructure\Proxmox\ExecutorEvidence\PveExecutorEvidenceRequest;
use App\Infrastructure\Proxmox\ExecutorEvidence\PveExecutorEvidenceTokenAuthenticator;
use App\Infrastructure\Proxmox\ExecutorEvidence\PveExecutorPermissionSnapshotParser;
use App\Infrastructure\Proxmox\PveApiTokenIdentity;
use App\Infrastructure\Proxmox\PveApiUrlBuilder;
use App\Infrastructure\Filesystem\NativeAtomicFileMaterializer;
use App\Infrastructure\Proxmox\PveCustomCaMaterializer;
use App\Infrastructure\Proxmox\PveNativeHttpClientFactory;
use App\Infrastructure\Proxmox\PveRequestAuthenticator;
use App\Infrastructure\Proxmox\PveTlsConfiguration;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use SensitiveParameter;

final class NativePveExecutorEvidenceRefreshSourceTest extends TestCase
{
    private const string CONNECTION = 'connection-id-01';
    private const string ENDPOINT = 'endpoint-id-0001';
    private const string WORKER = 'worker-id-000001';

    #[DataProvider('majorProvider')]
    public function testEachSupportedMajorUsesExactlyTwoGetOnlyRequestsWithSeparateCredentials(int $major): void
    {
        $fixture = $this->fixture($major);
        $factory = new EvidenceHttpFactory(new MockHttpClient(function (string $method, string $url, array $options) use ($fixture): MockResponse {
            self::assertSame('GET', $method);
            self::assertFalse($options['buffer']);
            self::assertSame(0, $options['max_redirects']);
            $headers = \strtolower(\serialize($options['normalized_headers'] ?? []));
            $path = (string) \parse_url($url, PHP_URL_PATH);
            if ('/api2/json/access/permissions' === $path) {
                self::assertStringContainsString('backup@pve!hoddmimir=backup-secret', $headers);
                return $this->response($fixture['backupPermissions']);
            }
            self::assertSame('/api2/json/access/acl', $path);
            self::assertStringContainsString('scan@pve!inventory=scan-secret', $headers);
            return $this->response($fixture['scanAcl']);
        }));
        $configuration = $this->configuration($major);
        $snapshot = $this->source($configuration, $factory)->read($this->claim(), $this->endpoint());

        self::assertSame($major, $snapshot->majorVersion);
        self::assertSame(self::ENDPOINT, $snapshot->endpointId);
        self::assertCount(2, $factory->http->requests);
        self::assertSame($configuration->tls, $factory->seenTls);
    }

    /** @return iterable<string, array{int}> */
    public static function majorProvider(): iterable
    {
        yield 'PVE 7' => [7];
        yield 'PVE 8' => [8];
        yield 'PVE 9' => [9];
    }

    #[DataProvider('bundleFailureProvider')]
    public function testBundleAlwaysAttemptsBothGetsAndReturnsTheLastFailedCall(
        MockResponse $first,
        MockResponse $second,
        ExecutorEvidenceRefreshFailureCode $expected,
    ): void {
        $factory = new EvidenceHttpFactory(new MockHttpClient([$first, $second]));
        try {
            $this->source($this->configuration(), $factory)->read($this->claim(), $this->endpoint());
            self::fail('Expected bundle failure.');
        } catch (ExecutorEvidenceRefreshFailure $failure) {
            self::assertSame($expected, $failure->failureCode);
            self::assertSame('Executor permission evidence refresh failed.', $failure->getMessage());
            self::assertNull($failure->getPrevious());
        }
        self::assertCount(2, $factory->http->requests);
    }

    /** @return iterable<string, array{MockResponse, MockResponse, ExecutorEvidenceRefreshFailureCode}> */
    public static function bundleFailureProvider(): iterable
    {
        $validMatrix = new MockResponse('{"data":{"/vms":{"VM.Backup":1},"/storage":{"Datastore.AllocateSpace":1}}}');
        $validAcl = new MockResponse('{"data":[]}');
        yield 'first only' => [new MockResponse('', ['http_code' => 503]), $validAcl, ExecutorEvidenceRefreshFailureCode::RemoteUnavailable];
        yield 'second only' => [$validMatrix, new MockResponse('', ['http_code' => 403]), ExecutorEvidenceRefreshFailureCode::PermissionDenied];
        yield 'terminal first beats local second' => [new MockResponse('', ['http_code' => 401]), new MockResponse('', ['http_code' => 503]), ExecutorEvidenceRefreshFailureCode::Authentication];
        yield 'terminal second beats local first' => [new MockResponse('', ['http_code' => 503]), new MockResponse('', ['http_code' => 403]), ExecutorEvidenceRefreshFailureCode::PermissionDenied];
        yield 'second terminal is last safe code' => [new MockResponse('', ['http_code' => 401]), new MockResponse('', ['http_code' => 403]), ExecutorEvidenceRefreshFailureCode::PermissionDenied];
        yield 'invalid first envelope still reads second' => [new MockResponse('{'), $validAcl, ExecutorEvidenceRefreshFailureCode::InvalidResponse];
    }

    public function testTransportErrorsAreNotRetriedAndDoNotExposeDetails(): void
    {
        $factory = new EvidenceHttpFactory(new MockHttpClient([
            new MockResponse('', ['error' => 'SECRET transport detail']),
            new MockResponse('{"data":[]}'),
        ]));
        try {
            $this->source($this->configuration(), $factory)->read($this->claim(), $this->endpoint());
            self::fail('Expected transport failure.');
        } catch (ExecutorEvidenceRefreshFailure $failure) {
            self::assertSame(ExecutorEvidenceRefreshFailureCode::Transport, $failure->failureCode);
            self::assertStringNotContainsString('SECRET', $failure->getMessage());
        }
        self::assertCount(2, $factory->http->requests);
    }

    public function testOversizedBodyIsCancelledAndStillRunsTheSecondGet(): void
    {
        $factory = new EvidenceHttpFactory(new MockHttpClient([
            new MockResponse('{"data":"'.\str_repeat('x', 8_388_609).'"}'),
            new MockResponse('{"data":[]}'),
        ]));
        try {
            $this->source($this->configuration(), $factory)->read($this->claim(), $this->endpoint());
            self::fail('Expected oversized response failure.');
        } catch (ExecutorEvidenceRefreshFailure $failure) {
            self::assertSame(ExecutorEvidenceRefreshFailureCode::InvalidResponse, $failure->failureCode);
        }
        self::assertCount(2, $factory->http->responses);
        self::assertTrue($factory->http->responses[0]->getInfo('canceled'));
    }

    #[DataProvider('httpStatusProvider')]
    public function testStatusMappingIsSanitizedAndNeverRetried(int $status, ExecutorEvidenceRefreshFailureCode $expected): void
    {
        $http = new MockHttpClient(new MockResponse('remote detail', ['http_code' => $status]));
        $transport = new PveExecutorEvidenceHttpGetTransport($http, new PveApiUrlBuilder('pve.test', 8006));
        try {
            $transport->get(PveExecutorEvidenceRequest::BackupPermissions, 'PVEAPIToken=test@pve!token=SECRET');
            self::fail('Expected status failure.');
        } catch (ExecutorEvidenceRefreshFailure $failure) {
            self::assertSame($expected, $failure->failureCode);
            self::assertStringNotContainsString('SECRET', $failure->getMessage());
        }
        self::assertSame(1, $http->getRequestsCount());
    }

    /** @return iterable<string, array{int, ExecutorEvidenceRefreshFailureCode}> */
    public static function httpStatusProvider(): iterable
    {
        yield '401' => [401, ExecutorEvidenceRefreshFailureCode::Authentication];
        yield '403' => [403, ExecutorEvidenceRefreshFailureCode::PermissionDenied];
        foreach ([408, 429, 502, 503, 504] as $status) yield (string) $status => [$status, ExecutorEvidenceRefreshFailureCode::RemoteUnavailable];
        yield 'other' => [500, ExecutorEvidenceRefreshFailureCode::InvalidResponse];
    }

    #[DataProvider('invalidEnvelopeProvider')]
    public function testInvalidEnvelopesAreSanitized(string $body): void
    {
        $transport = new PveExecutorEvidenceHttpGetTransport(
            new MockHttpClient(new MockResponse($body)), new PveApiUrlBuilder('pve.test', 8006),
        );
        $this->expectException(ExecutorEvidenceRefreshFailure::class);
        $transport->get(PveExecutorEvidenceRequest::BackupPermissions, 'SECRET');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidEnvelopeProvider(): iterable
    {
        yield 'json' => ['{'];
        yield 'list' => ['[]'];
        yield 'missing data' => ['{"other":true}'];
    }

    #[DataProvider('bindingMismatchProvider')]
    public function testConfigurationMustMatchClaimBeforeAnyHttpRequest(string $field): void
    {
        $configuration = $this->configuration();
        $arguments = $this->configurationArguments();
        $connectionId = 'connection' === $field ? 'different-id-000' : $arguments[0];
        $endpointId = 'endpoint' === $field ? 'different-id-000' : $arguments[1];
        $connectionRevision = 'connectionRevision' === $field ? 99 : $arguments[2];
        $backupRevision = 'backupRevision' === $field ? 99 : $arguments[3];
        $scanRevision = 'scanRevision' === $field ? 99 : $arguments[4];
        $factory = new EvidenceHttpFactory(new MockHttpClient());
        try {
            $this->source(new PveExecutorEvidenceEndpointConfiguration(
                $connectionId, $endpointId, $connectionRevision, $backupRevision, $scanRevision,
                ...\array_slice($arguments, 5),
            ), $factory)->read($this->claim(), $this->endpoint());
            self::fail('Expected binding failure.');
        } catch (ExecutorEvidenceRefreshFailure $failure) {
            self::assertSame(ExecutorEvidenceRefreshFailureCode::ConfigurationChanged, $failure->failureCode);
        }
        self::assertSame([], $factory->http->requests);
        self::assertSame('backup@pve', $configuration->backupOwnerIdentity);
    }

    /** @return iterable<string, array{string}> */
    public static function bindingMismatchProvider(): iterable
    {
        foreach (['connection', 'endpoint', 'connectionRevision', 'backupRevision', 'scanRevision'] as $field) yield $field => [$field];
    }

    public function testCredentialFailurePreventsAllRemoteIoWithoutLeakingSecrets(): void
    {
        $factory = new EvidenceHttpFactory(new MockHttpClient(new MockResponse('{"data":[]}')));
        $source = new NativePveExecutorEvidenceRefreshSource(
            new EvidenceConfigurationSource($this->configuration()),
            $factory,
            new EvidenceCipher(throwFor: SecretPurpose::PveBackupToken),
            new PveExecutorPermissionSnapshotParser(),
        );
        try {
            $source->read($this->claim(), $this->endpoint());
            self::fail('Expected credential failure.');
        } catch (ExecutorEvidenceRefreshFailure $failure) {
            self::assertSame(ExecutorEvidenceRefreshFailureCode::CredentialUnavailable, $failure->failureCode);
            self::assertStringNotContainsString('SECRET', $failure->getMessage());
        }
        self::assertCount(0, $factory->http->requests);
    }

    public function testConfigurationAndFactoryThrowablesAreMappedWithoutPreviousOrDetails(): void
    {
        $throwingConfiguration = new class implements PveExecutorEvidenceConfigurationSource {
            public function load(ExecutorEvidenceRefreshClaim $claim, ExecutorEvidenceRefreshEndpoint $endpoint): PveExecutorEvidenceEndpointConfiguration
            {
                throw new RuntimeException('SECRET configuration detail');
            }
        };
        $unusedFactory = new EvidenceHttpFactory(new MockHttpClient());
        $this->assertSourceFailure(
            new NativePveExecutorEvidenceRefreshSource($throwingConfiguration, $unusedFactory, new EvidenceCipher(), new PveExecutorPermissionSnapshotParser()),
            ExecutorEvidenceRefreshFailureCode::ConfigurationChanged,
        );

        $throwingFactory = new class implements PveExecutorEvidenceHttpClientFactory {
            public function create(PveTlsConfiguration $tls): HttpClientInterface { throw new RuntimeException('SECRET TLS detail'); }
        };
        $this->assertSourceFailure(
            new NativePveExecutorEvidenceRefreshSource(new EvidenceConfigurationSource($this->configuration()), $throwingFactory, new EvidenceCipher(), new PveExecutorPermissionSnapshotParser()),
            ExecutorEvidenceRefreshFailureCode::Tls,
        );

        $typedConfiguration = new class implements PveExecutorEvidenceConfigurationSource {
            public function load(ExecutorEvidenceRefreshClaim $claim, ExecutorEvidenceRefreshEndpoint $endpoint): PveExecutorEvidenceEndpointConfiguration
            {
                throw ExecutorEvidenceRefreshFailure::for(ExecutorEvidenceRefreshFailureCode::CredentialUnavailable);
            }
        };
        $this->assertSourceFailure(
            new NativePveExecutorEvidenceRefreshSource($typedConfiguration, $unusedFactory, new EvidenceCipher(), new PveExecutorPermissionSnapshotParser()),
            ExecutorEvidenceRefreshFailureCode::CredentialUnavailable,
        );

        $typedFactory = new class implements PveExecutorEvidenceHttpClientFactory {
            public function create(PveTlsConfiguration $tls): HttpClientInterface
            {
                throw ExecutorEvidenceRefreshFailure::for(ExecutorEvidenceRefreshFailureCode::CredentialUnavailable);
            }
        };
        $this->assertSourceFailure(
            new NativePveExecutorEvidenceRefreshSource(new EvidenceConfigurationSource($this->configuration()), $typedFactory, new EvidenceCipher(), new PveExecutorPermissionSnapshotParser()),
            ExecutorEvidenceRefreshFailureCode::CredentialUnavailable,
        );
    }

    public function testUnexpectedHttpClientThrowableIsSanitizedAndCancelHandlesNull(): void
    {
        $http = new MockHttpClient(static function (): never { throw new RuntimeException('SECRET local failure'); });
        $transport = new PveExecutorEvidenceHttpGetTransport($http, new PveApiUrlBuilder('pve.test', 8006));
        try {
            $transport->get(PveExecutorEvidenceRequest::BackupPermissions, 'SECRET authorization');
            self::fail('Expected transport failure.');
        } catch (ExecutorEvidenceRefreshFailure $failure) {
            self::assertSame(ExecutorEvidenceRefreshFailureCode::Transport, $failure->failureCode);
            self::assertStringNotContainsString('SECRET', $failure->getMessage());
        }
    }

    public function testBestEffortCancelFailureNeverReplacesASafeSuccessfulRead(): void
    {
        $http = new EvidenceCancelThrowingHttpClient(new MockHttpClient(new MockResponse('{"data":{"ok":true}}')));
        $transport = new PveExecutorEvidenceHttpGetTransport($http, new PveApiUrlBuilder('pve.test', 8006));
        $data = $transport->get(PveExecutorEvidenceRequest::BackupPermissions, 'SECRET authorization');
        self::assertInstanceOf(\stdClass::class, $data);
        self::assertTrue($data->ok);
    }

    public function testGetOnlyTypesCannotReachBackupWriteTransport(): void
    {
        self::assertSame(['get'], \array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(PveExecutorEvidenceGetTransport::class))->getMethods(),
        ));
        self::assertFalse((new ReflectionClass(PveExecutorEvidenceGetAuthenticator::class))->implementsInterface(PveRequestAuthenticator::class));
        $constructor = (new ReflectionClass(NativePveExecutorEvidenceRefreshSource::class))->getConstructor();
        self::assertNotNull($constructor);
        $types = \array_map(static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(), $constructor->getParameters());
        self::assertNotContains('App\\Infrastructure\\Proxmox\\PveBackup\\PveBackupApiTransport', $types);
        $authorization = (new ReflectionClass(PveExecutorEvidenceHttpGetTransport::class))->getMethod('request')->getParameters()[1];
        self::assertCount(1, $authorization->getAttributes(SensitiveParameter::class));
    }

    public function testConfigurationAndAuthenticatorAreSecretSafeAndPurposeScoped(): void
    {
        $configuration = $this->configuration();
        self::assertSame(['value' => '[REDACTED]'], $configuration->__debugInfo());
        try {
            \serialize($configuration);
            self::fail('Expected serialization refusal.');
        } catch (LogicException $failure) {
            self::assertStringNotContainsString('secret', \strtolower($failure->getMessage()));
        }
        try {
            $configuration->__unserialize(['secret' => 'attacker-controlled']);
        } catch (LogicException $failure) {
            self::assertStringNotContainsString('attacker-controlled', $failure->getMessage());
        }

        $authenticator = new PveExecutorEvidenceTokenAuthenticator(
            PveApiTokenIdentity::fromUserAndTokenId('backup@pve', 'hoddmimir'),
            EncryptedSecret::fromEncoded('opaque'),
            SecretContext::forBinaryCredentialId('backup-cred-0001', SecretPurpose::PveBackupToken),
            new EvidenceCipher(),
        );
        self::assertSame('ok', $authenticator->authorize(static function (string $header): string {
            self::assertStringContainsString('backup-secret', $header);
            return 'ok';
        }));
    }

    public function testDedicatedNativeFactoryUsesTheStrictPveTlsFactoryWithoutNetworkIo(): void
    {
        $factory = new PveNativeExecutorEvidenceHttpClientFactory(new PveNativeHttpClientFactory(
            new PveCustomCaMaterializer(new NativeAtomicFileMaterializer(), '/app/var'),
        ));
        self::assertInstanceOf(HttpClientInterface::class, $factory->create(PveTlsConfiguration::systemCa()));
    }

    private function source(
        PveExecutorEvidenceEndpointConfiguration $configuration,
        EvidenceHttpFactory $factory,
    ): NativePveExecutorEvidenceRefreshSource {
        return new NativePveExecutorEvidenceRefreshSource(
            new EvidenceConfigurationSource($configuration), $factory, new EvidenceCipher(), new PveExecutorPermissionSnapshotParser(),
        );
    }

    private function configuration(int $major = 9): PveExecutorEvidenceEndpointConfiguration
    {
        $arguments = $this->configurationArguments();
        $arguments[5] = $major;
        return new PveExecutorEvidenceEndpointConfiguration(...$arguments);
    }

    /**
     * @return array{
     *   string, string, int, int, int, int, string, int, PveTlsConfiguration,
     *   string, EncryptedSecret, SecretContext, string, EncryptedSecret, SecretContext
     * }
     */
    private function configurationArguments(): array
    {
        return [
            self::CONNECTION, self::ENDPOINT, 4, 5, 6, 9, 'pve-fixture.test', 8006,
            PveTlsConfiguration::systemCa(), 'backup@pve!hoddmimir', EncryptedSecret::fromEncoded('backup-envelope'),
            SecretContext::forBinaryCredentialId('backup-cred-0001', SecretPurpose::PveBackupToken),
            'scan@pve!inventory', EncryptedSecret::fromEncoded('scan-envelope'),
            SecretContext::forBinaryCredentialId('scan-cred-000001', SecretPurpose::PveCollectorToken),
        ];
    }

    private function claim(): ExecutorEvidenceRefreshClaim
    {
        return new ExecutorEvidenceRefreshClaim(
            self::CONNECTION, 4, 5, 6, self::WORKER, 'lease-token-0001', 7,
            new \DateTimeImmutable('2026-07-16T08:05:00Z'), [$this->endpoint()],
        );
    }

    private function endpoint(): ExecutorEvidenceRefreshEndpoint
    {
        return new ExecutorEvidenceRefreshEndpoint(self::ENDPOINT, 10);
    }

    /** @return array{backupPermissions: array<array-key, mixed>, scanAcl: array<array-key, mixed>} */
    private function fixture(int $major): array
    {
        $path = \dirname(__DIR__, 4).'/Fixtures/Proxmox/Pve/'.$major.'/executor-permissions.json';
        $fixture = \json_decode((string) \file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        $backupPermissions = $fixture['backupPermissions'] ?? null;
        $scanAcl = $fixture['scanAcl'] ?? null;
        self::assertIsArray($backupPermissions);
        self::assertIsArray($scanAcl);
        return ['backupPermissions' => $backupPermissions, 'scanAcl' => $scanAcl];
    }

    /** @param array<array-key, mixed> $data */
    private function response(array $data): MockResponse
    {
        return new MockResponse((string) \json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function assertSourceFailure(
        NativePveExecutorEvidenceRefreshSource $source,
        ExecutorEvidenceRefreshFailureCode $expected,
    ): void {
        try {
            $source->read($this->claim(), $this->endpoint());
            self::fail('Expected source failure.');
        } catch (ExecutorEvidenceRefreshFailure $failure) {
            self::assertSame($expected, $failure->failureCode);
            self::assertNull($failure->getPrevious());
            self::assertStringNotContainsString('SECRET', $failure->getMessage());
        }
    }
}

final readonly class EvidenceConfigurationSource implements PveExecutorEvidenceConfigurationSource
{
    public function __construct(private PveExecutorEvidenceEndpointConfiguration $configuration) {}
    public function load(ExecutorEvidenceRefreshClaim $claim, ExecutorEvidenceRefreshEndpoint $endpoint): PveExecutorEvidenceEndpointConfiguration
    {
        return $this->configuration;
    }
}

final class EvidenceHttpFactory implements PveExecutorEvidenceHttpClientFactory
{
    public ?PveTlsConfiguration $seenTls = null;
    public readonly EvidenceRecordingHttpClient $http;
    public function __construct(MockHttpClient $http) { $this->http = new EvidenceRecordingHttpClient($http); }
    public function create(PveTlsConfiguration $tls): HttpClientInterface
    {
        $this->seenTls = $tls;
        return $this->http;
    }
}

final class EvidenceRecordingHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, options: array<array-key, mixed>}> */
    public array $requests = [];
    /** @var list<ResponseInterface> */ public array $responses = [];
    public function __construct(private HttpClientInterface $inner) {}
    /** @param array<array-key, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
        $response = $this->inner->request($method, $url, $options);
        $this->responses[] = $response;
        return $response;
    }
    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }
    /** @param array<array-key, mixed> $options */
    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->inner = $this->inner->withOptions($options);
        return $clone;
    }
}

final class EvidenceCancelThrowingHttpClient implements HttpClientInterface
{
    public function __construct(private HttpClientInterface $inner) {}
    /** @param array<array-key, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return new EvidenceCancelThrowingResponse($this->inner->request($method, $url, $options));
    }
    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        if ($responses instanceof EvidenceCancelThrowingResponse) {
            $responses = $responses->inner();
        }
        return $this->inner->stream($responses, $timeout);
    }
    /** @param array<array-key, mixed> $options */
    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->inner = $this->inner->withOptions($options);
        return $clone;
    }
}

final readonly class EvidenceCancelThrowingResponse implements ResponseInterface
{
    public function __construct(private ResponseInterface $response) {}
    public function inner(): ResponseInterface { return $this->response; }
    public function getStatusCode(): int { return $this->response->getStatusCode(); }
    public function getHeaders(bool $throw = true): array { return $this->response->getHeaders($throw); }
    public function getContent(bool $throw = true): string { return $this->response->getContent($throw); }
    /** @return array<array-key, mixed> */
    public function toArray(bool $throw = true): array { return $this->response->toArray($throw); }
    public function cancel(): void { throw new RuntimeException('SECRET cancel detail'); }
    public function getInfo(?string $type = null): mixed { return $this->response->getInfo($type); }
}

final class EvidenceCipher implements SecretCipher
{
    public function __construct(private ?SecretPurpose $throwFor = null) {}
    public function encrypt(PlaintextSecret $plaintext, SecretContext $context): EncryptedSecret { throw new RuntimeException('not used'); }
    public function decrypt(EncryptedSecret $encrypted, SecretContext $context): PlaintextSecret
    {
        if ($context->purpose() === $this->throwFor) throw new RuntimeException('SECRET decryption detail');
        return PlaintextSecret::fromString(SecretPurpose::PveBackupToken === $context->purpose() ? 'backup-secret' : 'scan-secret');
    }
    public function primaryKeyId(): string { return 'test'; }
}
