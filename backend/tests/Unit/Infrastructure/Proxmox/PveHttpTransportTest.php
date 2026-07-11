<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox;

use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveReadFailureCode;
use App\Application\Proxmox\Pve\ReadPveInstallation;
use App\Infrastructure\Proxmox\PveApiTransport;
use App\Infrastructure\Proxmox\PveApiUrlBuilder;
use App\Infrastructure\Proxmox\PveClusterResourcesReader;
use App\Infrastructure\Proxmox\PveClusterStatusReader;
use App\Infrastructure\Proxmox\PveEndpointReadConnector;
use App\Infrastructure\Proxmox\PveHttpTransport;
use App\Infrastructure\Proxmox\PveJsonEnvelopeDecoder;
use App\Infrastructure\Proxmox\PveNodeStorageStatusReader;
use App\Infrastructure\Proxmox\PvePermissionReader;
use App\Infrastructure\Proxmox\PveRequestAuthenticator;
use App\Infrastructure\Proxmox\PveRetryDelay;
use App\Infrastructure\Proxmox\PveRetryPolicy;
use App\Infrastructure\Proxmox\PveStorageConfigurationReader;
use App\Infrastructure\Proxmox\PveTaskPageReader;
use App\Infrastructure\Proxmox\PveVersionReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Throwable;
use Traversable;

final class PveHttpTransportTest extends TestCase
{
    public function testSuccessfulAttemptIsFullyConsumedInsideAuthorizationScope(): void
    {
        $scope = new ScopeState();
        $response = new ScopeAwareResponse($scope, 200, '{"data":{"ok":true}}');
        $http = new QueueHttpClient([$response]);
        $authenticator = new ScopeTrackingAuthenticator($scope);
        $transport = $this->transport($http, $authenticator, new RecordingRetryDelay());

        $data = $transport->get(['cluster', 'resources']);

        self::assertInstanceOf(\stdClass::class, $data);
        self::assertTrue($data->ok);
        self::assertFalse($scope->active);
        self::assertSame(1, $authenticator->calls);
        self::assertTrue($response->statusReadInsideScope);
        self::assertTrue($response->bodyReadInsideScope);
        self::assertTrue($response->cancelledInsideScope);
        self::assertSame('GET', $http->requests[0]['method']);
        self::assertSame('https://pve.test:8006/api2/json/cluster/resources', $http->requests[0]['url']);
        self::assertTrue($http->requests[0]['authorizationWasPresent']);
        self::assertTrue($http->requests[0]['bufferWasDisabled']);
        self::assertTrue($http->requests[0]['tlsWasStrict']);
    }

    public function testTransportRetriesTransportFailuresAndDecryptsEachAttempt(): void
    {
        $scope = new ScopeState();
        $http = new QueueHttpClient([
            new TransportException('TOKEN-SENTINEL request'),
            new ScopeAwareResponse($scope, 200, [new TransportException('TOKEN-SENTINEL body')]),
            new ScopeAwareResponse($scope, 200, '{"data":42}'),
        ]);
        $authenticator = new ScopeTrackingAuthenticator($scope);
        $delay = new RecordingRetryDelay();

        self::assertSame(42, $this->transport($http, $authenticator, $delay)->get(['version']));
        self::assertSame(3, $authenticator->calls);
        self::assertSame([1, 2], $delay->retries);
    }

    public function testEveryPhysicalAttemptIsCheckpointedOutsideThePlaintextScope(): void
    {
        $scope = new ScopeState();
        $checkpoint = new RecordingTransportCheckpoint($scope);
        $http = new QueueHttpClient([
            new TransportException('first attempt failed'),
            new ScopeAwareResponse($scope, 503, ''),
            new ScopeAwareResponse($scope, 200, '{"data":true}'),
        ]);

        self::assertTrue($this->transport(
            $http,
            new ScopeTrackingAuthenticator($scope),
            new RecordingRetryDelay(),
            $checkpoint,
        )->get(['version']));

        self::assertSame(6, $checkpoint->calls);
        self::assertSame([false, false, false, false, false, false], $checkpoint->plaintextScopeStates);
        self::assertCount(3, $http->requests);
    }

