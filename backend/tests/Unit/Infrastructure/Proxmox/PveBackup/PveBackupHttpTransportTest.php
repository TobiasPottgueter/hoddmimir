<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox\PveBackup;

use App\Application\Proxmox\Pve\PveBackupApiFailure;
use App\Application\Proxmox\Pve\PveBackupApiFailureCode;
use App\Application\Proxmox\Pve\BuildPveVzdumpPayload;
use App\Application\Proxmox\Pve\PveBackupCompression;
use App\Application\Proxmox\Pve\PveBackupMode;
use App\Application\Proxmox\Pve\PveBackupSubmission;
use App\Application\Proxmox\Pve\PveBackupSubmissionStatus;
use App\Application\Proxmox\Pve\PveGuestType;
use App\Application\Proxmox\Pve\PveVersion;
use App\Infrastructure\Proxmox\PveApiUrlBuilder;
use App\Infrastructure\Proxmox\PveBackup\PveBackupHttpTransport;
use App\Infrastructure\Proxmox\PveBackup\PveBackupJsonEnvelopeDecoder;
use App\Infrastructure\Proxmox\PveBackup\PveBackupSubmissionReader;
use App\Infrastructure\Proxmox\PveBackup\PveBackupTaskLogReader;
use App\Infrastructure\Proxmox\PveBackup\PveBackupWriteTransportStatus;
use App\Infrastructure\Proxmox\PveBackup\PveHttpBackupClient;
use App\Infrastructure\Proxmox\PveRequestAuthenticator;
use App\Infrastructure\Proxmox\PveRetryDelay;
use App\Infrastructure\Proxmox\PveRetryPolicy;
use App\Infrastructure\Proxmox\PveTaskPageReader;
use App\Infrastructure\Proxmox\PveTaskStatusReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Chunk\DataChunk;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class PveBackupHttpTransportTest extends TestCase
{
    public function testGetDelegatesTlsTrustAndUsesBoundedSecretScopedRequestOptions(): void
    {
        $seen = null;
        $http = new BackupRawOptionRecordingHttpClient(new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$method, $url, $options];
            return new MockResponse('{"data":{"ok":true}}', ['http_code' => 200]);
        }));

        $result = $this->transport($http)->get(['nodes', 'pve-a', 'tasks'], ['limit' => 1]);

        self::assertInstanceOf(\stdClass::class, $result);
        self::assertTrue($result->ok);
        self::assertIsArray($seen);
        self::assertSame('GET', $seen[0]);
        self::assertSame('https://pve.test:8006/api2/json/nodes/pve-a/tasks?limit=1', $seen[1]);
        self::assertSame(0, $seen[2]['max_redirects']);
        self::assertFalse($seen[2]['buffer']);
        self::assertSame(30.0, $seen[2]['timeout']);
        self::assertStringContainsString('PVEAPIToken=', serialize($seen[2]['normalized_headers']));
        self::assertCount(1, $http->requests);
        self::assertArrayNotHasKey('verify_peer', $http->requests[0]);
        self::assertArrayNotHasKey('verify_host', $http->requests[0]);
        self::assertArrayNotHasKey('peer_fingerprint', $http->requests[0]);
        self::assertArrayNotHasKey('cafile', $http->requests[0]);
    }

    public function testGetRetriesTransportAndRetryableStatusThenSucceeds(): void
    {
        $delay = new BackupRecordingDelay();
        $http = new MockHttpClient([
            new MockResponse('', ['error' => 'TOKEN-SENTINEL transport']),
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('{"data":42}', ['http_code' => 200]),
        ]);

        self::assertSame(42, $this->transport($http, $delay)->get(['version']));
        self::assertSame([1, 2], $delay->attempts);
        self::assertSame(3, $http->getRequestsCount());
    }

    public function testExhaustedTransportAndUnexpectedLocalFailureAreStableTransportFailures(): void
    {
        $this->assertFailure(
            PveBackupApiFailureCode::Transport,
            fn () => $this->transport(
                new MockHttpClient(new MockResponse('', ['error' => 'TOKEN-SENTINEL'])),
                maximumAttempts: 1,
            )->get(['version']),
        );
        $this->assertFailure(
            PveBackupApiFailureCode::Transport,
            fn () => $this->transport(
                new MockHttpClient(static function (): never { throw new RuntimeException('TOKEN-SENTINEL local'); }),
                maximumAttempts: 1,
            )->get(['version']),
        );
    }

    #[DataProvider('statusProvider')]
    public function testGetMapsEveryDefinitiveStatusWithoutLeakingBodies(
        int $status,
        PveBackupApiFailureCode $expected,
    ): void {
        $this->assertFailure(
            $expected,
            fn () => $this->transport(
                new MockHttpClient(new MockResponse('TOKEN-SENTINEL body', ['http_code' => $status])),
                maximumAttempts: 1,
            )->get(['version']),
        );
    }

    /** @return iterable<string, array{int, PveBackupApiFailureCode}> */
    public static function statusProvider(): iterable
    {
        yield 'auth' => [401, PveBackupApiFailureCode::Authentication];
        yield 'permission' => [403, PveBackupApiFailureCode::PermissionDenied];
        yield 'missing' => [404, PveBackupApiFailureCode::NotFound];
        yield 'rate' => [429, PveBackupApiFailureCode::RateLimited];
        yield 'timeout' => [408, PveBackupApiFailureCode::RemoteUnavailable];
        yield 'bad gateway' => [502, PveBackupApiFailureCode::RemoteUnavailable];
        yield 'unavailable' => [503, PveBackupApiFailureCode::RemoteUnavailable];
        yield 'gateway timeout' => [504, PveBackupApiFailureCode::RemoteUnavailable];
        yield 'other' => [418, PveBackupApiFailureCode::HttpStatus];
    }

    #[DataProvider('invalidEnvelopeProvider')]
    public function testInvalidEnvelopesFailClosed(string $body): void
    {
        $this->assertFailure(
            PveBackupApiFailureCode::InvalidEnvelope,
            fn () => $this->transport(new MockHttpClient(new MockResponse($body)))->get(['version']),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidEnvelopeProvider(): iterable
    {
        yield 'malformed' => ['{'];
        yield 'scalar' => ['42'];
        yield 'missing data' => ['{"other":true}'];
    }

    public function testOversizedStreamAndDirectDecoderLimitAreRejected(): void
    {
        $oversized = str_repeat('x', PveBackupJsonEnvelopeDecoder::MAXIMUM_BODY_BYTES + 1);
        $this->assertFailure(
            PveBackupApiFailureCode::InvalidEnvelope,
            fn () => $this->transport(new MockHttpClient(new MockResponse($oversized)))->get(['version']),
        );
        $this->assertFailure(
            PveBackupApiFailureCode::InvalidEnvelope,
            fn () => (new PveBackupJsonEnvelopeDecoder())->decode($oversized),
        );
    }

    public function testPostDelegatesTlsTrustUsesExactlyOnePhysicalAttemptAndReturnsTypedResponse(): void
    {
        $seen = null;
        $http = new BackupRawOptionRecordingHttpClient(new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$method, $url, $options];
            return new MockResponse('{"data":"UPID:pve-a:0000002A:000F4240:67000000:vzdump:101:backup@pve:"}');
        }));
        $result = $this->transport($http)->post(
            ['nodes', 'pve-a', 'vzdump'],
            ['vmid' => 101, 'storage' => 'backup-store'],
        );

        self::assertSame(PveBackupWriteTransportStatus::Responded, $result->status);
        self::assertIsString($result->decodedData());
        self::assertSame(1, $http->requestsCount());
        self::assertIsArray($seen);
        self::assertSame('POST', $seen[0]);
        self::assertSame(60.0, $seen[2]['timeout']);
        self::assertSame('vmid=101&storage=backup-store', $seen[2]['body']);
        self::assertCount(1, $http->requests);
        self::assertArrayNotHasKey('verify_peer', $http->requests[0]);
        self::assertArrayNotHasKey('verify_host', $http->requests[0]);
        self::assertArrayNotHasKey('peer_fingerprint', $http->requests[0]);
        self::assertArrayNotHasKey('cafile', $http->requests[0]);
    }

    public function testPostTransportFailureIsAmbiguousAndIsNeverRetried(): void
    {
        $http = new MockHttpClient([
            new MockResponse('', ['error' => 'TOKEN-SENTINEL timeout']),
            new MockResponse('{"data":"must-not-be-used"}'),
        ]);
        $result = $this->transport($http)->post(['nodes', 'pve-a', 'vzdump'], ['vmid' => 101]);

        self::assertSame(PveBackupWriteTransportStatus::Ambiguous, $result->status);
        self::assertNull($result->decodedData());
        self::assertSame(1, $http->getRequestsCount());
    }

    #[DataProvider('ambiguousWriteStatusProvider')]
    public function testPostDispatchGatewayAndTimeoutStatusesAreAmbiguousWithoutRetry(int $status): void
    {
        $post = new MockHttpClient([
            new MockResponse('gateway response', ['http_code' => $status]),
            new MockResponse('{"data":"must-not-be-used"}'),
        ]);
        self::assertSame(
            PveBackupWriteTransportStatus::Ambiguous,
            $this->transport($post)->post(['nodes', 'pve-a', 'vzdump'], ['vmid' => 101])->status,
        );
        self::assertSame(1, $post->getRequestsCount());

        $delete = new MockHttpClient([
            new MockResponse('gateway response', ['http_code' => $status]),
            new MockResponse('{"data":null}'),
        ]);
        self::assertSame(
            PveBackupWriteTransportStatus::Ambiguous,
            $this->transport($delete)->delete(['nodes', 'pve-a', 'tasks', 'UPID'])->status,
        );
        self::assertSame(1, $delete->getRequestsCount());
    }

    /** @return iterable<string, array{int}> */
    public static function ambiguousWriteStatusProvider(): iterable
    {
        yield 'request timeout' => [408];
        yield 'bad gateway' => [502];
        yield 'service unavailable' => [503];
        yield 'gateway timeout' => [504];
    }

    #[DataProvider('definitiveWriteStatusProvider')]
    public function testOtherWriteStatusesRemainDefinitive(
        int $status,
        PveBackupApiFailureCode $expected,
    ): void {
        $post = new MockHttpClient(new MockResponse('remote response', ['http_code' => $status]));
        $this->assertFailure(
            $expected,
            fn () => $this->transport($post)->post(['nodes', 'pve-a', 'vzdump'], ['vmid' => 101]),
        );
        self::assertSame(1, $post->getRequestsCount());

        $delete = new MockHttpClient(new MockResponse('remote response', ['http_code' => $status]));
        $this->assertFailure(
            $expected,
            fn () => $this->transport($delete)->delete(['nodes', 'pve-a', 'tasks', 'UPID']),
        );
        self::assertSame(1, $delete->getRequestsCount());
    }

    /** @return iterable<string, array{int, PveBackupApiFailureCode}> */
    public static function definitiveWriteStatusProvider(): iterable
    {
        yield 'authentication' => [401, PveBackupApiFailureCode::Authentication];
        yield 'permission' => [403, PveBackupApiFailureCode::PermissionDenied];
        yield 'not found' => [404, PveBackupApiFailureCode::NotFound];
        yield 'rate limited' => [429, PveBackupApiFailureCode::RateLimited];
        yield 'other' => [418, PveBackupApiFailureCode::HttpStatus];
    }

    public function testCredentialFailureBeforeDispatchRemainsDefinitive(): void
    {
        $http = new MockHttpClient(new MockResponse('{"data":"must-not-be-used"}'));
        $transport = new PveBackupHttpTransport(
            $http,
            new PveApiUrlBuilder('pve.test', 8006),
            new BackupRejectingAuthenticator(),
            new PveRetryPolicy(),
            new BackupRecordingDelay(),
            new PveBackupJsonEnvelopeDecoder(),
        );

        $this->assertFailure(
            PveBackupApiFailureCode::CredentialUnavailable,
            fn () => $transport->post(['nodes', 'pve-a', 'vzdump'], ['vmid' => 101]),
        );
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testDeleteIsTypedUsesNoBodyAndCanBeAmbiguous(): void
    {
        $seen = null;
        $responded = $this->transport(new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$seen): MockResponse {
                $seen = [$method, $url, $options];
                return new MockResponse('{"data":null}');
            },
        ))->delete(['nodes', 'pve-a', 'tasks', 'UPID']);
        self::assertSame(PveBackupWriteTransportStatus::Responded, $responded->status);
        self::assertNull($responded->decodedData());
        self::assertIsArray($seen);
        self::assertSame('DELETE', $seen[0]);
        self::assertArrayNotHasKey('body', $seen[2]);
        self::assertSame(30.0, $seen[2]['timeout']);

        $http = new MockHttpClient(new MockResponse('', ['error' => 'disconnect']));
        self::assertSame(
            PveBackupWriteTransportStatus::Ambiguous,
            $this->transport($http)->delete(['nodes', 'pve-a', 'tasks', 'UPID'])->status,
        );
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testCancellationFailureNeverReplacesTheDecodedResult(): void
    {
        $response = new BackupThrowingCancelResponse('{"data":{"ok":true}}');
        $http = new BackupDirectHttpClient($response, '{"data":{"ok":true}}');

        $result = $this->transport($http)->get(['version']);

        self::assertInstanceOf(\stdClass::class, $result);
        self::assertTrue($result->ok);
    }

    public function testWritesKeepOnlyDefinitiveStatusAsFailureAndMapUnusableResponsesToAmbiguous(): void
    {
        $this->assertFailure(
            PveBackupApiFailureCode::PermissionDenied,
            fn () => $this->transport(new MockHttpClient(new MockResponse('secret', ['http_code' => 403])))
                ->post(['nodes', 'pve-a', 'vzdump'], ['vmid' => 101]),
        );
        $generic = new BackupDirectHttpClient(new BackupThrowingStatusResponse(), '');
        self::assertSame(
            PveBackupWriteTransportStatus::Ambiguous,
            $this->transport($generic)->post(['nodes', 'pve-a', 'vzdump'], ['vmid' => 101])->status,
        );
        self::assertSame(1, $generic->requests);

        $oversized = new MockHttpClient(new MockResponse(
                str_repeat('x', PveBackupJsonEnvelopeDecoder::MAXIMUM_BODY_BYTES + 1),
            ));
        self::assertSame(
            PveBackupWriteTransportStatus::Ambiguous,
            $this->transport($oversized)->post(['nodes', 'pve-a', 'vzdump'], ['vmid' => 101])->status,
        );
        self::assertSame(1, $oversized->getRequestsCount());

        $invalidJson = new MockHttpClient(new MockResponse('{'));
        self::assertSame(
            PveBackupWriteTransportStatus::Ambiguous,
            $this->transport($invalidJson)->post(['nodes', 'pve-a', 'vzdump'], ['vmid' => 101])->status,
        );
        self::assertSame(1, $invalidJson->getRequestsCount());

        $missingData = new MockHttpClient(new MockResponse('{"other":true}'));
        self::assertSame(
            PveBackupWriteTransportStatus::Ambiguous,
            $this->transport($missingData)->post(['nodes', 'pve-a', 'vzdump'], ['vmid' => 101])->status,
        );
        self::assertSame(1, $missingData->getRequestsCount());
    }

    public function testNullAndMalformedSuccessfulSubmitDataStaySingleRequestAndBecomeAmbiguous(): void
    {
        foreach (['{"data":null}', '{"data":true}', '{"data":"not-an-upid"}'] as $body) {
            $http = new MockHttpClient(new MockResponse($body));
            $version = new PveVersion(8, 4, 1, '1', '8.4.1', 'fixture');
            $client = new PveHttpBackupClient(
                $this->transport($http),
                $version,
                new BuildPveVzdumpPayload(),
                new PveBackupSubmissionReader(),
                new PveTaskStatusReader($version),
                new PveBackupTaskLogReader(),
                new PveTaskPageReader(),
            );

            $result = $client->submit(new PveBackupSubmission(
                'pve-a',
                101,
                PveGuestType::Qemu,
                'backup-store',
                PveBackupMode::Snapshot,
                PveBackupCompression::Zstd,
            ));

            self::assertSame(PveBackupSubmissionStatus::Ambiguous, $result->status);
            self::assertNull($result->upid);
            self::assertSame(1, $http->getRequestsCount());
        }

        $invalidStop = new MockHttpClient(new MockResponse('{'));
        self::assertSame(
            PveBackupWriteTransportStatus::Ambiguous,
            $this->transport($invalidStop)->delete(['nodes', 'pve-a', 'tasks', 'UPID'])->status,
        );
        self::assertSame(1, $invalidStop->getRequestsCount());
    }

    private function transport(
        HttpClientInterface $http,
        ?BackupRecordingDelay $delay = null,
        int $maximumAttempts = 3,
    ): PveBackupHttpTransport {
        return new PveBackupHttpTransport(
            $http,
            new PveApiUrlBuilder('pve.test', 8006),
            new BackupInlineAuthenticator(),
            new PveRetryPolicy($maximumAttempts),
            $delay ?? new BackupRecordingDelay(),
            new PveBackupJsonEnvelopeDecoder(),
        );
    }

    private function assertFailure(PveBackupApiFailureCode $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected typed PVE backup API failure.');
        } catch (PveBackupApiFailure $failure) {
            self::assertSame($code, $failure->failureCode);
            self::assertStringNotContainsString('TOKEN-SENTINEL', $failure->getMessage());
        }
    }
}

