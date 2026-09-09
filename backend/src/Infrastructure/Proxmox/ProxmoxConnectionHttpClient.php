<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/** cURL transport guard: bounded connection establishment and exact leaf trust before HTTP. */
final readonly class ProxmoxConnectionHttpClient implements HttpClientInterface
{
    public function __construct(
        private HttpClientInterface $client,
        private ?string $leafFingerprint = null,
        private float $connectSeconds = 5.0,
        private ?\Closure $defaultProgress = null,
        private \App\Application\Worker\MonotonicClock $clock = new \App\Infrastructure\Time\SystemMonotonicClock(),
    ) {
        if ($connectSeconds <= 0 || $connectSeconds > 30) throw new \InvalidArgumentException('Invalid Proxmox connection deadline.');
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $progress = $this->progress($options);
        $options['on_progress'] = function (int $downloaded, int $size, array $info) use ($progress): void {
            // pretransfer_time includes DNS, TCP and TLS. A slow response after that point
            // is governed by the separate 30/60-second read/total request budgets.
            /** @var array{pretransfer_time?: float, total_time?: float} $info cURL timing fields. */
            $connected = (float) ($info['pretransfer_time'] ?? 0.0);
            $elapsed = (float) ($info['total_time'] ?? 0.0);
            if ($connected > $this->connectSeconds || $connected <= 0 && $elapsed > $this->connectSeconds) {
                throw new TimeoutException('Proxmox connection establishment timed out.');
            }
            if (null !== $progress) $progress($downloaded, $size, $info);
        };
        $options['http_version'] = '1.1';
        $options['max_redirects'] = 0;
        $options['capture_peer_cert_chain'] = null !== $this->leafFingerprint;
        $extra = $options['extra'] ?? [];
        if (!is_array($extra) || !is_array($extra['curl'] ?? [])) throw new \InvalidArgumentException('Invalid cURL transport options.');
        $curl = $extra['curl'] ?? [];
        $curl[\CURLOPT_FRESH_CONNECT] = true;
        $curl[\CURLOPT_FORBID_REUSE] = true;
        // Resumed TLS sessions may omit CURLINFO_CERTINFO. Require a complete
        // handshake so the exact leaf check has evidence on every request.
        $curl[\CURLOPT_SSL_SESSIONID_CACHE] = false;
        $curl[\CURLOPT_PREREQFUNCTION] = function (\CurlHandle $handle): int {
            // This callback runs after TLS but before any HTTP request/header is sent.
            // Return ABORT explicitly: a thrown PHP exception alone does not stop libcurl
            // from sending headers before that exception is propagated to the caller.
            if (curl_getinfo($handle, \CURLINFO_TOTAL_TIME) > $this->connectSeconds) {
                return \CURL_PREREQFUNC_ABORT;
            }
            if (null !== $this->leafFingerprint) {
                /** @var list<array<string, string>> $certificates CURLINFO_CERTINFO's documented shape. */
                $certificates = curl_getinfo($handle, \CURLINFO_CERTINFO);
                if (!$this->matchesLeaf($certificates[0]['Cert'] ?? null)) {
                    return \CURL_PREREQFUNC_ABORT;
                }
            }
            return \CURL_PREREQFUNC_OK;
        };
        $options['extra'] = array_replace($extra, ['curl' => $curl]);
        $started = $this->clock->nowNanoseconds();
        $response = $this->client->request($method, $url, $options);
        // Symfony 7.4 otherwise waits for socket readiness using the read budget.
        // Polling its public stream API also services libcurl's connection timers.
        do {
            foreach ($this->client->stream($response, 0.1) as $chunk) {
                if ($chunk->isTimeout()) break;
                return $response;
            }
            if (($this->clock->nowNanoseconds() - $started) / 1e9 > $this->connectSeconds) {
                $response->cancel();
                throw new TimeoutException('Proxmox connection establishment timed out.');
            }
        } while (0 >= $response->getInfo('pretransfer_time'));
        return $response;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        return new self($this->client->withOptions($options), $this->leafFingerprint, $this->connectSeconds, $this->progress($options), $this->clock);
    }

    /** @param array<string, mixed> $options */
    private function progress(array $options): ?\Closure
    {
        $progress = array_key_exists('on_progress', $options) ? $options['on_progress'] : $this->defaultProgress;
        if (null === $progress) return null;
        if (!is_callable($progress)) throw new \InvalidArgumentException('Invalid Proxmox progress callback.');
        return \Closure::fromCallable($progress);
    }

    public function matchesLeaf(?string $certificate): bool
    {
        if (null === $certificate || null === $this->leafFingerprint) return false;
        $actual = @openssl_x509_fingerprint($certificate, 'sha256');
        return is_string($actual) && hash_equals($this->leafFingerprint, $actual);
    }
}
