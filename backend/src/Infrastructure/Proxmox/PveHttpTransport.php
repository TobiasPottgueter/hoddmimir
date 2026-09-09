<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveReadFailureCode;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

final readonly class PveHttpTransport implements PveApiTransport
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private PveApiUrlBuilder $urlBuilder,
        private PveRequestAuthenticator $authenticator,
        private PveRetryPolicy $retryPolicy,
        private PveRetryDelay $retryDelay,
        private PveJsonEnvelopeDecoder $envelopeDecoder,
        private ConnectionReadCheckpoint $checkpoint,
    ) {
    }

    public function get(array $pathSegments, array $query = []): mixed
    {
        $url = $this->urlBuilder->build($pathSegments, $query);

        return $this->requestWithRetry($url, 1);
    }

    private function requestWithRetry(string $url, int $attempt): mixed
    {
        $this->checkpoint->checkpoint();
        try {
            $result = $this->attempt($url);
        } finally {
            // attempt() returns only after authorize() and its plaintext-secret
            // callback have closed. A lost lease must propagate verbatim here.
            $this->checkpoint->checkpoint();
        }

        if ('transport' === $result['kind']) {
            if ($this->retryPolicy->shouldRetry(PveHttpMethod::Get, $attempt, true, null)) {
                $this->retryDelay->pause($attempt);
                return $this->requestWithRetry($url, $attempt + 1);
            }

            throw PveReadFailure::for(PveReadFailureCode::Transport);
        }

        if ('internal' === $result['kind']) {
            throw PveReadFailure::for(PveReadFailureCode::Transport);
        }

        if ('status' === $result['kind']) {
            $statusCode = $result['status'];
            if ($this->retryPolicy->shouldRetry(PveHttpMethod::Get, $attempt, false, $statusCode)) {
                $this->retryDelay->pause($attempt);
                return $this->requestWithRetry($url, $attempt + 1);
            }

            throw PveReadFailure::for($this->mapStatus($statusCode));
        }

        if ('oversized' === $result['kind']) {
            throw PveReadFailure::for(PveReadFailureCode::InvalidEnvelope);
        }

        return $this->envelopeDecoder->decode($result['body']);
    }

    /**
     * The response and Authorization header never leave the plaintext-secret
     * callback. Only a secret-free status/body result crosses this boundary.
     *
     * @return array{kind: 'success', body: string}|array{kind: 'status', status: int}|array{kind: 'transport'|'internal'|'oversized'}
     */
    private function attempt(string $url): array
    {
        return $this->authenticator->authorize(function (string $authorization) use ($url): array {
            /** @var ResponseInterface|null $response */
            $response = null;

            try {
                $response = $this->httpClient->request(
                    PveHttpMethod::Get->value,
                    $url,
                    [
                        'headers' => [
                            'Accept' => 'application/json',
                            'Authorization' => $authorization,
                        ],
                        'buffer' => false,
                        'max_redirects' => 0,
                    ],
                );
                $statusCode = $response->getStatusCode();
                if ($statusCode < 200 || $statusCode >= 300) {
                    $this->cancel($response);
                    return ['kind' => 'status', 'status' => $statusCode];
                }

                $body = $this->readBoundedBody($response);
                $this->cancel($response);
                if (null === $body) {
                    return ['kind' => 'oversized'];
                }

                return ['kind' => 'success', 'body' => $body];
            } catch (TransportExceptionInterface) {
                $this->cancel($response);
                return ['kind' => 'transport'];
            } catch (Throwable) {
                $this->cancel($response);
                return ['kind' => 'internal'];
            }
        });
    }

    private function readBoundedBody(ResponseInterface $response): ?string
    {
        $body = '';
        foreach ($this->httpClient->stream($response) as $chunk) {
            $content = $chunk->getContent();
            if (strlen($body) > PveJsonEnvelopeDecoder::MAXIMUM_BODY_BYTES - strlen($content)) {
                return null;
            }

            $body .= $content;
        }

        return $body;
    }

    private function cancel(?ResponseInterface $response): void
    {
        if (null === $response) {
            return;
        }

        try {
            $response->cancel();
        } catch (Throwable) {
            // Cancellation is best-effort and must never replace the safe read result.
        }
    }

    private function mapStatus(int $statusCode): PveReadFailureCode
    {
        return match ($statusCode) {
            401 => PveReadFailureCode::Authentication,
            403 => PveReadFailureCode::PermissionDenied,
            404 => PveReadFailureCode::NotFound,
            429 => PveReadFailureCode::RateLimited,
            408, 502, 503, 504 => PveReadFailureCode::RemoteUnavailable,
            default => PveReadFailureCode::HttpStatus,
        };
    }
}