    public function testPreAttemptCheckpointFailureIsPropagatedVerbatimWithoutAuthorizationOrRequest(): void
    {
        $scope = new ScopeState();
        $failure = new TransportCheckpointFailure('lease lost before attempt');
        $checkpoint = new RecordingTransportCheckpoint($scope, 1, $failure);
        $http = new QueueHttpClient([new ScopeAwareResponse($scope, 200, '{"data":true}')]);
        $authenticator = new ScopeTrackingAuthenticator($scope);

        try {
            $this->transport(
                $http,
                $authenticator,
                new RecordingRetryDelay(),
                $checkpoint,
            )->get(['version']);
            self::fail('A request was started after the pre-attempt checkpoint failed.');
        } catch (TransportCheckpointFailure $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(1, $checkpoint->calls);
        self::assertSame([false], $checkpoint->plaintextScopeStates);
        self::assertSame(0, $authenticator->calls);
        self::assertSame([], $http->requests);
    }

    public function testPostAttemptCheckpointFailureIsPropagatedVerbatimAndPreventsRetry(): void
    {
        $scope = new ScopeState();
        $failure = new TransportCheckpointFailure('lease lost after attempt');
        $checkpoint = new RecordingTransportCheckpoint($scope, 2, $failure);
        $response = new ScopeAwareResponse($scope, 503, '');
        $http = new QueueHttpClient([
            $response,
            new ScopeAwareResponse($scope, 200, '{"data":true}'),
        ]);
        $authenticator = new ScopeTrackingAuthenticator($scope);
        $delay = new RecordingRetryDelay();

        try {
            $this->transport($http, $authenticator, $delay, $checkpoint)->get(['version']);
            self::fail('The retry continued after the post-attempt checkpoint failed.');
        } catch (TransportCheckpointFailure $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(2, $checkpoint->calls);
        self::assertSame([false, false], $checkpoint->plaintextScopeStates);
        self::assertSame(1, $authenticator->calls);
        self::assertCount(1, $http->requests);
        self::assertSame([], $delay->retries);
        self::assertTrue($response->cancelledInsideScope);
    }

    #[DataProvider('statusProvider')]
    public function testStatusFailuresAreMappedWithoutBodiesOrSecrets(int $status, PveReadFailureCode $code): void
    {
        $scope = new ScopeState();
        $responses = [];
        $responseCount = in_array($status, [408, 429, 502, 503, 504], true) ? 3 : 1;
        for ($index = 0; $index < $responseCount; ++$index) {
            $responses[] = new ScopeAwareResponse($scope, $status, 'TOKEN-SENTINEL raw body');
        }
        $delay = new RecordingRetryDelay();

        try {
            $this->transport(new QueueHttpClient($responses), new ScopeTrackingAuthenticator($scope), $delay)->get(['version']);
            self::fail('Expected typed status failure.');
        } catch (PveReadFailure $failure) {
            self::assertSame($code, $failure->failureCode);
            self::assertStringNotContainsString('TOKEN-SENTINEL', $failure->getMessage());
            self::assertCount($responseCount - 1, $delay->retries);
        }
    }

    /** @return iterable<string, array{int, PveReadFailureCode}> */
    public static function statusProvider(): iterable
    {
        yield 'authentication' => [401, PveReadFailureCode::Authentication];
        yield 'permission' => [403, PveReadFailureCode::PermissionDenied];
        yield 'not found' => [404, PveReadFailureCode::NotFound];
        yield 'rate limit exhausted' => [429, PveReadFailureCode::RateLimited];
        yield 'timeout exhausted' => [408, PveReadFailureCode::RemoteUnavailable];
        yield 'bad gateway exhausted' => [502, PveReadFailureCode::RemoteUnavailable];
        yield 'unavailable exhausted' => [503, PveReadFailureCode::RemoteUnavailable];
        yield 'gateway timeout exhausted' => [504, PveReadFailureCode::RemoteUnavailable];
        yield 'unexpected' => [500, PveReadFailureCode::HttpStatus];
    }

    public function testFinalTransportAndInternalFailuresAreSafeAndNotOverRetried(): void
    {
        $scope = new ScopeState();
        $transportFailures = [
            new TransportException('TOKEN-SENTINEL 1'),
            new TransportException('TOKEN-SENTINEL 2'),
            new TransportException('TOKEN-SENTINEL 3'),
        ];
        $this->assertTransportFailure(
            $this->transport(new QueueHttpClient($transportFailures), new ScopeTrackingAuthenticator($scope), new RecordingRetryDelay()),
        );

        $internal = new QueueHttpClient([new RuntimeException('TOKEN-SENTINEL internal')]);
        $authenticator = new ScopeTrackingAuthenticator($scope);
        $this->assertTransportFailure($this->transport($internal, $authenticator, new RecordingRetryDelay()));
        self::assertSame(1, $authenticator->calls);

        $internalResponse = new QueueHttpClient([
            new ScopeAwareResponse($scope, new RuntimeException('TOKEN-SENTINEL status'), ''),
        ]);
        $this->assertTransportFailure($this->transport(
            $internalResponse,
            new ScopeTrackingAuthenticator($scope),
            new RecordingRetryDelay(),
        ));
    }

    public function testInvalidJsonEnvelopeNeverLeaksTheRawBody(): void
    {
        $scope = new ScopeState();
        $transport = $this->transport(
            new QueueHttpClient([new ScopeAwareResponse($scope, 200, 'TOKEN-SENTINEL {')]),
            new ScopeTrackingAuthenticator($scope),
            new RecordingRetryDelay(),
        );

        try {
            $transport->get(['version']);
            self::fail('Expected invalid envelope failure.');
        } catch (PveReadFailure $failure) {
            self::assertSame(PveReadFailureCode::InvalidEnvelope, $failure->failureCode);
            self::assertStringNotContainsString('TOKEN-SENTINEL', $failure->getMessage());
        }
    }

    public function testOversizedResponseIsStoppedDuringStreamingWithoutRetry(): void
    {
        $scope = new ScopeState();
        $response = new ScopeAwareResponse($scope, 200, [
            str_repeat('a', intdiv(PveJsonEnvelopeDecoder::MAXIMUM_BODY_BYTES, 2)),
            str_repeat('b', intdiv(PveJsonEnvelopeDecoder::MAXIMUM_BODY_BYTES, 2)),
            'x',
        ]);
        $http = new QueueHttpClient([$response]);
        $authenticator = new ScopeTrackingAuthenticator($scope);
        $delay = new RecordingRetryDelay();

        try {
            $this->transport($http, $authenticator, $delay)->get(['version']);
            self::fail('Expected oversized envelope failure.');
        } catch (PveReadFailure $failure) {
            self::assertSame(PveReadFailureCode::InvalidEnvelope, $failure->failureCode);
            self::assertSame(1, $authenticator->calls);
            self::assertSame([], $delay->retries);
            self::assertTrue($response->bodyReadInsideScope);
            self::assertTrue($response->cancelledInsideScope);
        }
    }

    public function testCancellationFailuresAreBestEffortAndNeverLeakTheirCause(): void
    {
        $scope = new ScopeState();
        $cancelFailure = new RuntimeException('TOKEN-SENTINEL cancel');
        $success = new ScopeAwareResponse($scope, 200, '{"data":true}', $cancelFailure);
        self::assertTrue($this->transport(
            new QueueHttpClient([$success]),
            new ScopeTrackingAuthenticator($scope),
            new RecordingRetryDelay(),
        )->get(['version']));
        self::assertTrue($success->cancelledInsideScope);

        $status = new ScopeAwareResponse($scope, 401, 'TOKEN-SENTINEL body', $cancelFailure);
        try {
            $this->transport(
                new QueueHttpClient([$status]),
                new ScopeTrackingAuthenticator($scope),
                new RecordingRetryDelay(),
            )->get(['version']);
            self::fail('Expected safe authentication failure.');
        } catch (PveReadFailure $failure) {
            self::assertSame(PveReadFailureCode::Authentication, $failure->failureCode);
            self::assertStringNotContainsString('TOKEN-SENTINEL', $failure->getMessage());
            self::assertTrue($status->cancelledInsideScope);
        }

        $transportResponses = [];
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $transportResponses[] = new ScopeAwareResponse(
                $scope,
                200,
                [new TransportException('TOKEN-SENTINEL stream')],
                $cancelFailure,
            );
        }
        $this->assertTransportFailure($this->transport(
            new QueueHttpClient($transportResponses),
            new ScopeTrackingAuthenticator($scope),
            new RecordingRetryDelay(),
        ));
    }

    public function testEndpointBoundClientUsesOnlyTheWhitelistedReadCalls(): void
    {
        $transport = new RecordingPveTransport();
        $connector = new PveEndpointReadConnector(
            $transport,
            new PveVersionReader(),
            new PvePermissionReader(),
            new PveClusterStatusReader(),
            new PveClusterResourcesReader(),
            new PveStorageConfigurationReader(),
            new PveNodeStorageStatusReader(),
            new PveTaskPageReader(),
        );

        $client = $connector->connect();
        self::assertSame(9, $client->version()->major);
        self::assertTrue($client->permissions()->isComplete());
        self::assertTrue($client->topology()->isComplete());
        self::assertTrue($client->resources()->isComplete());
        self::assertTrue($client->storageConfigurations()->isContractValid());
        self::assertCount(1, $client->nodeBackupStorages('a.test')->statuses);
        self::assertCount(1, $client->backupJobs()->jobs);
        $activeQuery = \App\Application\Proxmox\Pve\PveTaskQuery::active();
        self::assertCount(1, $client->backupTaskPage('a-test', $activeQuery)->tasks);
        $upid = \App\Application\Proxmox\Pve\PveUpid::parse(
            'UPID:a-test:0000002A:00000064:65A0BC00:vzdump:100:backup@pve:',
        );
        self::assertTrue($client->backupTaskStatus('a-test', $upid)->isSuccessful());
        self::assertSame(
            'https://pve.test:8006/api2/json/nodes/a-test/tasks/'.rawurlencode($upid->raw).'/status',
            (new PveApiUrlBuilder('pve.test'))->build(['nodes', 'a-test', 'tasks', $upid->raw, 'status']),
        );
        self::assertSame([
            ['path' => ['version'], 'query' => []],
            ['path' => ['access', 'permissions'], 'query' => []],
            ['path' => ['cluster', 'status'], 'query' => []],
            ['path' => ['cluster', 'resources'], 'query' => []],
            ['path' => ['storage'], 'query' => []],
            ['path' => ['nodes', 'a.test', 'storage'], 'query' => ['content' => 'backup']],
            ['path' => ['cluster', 'backup'], 'query' => []],
            ['path' => ['nodes', 'a-test', 'tasks'], 'query' => [
                'typefilter' => 'vzdump',
                'source' => 'active',
                'start' => 0,
                'limit' => 100,
            ]],
            ['path' => ['nodes', 'a-test', 'tasks', $upid->raw, 'status'], 'query' => []],
        ], $transport->calls);
        $client->resources();
        self::assertCount(10, $transport->calls, 'Direct client reads stay fresh; an orchestrator controls call multiplicity.');

        try {
            $client->nodeBackupStorages("bad\nnode");
            self::fail('Expected an invalid node name to fail before transport.');
        } catch (PveReadFailure $failure) {
            self::assertSame(PveReadFailureCode::InvalidResponse, $failure->failureCode);
        }
        self::assertCount(10, $transport->calls);

        try {
            $client->backupTaskStatus('other-test', $upid);
            self::fail('Expected a route/UPID node mismatch to fail before transport.');
        } catch (PveReadFailure $failure) {
            self::assertSame(PveReadFailureCode::InvalidResponse, $failure->failureCode);
        }
        self::assertCount(10, $transport->calls);

        try {
            $client->backupTaskPage('pve.test', $activeQuery);
            self::fail('Expected the official task-node grammar to fail before transport.');
        } catch (PveReadFailure $failure) {
            self::assertSame(PveReadFailureCode::InvalidResponse, $failure->failureCode);
        }
        self::assertCount(10, $transport->calls);
    }

    public function testCoreInstallationReadUsesExactlyTheFourGetOnlyRoutes(): void
    {
        $transport = new RecordingPveTransport();
        $connector = new PveEndpointReadConnector(
            $transport,
            new PveVersionReader(),
            new PvePermissionReader(),
            new PveClusterStatusReader(),
            new PveClusterResourcesReader(),
            new PveStorageConfigurationReader(),
            new PveNodeStorageStatusReader(),
            new PveTaskPageReader(),
        );

        $snapshot = (new ReadPveInstallation($connector))->read();

        self::assertTrue($snapshot->isComplete());
        self::assertSame([
            ['path' => ['version'], 'query' => []],
            ['path' => ['access', 'permissions'], 'query' => []],
            ['path' => ['cluster', 'status'], 'query' => []],
            ['path' => ['cluster', 'resources'], 'query' => []],
        ], $transport->calls);
    }

    private function transport(
        HttpClientInterface $httpClient,
        PveRequestAuthenticator $authenticator,
        PveRetryDelay $delay,
        ?ConnectionReadCheckpoint $checkpoint = null,
    ): PveHttpTransport {
        return new PveHttpTransport(
            $httpClient,
            new PveApiUrlBuilder('pve.test'),
            $authenticator,
            new PveRetryPolicy(3),
            $delay,
            new PveJsonEnvelopeDecoder(),
            $checkpoint ?? new RecordingTransportCheckpoint(new ScopeState()),
        );
    }

    private function assertTransportFailure(PveHttpTransport $transport): void
    {
        try {
            $transport->get(['version']);
            self::fail('Expected transport failure.');
        } catch (PveReadFailure $failure) {
            self::assertSame(PveReadFailureCode::Transport, $failure->failureCode);
            self::assertStringNotContainsString('TOKEN-SENTINEL', $failure->getMessage());
        }
    }
}

/** @internal */
final class ScopeState
{
    public bool $active = false;
}

/** @internal */
final class TransportCheckpointFailure extends RuntimeException
{
}

/** @internal */
final class RecordingTransportCheckpoint implements ConnectionReadCheckpoint
{
    public int $calls = 0;

