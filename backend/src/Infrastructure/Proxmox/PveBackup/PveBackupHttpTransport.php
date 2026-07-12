<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\PveBackup;

use App\Application\Proxmox\Pve\PveBackupApiFailure;
use App\Application\Proxmox\Pve\PveBackupApiFailureCode;
use App\Infrastructure\Proxmox\PveApiUrlBuilder;
use App\Infrastructure\Proxmox\PveHttpMethod;
use App\Infrastructure\Proxmox\PveRequestAuthenticator;
use App\Infrastructure\Proxmox\PveRetryDelay;
use App\Infrastructure\Proxmox\PveRetryPolicy;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

final readonly class PveBackupHttpTransport implements PveBackupApiTransport
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private PveApiUrlBuilder $urlBuilder,
        private PveRequestAuthenticator $authenticator,
        private PveRetryPolicy $retryPolicy,
        private PveRetryDelay $retryDelay,
        private PveBackupJsonEnvelopeDecoder $envelopeDecoder,
    ) {
    }

    public function get(array $pathSegments, array $query = []): mixed
    {
        return $this->readWithRetry($this->urlBuilder->build($pathSegments, $query), 1);
    }

    public function post(array $pathSegments, array $form): PveBackupWriteTransportResult
    {
        return $this->write(PveHttpMethod::Post, $this->urlBuilder->build($pathSegments), $form);
    }

    public function delete(array $pathSegments): PveBackupWriteTransportResult
    {
        return $this->write(PveHttpMethod::Delete, $this->urlBuilder->build($pathSegments), []);
    }

    private function readWithRetry(string $url, int $attempt): mixed
    {
        $result = $this->attempt(PveHttpMethod::Get, $url, []);
        if ('transport' === $result['kind']) {
            if ($this->retryPolicy->shouldRetry(PveHttpMethod::Get, $attempt, true, null)) {
                $this->retryDelay->pause($attempt);
                return $this->readWithRetry($url, $attempt + 1);
            }
            throw PveBackupApiFailure::for(PveBackupApiFailureCode::Transport);
        }
        if ('internal' === $result['kind']) {
            throw PveBackupApiFailure::for(PveBackupApiFailureCode::Transport);
        }
        if ('status' === $result['kind']) {
            if ($this->retryPolicy->shouldRetry(PveHttpMethod::Get, $attempt, false, $result['status'])) {
                $this->retryDelay->pause($attempt);
                return $this->readWithRetry($url, $attempt + 1);
            }
            throw PveBackupApiFailure::for($this->mapStatus($result['status']));
        }
        if ('oversized' === $result['kind']) {
            throw PveBackupApiFailure::for(PveBackupApiFailureCode::InvalidEnvelope);
        }

        return $this->envelopeDecoder->decode($result['body']);
    }

    /** @param array<string, string|int> $form */
    private function write(PveHttpMethod $method, string $url, array $form): PveBackupWriteTransportResult
    {
        $result = $this->attempt($method, $url, $form);
        if ('transport' === $result['kind']) {
            return PveBackupWriteTransportResult::ambiguous();
        }
        if ('internal' === $result['kind']) {
            return PveBackupWriteTransportResult::ambiguous();
        }
        if ('status' === $result['kind']) {
            if ($this->isAmbiguousWriteStatus($result['status'])) {
                return PveBackupWriteTransportResult::ambiguous();
            }
            throw PveBackupApiFailure::for($this->mapStatus($result['status']));
        }
        if ('oversized' === $result['kind']) {
            return PveBackupWriteTransportResult::ambiguous();
        }
        try {
            $data = $this->envelopeDecoder->decode($result['body']);
        } catch (PveBackupApiFailure) {
            return PveBackupWriteTransportResult::ambiguous();
        }

        return null === $data
            ? PveBackupWriteTransportResult::respondedWithoutData()
            : PveBackupWriteTransportResult::responded($data);
    }

    /**
     * @param array<string, string|int> $form
     * @return array{kind: 'success', body: string}|array{kind: 'status', status: int}|array{kind: 'transport'|'internal'|'oversized'}
     */
    private function attempt(PveHttpMethod $method, string $url, array $form): array
    {
        return $this->authenticator->authorize(function (string $authorization) use ($method, $url, $form): array {
            $response = null;
            try {
                $options = [
                    'headers' => ['Accept' => 'application/json', 'Authorization' => $authorization],
                    'buffer' => false,
                    'verify_peer' => true,
                    'verify_host' => true,
                    'max_redirects' => 0,
                    'timeout' => PveHttpMethod::Post === $method ? 60.0 : 30.0,
                    'max_duration' => PveHttpMethod::Post === $method ? 60.0 : 30.0,
                ];
                if (PveHttpMethod::Post === $method) {
                    $options['body'] = $form;
                }
                $response = $this->httpClient->request($method->value, $url, $options);
                $status = $response->getStatusCode();
                if ($status < 200 || $status >= 300) {
                    $this->cancel($response);
                    return ['kind' => 'status', 'status' => $status];
                }
                $body = $this->readBoundedBody($response);
                $this->cancel($response);

                return null === $body ? ['kind' => 'oversized'] : ['kind' => 'success', 'body' => $body];
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
            if (strlen($body) > PveBackupJsonEnvelopeDecoder::MAXIMUM_BODY_BYTES - strlen($content)) {
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
            // Cancellation is best-effort and never replaces the stable result.
        }
    }

    private function isAmbiguousWriteStatus(int $status): bool
    {
        return match ($status) {
            408, 502, 503, 504 => true,
            default => false,
        };
    }

    private function mapStatus(int $status): PveBackupApiFailureCode
    {
        return match ($status) {
            401 => PveBackupApiFailureCode::Authentication,
            403 => PveBackupApiFailureCode::PermissionDenied,
            404 => PveBackupApiFailureCode::NotFound,
            429 => PveBackupApiFailureCode::RateLimited,
            408, 502, 503, 504 => PveBackupApiFailureCode::RemoteUnavailable,
            default => PveBackupApiFailureCode::HttpStatus,
        };
    }
}
