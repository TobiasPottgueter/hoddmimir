<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

final readonly class PbsHttpTransport implements PbsApiTransport
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private PbsApiUrlBuilder $urlBuilder,
        private PbsRequestAuthenticator $authenticator,
        private PbsRetryPolicy $retryPolicy,
        private PbsRetryDelay $retryDelay,
        private PbsJsonEnvelopeDecoder $envelopeDecoder,
        private ConnectionReadCheckpoint $checkpoint,
    ) {}

    public function get(PbsRequest $request): PbsApiEnvelope
    {
        return $this->requestWithRetry($request, 1);
    }

    private function requestWithRetry(PbsRequest $request, int $attempt): PbsApiEnvelope
    {
        $this->checkpoint->checkpoint();
        try {
            $result = $this->attempt($request);
        } finally {
            // attempt() crosses this boundary only after the plaintext-secret
            // callback has closed. Checkpoint failures propagate unchanged.
            $this->checkpoint->checkpoint();
        }
        if ('transport' === $result['kind']) {
            if ($this->retryPolicy->shouldRetry($attempt, true, null)) {
                $this->retryDelay->pause($attempt);
                return $this->requestWithRetry($request, $attempt + 1);
            }
            throw PbsReadFailure::for(PbsReadFailureCode::Transport);
        }
        if ('internal' === $result['kind']) {
            throw PbsReadFailure::for(PbsReadFailureCode::Transport);
        }
        if ('status' === $result['kind']) {
            if ($this->retryPolicy->shouldRetry($attempt, false, $result['status'])) {
                $this->retryDelay->pause($attempt);
                return $this->requestWithRetry($request, $attempt + 1);
            }
            throw PbsReadFailure::for($this->mapStatus($result['status']));
        }
        if ('oversized' === $result['kind']) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidEnvelope);
        }
        return $this->envelopeDecoder->decode($result['body'], $request->maximumBodyBytes);
    }

    /** @return array{kind:'success',body:string}|array{kind:'status',status:int}|array{kind:'transport'|'internal'|'oversized'} */
    private function attempt(PbsRequest $request): array
    {
        $url = $this->urlBuilder->build($request);
        $result = $this->authenticator->authorize(function (string $authorization) use ($url, $request): array {
            $response = null;
            try {
                $response = $this->httpClient->request('GET', $url, [
                    'headers' => ['Accept' => 'application/json', 'Authorization' => $authorization],
                    'buffer' => false,
                    'verify_peer' => true,
                    'verify_host' => true,
                    'max_redirects' => 0,
                ]);
                $status = $response->getStatusCode();
                if ($status < 200 || $status >= 300) {
                    $this->cancel($response);
                    return ['kind' => 'status', 'status' => $status];
                }
                if ($this->contentLengthExceeds($response, $request->maximumBodyBytes)) {
                    $this->cancel($response);
                    return ['kind' => 'oversized'];
                }
                $body = $this->readBoundedBody($response, $request->maximumBodyBytes);
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
        return $result;
    }

    private function contentLengthExceeds(ResponseInterface $response, int $maximum): bool
    {
        $value = $response->getHeaders(false)['content-length'][0] ?? null;
        if (!is_string($value) || '' === $value || !ctype_digit($value)) {
            return false;
        }
        $value = ltrim($value, '0');
        if ('' === $value) {
            return false;
        }
        $maximumText = (string) $maximum;
        return strlen($value) > strlen($maximumText)
            || (strlen($value) === strlen($maximumText) && strcmp($value, $maximumText) > 0);
    }

    private function readBoundedBody(ResponseInterface $response, int $maximum): ?string
    {
        $body = '';
        foreach ($this->httpClient->stream($response) as $chunk) {
            $content = $chunk->getContent();
            if (strlen($body) > $maximum - strlen($content)) {
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
            // Best effort only; never replace a safe result with response internals.
        }
    }

    private function mapStatus(int $status): PbsReadFailureCode
    {
        return match ($status) {
            401 => PbsReadFailureCode::Authentication,
            403 => PbsReadFailureCode::PermissionDenied,
            404 => PbsReadFailureCode::NotFound,
            429 => PbsReadFailureCode::RateLimited,
            408, 502, 503, 504 => PbsReadFailureCode::RemoteUnavailable,
            default => PbsReadFailureCode::HttpStatus,
        };
    }
}