    /** @var list<bool> */
    public array $plaintextScopeStates = [];

    public function __construct(
        private readonly ScopeState $scope,
        private readonly ?int $failAt = null,
        private readonly ?TransportCheckpointFailure $failure = null,
    ) {
    }

    public function checkpoint(): void
    {
        ++$this->calls;
        $this->plaintextScopeStates[] = $this->scope->active;
        if ($this->calls === $this->failAt) {
            throw $this->failure ?? new TransportCheckpointFailure('checkpoint failed');
        }
    }
}

/** @internal */
final class ScopeTrackingAuthenticator implements PveRequestAuthenticator
{
    public int $calls = 0;

    public function __construct(private readonly ScopeState $scope)
    {
    }

    public function authorize(callable $request): mixed
    {
        ++$this->calls;
        $this->scope->active = true;
        try {
            return $request('PVEAPIToken=collector@pve!token=TOKEN-SENTINEL');
        } finally {
            $this->scope->active = false;
        }
    }
}

/** @internal */
final class ScopeAwareResponse implements ResponseInterface
{
    public bool $statusReadInsideScope = false;
    public bool $bodyReadInsideScope = false;
    public bool $cancelledInsideScope = false;

    /** @param string|iterable<string|Throwable> $body */
    public function __construct(
        private readonly ScopeState $scope,
        private readonly int|Throwable $status,
        private string|iterable $body,
        private readonly ?Throwable $cancelFailure = null,
    ) {
    }

