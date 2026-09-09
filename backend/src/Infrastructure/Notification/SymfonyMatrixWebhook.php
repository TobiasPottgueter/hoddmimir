<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Application\Backup\Notification\MatrixWebhook;
use App\Application\Backup\Notification\MatrixWebhookFailure;
use App\Application\Backup\Notification\MatrixWebhookFailureCode;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class SymfonyMatrixWebhook implements MatrixWebhook
{
    public function __construct(
        private HttpClientInterface $client,
        private string $url,
        private string $channel,
        private float $timeoutSeconds = 10.0,
    ) {
        $parts = parse_url($url);
        if (false === $parts
            || 'https' !== ($parts['scheme'] ?? null)
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || 1 !== preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $channel)
            || $timeoutSeconds < 1.0
            || $timeoutSeconds > 30.0) {
            throw new \InvalidArgumentException('The Matrix webhook configuration is invalid.');
        }
    }

    public function send(string $eventId, string $body): void
    {
        if (1 !== preg_match('/^[a-f0-9]{32}$/D', $eventId)
            || '' === trim($body)
            || strlen($body) > 16_384) {
            throw new MatrixWebhookFailure(MatrixWebhookFailureCode::Configuration);
        }
        try {
            $json = json_encode([
                'body' => $body,
                'channel' => $this->channel,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new MatrixWebhookFailure(MatrixWebhookFailureCode::Configuration);
        }

        try {
            $response = $this->client->request('POST', $this->url, [
                'body' => $json,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Idempotency-Key' => $eventId,
                ],
                'max_duration' => $this->timeoutSeconds,
                'max_redirects' => 0,
                'timeout' => $this->timeoutSeconds,
                'verify_host' => true,
                'verify_peer' => true,
            ]);
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface) {
            throw new MatrixWebhookFailure(MatrixWebhookFailureCode::Transport);
        }
        if ($status < 200 || $status >= 300) {
            throw new MatrixWebhookFailure(MatrixWebhookFailureCode::Rejected);
        }
    }
}
