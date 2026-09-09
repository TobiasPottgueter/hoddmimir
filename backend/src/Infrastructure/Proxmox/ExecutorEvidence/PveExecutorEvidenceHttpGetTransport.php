<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\ExecutorEvidence;

use App\Application\Backup\Execution\ExecutorEvidenceRefreshFailure;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshFailureCode;
use App\Infrastructure\Proxmox\PveApiUrlBuilder;
use JsonException;
use SensitiveParameter;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

final readonly class PveExecutorEvidenceHttpGetTransport implements PveExecutorEvidenceGetTransport
{
    private const int MAXIMUM_BODY_BYTES = 8_388_608;

    public function __construct(
        private HttpClientInterface $http,
        private PveApiUrlBuilder $urls,
    ) {
    }

    public function get(PveExecutorEvidenceRequest $request, #[SensitiveParameter] string $authorization): mixed
    {
        $url = $this->urls->build($request->pathSegments());
        return $this->request($url, $authorization);
    }

    private function request(string $url, #[SensitiveParameter] string $authorization): mixed
    {
        $response = null;
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['Accept' => 'application/json', 'Authorization' => $authorization],
                'buffer' => false,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->cancel($response);
                throw ExecutorEvidenceRefreshFailure::for($this->statusCode($status));
            }
            $body = $this->body($response);
            $this->cancel($response);
        } catch (ExecutorEvidenceRefreshFailure $safe) {
            $this->cancel($response);
            throw $safe;
        } catch (TransportExceptionInterface) {
            $this->cancel($response);
            throw ExecutorEvidenceRefreshFailure::for(ExecutorEvidenceRefreshFailureCode::Transport);
        } catch (Throwable) {
            $this->cancel($response);
            throw ExecutorEvidenceRefreshFailure::for(ExecutorEvidenceRefreshFailureCode::Transport);
        }

        try {
            $envelope = \json_decode($body, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ExecutorEvidenceRefreshFailure::for(ExecutorEvidenceRefreshFailureCode::InvalidResponse);
        }
        if (!$envelope instanceof \stdClass || !\property_exists($envelope, 'data')) {
            throw ExecutorEvidenceRefreshFailure::for(ExecutorEvidenceRefreshFailureCode::InvalidResponse);
        }

        return $envelope->data;
    }

    private function body(ResponseInterface $response): string
    {
        $body = '';
        foreach ($this->http->stream($response) as $chunk) {
            $content = $chunk->getContent();
            if (\strlen($body) > self::MAXIMUM_BODY_BYTES - \strlen($content)) {
                throw ExecutorEvidenceRefreshFailure::for(ExecutorEvidenceRefreshFailureCode::InvalidResponse);
            }
            $body .= $content;
        }
        return $body;
    }

    private function statusCode(int $status): ExecutorEvidenceRefreshFailureCode
    {
        return match ($status) {
            401 => ExecutorEvidenceRefreshFailureCode::Authentication,
            403 => ExecutorEvidenceRefreshFailureCode::PermissionDenied,
            408, 429, 502, 503, 504 => ExecutorEvidenceRefreshFailureCode::RemoteUnavailable,
            default => ExecutorEvidenceRefreshFailureCode::InvalidResponse,
        };
    }

    private function cancel(?ResponseInterface $response): void
    {
        if (null === $response) {
            return;
        }
        try {
            $response->cancel();
        } catch (Throwable) {
            // Best effort only; never replace the already sanitized outcome.
        }
    }
}