    public function getStatusCode(): int
    {
        $this->statusReadInsideScope = $this->scope->active;
        if (!$this->scope->active) {
            throw new RuntimeException('Status read outside authorization scope.');
        }
        if ($this->status instanceof Throwable) {
            throw $this->status;
        }
        return $this->status;
    }

    public function getHeaders(bool $throw = true): array
    {
        return [];
    }

    public function getContent(bool $throw = true): string
    {
        $this->bodyReadInsideScope = $this->scope->active;
        if (!$this->scope->active) {
            throw new RuntimeException('Body read outside authorization scope.');
        }
        if (is_string($this->body)) {
            return $this->body;
        }

        $contents = '';
        foreach ($this->body as $chunk) {
            if ($chunk instanceof Throwable) {
                throw $chunk;
            }
            $contents .= $chunk;
        }
        return $contents;
    }

    /** @return array<array-key, mixed> */
    public function toArray(bool $throw = true): array
    {
        return [];
    }

    public function cancel(): void
    {
        $this->cancelledInsideScope = $this->scope->active;
        if (!$this->scope->active) {
            throw new RuntimeException('Cancel outside authorization scope.');
        }
        if (null !== $this->cancelFailure) {
            throw $this->cancelFailure;
        }
    }

    public function getInfo(?string $type = null): mixed
    {
        return null === $type ? ['http_code' => is_int($this->status) ? $this->status : 0] : null;
    }

