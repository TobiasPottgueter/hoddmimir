<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsDatastoreBackendType;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use App\Infrastructure\Proxmox\Pbs\PbsApiEnvelope;
use App\Infrastructure\Proxmox\Pbs\PbsApiTransport;
use App\Infrastructure\Proxmox\Pbs\PbsApiUrlBuilder;
use App\Infrastructure\Proxmox\Pbs\PbsDatastoreConfigurationReader;
use App\Infrastructure\Proxmox\Pbs\PbsDatastoreListReader;
use App\Infrastructure\Proxmox\Pbs\PbsDatastoreStatusReader;
use App\Infrastructure\Proxmox\Pbs\PbsEndpointReadConnector;
use App\Infrastructure\Proxmox\Pbs\PbsHttpTransport;
use App\Infrastructure\Proxmox\Pbs\PbsInstanceIdentityReader;
use App\Infrastructure\Proxmox\Pbs\PbsJsonEnvelopeDecoder;
use App\Infrastructure\Proxmox\Pbs\PbsNodesReader;
use App\Infrastructure\Proxmox\Pbs\PbsNodeStatusReader;
use App\Infrastructure\Proxmox\Pbs\PbsPermissionReader;
use App\Infrastructure\Proxmox\Pbs\PbsPingReader;
use App\Infrastructure\Proxmox\Pbs\PbsRequest;
use App\Infrastructure\Proxmox\Pbs\PbsRequestAuthenticator;
use App\Infrastructure\Proxmox\Pbs\PbsRetryDelay;
use App\Infrastructure\Proxmox\Pbs\PbsRetryPolicy;
use App\Infrastructure\Proxmox\Pbs\PbsVersionReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class PbsHttpTransportTest extends TestCase
{
    public function testSuccessfulRequestUsesStrictTlsAndSecretScopedHeader(): void
    {
        $called = false;
        /** @var array<string, mixed> $seenOptions */
        $seenOptions = [];
        $seenMethod = '';
        $seenUrl = '';
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$called, &$seenOptions, &$seenMethod, &$seenUrl): MockResponse {
            $called = true;
            $seenMethod = $method;
            $seenUrl = $url;
            $seenOptions = $options;
            return new MockResponse('{"data":{"ok":true}}', ['http_code' => 200]);
        });
        $auth = new PbsInlineAuthenticator();
        $envelope = $this->transport($http, $auth, new PbsRecordingDelay())->get(PbsRequest::ping());
        self::assertInstanceOf(\stdClass::class, $envelope->data);
        self::assertTrue($envelope->data->ok);
        self::assertTrue($called);
        self::assertSame('GET', $seenMethod);
        self::assertSame('https://pbs.test:8007/api2/json/ping', $seenUrl);
        $headers = $seenOptions['normalized_headers'] ?? [];
        self::assertIsArray($headers);
        $normalizedHeaders = [];
        array_walk_recursive($headers, static function (mixed $header) use (&$normalizedHeaders): void {
            if (!is_string($header)) { self::fail('Expected string header.'); }
            $normalizedHeaders[] = strtolower($header);
        });
        self::assertContains('authorization: pbsapitoken collector@pbs!inventory:01234567-89ab-cdef-0123-456789abcdef', $normalizedHeaders);
        self::assertFalse($seenOptions['buffer']);
        self::assertTrue($seenOptions['verify_peer']); self::assertTrue($seenOptions['verify_host']); self::assertSame(0, $seenOptions['max_redirects']);
        self::assertSame(1, $auth->calls);
        self::assertFalse($auth->active);
    }

    #[DataProvider('statusProvider')]
    public function testStatusesAreMappedAndOnlyRetryableReadsRetry(int $status, PbsReadFailureCode $code, int $attempts): void
    {
        $responses = [];
        for ($i = 0; $i < $attempts; ++$i) { $responses[] = new MockResponse('TOKEN-SENTINEL', ['http_code' => $status]); }
        $delay = new PbsRecordingDelay();
        try { $this->transport(new MockHttpClient($responses), new PbsInlineAuthenticator(), $delay)->get(PbsRequest::ping()); self::fail('status'); }
        catch (PbsReadFailure $failure) {
            self::assertSame($code, $failure->failureCode);
            self::assertStringNotContainsString('TOKEN-SENTINEL', $failure->getMessage());
            self::assertCount($attempts - 1, $delay->attempts);
        }
    }

    /** @return iterable<string,array{int,PbsReadFailureCode,int}> */
    public static function statusProvider(): iterable
    {
        yield '401' => [401, PbsReadFailureCode::Authentication, 1];
        yield '403' => [403, PbsReadFailureCode::PermissionDenied, 1];
        yield '404' => [404, PbsReadFailureCode::NotFound, 1];
        yield '429' => [429, PbsReadFailureCode::RateLimited, 3];
        yield '408' => [408, PbsReadFailureCode::RemoteUnavailable, 3];
        yield '502' => [502, PbsReadFailureCode::RemoteUnavailable, 3];
        yield '503' => [503, PbsReadFailureCode::RemoteUnavailable, 3];
        yield '504' => [504, PbsReadFailureCode::RemoteUnavailable, 3];
        yield '500' => [500, PbsReadFailureCode::HttpStatus, 1];
    }

    public function testTransportAndOversizeFailuresAreBoundedAndSecretFree(): void
    {
        $delay = new PbsRecordingDelay();
        $transport = $this->transport(new MockHttpClient([
            new MockResponse('', ['error' => 'TOKEN-SENTINEL']),
            new MockResponse('', ['error' => 'TOKEN-SENTINEL']),
            new MockResponse('', ['error' => 'TOKEN-SENTINEL']),
        ]), new PbsInlineAuthenticator(), $delay);
        $this->assertFailure(PbsReadFailureCode::Transport, static fn () => $transport->get(PbsRequest::ping()));
        self::assertSame([1, 2], $delay->attempts);

        $oversizedByHeader = new class implements ResponseInterface {
            public function getStatusCode(): int { return 200; }
            public function getHeaders(bool $throw = true): array { return ['content-length' => ['65537']]; }
            public function getContent(bool $throw = true): string { return '{"data":true}'; }
            /** @return array<array-key, mixed> */
            public function toArray(bool $throw = true): array { return []; }
            public function cancel(): void {}
            public function getInfo(?string $type = null): mixed { return null; }
        };
        $headerClient = new class($oversizedByHeader) implements HttpClientInterface {
            public function __construct(private readonly ResponseInterface $response) {}
            /** @param array<string, mixed> $options */
            public function request(string $method, string $url, array $options = []): ResponseInterface { return $this->response; }
            public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface { throw new \RuntimeException('Oversized response must not stream.'); }
            /** @param array<string, mixed> $options */
            public function withOptions(array $options): static { return $this; }
        };
        $headerTransport = new PbsHttpTransport($headerClient, new PbsApiUrlBuilder('pbs.test'), new PbsInlineAuthenticator(), new PbsRetryPolicy(), new PbsRecordingDelay(), new PbsJsonEnvelopeDecoder());
        $this->assertFailure(PbsReadFailureCode::InvalidEnvelope, static fn () => $headerTransport->get(PbsRequest::ping()));
        $oversizedByBody = new MockResponse(str_repeat('x', 65_537), ['http_code' => 200]);
        $this->assertFailure(PbsReadFailureCode::InvalidEnvelope, fn () => $this->transport(new MockHttpClient($oversizedByBody), new PbsInlineAuthenticator(), new PbsRecordingDelay())->get(PbsRequest::ping()));
        $this->assertFailure(PbsReadFailureCode::InvalidEnvelope, fn () => $this->transport(new MockHttpClient(new MockResponse('TOKEN-SENTINEL {')), new PbsInlineAuthenticator(), new PbsRecordingDelay())->get(PbsRequest::ping()));
    }

    #[DataProvider('safeContentLengthProvider')]
    public function testSafeOrUnusableContentLengthsDoNotRejectBoundedBodies(?string $contentLength): void
    {
        $options = ['http_code' => 200];
        if (null !== $contentLength) {
            $options['response_headers'] = ['content-length: '.$contentLength];
        }

        $body = '{"data":true}';
        if (null !== $contentLength && ctype_digit($contentLength) && (int) $contentLength >= strlen($body)) {
            $body = str_pad($body, (int) $contentLength);
        }
        $response = new MockResponse($body, $options);
        $envelope = $this->transport(
            new MockHttpClient($response),
            new PbsInlineAuthenticator(),
            new PbsRecordingDelay(),
        )->get(PbsRequest::ping());

        self::assertTrue($envelope->data);
    }

    /** @return iterable<string, array{?string}> */
    public static function safeContentLengthProvider(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'non numeric' => ['unknown'];
        yield 'zero' => ['000'];
        yield 'shorter digit width' => ['42'];
        yield 'equal maximum' => ['65536'];
        yield 'leading zero maximum' => ['00065536'];
    }

    public function testUnexpectedHttpClientFailureIsMappedToTransportFailure(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new \RuntimeException('implementation detail');
        });

        $this->assertFailure(
            PbsReadFailureCode::Transport,
            fn () => $this->transport($http, new PbsInlineAuthenticator(), new PbsRecordingDelay())->get(PbsRequest::ping()),
        );
    }

    public function testTransportFailureBeforeResponseIsRetriedAndMapped(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new TransportException('network detail');
        });
        $delay = new PbsRecordingDelay();

        $this->assertFailure(
            PbsReadFailureCode::Transport,
            fn () => $this->transport($http, new PbsInlineAuthenticator(), $delay)->get(PbsRequest::ping()),
        );
        self::assertSame([1, 2], $delay->attempts);
    }

    public function testCancellationFailureNeverReplacesStatusMapping(): void
    {
        $response = new class implements ResponseInterface {
            public function getStatusCode(): int { return 401; }
            public function getHeaders(bool $throw = true): array { return []; }
            public function getContent(bool $throw = true): string { return ''; }
            /** @return array<array-key, mixed> */
            public function toArray(bool $throw = true): array { return []; }
            public function cancel(): void { throw new \RuntimeException('cancel detail'); }
            public function getInfo(?string $type = null): mixed { return null; }
        };
        $http = new class($response) implements HttpClientInterface {
            public function __construct(private readonly ResponseInterface $response) {}
            /** @param array<string, mixed> $options */
            public function request(string $method, string $url, array $options = []): ResponseInterface { return $this->response; }
            public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface { throw new \LogicException('Not reached.'); }
            /** @param array<string, mixed> $options */
            public function withOptions(array $options): static { return $this; }
        };

        $this->assertFailure(
            PbsReadFailureCode::Authentication,
            static fn () => (new PbsHttpTransport(
                $http,
                new PbsApiUrlBuilder('pbs.test'),
                new PbsInlineAuthenticator(),
                new PbsRetryPolicy(),
                new PbsRecordingDelay(),
                new PbsJsonEnvelopeDecoder(),
            ))->get(PbsRequest::ping()),
        );
    }

    public function testConnectorEnforcesVersionThenPingAndClientUsesOnlyWhitelistedGets(): void
    {
        $transport = new PbsRecordingTransport();
        $connector = new PbsEndpointReadConnector(
            $transport,
            new PbsVersionReader(), new PbsPingReader(), new PbsNodesReader(), new PbsPermissionReader(),
            new PbsNodeStatusReader(), new PbsInstanceIdentityReader(), new PbsDatastoreConfigurationReader(),
            new PbsDatastoreListReader(), new PbsDatastoreStatusReader(),
        );
        $client = $connector->connect();
        self::assertSame(4, $client->version()->major);
        self::assertSame(['pbs'], $client->nodeNames());
        self::assertTrue($client->permission('/system/status')->grants('Sys.Audit'));
        self::assertSame(10, $client->nodeStatus('pbs')->rootTotalBytes);
        self::assertSame(str_repeat('a', 32), $client->instanceIdentity('pbs')->value);
        self::assertSame(str_repeat('b', 64), $client->datastoreConfigurations()->digest);
        $definitions = $client->datastores();
        self::assertCount(1, $definitions);
        self::assertSame(100, $client->datastoreStatus($definitions[0]->id, $definitions[0]->backendType)->totalBytes);
        self::assertSame([
            ['version'], ['ping'], ['nodes'], ['access', 'permissions'], ['nodes', 'pbs', 'status'],
            ['nodes', 'pbs', 'identity'], ['config', 'datastore'], ['admin', 'datastore'],
            ['admin', 'datastore', 'store_a', 'status'],
        ], array_map(static fn (PbsRequest $request): array => $request->pathSegments, $transport->requests));
        self::assertSame(['verbose' => 0], $transport->requests[8]->query);
    }

    private function transport(MockHttpClient $http, PbsRequestAuthenticator $auth, PbsRetryDelay $delay): PbsHttpTransport
    {
        return new PbsHttpTransport($http, new PbsApiUrlBuilder('pbs.test'), $auth, new PbsRetryPolicy(), $delay, new PbsJsonEnvelopeDecoder());
    }

    private function assertFailure(PbsReadFailureCode $code, callable $operation): void
    {
        try { $operation(); self::fail('failure expected'); }
        catch (PbsReadFailure $failure) { self::assertSame($code, $failure->failureCode); }
    }
}

