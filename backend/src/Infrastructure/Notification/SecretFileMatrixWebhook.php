<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Application\Backup\Notification\MatrixWebhook;
use App\Application\Backup\Notification\BackupNotificationConfiguration;
use App\Application\Backup\Notification\MatrixWebhookFailure;
use App\Application\Backup\Notification\MatrixWebhookFailureCode;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class SecretFileMatrixWebhook implements MatrixWebhook, BackupNotificationConfiguration
{
    public function __construct(
        private HttpClientInterface $client,
        private string $urlFile,
        private string $channel,
        private float $timeoutSeconds = 10.0,
    ) {
        if ('' === $urlFile || '/' !== $urlFile[0]) {
            throw new \InvalidArgumentException('The Matrix webhook URL secret path must be absolute.');
        }
    }

    public function isValid(): bool
    {
        $url = @file_get_contents($this->urlFile);
        if (false === $url || '' === trim($url)) {
            return false;
        }
        try {
            new SymfonyMatrixWebhook($this->client, trim($url), $this->channel, $this->timeoutSeconds);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return true;
    }

    public function send(string $eventId, string $body): void
    {
        $url = @file_get_contents($this->urlFile);
        if (false === $url || '' === trim($url)) {
            throw new MatrixWebhookFailure(MatrixWebhookFailureCode::Configuration);
        }

        (new SymfonyMatrixWebhook(
            $this->client,
            trim($url),
            $this->channel,
            $this->timeoutSeconds,
        ))->send($eventId, $body);
    }
}