    /** @return list<string|Throwable> */
    public function streamItems(): array
    {
        $this->bodyReadInsideScope = $this->scope->active;
        if (!$this->scope->active) {
            throw new RuntimeException('Body streamed outside authorization scope.');
        }

        if (is_string($this->body)) {
            return [$this->body];
        }

        $items = [];
        foreach ($this->body as $item) {
            $items[] = $item;
        }

        return $items;
    }
}

/** @internal */
final class QueueHttpClient implements HttpClientInterface
{
    /** @var list<ResponseInterface|Throwable> */
    private array $queue;

    /** @var list<array{method: string, url: string, authorizationWasPresent: bool, bufferWasDisabled: bool, tlsWasStrict: bool}> */
    public array $requests = [];

    /** @param list<ResponseInterface|Throwable> $queue */
    public function __construct(array $queue)
    {
        $this->queue = $queue;
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $headers = $options['headers'] ?? [];
        $authorizationWasPresent = false;
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if ('Authorization' === $name && is_string($value) && str_starts_with($value, 'PVEAPIToken=')) {
                    $authorizationWasPresent = true;
                }
            }
        }
        $bufferWasDisabled = false === ($options['buffer'] ?? null);
        $tlsWasStrict = true === ($options['verify_peer'] ?? null)
            && true === ($options['verify_host'] ?? null)
            && 0 === ($options['max_redirects'] ?? null);
        $this->requests[] = compact(
            'method',
            'url',
            'authorizationWasPresent',
            'bufferWasDisabled',
            'tlsWasStrict',
        );