/** @internal */
final class PbsInlineAuthenticator implements PbsRequestAuthenticator
{
    public int $calls = 0; public bool $active = false;
    public function authorize(callable $request): mixed
    {
        ++$this->calls; $this->active = true;
        try { return $request('PBSAPIToken collector@pbs!inventory:01234567-89ab-cdef-0123-456789abcdef'); }
        finally { $this->active = false; }
    }
}
/** @internal */
final class PbsRecordingDelay implements PbsRetryDelay
{
    /** @var list<int> */ public array $attempts = [];
    public function pause(int $attempt): void { $this->attempts[] = $attempt; }
}
/** @internal */
final class PbsRecordingTransport implements PbsApiTransport
{
    /** @var list<PbsRequest> */ public array $requests = [];
    public function get(PbsRequest $request): PbsApiEnvelope
    {
        $this->requests[] = $request;
        return match ($request->pathSegments) {
            ['version'] => new PbsApiEnvelope((object) ['version' => '4.2.2', 'release' => '1', 'repoid' => 'abcdef12'], null),
            ['ping'] => new PbsApiEnvelope((object) ['pong' => true], null),
            ['nodes'] => new PbsApiEnvelope([(object) ['node' => 'pbs']], null),
            ['access', 'permissions'] => new PbsApiEnvelope((object) [(string) ($request->query['path'] ?? '') => (object) ['Sys.Audit' => false]], null),
            ['nodes', 'pbs', 'status'] => new PbsApiEnvelope((object) ['uptime' => 1, 'memory' => (object) ['total' => 2, 'used' => 1], 'root' => (object) ['total' => 10, 'used' => 2, 'avail' => 8]], null),
            ['nodes', 'pbs', 'identity'] => new PbsApiEnvelope((object) ['pbs-instance-id' => str_repeat('a', 32)], null),
            ['config', 'datastore'] => new PbsApiEnvelope([(object) ['name' => 'store_a']], str_repeat('b', 64)),
            ['admin', 'datastore'] => new PbsApiEnvelope([(object) ['store' => 'store_a', 'mount-status' => 'mounted', 'backend-type' => 'filesystem']], null),
            ['admin', 'datastore', 'store_a', 'status'] => new PbsApiEnvelope((object) ['total' => 100, 'used' => 20, 'avail' => 80, 'backend-type' => 'filesystem'], null),
            default => throw new TransportException('unexpected route'),
        };
    }
}
