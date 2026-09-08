<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox;

use App\Application\Worker\MonotonicClock;
use App\Infrastructure\Proxmox\ProxmoxConnectionHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Chunk\ErrorChunk;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;

final class ProxmoxConnectionHttpClientTest extends TestCase
{
    public function testRequestOptionsPreserveReadBudgetAndComposeProgressCallbacks(): void
    {
        $captured = []; $calls = 0;
        $base = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options;
            return new MockResponse('safe', ['http_code' => 200]);
        });
        $client = (new ProxmoxConnectionHttpClient($base))->withOptions(['on_progress' => static function () use (&$calls): void { ++$calls; }]);
        $response = $client->request('POST', 'https://example.test/', ['timeout' => 60.0, 'max_duration' => 60.0]);
        $content = '';
        foreach ($client->stream($response) as $chunk) $content .= $chunk->getContent();
        self::assertSame('safe', $content);
        self::assertSame(60.0, $captured['timeout']);
        self::assertSame(60.0, $captured['max_duration']);
        self::assertSame('1.1', $captured['http_version']);
        self::assertTrue($this->curlOptions($captured)[CURLOPT_FRESH_CONNECT]);
        self::assertTrue($this->curlOptions($captured)[CURLOPT_FORBID_REUSE]);
        self::assertFalse($this->curlOptions($captured)[CURLOPT_SSL_SESSIONID_CACHE]);
        $progress = $captured['on_progress']; self::assertIsCallable($progress);
        $before = $calls; $progress(0, 0, ['pretransfer_time' => 1.0, 'total_time' => 50.0]);
        self::assertSame($before + 1, $calls);
        foreach ([['pretransfer_time' => 0.0, 'total_time' => 5.1], ['pretransfer_time' => 5.1, 'total_time' => 6.0]] as $info) {
            try { $progress(0, 0, $info); self::fail('Connection deadline must fail independently of the read budget.'); }
            catch (TimeoutExceptionInterface) { self::addToAssertionCount(1); }
        }
        $callback = $this->curlOptions($captured)[CURLOPT_PREREQFUNCTION]; self::assertIsCallable($callback);
        $handle = curl_init(); self::assertSame(CURL_PREREQFUNC_OK, $callback($handle));
        $response = $client->withOptions([])->withOptions(['on_progress' => null])->request('GET', 'https://example.test/');
        self::assertSame(200, $response->getStatusCode());
    }

    public function testTimeoutCancelsWithoutWaitingForSocketReadiness(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects(self::once())->method('cancel');
        $base = $this->createMock(HttpClientInterface::class);
        $base->expects(self::once())->method('request')->willReturn($response);
        $base->expects(self::once())->method('stream')->willReturn(new ResponseStream((static function () use ($response): \Generator {
            yield $response => new ErrorChunk(0, 'still connecting');
        })()));
        $clock = new class implements MonotonicClock {
            private int $calls = 0;
            public function nowNanoseconds(): int { return $this->calls++ * 5_000_000_001; }
        };
        $this->expectException(TimeoutExceptionInterface::class);
        (new ProxmoxConnectionHttpClient($base, clock: $clock))->request('GET', 'https://example.test/');
    }

    public function testPrerequisiteAbortAndMalformedCertificateAreFailClosed(): void
    {
        $captured = [];
        $base = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options; return new MockResponse('safe');
        });
        $client = new ProxmoxConnectionHttpClient($base, str_repeat('a', 64));
        $client->request('GET', 'https://example.test/')->getContent();
        $callback = $this->curlOptions($captured)[CURLOPT_PREREQFUNCTION]; self::assertIsCallable($callback);
        self::assertSame(CURL_PREREQFUNC_ABORT, $callback(curl_init()));
        self::assertFalse($client->matchesLeaf(null));
        self::assertFalse($client->matchesLeaf('not a certificate'));
        self::assertFalse((new ProxmoxConnectionHttpClient($base))->matchesLeaf('not a certificate'));
        $tiny = new ProxmoxConnectionHttpClient($base, connectSeconds: 0.000000001);
        try { $tiny->request('GET', 'https://example.test/')->getContent(); }
        catch (TimeoutExceptionInterface) { self::addToAssertionCount(1); }
        $callback = $this->curlOptions($captured)[CURLOPT_PREREQFUNCTION]; self::assertIsCallable($callback);
        $handle = curl_init('file:///dev/null'); curl_setopt($handle, CURLOPT_RETURNTRANSFER, true); curl_exec($handle);
        self::assertSame(CURL_PREREQFUNC_ABORT, $callback($handle));
    }

    /**
     * @param array<array-key, mixed> $options
     * @return array<array-key, mixed>
     */
    private function curlOptions(array $options): array
    {
        $extra = $options['extra']; self::assertIsArray($extra);
        $curl = $extra['curl']; self::assertIsArray($curl);
        return $curl;
    }

    public function testInvalidDeadlineAndOptionShapesAreRejected(): void
    {
        foreach ([0.0, 31.0] as $seconds) {
            try { new ProxmoxConnectionHttpClient(new MockHttpClient(), connectSeconds: $seconds); self::fail('Invalid deadline accepted.'); }
            catch (\InvalidArgumentException) { self::addToAssertionCount(1); }
        }
        foreach ([['extra' => 'bad'], ['extra' => ['curl' => 'bad']], ['on_progress' => 1]] as $options) {
            try { (new ProxmoxConnectionHttpClient(new MockHttpClient()))->request('GET', 'https://example.test/', $options); self::fail('Invalid option accepted.'); }
            catch (\InvalidArgumentException) { self::addToAssertionCount(1); }
        }
    }
}