        $next = array_shift($this->queue);
        if ($next instanceof Throwable) {
            throw $next;
        }
        if (!$next instanceof ResponseInterface) {
            throw new RuntimeException('Missing fake response.');
        }
        return $next;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        if (!$responses instanceof ScopeAwareResponse) {
            throw new RuntimeException('The PVE transport streams one scope-aware response.');
        }

        return new ScopeAwareResponseStream($responses, $responses->streamItems());
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        return $this;
    }
}

/** @internal */
final class ScopeAwareResponseStream implements ResponseStreamInterface
{
    /** @var list<ScopeAwareChunk> */
    private array $chunks;

    private int $position = 0;

    /** @param list<string|Throwable> $items */
    public function __construct(private readonly ResponseInterface $response, array $items)
    {
        $this->chunks = array_map(
            static fn (string|Throwable $item): ScopeAwareChunk => new ScopeAwareChunk($item),
            $items,
        );
    }

    public function current(): ChunkInterface
    {
        return $this->chunks[$this->position];
    }

    public function next(): void
    {
        ++$this->position;
    }

    public function key(): ResponseInterface
    {
        return $this->response;
    }

    public function valid(): bool
    {
        return isset($this->chunks[$this->position]);
    }

    public function rewind(): void
    {
        $this->position = 0;
    }
}