/** @internal */
final readonly class BackupInlineAuthenticator implements PveRequestAuthenticator
{
    public function authorize(callable $request): mixed
    {
        return $request('PVEAPIToken=backup@pve!hoddmimir=TOKEN-SENTINEL');
    }
}

/** @internal */
final class BackupRawOptionRecordingHttpClient implements HttpClientInterface
{
    /** @var list<array<string, mixed>> */
    public array $requests = [];

    public function __construct(private HttpClientInterface $inner)
    {
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->requests[] = $options;

        return $this->inner->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->inner = $this->inner->withOptions($options);

        return $clone;
    }

    public function requestsCount(): int
    {
        return $this->inner instanceof MockHttpClient ? $this->inner->getRequestsCount() : count($this->requests);
    }
}

/** @internal */
final readonly class BackupRejectingAuthenticator implements PveRequestAuthenticator
{
    public function authorize(callable $request): mixed
    {
        throw PveBackupApiFailure::for(PveBackupApiFailureCode::CredentialUnavailable);
    }
}

/** @internal */
final class BackupRecordingDelay implements PveRetryDelay
{
    /** @var list<int> */
    public array $attempts = [];

    public function pause(int $retryNumber): void
    {
        $this->attempts[] = $retryNumber;
    }
}

/** @internal */
final class BackupThrowingCancelResponse extends MockResponse
{
    private bool $failureRaised = false;

    public function cancel(): void
    {
        if (!$this->failureRaised) {
            $this->failureRaised = true;
            throw new RuntimeException('Cancellation failure.');
        }

        parent::cancel();
    }
}

/** @internal */
final class BackupDirectHttpClient implements HttpClientInterface
{
    public int $requests = 0;

    public function __construct(
        private readonly ResponseInterface $response,
        private readonly string $body,
    ) {
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        ++$this->requests;

        return $this->response;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        $response = $this->response;
        $body = $this->body;
        $generator = static function () use ($response, $body): \Generator {
            yield $response => new DataChunk(0, $body);
        };

        return new ResponseStream($generator());
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        return $this;
    }
}

/** @internal */
final class BackupThrowingStatusResponse extends MockResponse
{
    public function getStatusCode(): int
    {
        throw new RuntimeException('Post-dispatch response failure.');
    }
}