/** @internal */
final readonly class ScopeAwareChunk implements ChunkInterface
{
    public function __construct(private string|Throwable $content)
    {
    }

    public function isTimeout(): bool
    {
        return false;
    }

    public function isFirst(): bool
    {
        return false;
    }

    public function isLast(): bool
    {
        return false;
    }

    /** @return array{int, array<string, list<string>>}|null */
    public function getInformationalStatus(): ?array
    {
        return null;
    }

    public function getContent(): string
    {
        if ($this->content instanceof Throwable) {
            throw $this->content;
        }

        return $this->content;
    }

    public function getOffset(): int
    {
        return 0;
    }

    public function getError(): ?string
    {
        return null;
    }
}

/** @internal */
final class RecordingRetryDelay implements PveRetryDelay
{
    /** @var list<int> */
    public array $retries = [];

    public function pause(int $retryNumber): void
    {
        $this->retries[] = $retryNumber;
    }
}

/** @internal */
final class RecordingPveTransport implements PveApiTransport
{
    /** @var list<array{path: list<string>, query: array<string, string|int|bool|null>}> */
    public array $calls = [];

    public function get(array $pathSegments, array $query = []): mixed
    {
        $this->calls[] = ['path' => $pathSegments, 'query' => $query];
        return match ($pathSegments) {
            ['version'] => ['release' => '9.2', 'version' => '9.2.3', 'repoid' => '9000000c'],
            ['access', 'permissions'] => [
                '/' => ['Sys.Audit' => 1],
                '/vms' => ['VM.Audit' => 1],
                '/storage' => ['Datastore.Audit' => 1],
            ],
            ['cluster', 'status'] => [
                ['id' => 'node/a', 'type' => 'node', 'name' => 'a.test', 'local' => true, 'nodeid' => 0],
            ],
            ['cluster', 'resources'] => [
                ['id' => 'node/a', 'type' => 'node', 'node' => 'a.test'],
            ],
            ['storage'] => [
                ['storage' => 'backup', 'type' => 'dir', 'content' => 'backup', 'digest' => 'digest'],
            ],
            ['nodes', 'a.test', 'storage'] => [
                [
                    'storage' => 'backup',
                    'type' => 'dir',
                    'content' => 'backup',
                    'enabled' => 1,
                    'active' => 1,
                    'shared' => 0,
                    'total' => 100,
                    'used' => 20,
                    'avail' => 70,
                ],
            ],
            ['cluster', 'backup'] => [[
                'id' => 'nightly',
                'schedule' => 'daily',
                'enabled' => 1,
                'prune-backups' => ['keep-last' => 3],
            ]],
            ['nodes', 'a-test', 'tasks'] => [[
                'upid' => 'UPID:a-test:0000002A:00000064:65A0BC00:vzdump:100:backup@pve:',
                'node' => 'a-test',
                'pid' => 42,
                'pstart' => 100,
                'starttime' => 1705032704,
                'type' => 'vzdump',
                'id' => 100,
                'user' => 'backup@pve',
                'status' => 'RUNNING',
            ]],
            ['nodes', 'a-test', 'tasks', 'UPID:a-test:0000002A:00000064:65A0BC00:vzdump:100:backup@pve:', 'status'] => [
                'upid' => 'UPID:a-test:0000002A:00000064:65A0BC00:vzdump:100:backup@pve:',
                'node' => 'a-test',
                'pid' => 42,
                'pstart' => 100,
                'starttime' => 1705032704,
                'type' => 'vzdump',
                'id' => 100,
                'user' => 'backup@pve',
                'status' => 'stopped',
                'exitstatus' => 'OK',
            ],
            default => throw new RuntimeException('Unexpected PVE path.'),
        };
    }
}
